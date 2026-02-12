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

use MikoPBX\Common\Models\PbxSettings;
use MikoPBX\Core\System\PBX;
use MikoPBX\Core\System\Util;
use MikoPBX\Core\Workers\WorkerBase;
use MikoPBX\Core\System\BeanstalkClient;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleAutoDialer\Lib\AutoDialerMain;
use Modules\ModuleAutoDialer\Lib\Logger;
use Modules\ModuleAutoDialer\Lib\MikoPBXVersion;
use Modules\ModuleAutoDialer\Models\AudioFiles;
use Modules\ModuleAutoDialer\Models\Clients;
use Modules\ModuleAutoDialer\Models\ClientsPhones;
use Modules\ModuleAutoDialer\Models\ClientsProperties;
use Modules\ModuleAutoDialer\Models\ModuleAutoDialer;
use Modules\ModuleAutoDialer\Models\PolingResults;
use Modules\ModuleAutoDialer\Models\Polling;
use Modules\ModuleAutoDialer\Models\Question;
use Modules\ModuleAutoDialer\Models\QuestionActions;
use Modules\ModuleAutoDialer\Models\TaskResults;
use Modules\ModuleAutoDialer\Models\Tasks;
use Exception;

require_once 'Globals.php';

class ConnectorDB extends WorkerBase
{
    public const FUNC_SAVE_STATE                    = 'saveStateData';

    public const EVENT_CREATE_TASK                  = 'CreateTask';
    public const EVENT_CREATE_CALL_FILE             = 'CreateCallFile';
    public const EVENT_AFTER_DIAL_OUT               = 'afterDialOut';
    public const EVENT_FAIL_ORIGINATE               = 'failedOriginate';
    public const EVENT_START_DIAL_IN                = 'startDial';
    public const EVENT_END_DIAL_IN                  = 'endDial';
    public const EVENT_END_CALL                     = 'endCall';
    public const EVENT_POLLING                      = 'EVENT_POLLING';
    public const EVENT_POLLING_END                  = 'EVENT_POLLING_END';
    public const EVENT_ALL_USER_BUSY                = 'allUserBusy';
    public const EVENT_USER_CANCEL_CALLBACK         = 'UserCancelCallback';
    public const RESULT_SUCCESS                     = 'SUCCESS';
    public const RESULT_SUCCESS_ANOTHER_PHONE       = 'SUCCESS_ANOTHER_PHONE';
    public const RESULT_SUCCESS_EXTERNAL_SIGNAL     = 'SUCCESS_EXTERNAL_SIGNAL';
    public const RESULT_SUCCESS_CLIENT_H            = 'SUCCESS_CLIENT_H';
    public const RESULT_SUCCESS_USER_H              = 'SUCCESS_USER_H';
    public const RESULT_SUCCESS_POLLING             = 'SUCCESS_POLLING';
    public const RESULT_FAIL                        = 'FAIL';
    public const RESULT_FAIL_CLIENT_H_BEFORE_ANSWER = 'FAIL_CLIENT_H_BEFORE_ANSWER';
    public const RESULT_FAIL_USER_H_BEFORE_ANSWER   = 'FAIL_USER_H_BEFORE_CLIENT_ANSWER';
    public const RESULT_FAIL_USER_NO_ANSWER         = 'FAIL_USER_NO_ANSWER';
    public const RESULT_FAIL_USER_BUSY              = 'FAIL_USER_BUSY';
    public const RESULT_FAIL_ROUTE                  = 'FAIL_ROUTE';
    public const RESULT_FAIL_PROVIDER               = 'FAIL_PROVIDER';
    public const RESULT_FAIL_POLLING                = 'FAIL_POLLING';

    private const BATCH_INSERT_SIZE = 100;

    private Logger $logger;
    /** @var \Phalcon\Db\Adapter\AdapterInterface Соединение с БД модуля */
    private $moduleDb;

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
     * Starts the listener worker.
     *
     * @param $argv
     */
    public function start($argv):void
    {
        $task = Tasks::findFirst();
        if(!$task){
            $task = new Tasks();
            $task->id = 1000000000;
            $task->name = 'demo';
            $task->state=1;
            $task->save();
            $task->delete();
        }
        $this->moduleDb = (new TaskResults())->getWriteConnection();
        $this->logger   = new Logger('ConnectorDB', 'ModuleAutoDialer');
        $this->logger->writeInfo('Starting...');
        $beanstalk      = new BeanstalkClient(self::class);
        $beanstalk->subscribe(self::class, [$this, 'onEvents']);
        $beanstalk->subscribe($this->makePingTubeName(self::class), [$this, 'pingCallBack']);
        while ($this->needRestart === false) {
            $beanstalk->wait();
            $this->logger->rotate();
        }
    }

    public function pingCallBack(BeanstalkClient $message): void
    {
        parent::pingCallBack($message);
    }

    /**
     * Handles incoming IPC requests via Beanstalk.
     * @param $tube
     * @return void
     */
    private const ALLOWED_IPC_METHODS = [
        'saveStateData', 'savePolingResult', 'saveAudioFile', 'listAudioFiles', 'deleteAudioFile',
        'getSliceTask', 'deleteTask', 'addClient', 'deleteClient', 'findClientByPhone',
        'addPolling', 'deletePolling', 'addQuestion', 'changeTask', 'getTask', 'getTasks',
        'addTask', 'taskSignalClose', 'getResults', 'getResultsPolling',
        'getPolling', 'getPollingById',
    ];

    public function onEvents($tube): void
    {
        $data = [];
        try {
            $pathToData = $tube->getBody();
            if(file_exists($pathToData)) {
                $data = json_decode(file_get_contents($pathToData), true, 512, JSON_THROW_ON_ERROR);
                unlink($pathToData);
            }
        }catch (Exception $e){
            return;
        }
        $action = $data['action']??'';
        if($action === 'invoke'){
            $res_data = [];
            $funcName = $data['function']??'';
            if(in_array($funcName, self::ALLOWED_IPC_METHODS, true) && method_exists($this, $funcName)){
                if(count($data['args']) === 0){
                    $res_data = $this->$funcName();
                }else{
                    $res_data = $this->$funcName(...$data['args']??[]);
                }
            }
            if(isset($data['need-ret'])){
                $tube->reply(serialize($res_data));
            }
        }
    }

