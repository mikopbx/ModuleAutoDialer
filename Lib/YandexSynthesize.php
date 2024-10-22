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
use MikoPBX\Core\System\Util;

class YandexSynthesize
{
    private string $ttsDir;
    private string $apiKey;

    /**
     * Инициализация класса.
     * @param string $ttsDir
     * @param string $apiKey
     */
    public function __construct(string $ttsDir, string $apiKey)
    {
        $this->apiKey = $apiKey;
        $this->ttsDir = $ttsDir;
        if(!file_exists($this->ttsDir)){
            Util::mwMkdir($this->ttsDir);
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
        if($lang === 'uz-UZ'){
            $voice = 'nigora';
        }else{
            $voice = 'alena';
        }
        $speech_extension        = '.raw';
        $result_extension        = '.wav';
        $speech_filename         = md5($text_to_speech . $voice);
        $fullFileName            = $this->ttsDir .'/'. $speech_filename . $result_extension;
        $fullFileNameFromService = $this->ttsDir .'/'. $speech_filename . $speech_extension;
        $fullFileNameFromText    = $this->ttsDir .'/'. $speech_filename . '.txt';
        // Проверим вдург мы ранее уже генерировали такой файл.
        if (file_exists($fullFileName) && filesize($fullFileName) > 0) {
            return $fullFileName;
        }
        // Файла нет в кеше, будем генерировать новый.
        $post_vars = [
            'lang'            => $lang,
            'format'          => 'lpcm',
            'speed'           => '1.0',
            'sampleRateHertz' => '8000',
            'voice'           => $voice,
            'text'            => urldecode(strip_tags($text_to_speech)),
        ];

        $fp   = fopen($fullFileNameFromService, 'wb');
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Authorization: Api-Key ".$this->apiKey]);
        curl_setopt($curl, CURLOPT_FILE, $fp);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 4);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post_vars));
        curl_setopt($curl, CURLOPT_URL, 'https://tts.api.cloud.yandex.net/speech/v1/tts:synthesize');
        curl_exec($curl);
        $http_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        fclose($fp);
        if (200 === $http_code && file_exists($fullFileNameFromService) && filesize($fullFileNameFromService) > 0) {
            $soxPath = Util::which('sox');
            exec("$soxPath -r 8000 -e signed-integer -b 16 -c 1 -t raw $fullFileNameFromService $fullFileName");
            if (file_exists($fullFileName)) {
                // Удалим raw файл.
                unlink($fullFileNameFromService);
                file_put_contents($fullFileNameFromText, serialize([$text_to_speech,$lang]));
                // Файл успешно сгененрирован
                return $fullFileName;
            }
        } elseif (file_exists($fullFileNameFromService)) {
            unlink($fullFileNameFromService);
        }
        return null;
    }
}
