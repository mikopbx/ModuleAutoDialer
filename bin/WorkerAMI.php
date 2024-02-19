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
use MikoPBX\Core\Workers\WorkerBase;
use MikoPBX\Core\System\Util;
use Modules\ModuleAutoDialer\Lib\AutoDialerMain;
use Modules\ModuleAutoDialer\Lib\Logger;

class WorkerAMI extends WorkerBase
{
    /** @var AsteriskManager $am */
    protected AsteriskManager $am;
    private int $counter = 0;

    private array $states = [];
    private array $customStates = [];
    private bool $useCustomState = true;

    private array $queues = [];

    public const STATE_IDLE         = 'Idle';
    public const STATE_RINGING      = 'Ringing';
    public const STATE_BUSY         = 'Busy';
    public const STATE_UNAVAILABLE  = 'Unavailable';

    private Logger $logger;

    /**
     * Старт работы листнера.
     *
     * @param $argv
     */
    public function start($argv):void
    {
        $this->logger   = new Logger('WorkerAMI', 'ModuleAutoDialer');
        $this->logger->writeInfo('Starting...');
        $this->am = Util::getAstManager();
        $this->getSettings();
        $this->updateStates();
        $this->setFilter();
        $this->am->addEventHandler("UserEvent",       [$this, "callback"]);
        $this->am->addEventHandler("ExtensionStatus", [$this, "callback"]);
        while (true) {
            $this->am->waitUserEvent(true);
            if (!$this->am->loggedIn()) {
                $this->logger->writeInfo('Reconnect AMI');
                // Нужен реконнект.
                sleep(1);
                $this->am = Util::getAstManager();
                $this->setFilter();
            }
        }
    }

    /**
     * Начальное получение статусов.
     * @return void
     */
    private function updateStates():void
    {
        $peers = $this->getPjSipPeers();
        foreach ($peers as $peer) {
            if(!isset($this->states[$peer['id']])){
                continue;
            }
            $this->states[$peer['id']] = $peer['state'];
            $this->customStates[$peer['id']] = true;
        }
        $statesResult = $this->am->sendRequestTimeout('DBGetTree', ['Family' => 'UserBuddyStatus']);
        foreach ($statesResult['data']['DBGetTreeResponse']??[] as $stateData){
            $key = basename($stateData['Key']);
            if(!isset($this->states[$key])){
                continue;
            }
            $this->customStates[$key] = ($stateData['Val'] === '0')  ;
        }
        foreach ($this->queues as $number => $agents){
            $this->states[$number] = self::STATE_BUSY;
            foreach ($agents as $agent){
                $state = $this->states[$agent]??'';
                if($state === self::STATE_IDLE){
                    $this->states[$number] = self::STATE_IDLE;
                    break;
                }
            }
        }
        $this->updateCacheState();
    }

    /**
     * Функция обновляет кэш статусов сотрудников и очередей.
     * @param $useCustomState
     * @return void
     */
    private function updateCacheState():void
    {
        if(!$this->useCustomState){
            AutoDialerMain::setCacheData('statuses', $this->states);
            return;
        }
        $statesTmp = $this->states;
        foreach ($this->customStates as $key => $val){
            if($val === false && $statesTmp[$key] === self::STATE_IDLE){
                $statesTmp[$key] = self::STATE_BUSY;
            }
        }
        foreach ($this->queues as $number => $agents){
            $statesTmp[$number] = self::STATE_BUSY;
            foreach ($agents as $agent){
                $state = $statesTmp[$agent]??'';
                if($state === self::STATE_IDLE){
                    $statesTmp[$number] = self::STATE_IDLE;
                    break;
                }
            }
        }
        AutoDialerMain::setCacheData('statuses', $statesTmp);
    }

    /**
     * Get the PJSIP peers information.
     *
     * @return array The PJSIP peers information.
     */
    public function getPjSipPeers(): array
    {
        $peers  = [];
        $result = $this->am->sendRequestTimeout('PJSIPShowEndpoints');
        $state_array = [
            'Not in use' => self::STATE_IDLE,
            'Busy'       => self::STATE_BUSY,
            'Unavailable'=> self::STATE_UNAVAILABLE,
            'Ringing'    => self::STATE_RINGING
        ];
        $endpoints = $result['data']['EndpointList']??[];
        foreach ($endpoints as $index => $peer) {
            if ($peer['ObjectName'] === 'anonymous' || !is_numeric($peer['Auths'])) {
                unset($endpoints[$index]);
                continue;
            }
            if($peer['ObjectName'] === "{$peer['Auths']}-WS"){
                continue;
            }
            $peers[$peer['Auths']] = [
                'id'        => $peer['Auths'],
                'state'     => $state_array[$peer['DeviceState']] ?? $peer['DeviceState']
            ];
        }

        foreach ($endpoints as $peer) {
            if($peer['ObjectName'] !== "{$peer['Auths']}-WS"){
                continue;
            }
            $wsState = $state_array[$peer['DeviceState']];
            if($wsState === self::STATE_IDLE){
                $peers[$peer['Auths']]['state'] = $state_array[$peer['DeviceState']];
            }
        }
        return array_values($peers);
    }

