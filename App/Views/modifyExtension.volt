<form class="ui mini grey form" id="extension-form">
    {{ form.render('id') }}
    <div class="ui form">
      <div class="eight wide field">
          <label>{{ t._('mod_AutoDialer_ExtenTableNumber') }}</label>
          {{ form.render('exten') }}
      </div>
      <div class="eight wide field">
          <label>{{ t._('mod_AutoDialer_PollingTableName') }}</label>
          {{ form.render('name') }}
      </div>
      <div class="field">
          <label>{{ t._('mod_AutoDialer_ExtenTablePollingIdOK') }}</label>
          {{ form.render('pollingIdOK') }}
      </div>
      <div class="field">
          <label>{{ t._('mod_AutoDialer_ExtenTablePollingIdFAIL') }}</label>
          {{ form.render('pollingIdFAIL') }}
      </div>
    </div>
    <br>
    <br>
    {{ partial("partials/submitbutton",['indexurl':'module-auto-dialer/index/#extension']) }}
</form>
