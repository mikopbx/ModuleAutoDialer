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

namespace Modules\ModuleAutoDialer\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

class ModuleAutoDialer extends ModulesModelsBase
{
    public const TTS_MODEL_YANDEX = 'YANDEX';
    public const TTS_MODEL_RH_VOICE = 'RH_VOICE';

    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * Префикс для набора номера.
     * @Column(type="string", nullable=true)
     */
    public $defDialPrefix;

    /**
     * Текст оповещения об обратном звонке клиенту.
     * @Column(type="string", nullable=true)
     */
    public $callbackAlertText;

    /**
     * Префикс для набора номера.
     * @Column(type="string", nullable=true)
     */
    public $yandexApiKey;

    /**
     * Используемая модель генерации речи.
     * @Column(type="string", default="YANDEX", nullable=true)
     */
    public $ttsService = self::TTS_MODEL_YANDEX;

    /**
     * Идентификатор каталога Yandex Cloud для STT.
     * @Column(type="string", nullable=true)
     */
    public $yandexFolderId;

    /**
     * URL CRM системы (https/http).
     * @Column(type="string", nullable=true)
     */
    public $crmUrl;

    /**
     * Логин для Basic Auth к CRM.
     * @Column(type="string", nullable=true)
     */
    public $crmLogin;

    /**
     * Пароль для Basic Auth к CRM.
     * @Column(type="string", nullable=true)
     */
    public $crmPassword;

    /**
     * @param $calledModelObject
     * @return void
     */
    public static function getDynamicRelations(&$calledModelObject): void
    {
    }

    public function initialize(): void
    {
        $this->setSource('m_ModuleAutoDialer');
        parent::initialize();
    }
}