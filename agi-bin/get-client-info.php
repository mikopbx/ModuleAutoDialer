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

use Modules\ModuleAutoDialer\bin\ConnectorDB;
use MikoPBX\Core\Asterisk\AGI;
use Modules\ModuleAutoDialer\Models\DialerExtensions;
use MikoPBX\Core\System\Util;
require_once 'Globals.php';

$agi    = new AGI();
$phone  = preg_replace('/\D/', '', $agi->request['agi_callerid']);
$result = [];
try {
    $resultApi = ConnectorDB::invoke('findClientByPhone', [$phone]);
    $result = array_column($resultApi['data']??[], 'value', 'key');
}catch (Throwable $e){
    exit();
}

$extenId = $argv[1]??'';
/** @var DialerExtensions $extenData */
$extenData = DialerExtensions::findFirst("id='$extenId'");
if(!$extenData){
    exit();
}
$paramFile = "/storage/usbdisk1/mikopbx/astspool/dialer-client-response/".date('Y/m/d/').$agi->get_variable('CHANNEL(linkedid)', true).'.txt';
Util::mwMkdir(dirname($paramFile), true);
if(empty($result)){
    $agi->exec('Goto', "dialer-polling,$extenData->pollingIdFAIL,1");
}else{
    file_put_contents($paramFile,json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    $agi->set_variable('M_PARAMS', $paramFile);
    $agi->exec('Goto', "dialer-polling,$extenData->pollingIdOK,1");
}