    /**
     * Получает настройки АТС.
     * @return void
     */
    private function getSettings():void{
        $this->states  = [];
        $this->queues  = [];

        $queuesNumbers = [];
        $extensions = AutoDialerMain::getExtensions();
        foreach ($extensions as $extension){
            if(isset($this->states[$extension->number])){
                continue;
            }
            $this->states[$extension->number] = self::STATE_IDLE;
            if($extension->type === Extensions::TYPE_QUEUE){
                $queuesNumbers[$extension->queueId] = $extension->number;
            }
        }
        $queuesData = CallQueueMembers::find();
        foreach ($queuesData as $q){
            $number = $queuesNumbers[$q->queue];
            $this->queues[$number][] = $q->extension;
        }
        unset($queuesData);
        $this->logger->writeInfo(['action' => __FUNCTION__, 'queues' => $this->queues]);
        $this->logger->writeInfo(['action' => __FUNCTION__, 'queues' => $this->queues]);
    }

    /**
     * Установка фильтра
     *
     */
    private function setFilter():void
    {
        $pingTube = $this->makePingTubeName(self::class);
        $params = ['Operation' => 'Add', 'Filter' => 'UserEvent: '.$pingTube];
        $this->am->sendRequestTimeout('Filter', $params);
        $params = ['Operation' => 'Add', 'Filter' => 'Event: ExtensionStatus'];
        $this->am->sendRequestTimeout('Filter', $params);
        $params = ['Operation' => 'Add', 'Filter' => 'Event: DB_UserBuddyStatus'];
        $this->am->sendRequestTimeout('Filter', $params);
    }

    /**
     * Функция обработки оповещений.
     *
     * @param $parameters
     */
    public function callback($parameters):void{

        if ($parameters['Event'] === 'UserEvent' && $this->replyOnPingRequest($parameters)){
            $this->counter++;
            if ($this->counter > 2) {
                // Обновляем список номеров. Получаем актуальные настройки.
                // Пинг приходит раз в минуту. Интервал обновления списка номеров 5 минут.
                $this->getSettings();
                $this->counter = 0;
            }
            $this->logger->rotate();
            $this->getSettings();
            return;
        }

        if($parameters['Event'] !== 'ExtensionStatus'){
            $this->updateCustomState($parameters);
            return;
        }
        if(isset($this->states[$parameters['Exten']])){
            $this->states[$parameters['Exten']] = $parameters['StatusText'];
        }else{
            return;
        }
        foreach ($this->queues as $number => $agents){
            if(!in_array($parameters['Exten'], $agents, true)){
                continue;
            }
            $this->states[$number] = self::STATE_BUSY;
            foreach ($agents as $agent){
                $state = $this->states[$agent]??'';
                if($state === self::STATE_IDLE){
                    $this->states[$number] = self::STATE_IDLE;
                    break;
                }
            }
        }
        $this->updateCacheState();
        $this->logger->writeInfo(['action' => __FUNCTION__, 'statuses' => $this->states]);
    }

    /**
     * Обновление пользовательского статуса.
     * @param $parameters
     * @return void
     */
    private function updateCustomState($parameters):void
    {
        if($parameters['Event'] !== 'UserEvent' || $parameters['UserEvent'] !== 'DB_UserBuddyStatus'){
            return;
        }
        $key = basename($parameters['key']);
        if(!isset($this->states[$key])){
            return;
        }
        $this->customStates[$key] = $parameters['val'] === '0';
        $this->updateCacheState();
        $this->logger->writeInfo(['action' => __FUNCTION__, 'customStates' => $this->customStates]);
    }

}

if(isset($argv) && count($argv) !== 1
    && Util::getFilePathByClassName(WorkerAMI::class) === $argv[0]){
    // Start worker process
    WorkerAMI::startWorker($argv??[]);
}