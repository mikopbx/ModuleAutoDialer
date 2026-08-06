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

$syslogTag = 'confirm-stt';
try {
    $agi = new AGI();
    $pollingId     = $argv[1] ?? '';
    $questionCrmId = $argv[2] ?? '';
    $lang          = $argv[3] ?? 'ru-RU';
    $linkedId      = $agi->get_variable('CHANNEL(linkedid)', true);

    // Sync-запрос: получить все STT-результаты по linkedId.
    // Beanstalk FIFO гарантирует, что все предыдущие savePolingResult (с STT) уже обработаны.
    $response = ConnectorDB::invoke('getRecognizedResults', [$linkedId]);
    $results  = $response['data'] ?? [];

    if (empty($results)) {
        // Нет STT-результатов — не устанавливаем M_FILENAME, dialplan пропустит
        Util::sysLogMsg($syslogTag, 'Нет STT-результатов для linkedId=' . $linkedId);
        exit(0);
    }

    // Собираем текст подтверждения с подписями вопросов
    $parts = [];
    foreach ($results as $r) {
        $label = $r['recognizeLabel'] ?? '';
        $text  = $r['recognizedText'] ?? '';
        if (empty($text)) {
            continue;
        }
        if (!empty($label)) {
            $parts[] = $label . ': ' . $text;
        } else {
            $parts[] = $text;
        }
    }
    if (empty($parts)) {
        exit(0);
    }

    // Читаем базовый текст вопроса из M_FILENAME.txt (если есть)
    $baseFilename = $agi->get_variable('M_FILENAME', true);
    $baseText = '';
    if (!empty($baseFilename)) {
        $txtFile = $baseFilename . '.txt';
        if (file_exists($txtFile)) {
            $unserialized = unserialize(file_get_contents($txtFile), [stdClass::class]);
            if (is_array($unserialized) && count($unserialized) >= 1) {
                $baseText = $unserialized[0];
            }
        }
    }

    // Формируем итоговый текст
    $confirmText = trim($baseText) . ' ' . implode('. ', $parts) . '.';

    // Генерируем TTS
    $settings = ModuleAutoDialer::findFirst();
    if (!$settings) {
        Util::sysLogMsg($syslogTag, 'Настройки модуля не найдены');
        exit(0);
    }
    $ttsDir = dirname(__DIR__) . '/db/tts-additional';
    if ($settings->ttsService === ModuleAutoDialer::TTS_MODEL_YANDEX) {
        $tts = new YandexSynthesize($ttsDir, $settings->yandexApiKey);
    } else {
        $tts = new RHVoiceSynthesize($ttsDir, '');
    }
    $fullFilename = $tts->makeSpeechFromText(strip_tags($confirmText), $lang);
    if (!empty($fullFilename)) {
        $agi->set_variable('M_FILENAME', Util::trimExtensionForFile($fullFilename));
    } else {
        Util::sysLogMsg($syslogTag, 'TTS не вернул файл, текст: ' . mb_substr($confirmText, 0, 100));
    }
} catch (\Throwable $e) {
    Util::sysLogMsg($syslogTag, 'Ошибка: ' . $e->getMessage());
}
