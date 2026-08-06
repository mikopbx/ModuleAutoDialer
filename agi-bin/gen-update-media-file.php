#!/usr/bin/php
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

use MikoPBX\Core\Asterisk\AGI;
use MikoPBX\Core\System\Util;
use Modules\ModuleAutoDialer\Lib\RHVoiceSynthesize;
use Modules\ModuleAutoDialer\Lib\YandexSynthesize;
use Modules\ModuleAutoDialer\Models\ModuleAutoDialer;
require_once 'Globals.php';

$syslogTag = 'gen-update-media-file';
try {
    $agi = new AGI();
    $filename = $agi->get_variable('M_FILENAME', true) . '.txt';
    if (!file_exists($filename)) {
        exit(0);
    }
    $settings = ModuleAutoDialer::findFirst();
    if (!$settings || (empty($settings->yandexApiKey) && $settings->ttsService === ModuleAutoDialer::TTS_MODEL_YANDEX)) {
        Util::sysLogMsg($syslogTag, 'Настройки TTS не найдены или не задан yandexApiKey');
        exit(0);
    }
    $paramsSrc = (string)$agi->get_variable('M_PARAMS', true);
    if (file_exists($paramsSrc)) {
        $params = json_decode(file_get_contents($paramsSrc), true);
    } else {
        $params = unserialize(base64_decode($paramsSrc), [stdClass::class]);
    }
    if (!is_array($params)) {
        Util::sysLogMsg($syslogTag, 'Не удалось распарсить M_PARAMS: ' . $paramsSrc);
        exit(0);
    }
    $fileContent = file_get_contents($filename);
    if ($fileContent === false) {
        Util::sysLogMsg($syslogTag, 'Не удалось прочитать файл: ' . $filename);
        exit(0);
    }
    $unserialized = unserialize($fileContent, [stdClass::class]);
    if (!is_array($unserialized) || count($unserialized) < 2) {
        Util::sysLogMsg($syslogTag, 'Невалидное содержимое файла: ' . $filename);
        exit(0);
    }
    [$questionText, $lang] = $unserialized;
    foreach ($params as $key => $value) {
        if (is_string($value) && ctype_digit($value)) {
            $value = implode(' ', str_split($value));
        }
        $questionText = str_replace('<' . $key . '>', (string)$value, $questionText);
    }
    if ($settings->ttsService === ModuleAutoDialer::TTS_MODEL_YANDEX) {
        $tts = new YandexSynthesize(dirname(__DIR__) . "/db/tts-additional", $settings->yandexApiKey);
    } else {
        $tts = new RHVoiceSynthesize(dirname(__DIR__) . "/db/tts-additional", '');
    }
    $fullFilename = $tts->makeSpeechFromText(strip_tags($questionText), $lang);
    if (!empty($fullFilename)) {
        $agi->set_variable('M_FILENAME', Util::trimExtensionForFile($fullFilename));
    } else {
        Util::sysLogMsg($syslogTag, 'TTS не вернул файл, текст: ' . mb_substr($questionText, 0, 100));
    }
} catch (\Throwable $e) {
    Util::sysLogMsg($syslogTag, 'Ошибка: ' . $e->getMessage());
}
