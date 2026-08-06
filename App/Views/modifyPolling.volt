<form class="ui mini grey form" id="poling-form">

<div class="ui form">
    <button class="ui primary button" id="button-add-question">{{ t._('mod_AutoDialer_AddQuestion') }} </button>
    <input type="hidden" value="" name="change-signal">
    <br><br>
    <div class="field">
        <label>{{ t._('mod_AutoDialer_NamePolling') }} </label>
        <input type="text" name="name" value="{{name}}">
        <input type="hidden" value="{{pollingId}}" name="id">
    </div>
    {% for questionId,question in questions %}

    {% if question['template'] == '1' %}
    <div class="ui segment" style="display: none;" data-is-template="{{question['template']}}">
    {% else %}
    <div class="ui segment" data-is-template="{{question['template']}}" data-question-id="{{question['id']}}">
    {% endif %}
        <div class="field">
            <label>{{ t._('mod_AutoDialer_questionText') }}</label>
            <textarea rows="2" name="questionText-{{question['id']}}" >{{ question['questionText'] }}</textarea>
        </div>
        <div class="field">
            <div class="ui toggle checkbox confirmation-toggle">
                <input type="checkbox" name="type-{{question['id']}}" value="confirmation" {% if question['type'] == 'confirmation' %}checked{% endif %}>
                <label>{{ t._('mod_AutoDialer_confirmationType') }}</label>
            </div>
        </div>
        <div class="ui styled fluid accordion">
          <div class="title">
            <i class="dropdown icon"></i>
            {{ t._('mod_AutoDialer_questionOptionsTitle') }}
          </div>
          <div class="content">
            <div class="fields">
                <div class="four wide field">
                    <label>{{ t._('mod_AutoDialer_timeoutWaitTime') }}</label>
                    <input type="text" name="timeout-{{question['id']}}" value="{{ question['timeout'] }}" placeholder="">
                </div>
                <div class="four wide field">
                    <label>{{ t._('mod_AutoDialer_defPress') }}</label>
                    <div class="ui selection dropdown press">
                      <input type="hidden" value="{{ question['defPress'] }}" name="defPress-{{question['id']}}">
                      <i class="dropdown icon"></i>
                      {% if question['defPress'] == '1' %}
                      <div class="text">1</div>
                      <div class="menu">
                          <div class="item" data-value="">-</div>
                          <div class="item active selected" data-value="1">1</div>
                          <div class="item" data-value="0">0</div>
                      </div>
                      {% elseif question['defPress'] == '0' %}
                      <div class="text">0</div>
                        <div class="menu">
                            <div class="item" data-value="">-</div>
                            <div class="item" data-value="1">1</div>
                            <div class="item active selected" data-value="0">0</div>
                        </div>
                      {% else %}
                      <div class="text">0</div>
                      <div class="menu">
                          <div class="item active selected" data-value="">-</div>
                          <div class="item" data-value="1">1</div>
                          <div class="item" data-value="0">0</div>
                      </div>
                      {% endif %}
                    </div>
                </div>
            </div>
            {% for index,press in question['press'] %}
            {% if index > 0 %}
            <div class="ui divider"></div>
            {% endif %}
            <div class="press-section" data-key="{{press['key']}}">
                <div class="fields">
                    <div class="field">
                        <label><strong>{{ t._('mod_AutoDialer_Press') }} {{press['key']}}</strong></label>
                        <div class="ui selection dropdown press">
                          <input type="hidden" value="{{press['action']}}" name="{{question['id']}}-press-{{press['key']}}-action">
                          <i class="dropdown icon"></i>
                          {% if press['action'] == 'restart' %}
                          <div class="text">{{ t._('mod_AutoDialer_restart') }}</div>
                          {% elseif press['action'] == 'playback_record' %}
                          <div class="text">{{ t._('mod_AutoDialer_playback_record') }}</div>
                          {% elseif press['action'] == 'send_crm' %}
                          <div class="text">{{ t._('mod_AutoDialer_sendCrm') }}</div>
                          {% else %}
                          <div class="text">{{ t._('mod_AutoDialer_answer') }}</div>
                          {% endif %}
                          <div class="menu">
                              <div class="item{% if press['action'] == 'answer' %} active selected{% endif %}" data-value="answer">{{ t._('mod_AutoDialer_answer') }}</div>
                              <div class="item{% if press['action'] == 'playback_record' %} active selected{% endif %}" data-value="playback_record">{{ t._('mod_AutoDialer_playback_record') }}</div>
                              <div class="item{% if press['action'] == 'restart' %} active selected{% endif %}" data-value="restart">{{ t._('mod_AutoDialer_restart') }}</div>
                              <div class="item{% if press['action'] == 'send_crm' %} active selected{% endif %}" data-value="send_crm">{{ t._('mod_AutoDialer_sendCrm') }}</div>
                          </div>
                        </div>
                    </div>
                    <div class="four wide field" data-key="{{press['key']}}">
                      <label>{{ t._('mod_AutoDialer_PressValueOptions') }}</label>
                      <input type="text" placeholder="" value="{{press['valueOptions']}}" name="{{question['id']}}-press-{{press['key']}}-valueOptions">
                    </div>
                </div>
                <div class="field" data-key="{{press['key']}}">
                    <label>{{ t._('mod_AutoDialer_PressValue') }}</label>
                    <textarea rows="2" value="{{press['value']}}" name="{{question['id']}}-press-{{press['key']}}-value" >{{press['value']}}</textarea>
                </div>
                <div class="inline fields" data-key="{{press['key']}}">
                    <div class="field">
                        <div class="ui toggle checkbox needrecognize-toggle">
                            <input type="checkbox" name="{{question['id']}}-press-{{press['key']}}-needRecognize" value="1" {% if press['needRecognize'] == '1' %}checked{% endif %}>
                            <label>{{ t._('mod_AutoDialer_needRecognize') }}</label>
                        </div>
                    </div>
                    <div class="field recognize-label-field">
                        <input type="text" placeholder="{{ t._('mod_AutoDialer_recognizeLabelPlaceholder') }}" value="{{press['recognizeLabel']}}" name="{{question['id']}}-press-{{press['key']}}-recognizeLabel">
                    </div>
                </div>
                <div class="field crm-template-field" data-key="{{press['key']}}">
                    <label>{{ t._('mod_AutoDialer_crmResponseTemplate') }}</label>
                    <textarea rows="2" placeholder="{{ t._('mod_AutoDialer_crmResponseTemplatePlaceholder') }}" name="{{question['id']}}-press-{{press['key']}}-crmResponseTemplate">{{press['crmResponseTemplate']}}</textarea>
                </div>
            </div>
            {% endfor %}

          </div>
        </div>
        <div class="ui mini basic icon buttons" style="margin-top: 5px;">
          <button class="ui mini button" data-type="up"><i class="angle double up blue icon"></i></button>
          <button class="ui mini button" data-type="down"><i class="angle double down blue icon"></i></button>
          <button class="ui mini button" data-type="remove"><i class="trash red icon"></i></button>
        </div>
    </div>
    {% endfor %}
</div>
<br>
    {{ partial("partials/submitbutton",['indexurl':'module-auto-dialer/index/']) }}
</form>
