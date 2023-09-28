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
use Modules\ModuleAutoDialer\Lib\YandexSynthesize;
use Modules\ModuleAutoDialer\Models\ModuleAutoDialer;
require_once 'Globals.php';
$agi            = new AGI();

$filename = $agi->get_variable('M_FILENAME',true).'.txt';
if(!file_exists($filename)){
    exit(0);
}

$settings = ModuleAutoDialer::findFirst();
if(!$settings || empty($settings->yandexApiKey)){
    return '';
}
$tts = new YandexSynthesize(dirname(__DIR__)."/db/tts-additional", $settings->yandexApiKey);
[$questionText, $lang]   = unserialize(file_get_contents($filename), [stdClass::class]);
$params = unserialize(base64_decode($agi->get_variable('M_PARAMS',true)), [stdClass::class]);
foreach ($params as $key => $value){
    $questionText = str_replace('<'.$key.'>', $value, $questionText);
}
$fullFilename = $tts->makeSpeechFromText(strip_tags($questionText), $lang);
$agi->set_variable('M_FILENAME', Util::trimExtensionForFile($fullFilename));
