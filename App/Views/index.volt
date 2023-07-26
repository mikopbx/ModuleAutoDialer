<form class="ui large grey segment form" id="module-auto-dialer-form">
    <div class="eight wide field">
        <label>{{ t._('mod_AutoDialer_defDialPrefix') }}</label>
        {{ form.render('defDialPrefix') }}
    </div>
    {{ partial("partials/submitbutton",['indexurl':'pbx-extension-modules/index/']) }}
</form>