"use strict";

/*
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Nikolay Beketov, 11 2018
 *
 */
var idUrl = 'module-auto-dialer';
var idForm = 'extension-form';
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
    ModuleAutoDialer.initializeForm();
    $('.menu .item').tab();
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
  cbAfterSendForm: function cbAfterSendForm(response) {
    if (response.success) {
      $('input[name="id"]').val(response.id);
      var newUrl = baseUrl + '/admin-cabinet/module-auto-dialer/modifyExtension/' + response.id;
      window.history.pushState({
        path: newUrl
      }, '', newUrl);
    }

    Extensions.cbOnDataChanged();
  },

  /**
   * Initialize form parameters
   */
  initializeForm: function initializeForm() {
    Form.$formObj = ModuleAutoDialer.$formObj;
    Form.url = "".concat(globalRootUrl).concat(idUrl, "/saveExtension");
    Form.validateRules = ModuleAutoDialer.validateRules;
    Form.cbBeforeSendForm = ModuleAutoDialer.cbBeforeSendForm;
    Form.cbAfterSendForm = ModuleAutoDialer.cbAfterSendForm;
    Form.initialize();
  }
};
$(document).ready(function () {
  ModuleAutoDialer.initialize();
});
//# sourceMappingURL=module-auto-dialer-modify-extension.js.map