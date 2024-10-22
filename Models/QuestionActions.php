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
 * Class QuestionActions
 *
 * @package Modules\ModuleAutoDialer\Models
 * @Indexes(
 *     [name='questionId', columns=['questionId'], type=''],
 *     [name='pollingId', columns=['pollingId'], type='']
 * )
 */
class QuestionActions extends ModulesModelsBase
{
    public const ACTION_NONE    = '';
    public const ACTION_DIAL    = 'dial';
    public const ACTION_ANSWER  = 'answer';
    public const ACTION_PLAYBACK= 'playback';
    public const ACTION_PLAYBACK_RECORD = 'playback_record';
    public const ACTION_PLAYBACK_FILE = 'file';
    public const ACTION_PLAYBACK_TEXT = 'text';

    /**
     * Идентификатор задачи.
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     *
     * @Column(type="string", nullable=false)
     */
    public $questionId;

    /**
     *
     * @Column(type="string", nullable=false)
     */
    public $pollingId;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $key;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $action;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $value;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $valueOptions;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $nextQuestion;

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
        $this->setSource('m_QuestionActions');
        parent::initialize();
    }
}