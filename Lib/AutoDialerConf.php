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

namespace Modules\ModuleAutoDialer\Lib;

use MikoPBX\Common\Models\Extensions;
use MikoPBX\Core\Asterisk\Configs\ExtensionsConf;
use MikoPBX\Core\System\PBX;
use MikoPBX\Core\System\Util;
use MikoPBX\Core\Workers\Cron\WorkerSafeScriptsCore;
use MikoPBX\Modules\Config\ConfigClass;
use Modules\ModuleAutoDialer\bin\ConnectorDB;
use Modules\ModuleAutoDialer\bin\WorkerAMI;
use Modules\ModuleAutoDialer\bin\WorkerDialer;
use Modules\ModuleAutoDialer\Lib\RestAPI\Controllers\ApiController;
use Modules\ModuleAutoDialer\Models\ModuleAutoDialer;
use Modules\ModuleAutoDialer\Models\Polling;
use Modules\ModuleAutoDialer\Models\Question;
use Modules\ModuleAutoDialer\Models\QuestionActions;
use Modules\ModuleAutoDialer\Models\Tasks;

use function GuzzleHttp\Psr7\str;

class AutoDialerConf extends ConfigClass
{
    public const CONTEXT_NAME = 'dialer-out-originate-in';
    public const CONTEXT_POLLING_NAME = 'dialer-polling';
    private string $lang = '';
    private string $modName = 'func_hangupcause';


    public function getSettings(): void
    {
        parent::getSettings();
        $this->lang = str_replace('_', '-', strtolower($this->generalSettings['PBXLanguage']));
    }

    /**
     * Adds priorities ащк [dial_create_chan] context section in the extensions.conf file
     * @see https://docs.mikopbx.com/mikopbx-development/module-developement/module-class#extensiongenhints
     *
     * @return string
     */
    public function extensionGenCreateChannelDialplan(): string
    {
        return 'same => n,ExecIf($["${CHANNEL(channeltype)}" == "PJSIP" && "${CHANNEL(endpoint):0:4}" == "SIP-"]?Set(CHANNEL(hangup_handler_push)=dialer-out-originate-in-hangup-handler,s,1))'."\t";
    }

    public function generateModulesConf(): string
    {
        return "load => $this->modName.so".PHP_EOL;
    }

