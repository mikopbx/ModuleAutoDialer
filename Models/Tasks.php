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

/**
 * Class ModuleCdrCallTags
 *
 * @package Modules\ModuleAutoDialer\Models
 * @Indexes(
 *     [name='crmId', columns=['crmId'], type=''],
 *     [name='timeStart', columns=['timeStart'], type=''],
 *     [name='timeEnd', columns=['timeEnd'], type=''],
 *     [name='state', columns=['state'], type='']
 * )
 */
class Tasks extends ModulesModelsBase
{
    public const STATE_OPEN  = 0;
    public const STATE_CLOSE = 1;
    public const STATE_PAUSE = 2;

    public const TYPE_INNER_NUM_EXTENSION = 'exten';
    public const TYPE_INNER_NUM_POLLING = 'polling';

    /**
     * Идентификатор задачи.
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $crmId;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $name;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $innerNum;

    /**
     * Количество попыток звонка
     * @Column(type="integer", nullable=true, default="1")
     */
    public $maxAttempt;

    /**
     * Звонить клиенту солько раз, сколько описано в maxAttempt, или пока не получен сигнал извне
     * В этом случае статус "Успешности дозвона" будет игнорироваться.
     * @Column(type="integer", nullable=true, default="0")
     */
    public $attemptUntilSignal = 0;

    /**
     * Количество минут начиная с 00:00, когда разрешены звонки 09:00 - 9*60 ~ 540, по умолчанию 0
     * @Column(type="integer", nullable=true, default="0")
     */
    public $timeStart= 0;

    /**
     * Количество минут начиная с 00:00, начиная с этого времени звонки запрещены 18:00 - 18*60 ~ 1080, по умолчанию 1440
     * @Column(type="integer", nullable=true, default="1440")
     */
    public $timeEnd= 1440;

    /**
     * Интервал между попытками звонка в секундах
     * @Column(type="integer", nullable=true, default="60")
     */
    public $tryInterval = 60;

    /**
     *
     * @Column(type="string", nullable=false, default="exten")
     */
    public string $innerNumType = self::TYPE_INNER_NUM_EXTENSION;

    /**
    * @Column(type="integer", nullable=false, default="1")
    */
    public $maxCountChannels;

    /**
    * @Column(type="integer", nullable=false, default="1")
    */
    public $isCallback = 0;

    /**
     *
     * @Column(type="string", nullable=true, default="0")
     */
    public $state;

    /**
     *
     * @Column(type="string", nullable=true, default="")
     */
    public $dialPrefix;

    /**
     * Returns dynamic relations between module models and common models
     * @param $calledModelObject
     *
     * @return void
     */
    public static function getDynamicRelations(&$calledModelObject): void
    {
    }

    public function initialize(): void
    {
        $this->setSource('m_Tasks');
        parent::initialize();
    }
}