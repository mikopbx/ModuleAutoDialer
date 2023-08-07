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
 *     [name='phoneId', columns=['phoneId'], type=''],
 *     [name='changeTime', columns=['changeTime'], type=''],
 *     [name='taskId', columns=['taskId'], type='']
 * )
 */
class PolingResults extends ModulesModelsBase
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
     * Идентификатор вопроса
     * @Column(type="string", nullable=false)
     */
    public $questionCrmId;

    /**
     * Идентификатор опроса
     * @Column(type="string", nullable=false)
     */
    public $pollingId;

    /**
     * Индекс номера телефона.
     * @Column(type="string", nullable=false)
     */
    public $phoneId;

    /**
     * номера телефона.
     * @Column(type="string", nullable=false)
     */
    public $phone;

    /**
     * Результат опроса
     * @Column(type="string", nullable=false)
     */
    public $result;

    /**
     * Введенный номер
     * @Column(type="string", nullable=true)
     */
    public $exten='';
    /**
     * Введенный номер
     * @Column(type="string", nullable=true)
     */
    public $changeTime;

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
        $this->setSource('m_PolingResults');
        parent::initialize();
    }
}