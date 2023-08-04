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

use MikoPBX\Core\System\Util;
use MikoPBX\Core\Workers\WorkerBase;
use MikoPBX\Core\System\BeanstalkClient;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleAutoDialer\Lib\Logger;
use Modules\ModuleAutoDialer\Models\ModuleAutoDialer;
use Modules\ModuleAutoDialer\Models\Polling;
use Modules\ModuleAutoDialer\Models\Question;
use Modules\ModuleAutoDialer\Models\QuestionActions;
use Modules\ModuleAutoDialer\Models\TaskResults;
use Modules\ModuleAutoDialer\Models\Tasks;

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
    public const EVENT_ALL_USER_BUSY                = 'allUserBusy';

    public const RESULT_SUCCESS                     = 'SUCCESS';
    public const RESULT_SUCCESS_CLIENT_H            = 'SUCCESS_CLIENT_H';
    public const RESULT_SUCCESS_USER_H              = 'SUCCESS_USER_H';
    public const RESULT_FAIL                        = 'FAIL';
    public const RESULT_FAIL_CLIENT_H_BEFORE_ANSWER = 'FAIL_CLIENT_H_BEFORE_ANSWER';
    public const RESULT_FAIL_USER_NO_ANSWER         = 'FAIL_USER_NO_ANSWER';
    public const RESULT_FAIL_USER_BUSY              = 'FAIL_USER_BUSY';
    public const RESULT_FAIL_ROUTE                  = 'FAIL_ROUTE';
    public const RESULT_FAIL_PROVIDER               = 'FAIL_PROVIDER';

    private Logger $logger;

    /**
     * Старт работы листнера.
     *
     * @param $params
     */
    public function start($params):void
    {
        $this->logger   = new Logger('ConnectorDB', 'ModuleAutoDialer');
        $this->logger->writeInfo('Starting...');
        $beanstalk      = new BeanstalkClient(self::class);
        $beanstalk->subscribe(self::class, [$this, 'onEvents']);
        $beanstalk->subscribe($this->makePingTubeName(self::class), [$this, 'pingCallBack']);
        while (true) {
            $beanstalk->wait();
            $this->logger->rotate();
        }
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

        Util::sysLogMsg(self::class, "$state, $outNum, $taskId, ".json_encode($data));
        $phoneId = self::getPhoneIndex($outNum);
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
        }elseif(self::EVENT_ALL_USER_BUSY === $state){
            $taskRow->state = $state;
            $taskRow->result = self::RESULT_FAIL_USER_BUSY;
        }elseif(self::EVENT_END_CALL === $state){
            if ($data['DIALSTATUS'] === 'ANSWER'){
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
        $result = $taskRow->save();
        if (!$result){
            Util::sysLogMsg(self::class, 'Fail update state: '.print_r($data, true));
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
        $settings = ModuleAutoDialer::findFirst();
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
                'dialPrefix'        => 'MAX(Tasks.dialPrefix)',
                'maxCountChannels'  => 'MAX(Tasks.maxCountChannels)',
                'phone'             => "MIN(IIF(TaskResults.state = :resultState: AND TaskResults.timeCallAllow <= :time:, CAST(printf('%s.%d1', TaskResults.rowid, TaskResults.phone) as FLOA), NULL))",
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
        foreach ($result as $index => $taskData){
            if($taskData['not_completed'] === '0'){
                unset($result[$index]);
                $task = Tasks::findFirst("id='{$taskData['taskId']}'");
                $task->state = Tasks::STATE_CLOSE;
                $task->save();
            }
            if(isset($result[$index]['phone'])){
                $val = explode('.', $result[$index]['phone']);
                $val = array_pop($val);
                $result[$index]['phone'] = substr($val, 0, -1);
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
     * @return PBXApiResult
     */
    public function addPolling($data):PBXApiResult
    {
        $res = new PBXApiResult();
        $this->db->begin();

        $poll = Polling::findFirst("crmId='{$data['crmId']}'");
        if(!$poll){
            $poll = new Polling();
            $poll->crmId = $data['crmId'];
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
        }else{
            $this->db->rollback();
        }
        return $res;
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
        foreach ($questions as $questionData) {
            if (!$res->success) {
                break;
            }
            $question = new Question();
            $question->pollingId = $poll->id;
            $question->crmId = $questionData['questionId'];
            $question->questionText = $questionData['questionText'];
            $res->success = $question->save();
            if (!$res->success) {
                break;
            }
            foreach ($questionData['press'] as $pressData) {
                $press = new QuestionActions();
                $press->pollingId  = $poll->id;
                $press->questionId = $question->id;
                foreach ($press->toArray() as $key => $oldValue) {
                    $value = $pressData[$key] ?? $oldValue;
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
        $filter = "crmId='{$data['crmId']}'";
        if(empty($data['crmId'])){
            $filter = "id='{$taskId}'";
        }
        /** @var Tasks $task */
        $task = Tasks::findFirst($filter);
        if(!$task){
            if(!$createNew){
                $res->success = false;
                $res->data = ['error' => 'TaskNotFound'];
                return $res;
            }
            $task = new Tasks();
            if(isset($data['id'])){
                $task->id    = $data['id'];
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
            $res->data['results'] = TaskResults::find("taskId='$id'")->toArray();
        }else{
            $res->success = false;
        }
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
            'conditions' => 'changeTime > :changeTime:',
            'limit' => 1000,
            'bind' => [
                'changeTime' => $changeTime,
            ],
            'order' => 'changeTime'
        ];
        $res->data['results'] = TaskResults::find($filter)->toArray();
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
        foreach ($data['numbers'] as $number){
            $indexPhones[self::getPhoneIndex($number)] = $number;
        }
        /** @var TaskResults $oldResult */
        $oldResultsTask = TaskResults::find("taskId='{$data['id']}'");
        foreach ($oldResultsTask as $oldResult){
            if(!isset($indexPhones[$oldResult->phoneId])){
                // Номера больше нет в списке.
                $oldResult->delete();
            }else{
                // Убираем из индекс массива существующие номера.
                unset($indexPhones[$oldResult->phoneId]);
            }
        }
        foreach ($indexPhones as $phoneId => $numData){
            $taskDetail = new TaskResults();
            $taskDetail->phoneId        = $phoneId;
            $taskDetail->phone          = $numData;
            $taskDetail->taskId         = $data['id'];
            $taskDetail->state          = self::EVENT_CREATE_TASK;
            $taskDetail->changeTime     = time();
            $taskDetail->timeCallAllow  = 0;
            $taskDetail->closeTime      = 0;
            $result = min($result, $taskDetail->save());
            if(!$result){
                $errorMsg = $taskDetail->getMessages();
                break;
            }
        }
        return $result;
    }

}

if(isset($argv) && count($argv) !== 1){
    ConnectorDB::startWorker($argv??[]);
}
