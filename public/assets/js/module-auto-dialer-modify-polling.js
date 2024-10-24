"use strict";

/*
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Nikolay Beketov, 11 2018
 *
 */
var idUrl = 'module-auto-dialer';
var idForm = 'poling-form';
var baseUrl = window.location.protocol + '//' + window.location.hostname;

if (window.location.port) {
  baseUrl += ':' + window.location.port;
}
/* global globalRootUrl, globalTranslate, Form, Config */


var ModuleAutoDialer = {
  $formObj: $('#' + idForm),
  $checkBoxes: $('#' + idForm + ' .ui.checkbox'),
  $dropDowns: $('#' + idForm + ' .ui.dropdown'),

  /**
   * Field validation rules
   * https://semantic-ui.com/behaviors/form.html
   */
  validateRules: {},

  /**
   * On page load we init some Semantic UI library
   */
  initialize: function initialize() {
    $('#content-frame').removeClass('segment');
    $('.ui.accordion').accordion(); // инициализируем чекбоксы и выподающие менюшки

    ModuleAutoDialer.$checkBoxes.checkbox();
    ModuleAutoDialer.$dropDowns.dropdown();
    $("div.dropdown.press").dropdown({
      onChange: function onChange(value, text, choice) {
        var val = choice.closest('div.dropdown.press').dropdown('get value');

        if (val === 'answer') {
          choice.closest('div.press-section').find('[data-key="' + choice.closest('div.press-section').attr('data-key') + '"]').hide();
        } else {
          choice.closest('div.press-section').find('[data-key="' + choice.closest('div.press-section').attr('data-key') + '"]').show();
        }
      }
    });
    $("div.dropdown.press").each(function (index, element) {
      var val = $(element).dropdown('get value');

      if (val === 'answer') {
        $(element).closest('div.press-section').find('[data-key="' + $(element).closest('div.press-section').attr('data-key') + '"]').hide();
      } else {
        $(element).closest('div.press-section').find('[data-key="' + $(element).closest('div.press-section').attr('data-key') + '"]').show();
      }
    });
    ModuleAutoDialer.initializeForm();
    $('.menu .item').tab();
    $(document).on('click', 'a.delete', ModuleAutoDialer.deletePollingRowClick);
    $('#button-add-question').on('click', ModuleAutoDialer.addQuestion);
    $(document).on('click', 'div.ui.segment button[data-type="up"]', function () {
      var currentSegment = $(this).closest('div.ui.segment');
      var previousSegment = currentSegment.prev('div.ui.segment');

      if (previousSegment.length) {
        currentSegment.insertBefore(previousSegment);
      }

      $('input[name="change-signal"]').val(new Date()).trigger('change');
      ;
    });
    $(document).on('click', 'div.ui.segment button[data-type="down"]', function () {
      var currentSegment = $(this).closest('div.ui.segment');
      var nextSegment = currentSegment.next('div.ui.segment');

      if (nextSegment.length) {
        currentSegment.insertAfter(nextSegment);
      }

      $('input[name="change-signal"]').val(new Date()).trigger('change');
      ;
    });
    $(document).on('click', 'div.ui.segment button[data-type="remove"]', function () {
      $(this).closest('div.ui.segment').remove();
      $('input[name="change-signal"]').val(new Date()).trigger('change');
      ;
    });
    $('#submitbutton').off('click').on('click', function (event) {
      event.preventDefault();
      $.ajax({
        url: baseUrl + '/pbxcore/api/module-dialer/v1/polling',
        type: 'POST',
        dataType: 'json',
        contentType: 'application/json',
        data: JSON.stringify(ModuleAutoDialer.transformObject(ModuleAutoDialer.$formObj.form('get values'))),
        success: function success(response) {
          console.log('Успех:', response);

          if (response.result) {
            Form.initializeDirrity();
            $('input[name="id"]').val(response.data.id);
            var newUrl = baseUrl + '/admin-cabinet/module-auto-dialer/modifyPolling/' + response.data.id;
            window.history.pushState({
              path: newUrl
            }, '', newUrl);
          }
        },
        error: function error(xhr, status, _error) {
          console.log('Ошибка:', _error);
        }
      });
    });
  },
  addQuestion: function addQuestion() {
    var id = 1;
    var stringId = id.toString().padStart(9, '0');

    while ($('textarea[name="questionText-' + stringId + '"]').length > 0) {
      id++;
      stringId = id.toString().padStart(9, '0');
    }

    var templateHtml = $('div[data-is-template="1"]').html().replaceAll('000000000', stringId);
    var newElement = $('<div class="ui segment" data-is-template="0"></div>').html(templateHtml);
    $('div.ui.form').append(newElement);
    $('.ui.accordion').accordion();
    $('#' + idForm + ' .ui.dropdown').dropdown();
    $('input[name="change-signal"]').val(new Date()).trigger('change');
    ModuleAutoDialer.$formObj.form();
  },
  transformObject: function transformObject(input) {
    // Начальный объект
    var result = {
      id: input.id,
      crmId: parseInt(input.id, 10),
      name: input.name,
      questions: []
    }; // Перебираем все свойства объекта input

    Object.keys(input).forEach(function (key) {
      var segments = $('div.ui.segment[data-is-template="0"]');
      var keyMatch = key.match(/^questionText-(\d+)$/); // Проверяем, является ли ключ частью вопроса

      if (keyMatch) {
        var questionId = keyMatch[1];
        var questionIndex = '';
        var targetTextarea = $('textarea[name="questionText-' + questionId + '"]');
        var parentSegment = targetTextarea.closest('div.ui.segment[data-is-template="0"]');

        if (parentSegment.length > 0) {
          questionIndex = segments.index(parentSegment);
        }

        if (questionIndex === '') {
          return;
        }

        var nextQuestionIndex = questionIndex + 1;

        if (segments.length <= nextQuestionIndex) {
          nextQuestionIndex = '';
        }

        var question = {
          questionId: questionIndex,
          questionText: input["questionText-".concat(questionId)],
          defPress: input["defPress-".concat(questionId)] || "",
          timeout: parseInt(input["timeout-".concat(questionId)], 10),
          press: []
        }; // Ищем кнопки press-0 и press-1 для каждого вопроса

        for (var i = 0; i < 2; i++) {
          var actionKey = "".concat(questionId, "-press-").concat(i, "-action");
          var valueKey = "".concat(questionId, "-press-").concat(i, "-value");
          var valueOptionsKey = "".concat(questionId, "-press-").concat(i, "-valueOptions");

          if (input[actionKey]) {
            var press = {
              key: i.toString(),
              action: input[actionKey],
              nextQuestion: nextQuestionIndex
            }; // Добавляем значения value и valueOptions, если они существуют

            if (input[valueKey]) press.value = input[valueKey];
            if (input[valueOptionsKey]) press.valueOptions = input[valueOptionsKey]; // Добавляем press в массив press текущего вопроса

            question.press.push(press);
          }
        } // Добавляем вопрос в массив questions


        result.questions.push(question);
      }
    });
    result.questions.sort(function (a, b) {
      return a.questionId - b.questionId;
    });
    return result;
  },
  deletePollingRowClick: function deletePollingRowClick(e) {
    e.preventDefault();
    var linkElement = $(this);
    $.ajax({
      url: linkElement.attr('href'),
      type: 'DELETE',
      dataType: 'json',
      success: function success(response) {
        if (response.result) {
          linkElement.closest('tr').remove();
        }
      },
      error: function error(xhr, status, _error2) {
        console.error("Ошибка при удалении: " + _error2);
      }
    });
  },
  calculatePageLength: function calculatePageLength() {
    var rowHeight = ModuleAutoDialer.$pollingTable.find('tbody > tr').first().outerHeight();
    var windowHeight = window.innerHeight;
    var headerFooterHeight = 400;
    return Math.max(Math.floor((windowHeight - headerFooterHeight) / rowHeight), 5);
  },

  /**
   * We can modify some data before form send
   * @param settings
   * @returns {*}
   */
  cbBeforeSendForm: function cbBeforeSendForm(settings) {
    var result = settings;
    result.data = ModuleAutoDialer.$formObj.form('get values');
    return result;
  },

  /**
   * Some actions after forms send
   */
  cbAfterSendForm: function cbAfterSendForm() {//
  },

  /**
   * Initialize form parameters
   */
  initializeForm: function initializeForm() {
    Form.$formObj = ModuleAutoDialer.$formObj;
    Form.url = "".concat(globalRootUrl).concat(idUrl, "/save");
    Form.validateRules = ModuleAutoDialer.validateRules;
    Form.cbBeforeSendForm = ModuleAutoDialer.cbBeforeSendForm;
    Form.cbAfterSendForm = ModuleAutoDialer.cbAfterSendForm;
    Form.initialize();
  }
};
$(document).ready(function () {
  ModuleAutoDialer.initialize();
});
//# sourceMappingURL=module-auto-dialer-modify-polling.js.map