<div class="ui top attached tabular menu">
  <a class="active item" data-tab="polling">{{ t._('mod_AutoDialer_TabPolling') }}</a>
  <a class="item" data-tab="extension">{{ t._('mod_AutoDialer_TabExtensions') }}</a>
  <a class="item" data-tab="settings">{{ t._('mod_AutoDialer_TabSettings') }}</a>
</div>
<div class="ui bottom attached active tab segment" data-tab="polling">
  <button class="ui primary button" id="button-add">{{ t._('mod_AutoDialer_AddQuestion') }} </button>
  <br>
  <br>

  <table id="polling-table" data-report-name="polling" class="ui small very compact single line unstackable celled striped table ">
   <thead>
   <tr>
       <th class="one wide">crmId</th>
       <th class="one three">id</th>
       <th class="eleven wide">{{ t._('mod_AutoDialer_PollingTableName') }}</th>
       <th class="one wide"></th>
   </tr>
   </thead>
   <tbody>
   <tr>
       <td colspan="5" class="dataTables_empty">{{ t._('dt_TableIsEmpty') }}</td>
   </tr>
   </tbody>
  </table>
</div>
<div class="ui bottom attached tab segment" data-tab="extension">
    <button class="ui primary button" id="button-exten-add">{{ t._('mod_AutoDialer_AddExtension') }} </button>
      <br>
      <br>
      <table id="extensions-table" data-report-name="polling" class="ui small very compact single line unstackable celled striped table ">
       <thead>
       <tr>
           <th class="one wide">{{ t._('mod_AutoDialer_ExtenTableNumber') }}</th>
           <th class="one wide">{{ t._('mod_AutoDialer_PollingTableName') }}</th>
           <th class="six wide">{{ t._('mod_AutoDialer_ExtenTablePollingIdOK') }}</th>
           <th class="six wide">{{ t._('mod_AutoDialer_ExtenTablePollingIdFAIL') }}</th>
           <th class="one wide"></th>
       </tr>
       </thead>
       <tbody>
       {% for extension in extensions %}
       <tr data-exten-id="{{extension['id']}}">
           <th class="one wide">{{ extension['exten'] }}</th>
           <th class="one three">{{ extension['name'] }}</th>
           <th class="six three">{{ extension['pollingIdOKName'] }}</th>
           <th class="six wide">{{ extension['pollingIdFAILName'] }}</th>
           <th class="one wide">
               <div class="ui basic icon buttons action-buttons tiny">
                 <a href="/admin-cabinet/module-auto-dialer/modifyExtension/{{extension['id']}}" class="ui button edit popuped" data-content=""><i class="icon edit blue"></i> </a>
                 <a href="/admin-cabinet/module-auto-dialer/deleteExtension/{{extension['id']}}" class="ui button delete two-steps-delete popuped" data-content=""><i class="icon trash red"></i> </a>
               </div>
           </th>
       </tr>
       {% else %}
       <tr>
           <td colspan="5" class="dataTables_empty">{{ t._('dt_TableIsEmpty') }}</td>
       </tr>
       {% endfor %}

       </tbody>
   </table>
</div>
<div class="ui bottom attached tab segment" data-tab="settings">
  <form class="ui large grey segment form" id="module-auto-dialer-form">
      <div class="eight wide field">
          <label>{{ t._('mod_AutoDialer_defDialPrefix') }}</label>
          {{ form.render('defDialPrefix') }}
      </div>
      <div class="eight wide field">
          <label>{{ t._('mod_AutoDialer_yandexApiKey') }}</label>
          {{ form.render('yandexApiKey') }}
      </div>
      {{ partial("partials/submitbutton",['indexurl':'pbx-extension-modules/index/']) }}
  </form>
</div>





