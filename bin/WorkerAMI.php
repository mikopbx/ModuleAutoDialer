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
    private array $queues = [];

    public const STATE_IDLE         = 'Idle';
    public const STATE_RINGING      = 'Ringing';
    public const STATE_BUSY         = 'Busy';
    public const STATE_UNAVAILABLE  = 'Unavailable';

    private Logger $logger;

    /**
     * Старт работы листнера.
     *
     * @param $params
     */
    public function start($params):void
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
        AutoDialerMain::setCacheData('statuses', $this->states);
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
        foreach ($endpoints as $peer) {
            if ($peer['ObjectName'] === 'anonymous') {
                continue;
            }
            $peers[$peer['Auths']] = [
                'id'        => $peer['Auths'],
                'state'     => $state_array[$peer['DeviceState']] ?? $peer['DeviceState']
            ];
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
        AutoDialerMain::setCacheData('statuses', $this->states);
        $this->logger->writeInfo(['action' => __FUNCTION__, 'statuses' => $this->states]);
    }
}

if(isset($argv) && count($argv) !== 1){
    // Start worker process
    WorkerAMI::startWorker($argv??[]);
}