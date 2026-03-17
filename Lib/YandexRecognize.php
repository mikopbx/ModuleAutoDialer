<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2024 Alexey Portnov and Nikolay Beketov
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

class YandexRecognize
{
    private string $apiKey;
    private string $folderId;

    /**
     * @param string $apiKey   — API-ключ Yandex Cloud
     * @param string $folderId — идентификатор каталога Yandex Cloud
     */
    public function __construct(string $apiKey, string $folderId)
    {
        $this->apiKey = $apiKey;
        $this->folderId = $folderId;
    }

    /**
     * Распознаёт WAV-файл через Yandex STT API (sync, формат LPCM).
     *
     * @param string $wavFile — путь к WAV 8kHz mono 16-bit
     * @param string $lang    — язык распознавания (ru-RU, en-US, uz-UZ)
     * @return string|null    — распознанный текст или null при ошибке
     */
    public function recognizeFile(string $wavFile, string $lang = 'ru-RU'): ?string
    {
        if (!file_exists($wavFile) || filesize($wavFile) < 300) {
            return null;
        }
        // Конвертация WAV → raw PCM (lpcm 8000Hz 16bit mono)
        $soxPath = Util::which('sox');
        $pcmFile = $wavFile . '.pcm';
        Processes::mwExec("$soxPath '$wavFile' -t raw -r 8000 -c 1 -b 16 -e signed '$pcmFile'");
        if (!file_exists($pcmFile) || filesize($pcmFile) === 0) {
            Util::sysLogMsg('STT Yandex', 'sox conversion failed: ' . $wavFile);
            return null;
        }
        $pcmData = file_get_contents($pcmFile);
        unlink($pcmFile);

        $url = 'https://stt.api.cloud.yandex.net/speech/v1/stt:recognize'
            . '?lang=' . urlencode($lang)
            . '&format=lpcm&sampleRateHertz=8000&topic=general'
            . '&folderId=' . urlencode($this->folderId);

        $client = new Client(['timeout' => 10.0]);
        try {
            $response = $client->post($url, [
                'headers' => [
                    'Authorization' => 'Api-Key ' . $this->apiKey,
                ],
                'body' => $pcmData,
            ]);
            if ($response->getStatusCode() === 200) {
                $json = json_decode($response->getBody()->getContents(), true);
                return $json['result'] ?? null;
            }
            Util::sysLogMsg('STT Yandex', 'return code: ' . $response->getStatusCode());
        } catch (GuzzleException $e) {
            Util::sysLogMsg('STT Yandex', 'error: ' . $e->getMessage());
        }
        return null;
    }
}