    /**
     * Saves a polling result.
     * @param $data
     * @return bool
     */
    public function savePolingResult($data):bool
    {
        $result = new PolingResults();
        foreach ($result->toArray() as $key => $oldValue){
            if('id' === $key){
                continue;
            }
            $value = $data[$key]??$oldValue;
            $result->writeAttribute($key, $value);
        }
        $result->changeTime = microtime(true);
        $resSave = $result->save();
        if(!$resSave){
            $this->logger->writeInfo(['action' => __FUNCTION__, 'error-save' => $data]);
        }
        return $resSave;
    }

    /**
     * Saves an uploaded audio file.
     * @param $path
     * @param $name
     * @return PBXApiResult
     */
    public function saveAudioFile($path, $name):PBXApiResult
    {
        $result = new PBXApiResult();

        $modulesDir     = $this->di->getShared('config')->path('core.modulesDir');
        $audioDir       = $modulesDir."/ModuleAutoDialer/db/audio";
        Util::mwMkdir($audioDir);
        $newPath        = $audioDir."/".basename($path);
        $fileDbRecord = AudioFiles::findFirst(['name = :name:', 'bind' => ['name' => $name]]);
        if($fileDbRecord){
            $newPath = $fileDbRecord->path;
        }
        copy($path,$newPath);
        unlink($path);

        $res = AutoDialerMain::convertAudioFileAction($newPath);
        $resFile = $res->data[0]??'';
        if($res->success && file_exists($resFile)){
            // Audio conversion successful.
            $result->data['filename'] = $newPath;
            $result->success = true;
            if(!$fileDbRecord){
                $fileDbRecord  = new AudioFiles();
                $fileDbRecord->path = $newPath;
                $fileDbRecord->name = $name;
                $result->success = $fileDbRecord->save();
            }
        }else{
            unlink($newPath);
        }
        return $result;
    }

    /**
     * Returns list of audio files.
     * @return PBXApiResult
     */
    public function listAudioFiles():PBXApiResult
    {
        $result = new PBXApiResult();
        $result->data = AudioFiles::find()->toArray();
        $result->success = true;

        return $result;
    }

    /**
     * Deletes an audio file.
     * @param $name
     * @return PBXApiResult
     */
    public function deleteAudioFile($name):PBXApiResult{

        $result = new PBXApiResult();
        $data = AudioFiles::findFirst(['name=:name:', 'bind' => ['name' => $name]]);
        $result->success = true;
        if($data){
            shell_exec(Util::which('rm')." -rf ".Util::trimExtensionForFile($data->path).'.*');
            $result->success = $data->delete();
        }
        return $result;
    }

