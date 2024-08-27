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
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
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


    /**
     * Convert the audio file to various codecs using Asterisk.
     *
     * @param string $filename The path of the audio file to be converted.
     * @return PBXApiResult An object containing the result of the API call.
     */
    public static function convertAudioFileAction(string $filename): PBXApiResult
    {
        $res            = new PBXApiResult();
        $res->processor = __METHOD__;
        $res->success   = true;
        if (!file_exists($filename)) {
            $res->success    = false;
            $res->messages[] = "File '$filename' not found.";
        }
        $out          = [];
        $tmp_filename = '/tmp/' . time() . "_" . basename($filename);
        if ($res->success && false === copy($filename, $tmp_filename)) {
            $res->success    = false;
            $res->messages[] = "Unable to create temporary file '$tmp_filename'.";
        }
        if(!$res->success){
            return $res;
        }
        // Change extension to wav
        $trimmedFileName = Util::trimExtensionForFile($filename);
        $n_filename     = $trimmedFileName . ".wav";
        $n_filename_mp3 = $trimmedFileName . ".mp3";

        // Convert file to wav format
        $tmp_filename = escapeshellcmd($tmp_filename);
        $soxPath      = Util::which('sox');
        $soxIPath      = Util::which('soxi');
        $busyBoxPath      = Util::which('busybox');
        // Pre-conversion to wav step 1.
        if(Processes::mwExec("$soxIPath $tmp_filename | $busyBoxPath grep MPEG") === 0){
            Processes::mwExec("$soxPath $tmp_filename $tmp_filename.wav", $out);
            unlink($tmp_filename);
            $tmp_filename = "$tmp_filename.wav";
        }
        $n_filename   = escapeshellcmd($n_filename);
        // Pre-conversion to wav step 2.
        Processes::mwExec("$soxPath '$tmp_filename' -c 1 -r 8000 -b 16 '$n_filename'", $out);
        $result_str = implode('', $out);

        // Convert wav file to mp3 format
        $lamePath = Util::which('lame');
        Processes::mwExec("$lamePath -b 16 --silent '$n_filename' '$n_filename_mp3'", $out);
        $result_mp3 = implode('', $out);

        // Remove temporary file
        unlink($tmp_filename);
        if ($result_str !== '' && $result_mp3 !== '') {
            // Conversion failed
            $res->success    = false;
            $res->messages[] = $result_str;
            return $res;
        }

        if ($filename !== $n_filename
            && $filename !== $n_filename_mp3 && file_exists($filename)) {
            // Remove the original file if it's different from the converted files
            unlink($filename);
        }

        $res->success = true;
        $res->data[]  = $n_filename_mp3;

        return $res;
    }
}