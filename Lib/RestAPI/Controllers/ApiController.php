<?php
/**
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Nikolay Beketov, 4 2020
 *
 */

namespace Modules\ModuleAutoDialer\Lib\RestAPI\Controllers;

use MikoPBX\Core\System\Util;
use MikoPBX\PBXCoreREST\Controllers\Modules\ModulesControllerBase;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleAutoDialer\bin\ConnectorDB;

class ApiController extends ModulesControllerBase
{
    /**
     * curl -X POST -d '{"crmId":80001,"name":"New task","state":0,"innerNum":"2001","maxCountChannels":1,"dialPrefix": "999","numbers":["77952223344","77952223341"]}' http://127.0.0.1/pbxcore/api/module-dialer/v1/task
     * curl -X POST -d '{"crmId":90072,"name":"New pollingtask","state":0,"innerNum":"2","innerNumType": "polling","maxCountChannels":1,"dialPrefix": "999","numbers":["77952223344","77952223341"]}' http://127.0.0.1/pbxcore/api/module-dialer/v1/task
     * // Задача с параметрами:
     * curl -X POST -d '{"crmId":90072,"name":"New pollingtask","state":0,"innerNum":"2","innerNumType":"polling","maxCountChannels":1,"dialPrefix":"999","numbers":[{"number":"77952223344","params":{"speach":"Выша задолженность 1000 рублей"}}]}' http://127.0.0.1/pbxcore/api/module-dialer/v1/task
     */
    public function postTaskAction():void
    {
        $data =  $this->request->getJsonRawBody(true);
        $result = ConnectorDB::invoke('addTask', [$data]);
        $this->echoResponse($result);
        $this->response->sendRaw();
    }

    /**
     *  curl -X POST -d '{"crmId":"100000","name":"New polling","questions":[{"questionId":"1","questionText":"Готовы ли Вы принять груз? Нажмите 1, если согласны. Нажмите 0, если отказываетесь, нажмите 3 для связи с оператором. Нажмите 4 для заказа такси","press":[{"key":"1","action":"answer","value":"1","nextQuestion":"2"},{"key":"2","action":"answer","value":"0","nextQuestion":""},{"key":"3","action":"dial","value":"201","nextQuestion":""},{"key":"4","action":"","value":"","nextQuestion":"2"}]},{"questionId":"2","questionText":"Заказать Вам такси?","press":[{"key":"1","action":"answer","value":"1","nextQuestion":""},{"key":"2","action":"answer","value":"0","nextQuestion":""}]}]}' http://127.0.0.1/pbxcore/api/module-dialer/v1/polling
     *  curl -X POST -d '{"crmId":"100002","name":"New polling","questions":[{"questionId":"test-2","questionText":"На свзи компания МиКОО. Готовы ли Вы принять груз? Нажмите 1, если согласны. Нажмите 0, если отказываетесь, нажмите 3 для связи с оператором. Нажмите 4 для заказа такси","press":[{"key":"1","action":"answer","value":"1","nextQuestion":""},{"key":"2","action":"answer","value":"0","nextQuestion":""}]}]}' http://127.0.0.1/pbxcore/api/module-dialer/v1/polling
     * @return void
     */
    public function  postPollingAction():void
    {
        $data =  $this->request->getJsonRawBody(true);
        $result = ConnectorDB::invoke('addPolling', [$data]);
        $this->echoResponse($result);
        $this->response->sendRaw();
    }

    /**
     * curl 'http://127.0.0.1/pbxcore/api/module-dialer/v1/polling'
     * @return void
     */
    public function getPollingAction():void
    {
        $result = ConnectorDB::invoke('getPolling', []);
        $this->echoResponse($result);
        $this->response->sendRaw();
    }

    /**
     * curl 'http://127.0.0.1/pbxcore/api/module-dialer/v1/polling/1'
     * @param $id
     * @return void
     */
    public function getPollingByIdAction($id):void
    {
        $result = ConnectorDB::invoke('getPollingById', [$id]);
        $result['data'] = $result['data']['results'];
        $this->decodeData($result['data']);
        $this->echoResponse($result);
        $this->response->sendRaw();
    }

    /**
     * Удаление задачи.
     * curl -X DELETE http://127.0.0.1/pbxcore/api/module-dialer/v1/task/600011
     * @param string $taskId
     * @return void
     */
    public function deleteTaskAction(string $taskId):void
    {
        $result = ConnectorDB::invoke('deleteTask', [$taskId]);
        $this->echoResponse($result);
        $this->response->sendRaw();
    }

    /**
     * Получить данные задачи.
     * curl -X GET http://127.0.0.1/pbxcore/api/module-dialer/v1/task/5002
     * @param string $taskId
     * @return void
     */
    public function getTaskAction(string $taskId):void
    {
        $result = ConnectorDB::invoke('getTask', [$taskId]);
        $this->echoResponse($result);
        $this->response->sendRaw();
    }

