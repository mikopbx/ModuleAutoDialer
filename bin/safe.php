<?php
/*
 * Страховочный скрипт: проверяет и перезапускает воркеры модуля.
 * Запускается из cron каждую минуту.
 *
 * Если модуль отключён — выходит. Если воркер не запущен — запускает.
 * Если есть дубликаты процессов — завершает лишние.
 */

use MikoPBX\Core\System\Util;
use MikoPBX\Core\System\Processes;
use MikoPBX\Core\System\SystemMessages;
use MikoPBX\Modules\PbxExtensionUtils;
use Modules\ModuleAutoDialer\Lib\AutoDialerConf;

require_once 'Globals.php';

$moduleEnable = PbxExtensionUtils::isEnabled('ModuleAutoDialer');
if (!$moduleEnable) {
    exit(1);
}

$conf = new AutoDialerConf();
$workers = $conf->getModuleWorkers();
foreach ($workers as $workerData) {
    $WorkerPID = Processes::getPidOfProcess($workerData['worker']);
    if (empty($WorkerPID)) {
        Processes::processPHPWorker($workerData['worker']);
        SystemMessages::sysLogMsg('ModuleAutoDialer_SAFE', "Service {$workerData['worker']} started.", LOG_NOTICE);
    } else {
        // Проверка дубликата процесса
        $allButLast = array_slice(explode(' ', $WorkerPID), 0, -1);
        if (!empty($allButLast)) {
            $bbPath = Util::which('busybox');
            shell_exec("$bbPath kill -SIGUSR2 " . implode(" ", $allButLast));
        }
    }
}
