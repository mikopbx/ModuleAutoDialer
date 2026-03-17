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

namespace Modules\ModuleAutoDialer\App\Forms;

use Modules\ModuleAutoDialer\Models\ModuleAutoDialer;
use Phalcon\Forms\Element\Select;
use Phalcon\Forms\Element\TextArea;
use Phalcon\Forms\Form;
use Phalcon\Forms\Element\Text;


class ModuleAutoDialerForm extends Form
{
    public function initialize($entity = null, $options = null) :void
    {
        $this->add(new Text('defDialPrefix'));
        $this->add(new Text('yandexApiKey'));
        $this->add(new Text('yandexFolderId'));
        $this->add(new TextArea('callbackAlertText'));

        $arrConnType = [
            ModuleAutoDialer::TTS_MODEL_YANDEX => 'Yandex TTS',
            ModuleAutoDialer::TTS_MODEL_RH_VOICE => 'RH Voice',
        ];
        $library = new Select(
            'ttsService',
            $arrConnType,
            [
                'using'    => [
                    'id',
                    'name',
                ],
                'useEmpty' => false,
                'value'    => $entity->ttsService,
                'class'    => 'ui selection dropdown library-type-select',
            ]
        );
        $this->add($library);
    }
}