    /**
     * Prepares additional contexts sections in the extensions.conf file
     *
     * @return string
     */
    public function extensionGenContexts(): string
    {
        $this->getSettings();
        $extensions = AutoDialerMain::getExtensions();
        $conf = '';
        foreach ($extensions as $extensionData){
            if($extensionData->type === Extensions::TYPE_SIP){
                $conf .= 'exten => '.$extensionData->number.',1,ExecIf($["${DEVICE_STATE(PJSIP/'.$extensionData->number.')}" == "NOT_INUSE"]?return)'.PHP_EOL."\t".
                         $this->getAgiActionCmd(ConnectorDB::EVENT_ALL_USER_BUSY).PHP_EOL."\t".
                         'same => n,hangup'.PHP_EOL;
            }elseif ($extensionData->type === Extensions::TYPE_QUEUE){
                $conf .= 'exten => '.$extensionData->number.',1,Set(mReady=${QUEUE_MEMBER('.$extensionData->queueId.',ready)})'.PHP_EOL."\t".
                         'same => n,ExecIf($["${mReady}" != "0"]?return)'.PHP_EOL."\t".
                         $this->getAgiActionCmd(ConnectorDB::EVENT_ALL_USER_BUSY).PHP_EOL."\t".
                         'same => n,hangup'.PHP_EOL;
            }
        }
        return '['.self::CONTEXT_NAME.']'.PHP_EOL.
            'exten => '.ExtensionsConf::ALL_EXTENSION.',1,Noop(${MASTER_CHANNEL(CHANNEL)})'.PHP_EOL."\t".
                'same => n,Gosub(dialer-out-originate-set-bridge-peer,${EXTEN},1)'.PHP_EOL."\t".
                'same => n,ExecIf($[ "${bridgePeer}x" != "x" ]?ChannelRedirect(${bridgePeer},${CONTEXT},${EXTEN},1000))'.PHP_EOL."\t".
                'same => n,Hangup()'.PHP_EOL."\t".

                'same => 1000,NoOp()'.PHP_EOL."\t".
                'same => n,Set(CALLERID(name)=${M_OUT_NUMBER})'.PHP_EOL."\t".
                'same => n,Set(CALLERID(num)=${M_OUT_NUMBER})'.PHP_EOL."\t".
                'same => n,Set(__FROM_DID=${EXTEN})'.PHP_EOL."\t".
                'same => n,Set(__FROM_CHAN=${CHANNEL})'.PHP_EOL."\t".
                'same => n,ExecIf($["${CHANNEL(channeltype)}" != "Local"]?Gosub(set_from_peer,s,1))'.PHP_EOL."\t".
                'same => n,ExecIf($["${CHANNEL(channeltype)}" == "Local"]?Set(__FROM_PEER=${CALLERID(num)}))'.PHP_EOL."\t".
                'same => n,Set(__TRANSFER_OPTIONS=t)'.PHP_EOL."\t".
                'same => n,ExecIf($["${M_EXTEN_TYPE}" == "'.Tasks::TYPE_INNER_NUM_POLLING.'"]?Goto('.self::CONTEXT_POLLING_NAME.',${EXTEN},1))'.PHP_EOL."\t".
                'same => n,Gosub(hangup_chan,${EXTEN},1)'.PHP_EOL."\t".
                'same => n,Set(pt1c_UNIQUEID=${UNDEFINED})'.PHP_EOL."\t".

                $this->getAgiActionCmd(ConnectorDB::EVENT_START_DIAL_IN).PHP_EOL."\t".
                'same => n,ExecIf(${DIALPLAN_EXISTS(dialer-out-originate-check-inner-peer-state,${EXTEN},1)}?Gosub(dialer-out-originate-check-inner-peer-state,${EXTEN},1))'.PHP_EOL."\t".
                'same => n,ExecIf(${DIALPLAN_EXISTS(internal,${EXTEN},1)}?Dial(Local/${EXTEN}@internal,60,${TRANSFER_OPTIONS}KwWg))'.PHP_EOL."\t".
                $this->getAgiActionCmd(ConnectorDB::EVENT_END_DIAL_IN).PHP_EOL."\t".
                'same => n,Hangup()'.PHP_EOL.
            'exten => _[hit],1,Hangup() '.PHP_EOL.
            'exten => failed,1,NoOp( -- failed --)'.PHP_EOL."\t".
                $this->getAgiActionCmd(ConnectorDB::EVENT_FAIL_ORIGINATE).PHP_EOL."\t".
                'same => n,Hangup()'.PHP_EOL.
            '[dialer-out-originate-check-inner-peer-state]'.PHP_EOL.
            $conf.PHP_EOL.
            '[dialer-out-originate-set-bridge-peer]'.PHP_EOL."\t".
            'exten => '.ExtensionsConf::ALL_EXTENSION.',1,ExecIf($[ "${CHANNEL(channeltype)}" != "Local" ]?return)'.PHP_EOL."\t".
                'same => n,Wait(0.2))'.PHP_EOL."\t".
                'same => n,Set(pl=${IF($["${CHANNEL:-1}" == "1"]?2:1)})'.PHP_EOL."\t".
                'same => n,Set(bridgePeer=${IMPORT(${CUT(CHANNEL,\;,1)}\;${pl},DIALEDPEERNAME)})'.PHP_EOL."\t".
                'same => n,return'.PHP_EOL.
            'exten => _[hit],1,Hangup() '.PHP_EOL.PHP_EOL.
            '[dialer-out-originate-outgoing]'.PHP_EOL.
            'exten => '.ExtensionsConf::ALL_EXTENSION.',1,Set(QUEUE_SRC_CHAN=${CHANNEL})'.PHP_EOL."\t".
                'same => n,Goto(outgoing,${EXTEN},1)'.PHP_EOL.
            'exten => _[hit],1,Hangup() '.PHP_EOL.PHP_EOL.
            '[dialer-out-originate-in-hangup-handler]'.PHP_EOL.
            'exten => s,1,Gosub(hangup_handler,${EXTEN},1)'.PHP_EOL."\t".
                $this->getAgiActionCmd(ConnectorDB::EVENT_END_CALL).PHP_EOL."\t".
                'same => n,return'.PHP_EOL.PHP_EOL.
            $this->genPollingContexts();
    }

