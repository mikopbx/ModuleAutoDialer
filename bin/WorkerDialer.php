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

use MikoPBX\Core\System\BeanstalkClient;
use MikoPBX\Core\System\Processes;
use MikoPBX\Core\System\Util;
use MikoPBX\Core\Workers\WorkerBase;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleAutoDialer\Lib\AutoDialerConf;
use Modules\ModuleAutoDialer\Lib\AutoDialerMain;
use Modules\ModuleAutoDialer\Lib\Logger;
use Modules\ModuleAutoDialer\Models\Tasks;

class WorkerDialer extends WorkerBase
{
    private Logger $logger;

    /**
     * Handles the received signal.
     *
     * @param int $signal The signal to handle.
     *
     * @return void
     */
    public function signalHandler(int $signal): void
    {
        parent::signalHandler($signal);
        cli_set_process_title('SHUTDOWN_'.cli_get_process_title());
    }

    /**
     * Старт работы.
     *
     * @param $argv
     */
    public function start($argv):void
    {
        $this->logger   = new Logger('WorkerDialer', 'ModuleAutoDialer');
        $this->logger->writeInfo('Starting...');
        $beanstalk      = new BeanstalkClient(self::class);
        $beanstalk->subscribe(self::class, [$this, 'onEvents']);
        $beanstalk->subscribe($this->makePingTubeName(self::class), [$this, 'pingCallBack']);
        while ($this->needRestart === false){
            // Ожидаем таймаут, выполняем внешние команды.
            $beanstalk->wait(1);
            $slice    = ConnectorDB::invoke('getSliceTask');
            if(empty($slice)){
                continue;
            }
            $statuses = AutoDialerMain::getCacheData('statuses');
            $queues   = AutoDialerMain::getCacheData('queues');
            foreach ($slice as $taskData){
                if(empty($taskData['phone'])){
                    $this->logger->writeInfo(['action' => 'dialer', 'task' => $taskData['taskId'], 'message' => 'No next phone']);
                    // По задаче пока все номера отложены. Звонить нелья.
                    continue;
                }
                if((int)$taskData['maxCountChannels'] <= (int)$taskData['in_progress']){
                    // Превышено максимально число каналов для задачи.
                    $this->logger->writeInfo(['action' => 'dialer', 'task' => $taskData['taskId'], 'message' => "maxCountChannels({$taskData['maxCountChannels']}) <= in_progress({$taskData['in_progress']})"]);
                    continue;
                }
                if($taskData['innerNumType'] === Tasks::TYPE_INNER_NUM_EXTENSION && $statuses[$taskData['innerNum']] !== WorkerAMI::STATE_IDLE){
                    // Внутренний номер занят.
                    $this->logger->writeInfo(['action' => 'dialer', 'task' => $taskData['taskId'], 'message' => "Number: $taskData[innerNum], State: ({$statuses[$taskData['innerNum']]}) is BUSY"]);
                    continue;
                }
                $this->logger->writeInfo(['action' => 'dialer', 'task' => $taskData['taskId'], 'message' => "Create callfile. Phone ({$taskData['phone']}), InnerNum ({$taskData['innerNum']})"]);
                $this->createCallFile($taskData, $queues);
                usleep(200000);
            }
            $this->logger->rotate();
        }
    }

    /**
     * Генерация задачи на callback.
     * @param array $taskData
     * @param array $queues
     * @return string
     */
    public function createCallFile(array $taskData, array $queues): string {
        $phone    = preg_replace('/\D/', '', $taskData['phone'] ?? '');
        $innerNum = preg_replace('/\D/', '', $taskData['innerNum'] ?? '');
        $innerNumType = $taskData['innerNumType'] ?? '';
        $taskId = $taskData['taskId'] ?? '';
        $defDialPrefix = $taskData['dialPrefix'] ?? '';
        $params = $taskData['params'] ?? '';
        if(!file_exists($params)){
            $params = base64_encode($params);
        }
        $maxAttempt = $taskData['maxAttempt'] ?? '';
        $tryInterval = $taskData['tryInterval'] ?? '';
        $attemptUntilSignal = $taskData['attemptUntilSignal'] ?? '';
        $isCallback = (int)($taskData['isCallback']??0);

        if($isCallback){
            $queueId = $queues[$innerNum]??'';
            $srcNum = $innerNum;
            $dstNum = $defDialPrefix.$phone;
            $srcContext = 'internal-originate';
            $dstContext = 'outgoing';
            $additionalVars = "Setvar: __SRC_QUEUE=".$queueId.PHP_EOL;
            $additionalVars.= "Setvar: __pt1c_cid=$phone".PHP_EOL;
            $additionalVars.= "Setvar: __M_IS_CALLBACK=1".PHP_EOL;
        }else{
            $srcNum = $defDialPrefix.$phone;
            $dstNum = $innerNum;
            $srcContext = 'outgoing';
            $dstContext = 'internal';
            $additionalVars = '';
        }

        $conf = "Channel: Local/$srcNum@dialer-out-originate-outgoing".PHP_EOL.
            "Callerid: dialer <$taskId>".PHP_EOL.
            "MaxRetries: 0".PHP_EOL.
            "RetryTime: 3".PHP_EOL.
            "Context: ".AutoDialerConf::CONTEXT_NAME.PHP_EOL.
            "Extension: $dstNum".PHP_EOL.
            "Priority: 1".PHP_EOL.
            "Archive: no".PHP_EOL.
            $additionalVars.
            "Setvar: __DISABLE_ANNONCE=1".PHP_EOL.
            "Setvar: _QUEUE_SRC_CHAN=1".PHP_EOL.
            "Setvar: __SRC_CONTEXT=$srcContext".PHP_EOL.
            "Setvar: __DST_CONTEXT=$dstContext".PHP_EOL.
            "Setvar: OFF_ANSWER_SUB=1".PHP_EOL.
            "Setvar: __M_INNER_NUMBER=$innerNum".PHP_EOL.
            "Setvar: __M_TASK_ID=$taskId".PHP_EOL.
            "Setvar: __M_MAX_ATTEMPT=$maxAttempt".PHP_EOL.
            "Setvar: __M_MAX_RETRY=1".PHP_EOL.
            "Setvar: __M_TRY_INTERVAL=$tryInterval".PHP_EOL.
            "Setvar: __M_OUT_NUMBER=$phone".PHP_EOL.
            "Setvar: __M_ATTEMPT_UTIL_SIGNAL=$attemptUntilSignal".PHP_EOL.
            "Setvar: __M_EXTEN_TYPE=$innerNumType".PHP_EOL.
            "Setvar: __M_PARAMS=$params";

        $outgoingDir = AutoDialerMain::getDiSetting('asterisk.astspooldir').'/outgoing';
        $tmpDir      = AutoDialerMain::getDiSetting('core.tempDir');

        $tmpFileName = tempnam($tmpDir, 'call');
        $newFilename = "$outgoingDir/dialer-$taskId-$phone-$dstNum.call";

        file_put_contents($tmpFileName, $conf);
        $data = ['filename' => basename($newFilename)];
        ConnectorDB::invoke('saveStateData', [ConnectorDB::EVENT_CREATE_CALL_FILE, $phone, $taskId, $data], false);
        $mvPath = Util::which('mv');
        Processes::mwExec("$mvPath $tmpFileName $newFilename");
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
     * Выполнение методов worker, запущенного в другом процессе.
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

if(isset($argv) && count($argv) !== 1
    && Util::getFilePathByClassName(WorkerDialer::class) === $argv[0]){
    // Start worker process
    WorkerDialer::startWorker($argv??[]);
}