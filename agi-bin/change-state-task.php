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
require_once 'Globals.php';
$agi    = new AGI();
$event  = $argv[1]??'';
if(empty($event)){
    exit(0);
}
$outNum      = $agi->get_variable('M_OUT_NUMBER',true);
$taskId      = $agi->get_variable('M_TASK_ID',true);
$data = [
    'ID'        => $agi->get_variable('CHANNEL(linkedid)',true),
    'CALL_ID'   => $agi->get_variable('CHANNEL(callid)',true),
    'TIME'      => time(),
];
if(ConnectorDB::EVENT_START_DIAL_IN === $event){
    // Событие возникает перед Dial на внутренний номер.
    ConnectorDB::invoke(ConnectorDB::FUNC_SAVE_STATE, [$event, $outNum, $taskId, $data], false);
}elseif (ConnectorDB::EVENT_ALL_USER_BUSY === $event){
    ConnectorDB::invoke(ConnectorDB::FUNC_SAVE_STATE, [$event, $outNum, $taskId, $data], false);
}elseif (ConnectorDB::EVENT_END_DIAL_IN === $event){
    // Событие возникает после обработки Dial на внутренний номер.
    $data['DIALSTATUS']   = $agi->get_variable('M_DIALSTATUS',true);
    ConnectorDB::invoke(ConnectorDB::FUNC_SAVE_STATE, [$event, $outNum, $taskId, $data], false);
}elseif (ConnectorDB::EVENT_FAIL_ORIGINATE === $event){
    // Вызов на внешний номер завершился неудачно.
    ConnectorDB::invoke(ConnectorDB::FUNC_SAVE_STATE, [$event, $outNum, $taskId, $data], false);
}elseif (ConnectorDB::EVENT_AFTER_DIAL_OUT === $event){
    // Событие возникает после обработки Dial на внешний номер телефона.
    $data['DIALSTATUS']     = $agi->get_variable('DIALSTATUS',true);
    ConnectorDB::invoke(ConnectorDB::FUNC_SAVE_STATE, [$event, $outNum, $taskId, $data], false);
}elseif (ConnectorDB::EVENT_END_CALL === $event){
    $chan = $agi->get_variable('CHANNEL',true);
    // Событие возникает при hangup канала, связанного с внешним номером.
    $data['DIALSTATUS']   = $agi->get_variable('M_DIALSTATUS',true);
    $data['TECH_CAUSE']   = $agi->get_variable("HANGUPCAUSE($chan,tech)",true);
    $data['ATS_CAUSE']    = $agi->get_variable("HANGUPCAUSE($chan,ast)",true);
    ConnectorDB::invoke(ConnectorDB::FUNC_SAVE_STATE, [$event, $outNum, $taskId, $data], false);
}