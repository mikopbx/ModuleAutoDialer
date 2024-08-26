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