    /**
     * Генерация контекстов для опроса.
     * @return string
     */
    private function genPollingContexts(): string
    {
        /** @var ModuleAutoDialer $settings */
        $settings = ModuleAutoDialer::findFirst();
        if(!$settings || empty($settings->yandexApiKey)){
            return '';
        }
        $tts = new YandexSynthesize("{$this->moduleDir}/db/tts", $settings->yandexApiKey);
        $conf = '['.self::CONTEXT_POLLING_NAME.']'.PHP_EOL;
        $questionContexts = [];
        /** @var Polling $polling */
        $polling = Polling::find();
        foreach ($polling as $pollingData){
            $conf.= "exten => $pollingData->id,1,Answer()".PHP_EOL;
            $conf.=  "\t"."same => n,Gosub(dial_answer,s,1)".PHP_EOL;
            $conf.=  "\t".$this->getAgiActionCmd(ConnectorDB::EVENT_POLLING).PHP_EOL;

            $questionsKeys = [];
            /** @var Question $question */
            $questions = Question::find("pollingId='$pollingData->id'");
            foreach ($questions as $question){
                $questionsKeys[(string)$question->crmId] = $question->id;
            }
            foreach ($questions as $question){
                $fullFilename = $tts->makeSpeechFromText($question->questionText);
                if(!file_exists($fullFilename)){
                    continue;
                }
                $filename = Util::trimExtensionForFile($fullFilename);
                $context = "dialer-polling-$pollingData->id-$question->id";
                $conf.= "\t"."same => n,Goto($context,s,1)".PHP_EOL;
                $questionContexts[$context] = "exten => s,1,Background($filename)".PHP_EOL."\t";
                $questionContexts[$context].= "same => n,WaitExten(5)".PHP_EOL;
                $questionContexts[$context].= $this->genPolingActionsContexts($questionsKeys, $question->id, $pollingData->id);
            }
            $conf.= "\t"."same => n,Hangup()".PHP_EOL;
        }
        $conf.= PHP_EOL;
        foreach ($questionContexts as $contextName => $questionContext){
            $conf.= "[$contextName]".PHP_EOL;
            $conf.= $questionContext.PHP_EOL;
        }
        return $conf;
    }

    /**
     * Обработка нажатий в контексте опроса.
     * @param $questionsKeys
     * @param $questionId
     * @param $pollingDataId
     * @return string
     */
    private function genPolingActionsContexts($questionsKeys, $questionId, $pollingDataId):string
    {
        $conf = '';
        /** @var QuestionActions $actionData */
        $actions = QuestionActions::find("questionId='$questionId' AND pollingId='$pollingDataId'");
        foreach ($actions as $actionData){
            $conf.= "exten => $actionData->key,1,NoOp()".PHP_EOL."\t";
            if($actionData->action === 'answer'){
                $questionCrmId = array_search($questionId, array_values($questionsKeys), true);
                $conf.= "same => n,AGI($this->moduleDir/agi-bin/saveResult.php,$pollingDataId,$questionCrmId,$actionData->value,\${EXTEN})".PHP_EOL."\t";
            }elseif ($actionData->action === 'dial'){
                $conf.= 'same => n,Set(pt1c_UNIQUEID=${UNDEFINED})'.PHP_EOL."\t";
                $conf.= $this->getAgiActionCmd(ConnectorDB::EVENT_POLLING_END).PHP_EOL."\t";
                $conf.= 'same => n,Dial(Local/'.$actionData->value.'@internal,,${TRANSFER_OPTIONS}KwW)'.PHP_EOL."\t";
                $conf.= "same => n,Hangup()".PHP_EOL;
                continue;
            }
            $nextQuestion = $questionsKeys[$actionData->nextQuestion]??'';
            if(!empty($nextQuestion)){
                $conf.= "same => n,Goto(dialer-polling-$pollingDataId-{$questionsKeys[$actionData->nextQuestion]},s,1)".PHP_EOL."\t";
            }else{
                $conf.= $this->getAgiActionCmd(ConnectorDB::EVENT_POLLING_END).PHP_EOL."\t";
            }
            $conf.= "same => n,Hangup()".PHP_EOL;
        }
        $conf.= 'exten => t,1,Goto(${CONTEXT},s,1)'.PHP_EOL;
        $conf.= 'exten => i,1,Goto(${CONTEXT},s,1)'.PHP_EOL;
        return $conf;
    }