    /**
     * Updates the task result state for a phone number.
     * @param $state
     * @param $outNum
     * @param $taskId
     * @param $data
     * @return bool
     */
    public function saveStateData($state, $outNum, $taskId, $data):bool
    {
        $phoneId = self::getPhoneIndex($outNum);
        if(empty($taskId)){
            $this->logger->writeError(['action' => __FUNCTION__, 'state' => 'Fail update state', 'outNum' => $outNum, 'taskId' => $taskId, 'data' => $data]);
            return false;
        }
        // При retry несколько строк с одним taskId+phoneId (разные попытки).
        // Сортировка closeTime ASC гарантирует, что открытая строка (closeTime=0) будет первой.
        $taskRow = TaskResults::findFirst([
            'taskId = :taskId: AND phoneId = :phoneId:',
            'bind' => ['taskId' => $taskId, 'phoneId' => $phoneId],
            'order' => 'closeTime ASC, id DESC'
        ]);
        if(!$taskRow ){
            $taskRow = new TaskResults();
            $taskRow->taskId        = $taskId;
            $taskRow->phoneId       = $phoneId;
        }elseif (!empty($taskRow->result) || (int)$taskRow->closeTime !== 0) {
            $this->logger->writeInfo(['Modification is prohibited. The task is closed.']);
            return true;
        }
        // Строка в состоянии CreateTask ещё не обзванивалась. Принимаем только EVENT_CREATE_CALL_FILE.
        // Остальные события — от предыдущей попытки (hangup handler и т.д.), игнорируем.
        if($taskRow->state === self::EVENT_CREATE_TASK && self::EVENT_CREATE_CALL_FILE !== $state) {
            $this->logger->writeInfo(['Ignoring event for CreateTask row (belongs to previous attempt)', 'state' => $state, 'taskId' => $taskId]);
            return true;
        }
        $taskRow->changeTime = microtime(true);
        if(isset($data['CALL_ID'])){
            $taskRow->verboseCallId = $data['CALL_ID'];
            $taskRow->linkedId      = $data['ID'];
        }
        if(self::EVENT_CREATE_CALL_FILE === $state){
            $taskRow->state = $state;
            $taskRow->callFile = $data['filename'];
        }elseif(self::EVENT_AFTER_DIAL_OUT === $state){
            $taskRow->state = $state;
            // Event after Dial to the external phone number.
            $taskRow->outDialState = $data['DIALSTATUS'];
        }elseif(self::EVENT_POLLING_END === $state){
            $taskRow->state = $state;
            $taskRow->result = self::RESULT_SUCCESS_POLLING;
        }elseif(self::EVENT_POLLING === $state){
            $taskRow->state = $state;
        }elseif(self::EVENT_ALL_USER_BUSY === $state || self::EVENT_USER_CANCEL_CALLBACK === $state){
            $taskRow->state = $state;
            $taskRow->result = self::RESULT_FAIL_USER_BUSY;
        }elseif(self::EVENT_END_CALL === $state){
            if($taskRow->state === self::EVENT_POLLING){
                $taskRow->result = self::RESULT_FAIL_POLLING;
            }elseif ($data['DIALSTATUS'] === 'ANSWER'){
                if($data['IS_CALLBACK'] === '1'){
                    $taskRow->result = self::RESULT_SUCCESS;
                }elseif($taskRow->state === self::EVENT_START_DIAL_IN){
                    $taskRow->result = self::RESULT_SUCCESS_CLIENT_H;
                }elseif($taskRow->state === self::EVENT_END_DIAL_IN){
                    $taskRow->result = self::RESULT_SUCCESS_USER_H;
                }else{
                    $taskRow->result = self::RESULT_SUCCESS;
                }
            }elseif($taskRow->state === self::EVENT_START_DIAL_IN){
                // Call ended BEFORE the employee answered.
                $taskRow->result = ($data['IS_CALLBACK'] !== '1')?
                                        self::RESULT_FAIL_CLIENT_H_BEFORE_ANSWER:
                                        self::RESULT_FAIL_USER_H_BEFORE_ANSWER;
            }
            // Event on hangup of the external number channel.
            $taskRow->state = $state;
            $taskRow->cause = $data['TECH_CAUSE'];
        }elseif(self::EVENT_FAIL_ORIGINATE === $state){
            // Outbound call to external number failed.
            if(self::EVENT_CREATE_CALL_FILE  === $taskRow->state){
                // Route not found
                $taskRow->result = self::RESULT_FAIL_ROUTE;
            }elseif (self::EVENT_AFTER_DIAL_OUT === $taskRow->state && $taskRow->cause === null){
                $taskRow->result = self::RESULT_FAIL_PROVIDER;
            }
            $taskRow->state  = $state;
            if(empty($taskRow->result)){
                $taskRow->result = self::RESULT_FAIL;
            }
        }elseif (self::EVENT_START_DIAL_IN === $state){
            // Event before Dial to the internal extension.
            $taskRow->state = $state;
        }elseif(self::EVENT_END_DIAL_IN === $state){
            $taskRow->state = $state;
            // Event after Dial to the internal extension.
            $taskRow->inDialState = $data['DIALSTATUS'];
            if('ANSWER' !==  $data['DIALSTATUS']){
                $taskRow->result = self::RESULT_FAIL_USER_NO_ANSWER;
            }
        }

        if(!empty($taskRow->result)){
            $taskRow->closeTime = microtime(true);
        }
        for ($i = 1; $i <= 5; $i++) {
            $result = $taskRow->save();
            if (!$result){
                usleep(300000);
                continue;
            }
            break;
        }

        // Check whether to ignore the call result status.
        $attemptUntilSignal = (int)($data['ATTEMPT_UTIL_SIGNAL'] ?? 0);
        if(!$result){
            $this->logger->writeError(['action' => __FUNCTION__, 'state' => 'Fail update state', 'outNum' => $outNum, 'taskId' => $taskId, 'data' => $data]);
        }elseif(( ($attemptUntilSignal === 1 && !empty($taskRow->result)) || strpos($taskRow->result, self::RESULT_FAIL) === 0)
                &&  $taskRow->attemptNumber < (int)($data['MAX_ATTEMPT'] ?? 1)){
            $newTaskRow = new TaskResults();
            $keysForCopy = ['taskId', 'phoneId', 'clientId', 'phone', 'params'];
            foreach ($keysForCopy as $key) {
                $newTaskRow->$key  = $taskRow->$key;
            }
            $newTaskRow->attemptNumber = $taskRow->attemptNumber + 1;
            $newTaskRow->timeCallAllow = time() + (int)($data['TRY_INTERVAL'] ?? 60);
            $newTaskRow->changeTime = time();
            $newTaskRow->closeTime = 0;
            $newTaskRow->state = self::EVENT_CREATE_TASK;
            if($newTaskRow->save()){
                $this->logger->writeInfo(['action' => __FUNCTION__, 'state' => 'add new TaskResults', 'data' => $newTaskRow->toArray()]);
            }else{
                $this->logger->writeError(['action' => __FUNCTION__, 'state' => 'FAIL add new TaskResults', 'data' => $newTaskRow->toArray()]);
            }
        }elseif (strpos($taskRow->result, self::RESULT_SUCCESS) === 0 && !empty($taskRow->clientId)){
            // Stop calls to other phone numbers of this client.
            $clientsRows = TaskResults::find([
                'taskId = :taskId: AND clientId = :clientId: AND closeTime = 0 AND state = :state:',
                'bind' => [
                    'taskId'   => $taskRow->taskId,
                    'clientId' => $taskRow->clientId,
                    'state'    => self::EVENT_CREATE_TASK,
                ]
            ]);
            foreach ($clientsRows as $clientRow){
                $clientRow->state     = self::RESULT_SUCCESS_ANOTHER_PHONE;
                $clientRow->result    = self::RESULT_SUCCESS_ANOTHER_PHONE;
                $clientRow->closeTime =  $taskRow->closeTime;
                $clientRow->changeTime = time();
                $clientRow->save();
            }
        }
        return $result;
    }

    /**
     * Invokes a method on the worker running in a separate process via Beanstalk IPC.
     * @param string $function Method name to call on the worker
     * @param array $args Arguments to pass to the method
     * @param bool $retVal Whether to wait for a return value
     * @param int $timeout Request timeout in seconds
     * @return array|bool|mixed
     */
    public static function invoke(string $function, array $args = [], bool $retVal = true, int $timeout = 20){
        $req = [
            'action'   => 'invoke',
            'function' => $function,
            'args'     => $args
        ];
        $client = new BeanstalkClient(self::class);
        try {
            if($retVal){
                $req['need-ret'] = true;
                $pathToData = self::saveInTmpFile($req);
                $result = $client->request($pathToData, $timeout);
            }else{
                $pathToData = self::saveInTmpFile($req);
                $client->publish($pathToData);
                return true;
            }
            $object = unserialize($result, ['allowed_classes' => [PBXApiResult::class]]);
            if(is_array($object)){
                $results = $object['data']['results']??'';
                if(file_exists($results)){
                    $object['data']['results'] = json_decode(file_get_contents($object['data']['results']), true);
                }
                $results = $object['data']['resultsPoling']??'';
                if(file_exists($results)){
                    $object['data']['resultsPoling'] = json_decode(file_get_contents($object['data']['resultsPoling']), true);
                }
            }
        } catch (\Throwable $e) {
            $object = [];
        }
        return $object;
    }

