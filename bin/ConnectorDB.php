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

    public const RESULT_SUCCESS                     = 'SUCCESS';
    public const RESULT_SUCCESS_CLIENT_H            = 'SUCCESS_CLIENT_H';
    public const RESULT_SUCCESS_USER_H              = 'SUCCESS_USER_H';
    public const RESULT_SUCCESS_POLLING             = 'SUCCESS_POLLING';
    public const RESULT_FAIL                        = 'FAIL';
    public const RESULT_FAIL_CLIENT_H_BEFORE_ANSWER = 'FAIL_CLIENT_H_BEFORE_ANSWER';
    public const RESULT_FAIL_USER_NO_ANSWER         = 'FAIL_USER_NO_ANSWER';
    public const RESULT_FAIL_USER_BUSY              = 'FAIL_USER_BUSY';
    public const RESULT_FAIL_ROUTE                  = 'FAIL_ROUTE';
    public const RESULT_FAIL_PROVIDER               = 'FAIL_PROVIDER';
    public const RESULT_FAIL_POLLING                = 'FAIL_POLLING';

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
     * Старт работы листнера.
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
        $this->logger->writeInfo('Get event... PING '.getmypid());

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
        }catch (Exception $e){
            return;
        }
        if($data['action'] === 'invoke'){
            $res_data = [];
            $funcName = $data['function']??'';
            $this->logger->writeInfo('Get event...'.$funcName);
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
     * Сохранение результата опроса
     * @param $data
     * @return bool
     */
    public function savePolingResult($data):bool
    {
        $this->logger->writeInfo(['action' => __FUNCTION__, 'data' => $data]);
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
     * Сохранение аудиофайла
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
        $fileDbRecord = AudioFiles::findFirst("name='$name'");
        if($fileDbRecord){
            $newPath = $fileDbRecord->path;
        }
        copy($path,$newPath);
        unlink($path);

        $res = AutoDialerMain::convertAudioFileAction($newPath);
        $resFile = $res->data[0]??'';
        if($res->success && file_exists($resFile)){
            // конвертация прошла успешно.
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
     * Возвращает список аудио файлов.
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
     * Удаление медиафайла
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
     * Изменение статуса задачи по номеру.
     * @param $state
     * @param $outNum
     * @param $taskId
     * @param $data
     * @return bool
     */
    public function saveStateData($state, $outNum, $taskId, $data):bool
    {
        $this->logger->writeInfo(['action' => __FUNCTION__, 'state' => $state, 'outNum' => $outNum, 'taskId' => $taskId, 'data' => $data]);
        $phoneId = self::getPhoneIndex($outNum);
        if(empty($taskId)){
            $this->logger->writeError(['action' => __FUNCTION__, 'state' => 'Fail update state', 'outNum' => $outNum, 'taskId' => $taskId, 'data' => $data]);
            return false;
        }
        $taskRow = TaskResults::findFirst("taskId='$taskId' AND phoneId='$phoneId'");
        if(!$taskRow ){
            $taskRow = new TaskResults();
            $taskRow->taskId        = $taskId;
            $taskRow->phoneId       = $phoneId;
        }elseif (!empty($taskRow->result)) {
            // Модификация запрещена. Задание закрыто.
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
            // Событие возникает после обработки Dial на внешний номер телефона.
            $taskRow->outDialState = $data['DIALSTATUS'];
        }elseif(self::EVENT_POLLING_END === $state){
            $taskRow->state = $state;
            $taskRow->result = self::RESULT_SUCCESS_POLLING;
        }elseif(self::EVENT_POLLING === $state){
            $taskRow->state = $state;
        }elseif(self::EVENT_ALL_USER_BUSY === $state){
            $taskRow->state = $state;
            $taskRow->result = self::RESULT_FAIL_USER_BUSY;
        }elseif(self::EVENT_END_CALL === $state){
            if($taskRow->state === self::EVENT_POLLING){
                $taskRow->result = self::RESULT_FAIL_POLLING;
            }elseif ($data['DIALSTATUS'] === 'ANSWER'){
                // Вызов был отвечен
                if($taskRow->state === self::EVENT_START_DIAL_IN){
                    $taskRow->result = self::RESULT_SUCCESS_CLIENT_H;
                }elseif($taskRow->state === self::EVENT_END_DIAL_IN){
                    $taskRow->result = self::RESULT_SUCCESS_USER_H;
                }else{
                    $taskRow->result = self::RESULT_SUCCESS;
                }

            }elseif($taskRow->state === self::EVENT_START_DIAL_IN){
                // Вызов завершен ДО ответа со стороны сотрудника.
                $taskRow->result = self::RESULT_FAIL_CLIENT_H_BEFORE_ANSWER;
            }
            // Событие возникает при hangup канала, связанного с внешним номером.
            $taskRow->state = $state;
            $taskRow->cause = $data['TECH_CAUSE'];
        }elseif(self::EVENT_FAIL_ORIGINATE === $state){
            // Вызов на внешний номер завершился неудачно.
            if(self::EVENT_CREATE_CALL_FILE  === $taskRow->state){
                // Маршрут не найден
                $taskRow->result = self::RESULT_FAIL_ROUTE;
            }elseif (self::EVENT_AFTER_DIAL_OUT === $taskRow->state && $taskRow->cause === null){
                $taskRow->result = self::RESULT_FAIL_PROVIDER;
            }
            $taskRow->state  = $state;
            if(empty($taskRow->result)){
                $taskRow->result = self::RESULT_FAIL;
            }
        }elseif (self::EVENT_START_DIAL_IN === $state){
            // Событие возникает перед Dial на внутренний номер.
            $taskRow->state = $state;
        }elseif(self::EVENT_END_DIAL_IN === $state){
            $taskRow->state = $state;
            // Событие возникает после обработки Dial на внутренний номер.
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
        if(!$result){
            $this->logger->writeError(['action' => __FUNCTION__, 'state' => 'Fail update state', 'outNum' => $outNum, 'taskId' => $taskId, 'data' => $data]);
        }
        return $result;
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
     * Возвращает усеценный слева номер телефона.
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
     * Возвращает срез по задачам.
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
            'conditions' => 'Tasks.state = :state:',
            'bind' => [
                'state'       => Tasks::STATE_OPEN,
                'resultState' => self::EVENT_CREATE_TASK,
                'time'        => time(),
            ],
            'columns'    => [
                'taskId'            => 'Tasks.id',
                'innerNum'          => 'MAX(Tasks.innerNum)',
                'innerNumType'      => 'MAX(Tasks.innerNumType)',
                'dialPrefix'        => 'MAX(Tasks.dialPrefix)',
                'maxCountChannels'  => 'MAX(Tasks.maxCountChannels)',
                'id'                => "MIN(IIF(TaskResults.state = :resultState: AND TaskResults.timeCallAllow <= :time:, TaskResults.id, NULL))",
                'in_progress'       => 'SUM(IIF(TaskResults.state <> :resultState:, 1, 0))',
                'not_completed'     => 'SUM(IIF(TaskResults.closeTime IS NULL, 0, 1))',
            ],
            'order'      => 'Tasks.id',
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
            'columns' => 'id,phone,params',
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

        foreach ($result as $index => $taskData){
            if($taskData['not_completed'] === '0'){
                unset($result[$index]);
                $task = Tasks::findFirst("id='{$taskData['taskId']}'");
                $task->state = Tasks::STATE_CLOSE;
                $task->save();
            }
            $phone = $phones[$taskData['id']]['phone']??'';
            if(!empty($phone)){
                $result[$index]['phone'] = $phone;
            }
            $params = $phones[$taskData['id']]['params']??'';
            if(!empty($params)){
                $result[$index]['params'] = $params;
            }
            $result[$index]['dialPrefix'] = empty($taskData['dialPrefix'])?$defDialPrefix:$taskData['dialPrefix'];
        }
        return $result;
    }

    // **************************************************************
    // REST CALLBACKS

    /**
     * Удаление задачи.
     * @param $id
     * @return array
     */
    public function deleteTask($id):array
    {
        $res = new PBXApiResult();
        $res->success = true;
        $this->db->begin();
        /** @var Tasks $task */
        $task = Tasks::findFirst("id='$id'");
        if($task){
            $res->success = $task->delete();
        }
        if($res->success) {
            $results = TaskResults::find("taskId='$id'");
            if($results->count() !== '0'){
                $res->success = $results->delete();
            }
        }
        if($res->success){
            $this->db->commit();
        }else{
            $this->db->rollback();
        }
        return $res->getResult();

    }

    /**
     * Добавление опроса
     * @param $data
     * @return array
     */
    public function addPolling($data):array
    {
        $res = new PBXApiResult();
        $this->db->begin();

        $crmId = $data['crmId']??'';
        if(empty($crmId)){
            $maxPollingData = Polling::findFirst(['columns' => 'MAX(id) as id', 'order' => 'id DESC']);
            $crmId = ($maxPollingData)?($maxPollingData->id + 1):1;
        }
        $poll = Polling::findFirst("crmId='$crmId'");
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
            $this->db->commit();
            PBX::dialplanReload();
        }else{
            $this->db->rollback();
        }
        return $res->getResult();
    }

    public function deletePolling($id)
    {
        $res = new PBXApiResult();
        $this->db->begin();

        $pResult  = Polling::findFirst("id='$id'")->delete();
        $qResult  = Question::find("pollingId='$id'")->delete();
        $qaResult = QuestionActions::find("pollingId='$id'")->delete();

        if($pResult && $qResult && $qaResult){
            $this->db->commit();
            $res->success = true;
        }else{
            $this->db->rollback();
        }

        return $res->getResult();
    }

    /**
     * Добавление вопросов к опросу.
     * @param              $poll
     * @param              $questions
     * @return void
     */
    private function addQuestion($poll, $questions): PBXApiResult
    {
        $res = new PBXApiResult();
        $res->success = true;
        Question::find("pollingId='$poll->id'")->delete();
        QuestionActions::find("pollingId='$poll->id'")->delete();

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
     * Изменение данных задачи.
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
            $filter = "crmId='{$data['crmId']}'";
            if(empty($data['crmId'])){
                $filter = "id='{$taskId}'";
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
     * Получение данных по задаче.
     * @param $id
     * @return array
     */
    public function getTask($id):array
    {
        $res = new PBXApiResult();
        $res->success = true;
        $task = Tasks::findFirst("id='$id'");
        if($task){
            $res->data = $task->toArray();
            unset($task);
            $res->data['results'] = $this->saveResultInTmpFile(TaskResults::find("taskId='$id'")->toArray());
            $res->data['resultsPoling'] = $this->saveResultInTmpFile(PolingResults::find("taskId='$id'")->toArray());
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
        if(trim($state) !== ''){
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
     * Добавление новой задачи для Dialer
     * @param $data
     * @return array
     */
    public function addTask($data):array
    {
        $this->db->begin();
        $res = $this->changeTask($data['id'], $data, true);
        if($res->success){
            $data['id'] = (int)$res->data['id'];
            $res->success = min($res->success, $this->addTaskResults($data, $res->data));
        }
        if($res->success){
            $this->db->commit();
        }else{
            $this->db->rollback();
        }
        return $res->getResult();
    }

    /**
     * Возвращает результат обзвона, только изменненые данные.
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
     * Сериализует данные и сохраняет их во временный файл.
     * @param array $data
     * @return string
     */
    private function saveResultInTmpFile(array $data):string
    {
        try {
            $res_data = json_encode($data, JSON_THROW_ON_ERROR);
        }catch (\JsonException $e){
            return '';
        }
        $downloadCacheDir = '/tmp/';
        $tmpDir = '/tmp/';
        $di = MikoPBXVersion::getDefaultDi();
        if ($di) {
            $dirsConfig = $di->getShared('config');
            $tmoDirName = $dirsConfig->path('core.tempDir') . '/B24ConnectorDB';
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
     * Возвращает результат опроса, только изменненые данные.
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
     * Возвращает список опросов.
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
     * Возвращает настройки опроса.
     * @param  $id
     * @return array
     */
    public function getPollingById($id):array
    {
        $res = new PBXApiResult();
        $res->success = true;

        $polling = Polling::findFirst(['id = :id:', 'bind' => ['id'=> $id]])->toArray();
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
     * Наполнение таблицы результатов обзвона.
     * @param $data
     * @param $errorMsg
     * @return bool
     */
    private function addTaskResults($data, &$errorMsg):bool
    {
        $result = true;
        $indexPhones = [];
        foreach ($data['numbers'] as $numData){
            if(is_array($numData)){
                $number = $numData['number']??'';
                $indexPhones[] = [
                    'phone'         => $number,
                    'phoneId'       => self::getPhoneIndex($number),
                    'timeCallAllow' => (string)$numData['timeCallAllow'],
                    'params'        => serialize($numData['params']??'')
                ];
            }else{
                $indexPhones[] = [
                    'phone'         => $numData,
                    'phoneId'       => self::getPhoneIndex($numData),
                    'timeCallAllow' => (string)$numData['timeCallAllow'],
                    'params'        => ''
                ];
            }
        }
        /** @var TaskResults $oldResult */
        $oldResultsTask = TaskResults::find("taskId='{$data['id']}'");
        foreach ($oldResultsTask as $oldResult){
            $indexRow = $this->searchForId($oldResult->phoneId, 'phoneId', $indexPhones);
            if($indexRow === false){
                // Номера больше нет в списке.
                $oldResult->delete();
            }else{
                $oldResult->params        = $indexPhones[$indexRow]['params'];
                $oldResult->timeCallAllow = $this->getTimestampFromDate($indexPhones[$indexRow]['timeCallAllow']);
                $oldResult->changeTime     = microtime(true);
                $oldResult->save();
                // Убираем из индекс массива существующие номера.
                unset($indexPhones[$indexRow]);
            }
        }
        foreach ($indexPhones as $numData){
            $taskDetail = new TaskResults();
            $taskDetail->phoneId        = $numData['phoneId'];
            $taskDetail->phone          = $numData['phone'];
            $taskDetail->params         = $numData['params'];
            $taskDetail->timeCallAllow  = $this->getTimestampFromDate($numData['timeCallAllow']);

            $taskDetail->taskId         = $data['id'];
            $taskDetail->state          = self::EVENT_CREATE_TASK;
            $taskDetail->changeTime     = microtime(true);
            $taskDetail->closeTime      = 0;
            $result = min($result, $taskDetail->save());
            if(!$result){
                $errorMsg = $taskDetail->getMessages();
                break;
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
            // Преобразуем объект DateTime в timestamp
            $timestamp = $date->format('U');
        } else {
            $timestamp = 0;
        }
        return $timestamp;
    }

    private function searchForId($id, $colName, $array) {
        foreach ($array as $key => $val) {
            if ($val[$colName] === $id) {
                return $key;
            }
        }
        return false;
    }

}

if(isset($argv) && count($argv) !== 1
    && Util::getFilePathByClassName(ConnectorDB::class) === $argv[0]){
    ConnectorDB::startWorker($argv??[]);
}