    /**
     * Возвращает dialplan вызова agi change-state-task.php
     * @param $event
     * @return string
     */
    private function getAgiActionCmd($event):string
    {
        return "same => n,AGI(".$this->moduleDir."/agi-bin/change-state-task.php,$event)";
    }

    /**
     * Prepares additional parameters for each outgoing route context
     * after dial call in the extensions.conf file
     * @see https://docs.mikopbx.com/mikopbx-development/module-developement/module-class#generateoutroutafterdialcontext
     *
     * @param array $rout
     *
     * @return string
     */
    public function generateOutRoutAfterDialContext(array $rout): string
    {
        return  "\t".$this->getAgiActionCmd(ConnectorDB::EVENT_AFTER_DIAL_OUT).PHP_EOL.
                "\t".'same => n,ExecIf($["${M_TASK_ID}x" != "x"]?Gosub(hangup_chan,${EXTEN},1))';
    }

    /**
     * Returns module workers to start it at WorkerSafeScriptCore
     *
     * @return array
     */
    public function getModuleWorkers(): array
    {
        return [
            [
                'type'   => WorkerSafeScriptsCore::CHECK_BY_BEANSTALK,
                'worker' => ConnectorDB::class,
            ],
            [
                'type'   => WorkerSafeScriptsCore::CHECK_BY_AMI,
                'worker' => WorkerAMI::class,
            ],
            [
                'type'   => WorkerSafeScriptsCore::CHECK_BY_BEANSTALK,
                'worker' => WorkerDialer::class,
            ],
        ];
    }

    /**
     * REST API модуля.
     * @return array[]
     */
    public function getPBXCoreRESTAdditionalRoutes(): array
    {
        return [
            [ApiController::class, 'postPollingAction','/pbxcore/api/module-dialer/v1/polling', 'post', '/', false],
            [ApiController::class, 'postTaskAction',   '/pbxcore/api/module-dialer/v1/task', 'post', '/', false],
            [ApiController::class, 'getTaskAction',    '/pbxcore/api/module-dialer/v1/task/{id}', 'get', '/', false],
            [ApiController::class, 'putTaskAction',    '/pbxcore/api/module-dialer/v1/task/{id}', 'put', '/', false],
            [ApiController::class, 'deleteTaskAction', '/pbxcore/api/module-dialer/v1/task/{id}', 'delete', '/', false],
            [ApiController::class, 'getResultsAction', '/pbxcore/api/module-dialer/v1/results/{changeTime}', 'get', '/', false],
            [ApiController::class, 'getResultsPollingAction', '/pbxcore/api/module-dialer/v1/polling-results/{changeTime}', 'get', '/', false],
        ];
    }

    /**
     * Process after disable action in web interface
     *
     * @return void
     */
    public function onAfterModuleDisable(): void
    {
        PBX::dialplanReload();
    }

    /**
     * Process after enable action in web interface
     *
     * @return void
     * @throws \Exception
     */
    public function onAfterModuleEnable(): void
    {
        PBX::dialplanReload();
        $asteriskPath = Util::which('asterisk');
        $countMod = trim(shell_exec("{$asteriskPath}  -rx 'module show like $this->modName' | grep $this->modName | wc -l"));
        if($countMod === '0'){
            shell_exec("{$asteriskPath} -rx 'module load $this->modName'");
        }
    }
}