    /**
     * Returns the last 10 digits of a phone number.
     *
     * @param $number
     *
     * @return bool|string
     */
    public static function getPhoneIndex($number)
    {
        if(!is_numeric(str_replace('+', '', $number))){
            return $number;
        }
        return substr($number, -10);
    }

    /**
     * Returns a slice of active tasks ready for dialing.
     * @return array
     */
    public function getSliceTask():array
    {
        $defDialPrefix = '';
        $settings = ModuleAutoDialer::findFirst(['columns' => 'defDialPrefix']);
        if($settings){
            $defDialPrefix = trim($settings->defDialPrefix);
        }
        $manager = $this->di->get('modelsManager');
        $parameters = [
            'models'     => [
                'Tasks' => Tasks::class,
            ],
            'conditions' => 'Tasks.state = :state: AND :timeMin: BETWEEN Tasks.timeStart AND Tasks.timeEnd',
            'bind' => [
                'state'       => Tasks::STATE_OPEN,
                'resultState' => self::EVENT_CREATE_TASK,
                'time'        => time(),
                'timeMin'     => round((time() - strtotime("00:00")) / 60), // minutes since start of day
            ],
            'columns'    => [
                'taskId'            => 'Tasks.id',
                'innerNum'          => 'MAX(Tasks.innerNum)',
                'innerNumType'      => 'MAX(Tasks.innerNumType)',
                'maxAttempt'        => 'MAX(Tasks.maxAttempt)',
                'tryInterval'       => 'MAX(Tasks.tryInterval)',
                'dialPrefix'        => 'MAX(Tasks.dialPrefix)',
                'attemptUntilSignal'=> 'MAX(Tasks.attemptUntilSignal)',
                'maxCountChannels'  => 'MAX(Tasks.maxCountChannels)',
                'isCallback'        => 'MAX(Tasks.isCallback)',
                'id'                => "MIN(IIF(TaskResults.state = :resultState: AND TaskResults.timeCallAllow <= :time:, TaskResults.id, NULL))",
                'in_progress'       => 'SUM(IIF(TaskResults.state <> :resultState:, 1, 0))',
                'not_completed'     => 'SUM(IIF(TaskResults.closeTime IS NULL, 0, 1))',
            ],
            'order'      => 'Tasks.id,TaskResults.timeCallAllow ASC',
            'group'      => 'Tasks.id',
            'joins'      => [
                'TaskResults' => [
                    0 => TaskResults::class,
                    1 => 'Tasks.id = TaskResults.taskId AND TaskResults.closeTime = 0',
                    2 => 'TaskResults',
                    3 => 'LEFT',
                ],
            ],
        ];
        $result = $manager->createBuilder($parameters)->getQuery()->execute()->toArray();
        unset($manager,$parameters);
        if(empty($result)){
            return $result;
        }
        $filter = [
            'conditions' => 'id IN ({ids:array})',
            'columns' => 'id,phone,params,clientId',
            'bind' => [
                'ids' => array_column($result, 'id')
            ]
        ];
        /** @var TaskResults $row */
        $resultsRow = TaskResults::find($filter);
        $phones = [];
        foreach ($resultsRow as $row){
            $phones[$row['id']] = $row->toArray();
        }
        unset($resultsRow,$filter);

        // Клиенты с активными вызовами (по задачам). Ключ — taskId, значение — массив clientId.
        $busyClients = [];
        $taskIds = array_column($result, 'taskId');
        if(!empty($taskIds)){
            $busyRows = TaskResults::find([
                'taskId IN ({taskIds:array}) AND clientId <> :empty: AND state <> :state: AND closeTime = 0',
                'columns' => 'taskId, clientId',
                'bind' => [
                    'taskIds' => $taskIds,
                    'state'   => self::EVENT_CREATE_TASK,
                    'empty'   => '',
                ]
            ]);
            foreach ($busyRows as $row) {
                $busyClients[$row->taskId][$row->clientId] = true;
            }
            unset($busyRows);
        }

        foreach ($result as $index => $taskData){
            if($taskData['not_completed'] == '0'){
                unset($result[$index]);
                $task = Tasks::findFirst(['id = :id:', 'bind' => ['id' => $taskData['taskId']]]);
                $task->state = Tasks::STATE_CLOSE;
                $task->save();
                continue;
            }
            // Защита от одновременного обзвона нескольких номеров одного клиента.
            $currentId = $taskData['id'] ?? 0;
            $selectedClientId = $phones[$currentId]['clientId'] ?? '';
            if (!empty($selectedClientId) && isset($busyClients[$taskData['taskId']][$selectedClientId])) {
                $altPhone = $this->findAvailablePhone($taskData['taskId'], $busyClients[$taskData['taskId']]);
                if ($altPhone) {
                    $currentId = $altPhone->id;
                    $result[$index]['id'] = $currentId;
                    $phones[$currentId] = $altPhone->toArray();
                } else {
                    // Все доступные номера принадлежат занятым клиентам, пропускаем задачу.
                    if(isset($result[$index])){
                        $result[$index]['phone'] = '';
                        $result[$index]['dialPrefix'] = empty($taskData['dialPrefix'])?$defDialPrefix:$taskData['dialPrefix'];
                    }
                    continue;
                }
            }
            $phone = $phones[$currentId]['phone']??'';
            if(!empty($phone)){
                $result[$index]['phone'] = $phone;
            }
            $params = $phones[$currentId]['params']??'';
            if(!empty($params)){
                $result[$index]['params'] = $params;
            }
            if(isset($result[$index])){
                $result[$index]['dialPrefix'] = empty($taskData['dialPrefix'])?$defDialPrefix:$taskData['dialPrefix'];
            }
        }
        return $result;
    }

