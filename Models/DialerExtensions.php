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

use MikoPBX\Common\Models\Extensions;
use MikoPBX\Modules\Models\ModulesModelsBase;
use Phalcon\Mvc\Model\Relation;

/**
 * Class DialerExtensions
 *
 * @package Modules\ModuleAutoDialer\Models
 * @Indexes(
 *     [name='exten', columns=['exten'], type='']
 * )
 */
class DialerExtensions extends ModulesModelsBase
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
    public $exten;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $name;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $pollingIdOK;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $pollingIdFAIL;

    /**
     * Returns dynamic relations between module models and common models
     * @param $calledModelObject
     *
     * @return void
     */
    public static function getDynamicRelations(&$calledModelObject): void
    {
        if (is_a($calledModelObject, Extensions::class)) {
            $calledModelObject->belongsTo(
                'number',
                __CLASS__,
                'exten',
                [
                    'alias'      => 'ModuleAutoDialer',
                    'foreignKey' => [
                        'allowNulls' => 0,
                        'message'    => 'ModuleAutoDialer',
                        'action'     => Relation::NO_ACTION,
                    ],
                ]
            );
        }
    }

    public function initialize(): void
    {
        $this->setSource('m_DialerExtensions');
        parent::initialize();
    }
}