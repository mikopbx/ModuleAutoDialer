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

use MikoPBX\Common\Models\Providers;
use MikoPBX\Modules\Models\ModulesModelsBase;
use Phalcon\Mvc\Model\Relation;

/**
 * Class ModuleCdrCallTags
 *
 * @package Modules\ModuleAutoDialer\Models
 * @Indexes(
 *     [name='phoneId', columns=['phoneId'], type=''],
 *     [name='state', columns=['state'], type=''],
 *     [name='closeTime', columns=['closeTime'], type=''],
 *     [name='timeCallAllow', columns=['timeCallAllow'], type=''],
 *     [name='taskId', columns=['taskId'], type='']
 * )
 */
class TaskResults extends ModulesModelsBase
{
    /**
     * Идентификатор записи.
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * Идентификатор задачи.
     * @Column(type="integer", nullable=false)
     */
    public $taskId;
    
    /**
     * Индекс номера телефона.
     * @Column(type="string", nullable=false)
     */
    public $phoneId;

    /**
     * Номер телефона.
     * @Column(type="string", nullable=true)
     */
    public $phone;

    /**
     * Индекс номера телефона.
     * @Column(type="string", nullable=true)
     */
    public $linkedId;

    /**
     * Имя call файла
     * @Column(type="string", nullable=true)
     */
    public $callFile;

    /**
     * Итоговый статус звонка
     * @Column(type="string", nullable=true)
     */
    public $result = "";

    /**
     * Статус Dial на внешний номер;
     * @Column(type="string", nullable=true)
     */
    public $outDialState;

    /**
     * Статус Dial на внутренний номер;
     * @Column(type="string", nullable=true)
     */
    public $inDialState;

    /**
     * Текущее состояние.
     * @Column(type="string", nullable=true)
     */
    public $state;

    /**
     * Идентификатор лога для отладки.
     * @Column(type="string", nullable=true)
     */
    public $verboseCallId;

    /**
     * Причина завершения вызова.
     * @Column(type="string", nullable=true)
     */
    public $cause;

    /**
     * Время модификации.
     * @Column(type="integer", nullable=true)
     */
    public $changeTime;

    /**
     * Номер попытки.
     * @Column(type="integer", nullable=true)
     */
    public $countTry;

    /**
     * Начиная с этого timestamp вызов будет разрешен.
     * @Column(type="integer", nullable=true)
     */
    public $timeCallAllow;

    /**
     * Время завершения обарботки.
     * @Column(type="integer", nullable=true)
     */
    public $closeTime;

    /**
     * Returns dynamic relations between module models and common models
     * @param $calledModelObject
     *
     * @return void
     */
    public static function getDynamicRelations(&$calledModelObject): void
    {
    }

    /**
     * @return void
     */
    public function initialize(): void
    {
        $this->setSource('m_TaskResults');
        parent::initialize();
    }
}