    /**
     * Находит доступный для обзвона номер, пропуская клиентов с активными вызовами.
     * @param string $taskId
     * @param array $busyClientIds Массив занятых clientId (ключи — clientId)
     * @return TaskResults|null
     */
    private function findAvailablePhone(string $taskId, array $busyClientIds): ?TaskResults
    {
        $conditions = 'taskId = :taskId: AND state = :state: AND closeTime = 0 AND timeCallAllow <= :time:';
        $bind = [
            'taskId' => $taskId,
            'state'  => self::EVENT_CREATE_TASK,
            'time'   => time(),
        ];
        $clientKeys = array_keys($busyClientIds);
        foreach ($clientKeys as $i => $clientId) {
            $conditions .= " AND clientId <> :bc{$i}:";
            $bind["bc{$i}"] = $clientId;
        }
        $row = TaskResults::findFirst([
            $conditions,
            'bind' => $bind,
            'order' => 'id ASC',
        ]);
        return $row ?: null;
    }

    // **************************************************************
    // REST CALLBACKS

    /**
     * Deletes a task and its results.
     * @param $id
     * @return array
     */
    public function deleteTask($id):array
    {
        $res = new PBXApiResult();
        $res->success = true;
        $this->moduleDb->begin();
        /** @var Tasks $task */
        $task = Tasks::findFirst(['id = :id:', 'bind' => ['id' => $id]]);
        if($task){
            $res->success = $task->delete();
        }
        if($res->success) {
            $results = TaskResults::find(['taskId = :taskId:', 'bind' => ['taskId' => $id]]);
            if($results->count() !== '0'){
                $res->success = $results->delete();
            }
        }
        if($res->success){
            $this->moduleDb->commit();
        }else{
            $this->moduleDb->rollback();
        }
        return $res->getResult();

    }

    /**
     * Adds or updates client(s).
     * @param $data
     * @return array
     */
    public function addClient($data):array
    {
        $res = new PBXApiResult();
        $this->moduleDb->begin();
        foreach ($data as $clientData){
            $existingKeys = array_column($clientData['properties'], 'value', 'key');
            if (!isset($existingKeys['NAME'])) {
                $clientData['properties'][] = [
                    "key" => 'NAME',
                    "value" => $clientData['name']??''
                ];
            }
            $client = Clients::findFirst(['crmId = :crmId:', 'bind' => ['crmId' => $clientData['crmId']]]);
            if(!$client){
                $client = new Clients();
                $client->crmId = $clientData['crmId'];
            }
            $client->name = $clientData['name']??$client->name;
            $res->success = $client->save();
            $resultData = $client->toArray();
            if(!$res->success){
                break;
            }
            ClientsPhones::find(['clientId = :clientId:', 'bind' => ['clientId' => $client->id]])->delete();
            ClientsProperties::find(['clientId = :clientId:', 'bind' => ['clientId' => $client->id]])->delete();
            foreach ($clientData['phones'] as $phone){
                $phoneData = new ClientsPhones();
                $phoneData->clientId = $client->id;
                $phoneData->phone = $phone;
                $phoneData->phoneId = self::getPhoneIndex($phone);
                $phoneData->save();

                $resultData['phones'][] = $phoneData->toArray();
            }

            foreach ($clientData['properties'] as $property){
                $propertyData = new ClientsProperties();
                $propertyData->clientId = $client->id;
                $propertyData->key      = $property['key'];
                $propertyData->value    = $property['value'];
                $propertyData->save();

                $resultData['properties'][] = $propertyData->toArray();
            }
            $res->data[] = $resultData;
        }
        if($res->success){
            $this->moduleDb->commit();
        }else{
            $this->moduleDb->rollback();
        }
        return $res->getResult();
    }

    public function deleteClient($id):array
    {
        $res = new PBXApiResult();
        $this->moduleDb->begin();

        $client = Clients::findFirst(['crmId = :crmId:', 'bind' => ['crmId' => $id]]);
        if($client){
            ClientsPhones::find(['clientId = :clientId:', 'bind' => ['clientId' => $client->id]])->delete();
            ClientsProperties::find(['clientId = :clientId:', 'bind' => ['clientId' => $client->id]])->delete();
            $res->success = $client->delete();
        }else{
            $res->success = true;
        }

        if($res->success){
            $this->moduleDb->commit();
        }else{
            $this->moduleDb->rollback();
        }
        return $res->getResult();
    }

    /**
     * Finds client data by phone number.
     * @param $phone
     * @return array
     */
    public function findClientByPhone($phone):array
    {
        $res = new PBXApiResult();
        $manager = $this->di->get('modelsManager');
        $parameters = [
            'models'     => [
                'ClientsPhones' => ClientsPhones::class,
            ],
            'conditions' => 'ClientsPhones.phoneId = :phoneId:',
            'bind' => [
                'phoneId'     => self::getPhoneIndex($phone)
            ],
            'columns'    => [
                'key'            => 'ClientsProperties.key',
                'value'          => 'ClientsProperties.value'
            ],
            'joins'      => [
                'ClientsProperties' => [
                    0 => ClientsProperties::class,
                    1 => 'ClientsPhones.clientId = ClientsProperties.clientId',
                    2 => 'ClientsProperties',
                    3 => 'LEFT',
                ],
            ],
        ];
        $res->data = $manager->createBuilder($parameters)->getQuery()->execute()->toArray();
        $res->success = true;
        return $res->getResult();
    }

