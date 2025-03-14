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
        }
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
        $voice = $this->voice;
//        $tmpLang = strtolower($lang);
//        if($tmpLang === 'uz-uz') {
//            $voice = 'nigora';
//            $lang = 'uz-UZ';
//        }elseif($tmpLang === 'en-en'){
//            $lang = 'en-US';
//            $voice = 'john';
//        }else{
//            $lang = 'ru-RU';
//            $voice = 'alena';
//        }
        $speech_extension        = '.raw';
        $result_extension        = '.wav';
        $speech_filename         = md5($text_to_speech . $voice);
        $fullFileName            = $this->ttsDir .'/'. $speech_filename . $result_extension;
        $fullFileNameFromService = $this->ttsDir .'/'. $speech_filename . $speech_extension;
        $fullFileNameFromText    = $this->ttsDir .'/'. $speech_filename . '.txt';
        // Проверим мб мы ранее уже генерировали такой файл.
        if (file_exists($fullFileName) && filesize($fullFileName) > 0) {
            return $fullFileName;
        }

        try {
            $client = new Client([
                 'base_uri' => 'http://127.0.0.1:'.$this->local_port,
                 'timeout'  => 3.0,
            ]);
            $queryParams = [
                'text'   => $text_to_speech,
                'voice'  => $voice,
                'format' => 'wav',
            ];
            $response = $client->get('/say', [
                'query' => $queryParams,
                'sink'   => $fullFileNameFromService,
            ]);
            $http_code = $response->getStatusCode();
            if ($http_code === 200 && file_exists($fullFileNameFromService) && filesize($fullFileNameFromService) > 0) {
                // Конвертация raw в wav с помощью sox
                $soxPath = Util::which('sox');
                Processes::mwExec("$soxPath -v 0.99 -G '$fullFileNameFromService' -c 1 -r 8000 -b 16 '$fullFileName'", $out);

                if (file_exists($fullFileName)) {
                    // Удаляем raw файл
                    unlink($fullFileNameFromService);
                    // Сохраняем текст и язык в файл
                    file_put_contents($fullFileNameFromText, serialize([$text_to_speech, $lang]));
                    return $fullFileName;
                }
            } elseif (file_exists($fullFileNameFromService)) {
                // Удаляем raw файл, если что-то пошло не так
                unlink($fullFileNameFromService);
            }
            if(200 !== $http_code){
                Util::sysLogMsg('TTS RHVoice, return code: '. $http_code, '');
            }
        } catch (GuzzleException $e) {
            // Логирование ошибок
            Util::sysLogMsg('TTS RHVoice, error: ' . $e->getMessage(), '');
        }
        return null;
    }
}
