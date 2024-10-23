/*
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Nikolay Beketov, 11 2018
 *
 */
const idUrl     = 'module-auto-dialer';
const idForm    = 'poling-form';
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
		$(document).on('click', 'a.delete', ModuleAutoDialer.deletePollingRowClick);
		$('#button-add-question').on('click', ModuleAutoDialer.addQuestion);

		$(document).on('click', 'div.ui.segment button[data-type="up"]', function() {
			let currentSegment = $(this).closest('div.ui.segment');
			let previousSegment = currentSegment.prev('div.ui.segment');
			if (previousSegment.length) {
				currentSegment.insertBefore(previousSegment);
			}
			$('input[name="change-signal"]').val(new Date()).trigger('change');;
		});
		$(document).on('click', 'div.ui.segment button[data-type="down"]', function() {
			let currentSegment = $(this).closest('div.ui.segment');
			let nextSegment = currentSegment.next('div.ui.segment');
			if (nextSegment.length) {
				currentSegment.insertAfter(nextSegment);
			}
			$('input[name="change-signal"]').val(new Date()).trigger('change');;

		});
		$(document).on('click', 'div.ui.segment button[data-type="remove"]', function() {
			$(this).closest('div.ui.segment').remove();
			$('input[name="change-signal"]').val(new Date()).trigger('change');;
		});

		$('#submitbutton').off('click').on('click', function(event) {
			event.preventDefault();
			$.ajax({
				url: baseUrl+ '/pbxcore/api/module-dialer/v1/polling',
				type: 'POST',
				dataType: 'json',
				contentType: 'application/json',
				data: JSON.stringify(ModuleAutoDialer.transformObject(ModuleAutoDialer.$formObj.form('get values'))),
				success: function(response) {
					console.log('Успех:', response);
					if(response.result){
						Form.initializeDirrity();
						$('input[name="id"]').val(response.data.id);
						let newUrl = baseUrl + '/admin-cabinet/module-auto-dialer/modifyPolling/'+response.data.id;
						window.history.pushState({path: newUrl}, '', newUrl);
					}
				},
				error: function(xhr, status, error) {
					console.log('Ошибка:', error);
				}
			});
		});
	},

	addQuestion(){
		let id = 1;
		let stringId = id.toString().padStart(9, '0');
		while ($('textarea[name="questionText-' + stringId + '"]').length > 0) {
			id++;
			stringId = id.toString().padStart(9, '0');
		}
		let templateHtml = $('div[data-is-template="1"]').html().replaceAll('000000000',stringId);

		let newElement = $('<div class="ui segment" data-is-template="0"></div>').html(templateHtml);
		$('div.ui.form').append(newElement);
		$('.ui.accordion').accordion();
		$('#'+idForm+' .ui.dropdown').dropdown();

		$('input[name="change-signal"]').val(new Date()).trigger('change');
		ModuleAutoDialer.$formObj.form();
	},

	transformObject(input) {
		// Начальный объект
		const result = {
			id: input.id,
			crmId: parseInt(input.id, 10),
			name: input.name,
			questions: []
		};

		// Перебираем все свойства объекта input
		Object.keys(input).forEach(key => {
			const segments = $('div.ui.segment[data-is-template="0"]');
			const keyMatch = key.match(/^questionText-(\d+)$/);
			// Проверяем, является ли ключ частью вопроса
			if (keyMatch) {
				const questionId = keyMatch[1];
				let questionIndex = '';

				let targetTextarea = $('textarea[name="questionText-'+questionId+'"]');
				let parentSegment = targetTextarea.closest('div.ui.segment[data-is-template="0"]');
				if (parentSegment.length > 0) {
					questionIndex = segments.index(parentSegment);
				}
				if(questionIndex === ''){
					return;
				}

				let nextQuestionIndex = questionIndex+1;
				if(segments.length <= nextQuestionIndex){
					nextQuestionIndex =''
				}
				const question = {
					questionId: questionIndex,
					questionText: input[`questionText-${questionId}`],
					defPress: input[`defPress-${questionId}`] || "",
					timeout: parseInt(input[`timeout-${questionId}`], 10),
					press: []
				};
				// Ищем кнопки press-0 и press-1 для каждого вопроса
				for (let i = 0; i < 2; i++) {
					const actionKey = `${questionId}-press-${i}-action`;
					const valueKey = `${questionId}-press-${i}-value`;
					const valueOptionsKey = `${questionId}-press-${i}-valueOptions`;
					if (input[actionKey]) {
						const press = {
							key: i.toString(),
							action: input[actionKey],
							nextQuestion: nextQuestionIndex
						};
						// Добавляем значения value и valueOptions, если они существуют
						if (input[valueKey]) press.value = input[valueKey];
						if (input[valueOptionsKey]) press.valueOptions = input[valueOptionsKey];
						// Добавляем press в массив press текущего вопроса
						question.press.push(press);
					}
				}
				// Добавляем вопрос в массив questions
				result.questions.push(question);
			}
		});
		result.questions.sort((a, b) => a.questionId - b.questionId);
		return result;
	},

	deletePollingRowClick(e){
		e.preventDefault();
		let linkElement = $(this);

		$.ajax({
			url: linkElement.attr('href'),
			type: 'DELETE',
			dataType: 'json',
			success: function(response) {
				if (response.result) {
					linkElement.closest('tr').remove();
				}
			},
			error: function(xhr, status, error) {
				console.error("Ошибка при удалении: " + error);
			}
		});
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
	cbAfterSendForm() {
		//
	},
	/**
	 * Initialize form parameters
	 */
	initializeForm() {
		Form.$formObj = ModuleAutoDialer.$formObj;
		Form.url = `${globalRootUrl}${idUrl}/save`;
		Form.validateRules = ModuleAutoDialer.validateRules;
		Form.cbBeforeSendForm = ModuleAutoDialer.cbBeforeSendForm;
		Form.cbAfterSendForm = ModuleAutoDialer.cbAfterSendForm;
		Form.initialize();
	}
};

$(document).ready(() => {
	ModuleAutoDialer.initialize();
});