    /**
     * Adds or updates a polling survey.
     * @param $data
     * @return array
     */
    public function addPolling($data):array
    {
        $res = new PBXApiResult();
        $this->moduleDb->begin();

        $crmId = $data['crmId']??'';
        if(empty($crmId)){
            $maxPollingData = Polling::findFirst(['columns' => 'MAX(id) as id', 'order' => 'id DESC']);
            $crmId = ($maxPollingData)?($maxPollingData->id + 1):1;
        }
        $poll = Polling::findFirst(['crmId = :crmId:', 'bind' => ['crmId' => $crmId]]);
        if(!$poll){
            $poll = new Polling();
            $poll->crmId = $crmId;
        }
        foreach ($poll->toArray() as $key => $oldValue){
            $value = $data[$key]??$oldValue;
            $poll->writeAttribute($key, $value);
        }
        $res->success = $poll->save();
        if($res->success){
            $res = $this->addQuestion($poll, $data['questions']);
        }
        if($res->success){
            $res->data = $poll->toArray();
            $this->moduleDb->commit();
            PBX::dialplanReload();
        }else{
            $this->moduleDb->rollback();
        }
        return $res->getResult();
    }

    public function deletePolling($id)
    {
        $res = new PBXApiResult();
        $this->moduleDb->begin();

        $poll = Polling::findFirst(['id = :id:', 'bind' => ['id' => $id]]);
        $pResult  = $poll ? $poll->delete() : true;
        $qResult  = Question::find(['pollingId = :pollingId:', 'bind' => ['pollingId' => $id]])->delete();
        $qaResult = QuestionActions::find(['pollingId = :pollingId:', 'bind' => ['pollingId' => $id]])->delete();

        if($pResult && $qResult && $qaResult){
            $this->moduleDb->commit();
            $res->success = true;
        }else{
            $this->moduleDb->rollback();
        }

        return $res->getResult();
    }

    /**
     * Adds questions to a polling survey.
     * @param              $poll
     * @param              $questions
     * @return PBXApiResult
     */
    private function addQuestion($poll, $questions): PBXApiResult
    {
        $res = new PBXApiResult();
        $res->success = true;
        Question::find(['pollingId = :pollingId:', 'bind' => ['pollingId' => $poll->id]])->delete();
        QuestionActions::find(['pollingId = :pollingId:', 'bind' => ['pollingId' => $poll->id]])->delete();

        foreach ($questions as $index => $questionData) {
            if (!$res->success) {
                break;
            }
            $question = new Question();
            $question->pollingId    = $poll->id;
            $question->crmId        = empty($questionData['questionId'])?$index:$questionData['questionId'];
            $question->questionText = $questionData['questionText']??'';
            $question->questionFile = $questionData['questionFile']??'';
            $question->timeout      = ($questionData['timeout']??'')===''?5:$questionData['timeout'];
            $question->defPress     = $questionData['defPress'];
            $res->success           = $question->save();
            if (!$res->success) {
                break;
            }
            foreach ($questionData['press'] as $pressData) {
                $press = new QuestionActions();
                $press->pollingId  = $poll->id;
                $press->questionId = $question->id;
                foreach ($press->toArray() as $key => $defValue) {
                    $value = $pressData[$key] ?? $defValue;
                    $press->writeAttribute($key, $value);
                }
                $res->success = $press->save();
                if (!$res->success) {
                    break;
                }
            }
        }
        return $res;
    }

    /**
     * Changes task data or creates a new task.
     * @param $taskId
     * @param $data
     * @param $createNew
     * @return PBXApiResult
     */
    public function changeTask($taskId, $data, $createNew = false):PBXApiResult
    {
        $res = new PBXApiResult();

        if(empty($taskId) && empty($data['crmId']??'')){
            $createNew = true;
            $task = null;
        }else{
            if(!empty($data['crmId'])){
                $filter = ['crmId = :val:', 'bind' => ['val' => $data['crmId']]];
            }else{
                $filter = ['id = :val:', 'bind' => ['val' => $taskId]];
            }
            /** @var Tasks $task */
            $task = Tasks::findFirst($filter);
        }
        if(!$task){
            if(!$createNew){
                $res->success = false;
                $res->data = ['error' => 'TaskNotFound'];
                return $res;
            }
            $task = new Tasks();
            if(isset($data['id'])){
                $task->id    = $data['id'];
                $task->crmId = $data['crmId'];
            }
        }
        foreach ($task->toArray() as $key => $oldValue){
            $value = $data[$key]??$oldValue;
            $task->writeAttribute($key, $value);
        }
        if(empty($task->state)){
            $task->state         = Tasks::STATE_OPEN;
        }
        $res->success = $task->save();
        if(empty($task->crmId)){
            $task->crmId = $task->id;
            $res->success = $task->save();
        }
        if($res->success){
            $res->data = $task->toArray();
        }
        return $res;
    }

    /**
     * Returns task data by ID.
     * @param $id
     * @return array
     */
    public function getTask($id):array
    {
        $res = new PBXApiResult();
        $res->success = true;
        $task = Tasks::findFirst(['id = :id:', 'bind' => ['id' => $id]]);
        if($task){
            $res->data = $task->toArray();
            unset($task);
            $res->data['results'] = $this->saveResultInTmpFile(TaskResults::find(['taskId = :taskId:', 'bind' => ['taskId' => $id]])->toArray());
            $res->data['resultsPoling'] = $this->saveResultInTmpFile(PolingResults::find(['taskId = :taskId:', 'bind' => ['taskId' => $id]])->toArray());
        }else{
            $res->success = false;
        }
        return $res->getResult();
    }

    public function getTasks($state, $limit, $offset):array
    {
        $res = new PBXApiResult();
        $res->success = true;

        $filter = [
            'conditions' => '',
            'bind' => [],
            'order' => 'id'
        ];
        if(trim($state??'') !== ''){
            $filter['conditions'] = 'state = :state:';
            $filter['bind']['state'] = $state;
        }
        if(!empty($offset)){
            $filter['conditions'] .= ((!empty($filter['conditions']))?' AND ':'') . 'id > :offset:';
            $filter['bind']['offset'] = $offset;
        }
        if(!empty($limit)){
            $filter['limit'] = $limit;
        }
        $task = Tasks::find($filter);
        $res->data['results'] = $this->saveResultInTmpFile($task->toArray());
        return $res->getResult();
    }

