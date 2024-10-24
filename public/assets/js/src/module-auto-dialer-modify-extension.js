/*
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Nikolay Beketov, 11 2018
 *
 */
const idUrl     = 'module-auto-dialer';
const idForm    = 'extension-form';
let baseUrl = window.location.protocol + '//' + window.location.hostname;
if (window.location.port) {
	baseUrl += ':' + window.location.port;
}
/* global globalRootUrl, globalTranslate, Form, Config */
const ModuleAutoDialer = {
	$formObj: $('#'+idForm),
	$checkBoxes: $('#'+idForm+' .ui.checkbox'),
	$dropDowns: $('#'+idForm+' .ui.dropdown'),
	/**
	 * Field validation rules
	 * https://semantic-ui.com/behaviors/form.html
	 */
	validateRules: {
	},
	/**
	 * On page load we init some Semantic UI library
	 */
	initialize() {
		$('#content-frame').removeClass('segment');
		$('.ui.accordion').accordion();
		// инициализируем чекбоксы и выподающие менюшки
		ModuleAutoDialer.$checkBoxes.checkbox();
		ModuleAutoDialer.$dropDowns.dropdown();
		ModuleAutoDialer.initializeForm();
		$('.menu .item').tab();
	},

	calculatePageLength() {
		let rowHeight = ModuleAutoDialer.$pollingTable.find('tbody > tr').first().outerHeight();
		const windowHeight = window.innerHeight;
		const headerFooterHeight = 400 ;
		return Math.max(Math.floor((windowHeight - headerFooterHeight) / rowHeight), 5);
	},

	/**
	 * We can modify some data before form send
	 * @param settings
	 * @returns {*}
	 */
	cbBeforeSendForm(settings) {
		const result = settings;
		result.data = ModuleAutoDialer.$formObj.form('get values');
		return result;
	},
	/**
	 * Some actions after forms send
	 */
	cbAfterSendForm(response) {
		if(response.success){
			$('input[name="id"]').val(response.id);
			let newUrl = baseUrl + '/admin-cabinet/module-auto-dialer/modifyExtension/'+response.id;
			window.history.pushState({path: newUrl}, '', newUrl);
		}
		Extensions.cbOnDataChanged();
	},
	/**
	 * Initialize form parameters
	 */
	initializeForm() {
		Form.$formObj = ModuleAutoDialer.$formObj;
		Form.url = `${globalRootUrl}${idUrl}/saveExtension`;
		Form.validateRules = ModuleAutoDialer.validateRules;
		Form.cbBeforeSendForm = ModuleAutoDialer.cbBeforeSendForm;
		Form.cbAfterSendForm = ModuleAutoDialer.cbAfterSendForm;
		Form.initialize();
	}
};

$(document).ready(() => {
	ModuleAutoDialer.initialize();
});

