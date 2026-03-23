#!/usr/bin/php
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

use MikoPBX\Core\Asterisk\AGI;
use MikoPBX\Core\System\Util;
use Modules\ModuleAutoDialer\bin\ConnectorDB;
use Modules\ModuleAutoDialer\Lib\RHVoiceSynthesize;
use Modules\ModuleAutoDialer\Lib\YandexSynthesize;
use Modules\ModuleAutoDialer\Models\ModuleAutoDialer;
require_once 'Globals.php';

$syslogTag = 'send-crm';
try {
    $agi        = new AGI();
    $pollingId  = $argv[1] ?? '';
    $tplFile    = $argv[2] ?? '';
    $linkedId   = $agi->get_variable('CHANNEL(linkedid)', true);
    $phone      = $agi->get_variable('M_OUT_NUMBER', true);
    if (empty($phone)) {
        $phone = preg_replace('/\D/', '', $agi->request['agi_callerid']);
    }

    // Читаем настройки модуля
    /** @var ModuleAutoDialer $settings */
    $settings = ModuleAutoDialer::findFirst();
    if (!$settings || empty($settings->crmUrl)) {
        Util::sysLogMsg($syslogTag, 'CRM URL не настроен');
        exit(0);
    }

    // Читаем шаблон ответа
    $template = '';
    if (!empty($tplFile) && file_exists($tplFile)) {
        $template = file_get_contents($tplFile);
    }

    // Собираем результаты опроса
    $response = ConnectorDB::invoke('getResultsPollingByLinkedId', [$linkedId]);
    $results  = $response['data'] ?? [];

    // Формируем тело запроса
    $payload = [
        'phone'    => $phone,
        'linkedId' => $linkedId,
        'pollingId'=> $pollingId,
        'results'  => $results,
    ];

    // Отправляем POST в CRM
    $ch = curl_init($settings->crmUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    if (!empty($settings->crmLogin)) {
        curl_setopt($ch, CURLOPT_USERPWD, $settings->crmLogin . ':' . $settings->crmPassword);
    }
    // Отключаем проверку SSL для самоподписанных сертификатов
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

    $responseBody = curl_exec($ch);
    $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError    = curl_error($ch);
    curl_close($ch);

    if (!empty($curlError)) {
        Util::sysLogMsg($syslogTag, 'cURL ошибка: ' . $curlError);
        exit(0);
    }

    if ($httpCode !== 200) {
        Util::sysLogMsg($syslogTag, "CRM вернул код $httpCode: " . mb_substr($responseBody, 0, 200));
        exit(0);
    }

    // HTTP 200 — парсим JSON-ответ, извлекаем data
    $decoded = json_decode(trim($responseBody), true);
    if (is_array($decoded) && isset($decoded['data'])) {
        $resultValue = is_array($decoded['data']) ? json_encode($decoded['data'], JSON_UNESCAPED_UNICODE) : (string)$decoded['data'];
    } else {
        // Если ответ не JSON — используем как есть
        $resultValue = trim($responseBody);
    }

    // Цифры через пробел для корректного произношения TTS
    if (preg_match('/^\d+$/', $resultValue)) {
        $resultValue = implode(' ', str_split($resultValue));
    }

    if (empty($template)) {
        exit(0);
    }

    $text = str_replace('<result>', $resultValue, $template);

    $ttsDir = dirname(__DIR__) . '/db/tts-additional';
    if ($settings->ttsService === ModuleAutoDialer::TTS_MODEL_YANDEX) {
        $tts = new YandexSynthesize($ttsDir, $settings->yandexApiKey);
    } else {
        $tts = new RHVoiceSynthesize($ttsDir, '');
    }
    $fullFilename = $tts->makeSpeechFromText(strip_tags($text), 'ru-RU');
    if (!empty($fullFilename)) {
        $agi->set_variable('M_CRM_RESPONSE_FILE', Util::trimExtensionForFile($fullFilename));
    } else {
        Util::sysLogMsg($syslogTag, 'TTS не вернул файл, текст: ' . mb_substr($text, 0, 100));
    }
} catch (\Throwable $e) {
    Util::sysLogMsg($syslogTag, 'Ошибка: ' . $e->getMessage());
}