    /**
     * Creates a new dialer task with phone numbers.
     * @param $data
     * @return array
     */
    public function addTask($data):array
    {
        $this->moduleDb->begin();
        $res = $this->changeTask($data['id']??'', $data, true);
        if($res->success){
            $data['id'] = (int)$res->data['id'];
            $res->success = min($res->success, $this->addTaskResults($data, $res->data));
        }else{
            $res->messages[] = 'fail save task';
        }
        if($res->success){
            $this->moduleDb->commit();
        }else{
            $this->moduleDb->rollback();
        }
        return $res->getResult();
    }

    /**
     * Closes task results for a phone number by external signal.
     * @param $data
     * @return array
     */
    public function taskSignalClose($data):array
    {
        $this->moduleDb->begin();
        $res = new PBXApiResult();
        $data['phoneId'] = self::getPhoneIndex($data['phone']??'');
        if(empty($data['phoneId'])){
            $res->messages[] = 'error phone';
            return $res->getResult();
        }
        $taskId  = $data['taskId']??'';
        $conditions = 'closeTime = 0 AND phoneId = :phoneId:';
        $bind = ['phoneId' => $data['phoneId']];
        if(!empty($taskId)){
            $conditions .= ' AND taskId = :taskId:';
            $bind['taskId'] = $taskId;
        }
        $data['filter'] = ['conditions' => $conditions, 'bind' => $bind];
        $res->data = $data;
        $res->success = true;
        /** @var TaskResults $resultTask */
        $results = TaskResults::find($data['filter']);
        foreach($results as $resultTask){
            $resultTask->result = self::RESULT_SUCCESS_EXTERNAL_SIGNAL;
            $resultTask->changeTime = time();
            $resultTask->closeTime  = time();
            if(!$resultTask->save()){
                $res->success = false;
                $res->messages[] = $resultTask->getMessages();
                break;
            }
            $clientTaskResults = TaskResults::find([
                'closeTime = 0 AND clientId = :clientId: AND taskId = :taskId:',
                'bind' => ['clientId' => $resultTask->clientId, 'taskId' => $resultTask->taskId]
            ]);
            foreach($clientTaskResults as $clientTaskResult){
                $clientTaskResult->result = self::RESULT_SUCCESS_EXTERNAL_SIGNAL;
                $clientTaskResult->changeTime = time();
                $clientTaskResult->closeTime  = time();
                if(!$clientTaskResult->save()){
                    $res->success = false;
                    $res->messages[] = $clientTaskResult->getMessages();
                    break;
                }
            }
        }
        if($res->success){
            $this->moduleDb->commit();
        }else{
            $res->messages[] = 'fail save result';
            $this->moduleDb->rollback();
        }
        return $res->getResult();
    }

    /**
     * Returns dialing results changed since the given timestamp.
     * @param $changeTime
     * @return array
     */
    public function getResults($changeTime):array
    {
        $res = new PBXApiResult();
        $res->success = true;
        $filter = [
            'conditions' => 'changeTime >= :changeTime:',
            'limit' => 10000,
            'bind' => [
                'changeTime' => $changeTime,
            ],
            'order' => 'changeTime'
        ];
        $res->data['results'] = $this->saveResultInTmpFile(TaskResults::find($filter)->toArray());
        return $res->getResult();
    }

    /**
     * Serializes data and saves it to a temporary file.
     * @param array $data
     * @return string
     */
    private function saveResultInTmpFile(array $data):string
    {
        return self::saveInTmpFile($data);
    }

    /**
     * Serializes data and saves it to a temporary file.
     * @param array $data
     * @return string
     */
    public static function saveInTmpFile(array $data):string
    {
        try {
            $res_data = json_encode($data, JSON_THROW_ON_ERROR);
        }catch (\JsonException $e){
            return '';
        }
        $downloadCacheDir = '/tmp/';
        $tmpDir           = '/tmp/';
        $di = MikoPBXVersion::getDefaultDi();
        if ($di) {
            $dirsConfig = $di->getShared('config');
            $tmoDirName = $dirsConfig->path('core.tempDir') . '/ModuleAutoDialer';
            Util::mwMkdir($tmoDirName);
            chown($tmoDirName, 'www');
            if (file_exists($tmoDirName)) {
                $tmpDir = $tmoDirName;
            }

            $downloadCacheDir = $dirsConfig->path('www.downloadCacheDir');
            if (!file_exists($downloadCacheDir)) {
                $downloadCacheDir = '';
            }
        }
        $fileBaseName = md5(microtime(true));
        // "temp-" in the filename is necessary for the file to be automatically deleted after 5 minutes.
        $filename = $tmpDir . '/temp-' . $fileBaseName;
        file_put_contents($filename, $res_data);
        if (!empty($downloadCacheDir)) {
            $linkName = $downloadCacheDir . '/' . $fileBaseName;
            // For automatic file deletion.
            // A file with such a symlink will be deleted after 5 minutes by cron.
            Util::createUpdateSymlink($filename, $linkName, true);
        }
        chown($filename, 'www');
        return $filename;
    }

    /**
     * Returns polling results changed since the given timestamp.
     * @param $changeTime
     * @return array
     */
    public function getResultsPolling($changeTime):array
    {
        $res = new PBXApiResult();
        $res->success = true;
        $filter = [
            'conditions' => 'changeTime >= :changeTime:',
            'limit' => 10000,
            'bind' => [
                'changeTime' => $changeTime,
            ],
            'order' => 'changeTime'
        ];
        $res->data['results'] = $this->saveResultInTmpFile(PolingResults::find($filter)->toArray());
        return $res->getResult();
    }

    /**
     * Returns list of polling surveys.
     * @param array $filter
     * @return array
     */
    public function getPolling(array $filter = []):array
    {
        $res = new PBXApiResult();
        $res->success = true;

        $task = Polling::find($filter);
        $res->data['results'] = $this->saveResultInTmpFile($task->toArray());
        return $res->getResult();
    }

