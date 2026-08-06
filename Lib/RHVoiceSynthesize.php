<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2023 Alexey Portnov and Nikolay Beketov
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program.
 * If not, see <https://www.gnu.org/licenses/>.
 */

namespace Modules\ModuleAutoDialer\Lib;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use MikoPBX\Core\System\Processes;
use MikoPBX\Core\System\Util;
use Modules\ModuleRHVoice\Models\ModuleRHVoice;

class RHVoiceSynthesize
{
    private string $ttsDir;
    private string $apiKey;
    private string $voice = 'vitaliy-ng';
    private string $local_port= '8081';

    private string $rate= '40';
    private array $voiceData = [];

    /**
     * Инициализация класса.
     * @param string $ttsDir
     * @param string $apiKey
     */
    public function __construct(string $ttsDir, string $apiKey = '')
    {
        $this->apiKey = $apiKey;
        $this->ttsDir = $ttsDir;
        if(!file_exists($this->ttsDir)){
            Util::mwMkdir($this->ttsDir);
        }
        if(class_exists('Modules\ModuleRHVoice\Models\ModuleRHVoice')){
            $settings = ModuleRHVoice::findFirst();
            if($settings && !empty($settings->local_port)){
                $this->local_port = $settings->local_port;
            }
            if($settings && !empty($settings->voice)){
                $this->voice = $settings->voice;
            }

            if($settings && !empty($settings->rate)){
                $this->rate = $settings->rate;
            }
            $this->voiceData = ModuleRHVoice::getSelectVoiceData(true);
        }
    }

    /**
     * Транслитерация латиницы в кириллицу для корректного произношения RHVoice.
     * RHVoice — русский TTS-движок, латинские символы может пропускать или
     * генерировать почти пустой WAV. Особенно актуально для автомобильных номеров
     * (O501ET44), артикулов и адресов с латиницей.
     */
    private function transliterateForRHVoice(string $text): string
    {
        // Латиница → кириллица (фонетическая транслитерация для русской речи)
        $map = [
            'A' => 'А', 'B' => 'Б', 'C' => 'С', 'D' => 'Д', 'E' => 'Е',
            'F' => 'Ф', 'G' => 'Г', 'H' => 'Х', 'I' => 'И', 'J' => 'Дж',
            'K' => 'К', 'L' => 'Л', 'M' => 'М', 'N' => 'Н', 'O' => 'О',
            'P' => 'П', 'Q' => 'К', 'R' => 'Р', 'S' => 'С', 'T' => 'Т',
            'U' => 'У', 'V' => 'В', 'W' => 'В', 'X' => 'Кс', 'Y' => 'У',
            'Z' => 'З',
            'a' => 'а', 'b' => 'б', 'c' => 'с', 'd' => 'д', 'e' => 'е',
            'f' => 'ф', 'g' => 'г', 'h' => 'х', 'i' => 'и', 'j' => 'дж',
            'k' => 'к', 'l' => 'л', 'm' => 'м', 'n' => 'н', 'o' => 'о',
            'p' => 'п', 'q' => 'к', 'r' => 'р', 's' => 'с', 't' => 'т',
            'u' => 'у', 'v' => 'в', 'w' => 'в', 'x' => 'кс', 'y' => 'у',
            'z' => 'з',
        ];
        return strtr($text, $map);
    }

    /**
     * Генерирует и скачивает в на внешний диск файл с речью.
     *
     * @param $text_to_speech - генерируемый текст
     * @param $lang           - язык
     *
     * @return null|string
     *
     * https://tts.api.cloud.yandex.net/speech/v1/tts:synthesize
     */
    public function makeSpeechFromText(string $text_to_speech, string $lang): ?string
    {
        if (trim($text_to_speech) === '') {
            return null;
        }
        $text_to_speech = $this->transliterateForRHVoice($text_to_speech);
        $voice = $this->voice;
        $tmpLang = strtolower($lang);
        if(isset($this->voiceData[$tmpLang]) && !in_array($voice, $this->voiceData[$tmpLang], true)){
            // Проверка корректности выбора языка.
            $voice = $this->voiceData[$tmpLang][0];
        }
        $speech_extension        = '.raw';
        $result_extension        = '.wav';
        $speech_filename         = md5($text_to_speech . $voice. $this->rate);
        $fullFileName            = $this->ttsDir .'/'. $speech_filename . $result_extension;
        $fullFileNameFromService = $this->ttsDir .'/'. $speech_filename . $speech_extension;
        $fullFileNameFromText    = $this->ttsDir .'/'. $speech_filename . '.txt';
        // Проверим мб мы ранее уже генерировали такой файл.
        // Минимальный размер: 1 секунда аудио 8kHz 16-bit mono = ~16000 байт.
        // RHVoice может вернуть HTTP 200 с почти пустым WAV (~1600 байт) при ошибках
        // обработки текста (латиница, спецсимволы), и такой файл закешируется навсегда.
        $minFileSize = 16000;
        if (file_exists($fullFileName)) {
            if (filesize($fullFileName) > $minFileSize) {
                return $fullFileName;
            }
            unlink($fullFileName);
        }

        $maxRetries = 3;
        $client = new Client([
             'base_uri' => 'http://127.0.0.1:'.$this->local_port,
             'timeout'  => 10.0,
        ]);
        $queryParams = [
            'text'   => $text_to_speech,
            'voice'  => $voice,
            'format' => 'wav',
            'rate' => $this->rate,
        ];
        $soxPath = Util::which('sox');
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = $client->get('/say', [
                    'query' => $queryParams,
                    'sink'   => $fullFileNameFromService,
                ]);
                $http_code = $response->getStatusCode();
                if ($http_code === 200 && file_exists($fullFileNameFromService) && filesize($fullFileNameFromService) > 0) {
                    // Конвертация в wav 8kHz mono 16-bit для Asterisk
                    // RHVoice отдаёт WAV (format=wav), указываем -t wav чтобы sox не угадывал формат по расширению .raw
                    Processes::mwExec("$soxPath -v 0.99 -G -t wav '$fullFileNameFromService' -c 1 -r 8000 -b 16 '$fullFileName'", $out);

                    if (file_exists($fullFileName) && filesize($fullFileName) > $minFileSize) {
                        // Удаляем raw файл
                        unlink($fullFileNameFromService);
                        // Сохраняем текст и язык в файл
                        file_put_contents($fullFileNameFromText, serialize([$text_to_speech, $lang]));
                        return $fullFileName;
                    }
                    // sox создал слишком маленький файл — RHVoice вернул почти пустой ответ
                    $actualSize = file_exists($fullFileName) ? filesize($fullFileName) : 0;
                    if ($actualSize > 0) {
                        unlink($fullFileName);
                    }
                    Util::sysLogMsg('TTS RHVoice', "Попытка $attempt/$maxRetries: файл слишком маленький ($actualSize байт, мин. $minFileSize), текст: " . mb_substr($text_to_speech, 0, 80));
                } else {
                    if (file_exists($fullFileNameFromService)) {
                        unlink($fullFileNameFromService);
                    }
                    Util::sysLogMsg('TTS RHVoice', "Попытка $attempt/$maxRetries: HTTP $http_code, текст: " . mb_substr($text_to_speech, 0, 80));
                }
            } catch (GuzzleException $e) {
                Util::sysLogMsg('TTS RHVoice', "Попытка $attempt/$maxRetries: " . $e->getMessage());
            }
            // Удаляем промежуточные файлы перед повтором
            if (file_exists($fullFileNameFromService)) {
                unlink($fullFileNameFromService);
            }
        }
        return null;
    }
}
