<?php
/**
 * Конфигурация для E2E тестов ModuleAutoDialer.
 *
 * SIP-клиент регистрируется как SIP-транк (SIP-1692280724) — получает
 * исходящие вызовы через маршрут AutoDialer (префикс 999).
 * SIP-оператор — внутренний номер 228 (Тест Битрикс 2).
 */

$arch = php_uname('m');
if ($arch === 'aarch64' || $arch === 'arm64') {
    $pjsuaBinary = __DIR__ . '/bin/pjsua-linux-aarch64';
} else {
    $pjsuaBinary = __DIR__ . '/bin/pjsua-linux-x86_64';
}

return [
    // REST API
    'api_url' => 'http://127.0.0.1/pbxcore/api/module-dialer/v1',

    // PJSUA
    'pjsua_binary' => $pjsuaBinary,

    // SIP-клиент: регистрируется как SIP-транк, получает исходящие вызовы
    'sip_client' => [
        'username'   => 'SIP-1692280724',
        'password'   => '23e4d342e5033c5581a7aa2f4b8de33e',
        'registrar'  => 'sip:127.0.0.1',
        'local_port' => 5080,
    ],

    // SIP-оператор: внутренний номер 228
    'sip_operator' => [
        'username'   => '228',
        'password'   => '36daa21f30e92b5f2c9adc969bc1ce47',
        'registrar'  => 'sip:127.0.0.1',
        'local_port' => 5082,
    ],

    // Параметры задач обзвона
    'dial_prefix'    => '999',       // Префикс маршрута AutoDialer
    'operator_exten' => '228',       // Внутренний номер оператора
    'test_phone'     => '79990000228', // Тестовый номер клиента
    'polling_id'     => '15',        // ID существующего опроса (диалплан генерируется при старте модуля)

    // Паттерны каналов AMI (автоматически из sip_client/sip_operator username)
    'trunk_channel'    => 'PJSIP/SIP-1692280724-',
    'operator_channel' => 'PJSIP/228-',

    // Таймауты (секунды)
    'timeouts' => [
        'registration'  => 15,
        'call_start'    => 60,
        'call_complete' => 30,
        'poll_max_wait' => 120,
        'poll_interval' => 5,
        'dtmf_delay'    => 5,
    ],

    // Префикс crmId тестовых задач
    'test_crm_prefix' => 'autotest-',
];