    /**
     * Returns polling survey details by ID.
     * @param  $id
     * @return array
     */
    public function getPollingById($id):array
    {
        $res = new PBXApiResult();
        $res->success = true;

        $pollingRecord = Polling::findFirst(['id = :id:', 'bind' => ['id' => $id]]);
        if (!$pollingRecord) {
            $res->success = false;
            return $res->getResult();
        }
        $polling = $pollingRecord->toArray();
        $filter = [
            'pollingId = :id:',
            'columns' => ['id', 'questionText', 'questionFile', 'lang'],
            'bind' => [
                'id'=> $id
            ]
        ];
        $polling['questions'] = Question::find($filter)->toArray();

        foreach ($polling['questions'] as &$question){
            $filter = [
                'questionId=:questionId: AND pollingId = :id:',
                'columns' => ['key', 'action', 'value', 'valueOptions', 'nextQuestion'],
                'bind' => [
                    'id'=> $id,
                    'questionId' => $question['id']]
            ];
            $question['press'] = QuestionActions::find($filter);
        }
        unset($question);

        $res->data['results'] = $this->saveResultInTmpFile($polling);
        return $res->getResult();
    }

    /**
     * Populates the task results table with phone numbers for dialing.
     * @param array $data Task data including 'id' and 'numbers'
     * @param mixed $errorMsg Error messages output (passed by reference)
     * @return bool
     */
    private function addTaskResults($data, &$errorMsg):bool
    {
        $result = true;
        $indexPhones = [];
        // Hashmap for fast phoneId lookup: phoneId => key in $indexPhones
        $phoneIdIndex = [];
        foreach ($data['numbers'] as $numData){
            if(is_array($numData)){
                $number = $numData['number']??'';
                if(empty($number)){
                    continue;
                }
                $phoneId = self::getPhoneIndex($number);
                // Skip duplicate phoneId — keep the first occurrence
                if (isset($phoneIdIndex[$phoneId])) {
                    continue;
                }
                $key = count($indexPhones);
                $indexPhones[$key] = [
                    'phone'         => $number,
                    'phoneId'       => $phoneId,
                    'timeCallAllow' => (string)($numData['timeCallAllow']??''),
                    'clientId'      => (string)($numData['clientId']??''),
                    'params'        => serialize($numData['params']??'')
                ];
                $phoneIdIndex[$phoneId] = $key;
            }else{
                $phoneId = self::getPhoneIndex($numData);
                // Skip duplicate phoneId — keep the first occurrence
                if (isset($phoneIdIndex[$phoneId])) {
                    continue;
                }
                $key = count($indexPhones);
                $indexPhones[$key] = [
                    'phone'         => $numData,
                    'phoneId'       => $phoneId,
                    'timeCallAllow' => '',
                    'clientId'      => '',
                    'params'        => ''
                ];
                $phoneIdIndex[$phoneId] = $key;
            }
        }

        /** @var TaskResults $oldResult */
        $oldResultsTask = TaskResults::find([
            'conditions' => 'taskId = :taskId:',
            'bind' => ['taskId' => $data['id']]
        ]);
        foreach ($oldResultsTask as $oldResult){
            $indexRow = $phoneIdIndex[$oldResult->phoneId] ?? false;
            if($indexRow === false){
                // Phone number no longer in the list
                $oldResult->delete();
            }else{
                if(intval($oldResult->closeTime) < 1 ){
                    $oldResult->params        = $indexPhones[$indexRow]['params'];
                    $oldResult->clientId      = $indexPhones[$indexRow]['clientId'];
                    $oldResult->timeCallAllow = $this->getTimestampFromDate($indexPhones[$indexRow]['timeCallAllow']);
                    $oldResult->changeTime    = microtime(true);
                    $oldResult->save();
                }
                // Remove already existing numbers from the index
                unset($indexPhones[$indexRow]);
                unset($phoneIdIndex[$oldResult->phoneId]);
            }
        }

        // Batch insert new phone numbers via raw SQL
        if (!empty($indexPhones)) {
            $taskId     = (int)$data['id'];
            $state      = self::EVENT_CREATE_TASK;
            $changeTime = microtime(true);
            $batchSize  = self::BATCH_INSERT_SIZE;
            $columns    = 'taskId, phoneId, phone, clientId, params, state, changeTime, closeTime, timeCallAllow';
            $batch      = [];
            $binds      = [];
            $count      = 0;

            foreach ($indexPhones as $numData) {
                $batch[]  = '(?,?,?,?,?,?,?,?,?)';
                $binds[]  = $taskId;
                $binds[]  = $numData['phoneId'];
                $binds[]  = $numData['phone'];
                $binds[]  = $numData['clientId'];
                $binds[]  = $numData['params'];
                $binds[]  = $state;
                $binds[]  = $changeTime;
                $binds[]  = 0;
                $binds[]  = $this->getTimestampFromDate($numData['timeCallAllow']);
                $count++;

                if ($count >= $batchSize) {
                    $sql = "INSERT INTO m_TaskResults ($columns) VALUES " . implode(',', $batch);
                    try {
                        $this->moduleDb->execute($sql, $binds);
                    } catch (Exception $e) {
                        $errorMsg = [$e->getMessage()];
                        $result = false;
                        break;
                    }
                    $batch = [];
                    $binds = [];
                    $count = 0;
                }
            }
            // Flush remaining batch
            if ($result && !empty($batch)) {
                $sql = "INSERT INTO m_TaskResults ($columns) VALUES " . implode(',', $batch);
                try {
                    $this->moduleDb->execute($sql, $binds);
                } catch (Exception $e) {
                    $errorMsg = [$e->getMessage()];
                    $result = false;
                }
            }
        }

        return $result;
    }

    private function getTimestampFromDate(string $dateString):int
    {
        $db_tz = PbxSettings::getValueByKey('PBXTimezone');
        $timezone = new \DateTimeZone($db_tz);
        $date = \DateTime::createFromFormat('d.m.Y H:i:s', $dateString, $timezone);
        if ($date) {
            $timestamp = $date->format('U');
        } else {
            $timestamp = 0;
        }
        return $timestamp;
    }


}

if(isset($argv) && count($argv) !== 1
    && Util::getFilePathByClassName(ConnectorDB::class) === $argv[0]){
    ConnectorDB::startWorker($argv??[]);
}