    /**
     * Получить данные задачи.
     * curl -X GET http://127.0.0.1/pbxcore/api/module-dialer/v1/task
     * @return void
     */
    public function getTasksAction():void
    {
        $state  = $this->request->get('state');
        $limit  = $this->request->get('limit');
        $offset = $this->request->get('offset');
        $result = ConnectorDB::invoke('getTasks', [$state, $limit, $offset]);
        $this->echoResponse($result);
        $this->response->sendRaw();
    }

    /**
     * curl -H 'Content-Type: application/json' -X PUT -d '{"state":0}' http://127.0.0.1/pbxcore/api/module-dialer/v1/task/997
     * @param string $taskId
     * @return void
     */
    public function putTaskAction(string $taskId):void
    {
        $data =  $this->request->getJsonRawBody(true);
        $result = ConnectorDB::invoke('changeTask', [$taskId, $data]);
        $this->echoResponse($result->getResult());
        $this->response->sendRaw();
    }

    /**
     * curl -F "file=@/home/serber/1.mp3" 'http://127.0.0.1/pbxcore/api/module-dialer/v1/audio'
     * @return void
     */
    public function uploadAudio():void
    {
        $result = new PBXApiResult();
        // Если запрос POST
        if ($this->request->isPost()) {
            // Получаем файл из запроса
            $file = $this->request->getUploadedFiles();

            // Проверяем, что файл был загружен
            if (isset($file[0])) {
                $uploadedFile = $file[0];
                $extension = Util::getExtensionOfFile($uploadedFile->getName());
                $path = '/tmp/' . md5($uploadedFile->getTempName()).'.'.$extension;
                // Сохраняем файл
                if ($uploadedFile->moveTo($path)) {
                    $result = ConnectorDB::invoke('saveAudioFile', [$path, basename($uploadedFile->getName())]);
                } else {
                    $result->messages[] = 'error upload file: fail mv fail to /tmp';
                }
                unlink($path);
            } else {
                $result->messages[] = 'error upload file: file is empty';
            }
        }
        try {
            $this->echoResponse($result->getResult());
        }catch (\Throwable $e){
        }
        $this->response->sendRaw();
    }

    /**
     * curl 'http://127.0.0.1/pbxcore/api/module-dialer/v1/audio'
     * @return void
     */
    public function listAudioFiles():void
    {
        $result = ConnectorDB::invoke('listAudioFiles', []);
        try {
            $this->echoResponse($result->getResult());
        }catch (\Throwable $e){
        }
        $this->response->sendRaw();
    }

    /**
     *  curl -X DELETE 'http://127.0.0.1/pbxcore/api/module-dialer/v1/audio/1.mp3'
     * @return void
     */
    public function deleteAudioFile($name):void
    {
        $result = ConnectorDB::invoke('deleteAudioFile', [$name]);
        try {
            $this->echoResponse($result->getResult());
        }catch (\Throwable $e){
        }
        $this->response->sendRaw();
    }

    /**
     * curl -X GET http://127.0.0.1/pbxcore/api/module-dialer/v1/results/{changeTime}
     * curl -X GET http://127.0.0.1/pbxcore/api/module-dialer/v1/results/1690194629
     * @param string $changeTime
     * @return void
     */
    public function getResultsAction(string $changeTime):void
    {
        $result = ConnectorDB::invoke('getResults', [$changeTime]);
        $this->echoResponse($result);
        $this->response->sendRaw();
    }

    /**
     * curl -X GET http://127.0.0.1/pbxcore/api/module-dialer/v1/polling-results/{changeTime}
     * curl -X GET http://127.0.0.1/pbxcore/api/module-dialer/v1/polling-results/1690194629
     * @param string $changeTime
     * @return void
     */
    public function getResultsPollingAction(string $changeTime):void
    {
        $result = ConnectorDB::invoke('getResultsPolling', [$changeTime]);
        $this->echoResponse($result);
        $this->response->sendRaw();
    }

    /**
     * Вывод ответа сервера.
     * @param $result
     * @return void
     */
    private function echoResponse($result):void
    {
        if(isset($result['data']['results'])){
            $this->decodeData($result['data']['results']);
        }
        try {
            echo json_encode($result, JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
        }catch (\Exception $e){
            echo 'Error json encode: '. print_r($result, true);
        }
    }

    /**
     * Если передан путь к файлу, то будет выполнена попытка декодировать как JSON.
     * @param $data
     * @return void
     */
    private function decodeData(& $data):void
    {
        if(is_file($data) && file_exists($data)){
            try {
                $data = json_decode(file_get_contents($data), true, 512, JSON_THROW_ON_ERROR);
            }catch ( \JsonException $e){
            }
            unlink($data);
        }
    }
}