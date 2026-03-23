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
use Modules\ModuleAutoDialer\bin\ConnectorDB;
use Modules\ModuleAutoDialer\Models\PolingResults;
require_once 'Globals.php';
$agi            = new AGI();

$result = new PolingResults();
$result->pollingId      = $argv[1]??'';
$result->questionCrmId  = $argv[2]??'';
$result->result         = $argv[3]??'';
$result->exten          = $argv[4]??'';
$result->phone          = $agi->get_variable('M_OUT_NUMBER',true);
if (empty($result->phone)) {
    // Входящий вызов, маршрутизированный напрямую на опрос — берём номер звонящего
    $result->phone = preg_replace('/\D/', '', $agi->request['agi_callerid']);
}
$result->phoneId        = ConnectorDB::getPhoneIndex($result->phone);
$result->taskId         = $agi->get_variable('M_TASK_ID',true);
$result->linkedId       = $agi->get_variable('CHANNEL(linkedid)',true);
$result->verboseCallId  = $agi->get_variable('CHANNEL(callid)',true);

if(empty($result->taskId)){
    $result->taskId = -1;
}
if(empty($result->result)){
    $result->result = '-';
}

$dataToSave = $result->toArray();

// Передаём флаг распознавания и подпись из канальных переменных
$needRecognize = (string)$agi->get_variable('M_NEED_RECOGNIZE', true);
if ($needRecognize === '1') {
    $dataToSave['needRecognize'] = '1';
    $dataToSave['recognizeLabel'] = (string)$agi->get_variable('M_RECOGNIZE_LABEL', true);
    $sttLang = (string)$agi->get_variable('M_STT_LANG', true);
    $dataToSave['lang'] = empty($sttLang) ? 'ru-RU' : $sttLang;
}

ConnectorDB::invoke('savePolingResult', [$dataToSave], false);