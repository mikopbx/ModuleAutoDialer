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
namespace Modules\ModuleAutoDialer\bin;
require_once 'Globals.php';

use MikoPBX\Common\Models\CallQueueMembers;
use MikoPBX\Common\Models\Extensions;
use MikoPBX\Core\Asterisk\AsteriskManager;
use MikoPBX\Core\System\BeanstalkClient;
use MikoPBX\Core\System\Processes;
use MikoPBX\Core\Workers\WorkerBase;
use MikoPBX\Core\System\Util;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleAutoDialer\Lib\AutoDialerMain;
use Modules\ModuleAutoDialer\Lib\Logger;
use Modules\ModuleAutoDialer\Models\Tasks;

class WorkerDialer extends WorkerBase
{
    private Logger $logger;

    /**
     * Старт работы.
     *
     * @param $params
     */
    public function start($params):void
    {
        $this->logger   = new Logger('WorkerDialer', 'ModuleAutoDialer');
        $this->logger->writeInfo('Starting...');
        $beanstalk      = new BeanstalkClient(self::class);
        $beanstalk->subscribe(self::class, [$this, 'onEvents']);
        $beanstalk->subscribe($this->makePingTubeName(self::class), [$this, 'pingCallBack']);
        while (true){
            // Ожидаем таймаут, выполняем внешние команды.
            $beanstalk->wait(1);
            $slice    = ConnectorDB::invoke('getSliceTask');
            if(empty($slice)){
                continue;
            }
            $statuses = AutoDialerMain::getCacheData('statuses');
            foreach ($slice as $taskData){
                if(empty($taskData['phone'])){
                    // $this->logger->writeInfo(['action' => 'dialer', 'task' => $taskData['taskId'], 'message' => 'No next phone']);
                    // По задаче пока все номера отложены. Звонить нелья.
                    continue;
                }
                if((int)$taskData['maxCountChannels'] <= (int)$taskData['in_progress']){
                    // Превышено максимально число каналов для задачи.
                    // $this->logger->writeInfo(['action' => 'dialer', 'task' => $taskData['taskId'], 'message' => "maxCountChannels({$taskData['maxCountChannels']}) <= in_progress({$taskData['in_progress']})"]);
                    continue;
                }
                if($statuses[$taskData['innerNum']] !== WorkerAMI::STATE_IDLE){
                    // Внутренний номер занят.
                    // $this->logger->writeInfo(['action' => 'dialer', 'task' => $taskData['taskId'], 'message' => "innerNum({$statuses[$taskData['innerNum']]}) is BUSY"]);
                    continue;
                }
                $this->logger->writeInfo(['action' => 'dialer', 'task' => $taskData['taskId'], 'message' => "Create callfile. Phone ({$taskData['phone']}), InnerNum ({$taskData['innerNum']})"]);
                $this->createCallFile($taskData['phone'], $taskData['innerNum'], $taskData['taskId']);
                usleep(200000);
            }
            $this->logger->rotate();
        }
    }

    /**
     * Генерация задачи на callback.
     * @param        $outNum
     * @param        $innerNum
     * @param        $taskId
     * @return string
     */
    public function createCallFile($outNum, $innerNum, $taskId):string{
        $outNum     = preg_replace('/\D/', '', $outNum);
        $innerNum   = preg_replace('/\D/', '', $innerNum);
        $conf = "Channel: Local/$outNum@dialer-out-originate-outgoing".PHP_EOL.
            "Callerid: dialer <$taskId>".PHP_EOL.
            "MaxRetries: 0".PHP_EOL.
            "RetryTime: 3".PHP_EOL.
            "Context: dialer-out-originate-in".PHP_EOL.
            "Extension: $innerNum".PHP_EOL.
            "Priority: 1".PHP_EOL.
            "Archive: no".PHP_EOL.
            "Setvar: __M_INNER_NUMBER=$innerNum".PHP_EOL.
            "Setvar: __M_TASK_ID=$taskId".PHP_EOL.
            "Setvar: __M_MAX_RETRY=1".PHP_EOL.
            "Setvar: __M_OUT_NUMBER=$outNum";

        $outgoingDir = AutoDialerMain::getDiSetting('asterisk.astspooldir').'/outgoing';
        $tmpDir      = AutoDialerMain::getDiSetting('core.tempDir');

        $tmpFileName = tempnam($tmpDir, 'call');
        $newFilename = "$outgoingDir/dialer-$taskId-$outNum-$innerNum.call";

        file_put_contents($tmpFileName, $conf);
        $newTimeInt    = time()+1;
        $newTimeString = date('ymdHi.s', $newTimeInt);

        $data = ['filename' => basename($newFilename)];
        ConnectorDB::invoke('saveStateData', [ConnectorDB::EVENT_CREATE_CALL_FILE, $outNum, $taskId, $data], false);
        Processes::mwExec("touch -t '$newTimeString' '$tmpFileName'");
        Processes::mwExec("cp -p $tmpFileName $newFilename");
        touch($newFilename, $newTimeInt);
        return $newFilename;
    }

    /**
     * Получение запросов на идентификацию номера телефона.
     * @param $tube
     * @return void
     */
    public function onEvents($tube): void
    {
        try {
            $data = json_decode($tube->getBody(), true, 512, JSON_THROW_ON_ERROR);
        }catch (\Throwable $e){
            return;
        }
        if($data['action'] === 'invoke'){
            $res_data = [];
            $funcName = $data['function']??'';
            if(method_exists($this, $funcName)){
                if(count($data['args']) === 0){
                    $res_data = $this->$funcName();
                }else{
                    $res_data = $this->$funcName(...$data['args']??[]);
                }
                $res_data = serialize($res_data);
            }
            if(isset($data['need-ret'])){
                $tube->reply($res_data);
            }
        }
    }

    /**
     * Выполнение меодов worker, запущенного в другом процессе.
     * @param string $function
     * @param array $args
     * @param bool $retVal
     * @return array|bool|mixed
     */
    public static function invoke(string $function, array $args = [], bool $retVal = true){
        $req = [
            'action'   => 'invoke',
            'function' => $function,
            'args'     => $args
        ];
        $client = new BeanstalkClient(self::class);
        try {
            if($retVal){
                $req['need-ret'] = true;
                $result = $client->request(json_encode($req, JSON_THROW_ON_ERROR), 20);
            }else{
                $client->publish(json_encode($req, JSON_THROW_ON_ERROR));
                return true;
            }
            $object = unserialize($result, ['allowed_classes' => [PBXApiResult::class]]);
        } catch (\Throwable $e) {
            $object = [];
        }
        return $object;
    }
}

if(isset($argv) && count($argv) !== 1){
    // Start worker process
    WorkerDialer::startWorker($argv??[]);
}