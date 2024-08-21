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
 * Class Question
 *
 * @package Modules\ModuleAutoDialer\Models
 * @Indexes(
 *     [name='crmId', columns=['crmId'], type=''],
 *     [name='pollingId', columns=['pollingId'], type='']
 * )
 */
class Question extends ModulesModelsBase
{
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
    public $pollingId;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $questionText;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $questionFile;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $crmId;

    /**
     *
     * @Column(type="string", nullable=true, default="ru-RU")
     */
    public $lang = 'ru-RU';

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
        $this->setSource('m_Question');
        parent::initialize();
    }
}