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

use MikoPBX\Common\Models\CallQueues;
use MikoPBX\Common\Models\Extensions;
use MikoPBX\Common\Providers\ConfigProvider;
use MikoPBX\Core\System\Processes;
use MikoPBX\Core\System\Util;
use Modules\ModuleAutoDialer\bin\ConnectorDB;
use Phalcon\Cache\Adapter\Redis;
use Phalcon\Di;
use Phalcon\Storage\SerializerFactory;
use Throwable;


class AutoDialerMain
{
    public const REDIS_PREFIX       = 'auto_dialer_';

    /**
     * Получение настроек АТС.
     * @param $name
     * @return string
     */
    public static function getDiSetting($name):string
    {
        $di     = Di::getDefault();
        if($di === null){
            return '';
        }
        return (string)$di->getShared('config')->path($name);
    }

    /**
     * Получение внутренних номеров.
     * @return null
     */
    public static function getExtensions(){
        $di = Di::getDefault();
        if ($di === null) {
            return null;
        }
        $manager = $di->get('modelsManager');
        $parameters = [
            'models'     => [
                'ExtensionsSip' => Extensions::class,
            ],
            'conditions' => "type='".Extensions::TYPE_SIP."' OR type='".Extensions::TYPE_QUEUE."'",
            'columns'    => [
                'number'         => 'ExtensionsSip.number',
                'type'           => 'ExtensionsSip.type',
                'queueId'        => 'CallQueues.uniqid',
            ],
            'order'      => 'number',
            'joins'      => [
                'CallQueues' => [
                    0 => CallQueues::class,
                    1 => 'ExtensionsSip.number = CallQueues.extension',
                    2 => 'CallQueues',
                    3 => 'LEFT',
                ],
            ],
        ];
        return $manager->createBuilder($parameters)->getQuery()->execute();
    }

    /**
     * Возвращает адаптер для подключения к Redis.
     * @return Redis
     */
    public static function cacheAdapter():Redis
    {
        $serializerFactory = new SerializerFactory();
        $di     = Di::getDefault();
        $options = [
            'defaultSerializer' => 'Php',
            'lifetime'          => 86400,
            'index'             => 3,
            'prefix'            => self::REDIS_PREFIX
        ];
        if($di !== null){
            $config          = $di->getShared(ConfigProvider::SERVICE_NAME);
            $options['host'] = $config->path('redis.host');
            $options['port'] = $config->path('redis.port');
        }
        return (new Redis($serializerFactory, $options));
    }

    /**
     * Сохранение кэш в redis
     * @param $key
     * @param $value
     * @param $ttl
     * @return void
     */
    public static function setCacheData($key, $value, $ttl = 86400):void
    {
        $cacheAdapter = AutoDialerMain::cacheAdapter();
        try {
            $cacheAdapter->set($key, $value, $ttl);
        }catch (Throwable $e){
            Util::sysLogMsg(self::class, $e->getMessage());
        }
    }

    /**
     * Получение данных из кэш
     * @param $key
     * @return array
     */
    public static function getCacheData($key):array
    {
        $result = [];
        $cacheAdapter = AutoDialerMain::cacheAdapter();
        try {
            $result = $cacheAdapter->get($key);
            $result = (array)$result;
        }catch (Throwable $e){
            Util::sysLogMsg(self::class, $e->getMessage());
        }
        if(empty($result)){
            $result = [];
        }
        return $result;
    }

}