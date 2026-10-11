<div class="card mb-4">
    <div class="card-body py-2">

        <ul class="nav nav-pills gap-2 mb-0">

            <li class="nav-item">
                <button
                    type="button"
                    class="nav-link active chatwoot-main-tab"
                    data-target="chatwoot-settings-pane"
                >
                    <i class="bi bi-gear me-1"></i>
                    {{ __('lynomia chat') }}
                </button>
            </li>

            <li class="nav-item">
                <button
                    type="button"
                    class="nav-link chatwoot-main-tab"
                    data-target="abandoned-carts-pane"
                >
                    <i class="bi bi-cart-x me-1"></i>
                  {{ __('chatwoot.abandoned_carts') }}
                </button>
            </li>

        </ul>

    </div>
</div>


<div
    id="chatwoot-settings-pane"
    class="chatwoot-main-pane"
    style="display: block;"
>






<div class="card">
    <div class="card-header">
        <h5>{{ __('chatwoot.integration') }}</h5>
    </div>

    <div class="card-body">
        <div class="row">

            <div class="col-md-12 mb-3">
                <label class="form-label">
                    {{ __('Chatwoot URL') }}
                </label>

                <input type="text"
                       class="form-control"
                       value="https://chat.lynomia.com"
                       readonly>
            </div>

            <div class="col-md-12 mb-3">
                <label class="form-label">
                 {{ __('chatwoot.account_id') }}
                </label>

                <input type="number"
                       id="chatwoot_account_id"
                       class="form-control"
                       value="{{ $integration->account_id ?? '' }}"
                       placeholder="{{ __('Enter Chatwoot Account ID') }}">
            </div>

            <div class="col-md-12 mb-3">
                <label class="form-label">
                    {{ __('API Access Token') }}
                </label>

                <input type="password"
                       id="chatwoot_api_token"
                       class="form-control"
                       placeholder="{{ $integration ? __('Token saved - leave blank to keep it') : __('Enter Chatwoot API Access Token') }}">
            </div>

            <div class="col-md-12">
                <button type="button"
                        class="btn btn-primary"
                        id="chatwoot-connect-btn">

                    {{ $integration ? __('Refresh Connection') : __('Connect Chatwoot') }}

                </button>
            </div>

            <div class="row mt-4"
                 id="chatwoot-inbox-section"
                 style="{{ $integration ? '' : 'display: none;' }}">

                <div class="col-md-12 mb-3">

                    <label class="form-label">
                        {{ __('WhatsApp Inbox') }}
                    </label>

                    <select id="chatwoot_inbox_id"
                            class="form-control">

                        <option value="">
                            {{ __('Select WhatsApp Inbox') }}
                        </option>

                    </select>

                </div>

                <div class="col-md-12">

                    <button type="button"
                            class="btn btn-success"
                            id="chatwoot-save-inbox-btn">

                        {{ __('Save Inbox') }}

                    </button>

                </div>

            </div>

        </div>
    </div>
</div>


@if($integration && $integration->inbox_id)

<div class="row mt-4">

    <div class="col-md-12">

        <div class="card">

            <div class="card-header">

                <h5>
                  {{ __('chatwoot.whatsapp_templates') }}
                </h5>

            </div>

            <div class="card-body">

                <div id="chatwoot-templates-loading">
                    {{ __('Loading templates...') }}
                </div>

                <div id="chatwoot-templates-list"></div>

            </div>

        </div>

    </div>

</div>


<div class="row mt-4">

    <div class="col-md-12">

        <div class="card">

            <div class="card-header">

                <h5>
                  {{ __('chatwoot.order_notification_templates') }}
                </h5>

            </div>

            <div class="card-body">

                <div class="alert alert-info">
{{ __('chatwoot.choose_template_for_status') }}

</div>

                <div id="chatwoot-event-template-settings">

                    <div class="text-muted">
                        {{ __('Loading notification settings...') }}
                    </div>

                </div>

            </div>

        </div>

    </div>

</div>

@endif




</div>


<div
    id="abandoned-carts-pane"
    class="chatwoot-main-pane"
    style="display: none;"
>

@include('setting.partials.abandoned-carts', [
    'carts' => $carts,
    'setting' => $abandonedCartSetting,
])
</div>








@if($integration)

<script>

$(document).ready(function () {

    $.ajax({

        url: '{{ route("chatwoot.inboxes") }}',

        type: 'GET',

        success: function(response) {

            if (!response.success) {
                return;
            }

            let select = $('#chatwoot_inbox_id');

            select.empty();

            select.append(
                '<option value="">{{ __("Select WhatsApp Inbox") }}</option>'
            );

            response.inboxes.forEach(function(inbox) {

                let label = inbox.name;

                if (inbox.phone_number) {
                    label += ' - ' + inbox.phone_number;
                }

                let selected =
                    parseInt(response.selected_inbox_id) === parseInt(inbox.id)
                    ? 'selected'
                    : '';

                select.append(
                    '<option value="' + inbox.id + '" ' + selected + '>' +
                    label +
                    '</option>'
                );

            });

            $('#chatwoot-inbox-section').show();

        },

        error: function(xhr) {

            console.log(xhr.responseText);

        }

    });

});

</script>

@endif


<script>

$(document).on('click', '#chatwoot-connect-btn', function () {

    let button = $(this);

    let accountId = $('#chatwoot_account_id').val();

    let token = $('#chatwoot_api_token').val();


    if (!accountId) {

        show_toastr(
            'Error',
            '{{ __("Account ID is required.") }}',
            'error'
        );

        return;

    }


    @if(!$integration)

    if (!token) {

        show_toastr(
            'Error',
            '{{ __("API Access Token is required.") }}',
            'error'
        );

        return;

    }

    @endif


    button.prop('disabled', true);

    button.text('{{ __("Connecting...") }}');


    $.ajax({

        url: '{{ route("chatwoot.connect") }}',

        type: 'POST',

        data: {

            _token: '{{ csrf_token() }}',

            account_id: accountId,

            api_access_token: token

        },

        success: function(response) {

            button.prop('disabled', false);

            button.text(
                '{{ $integration ? __("Refresh Connection") : __("Connect Chatwoot") }}'
            );


            if (!response.success) {
                return;
            }


            let select = $('#chatwoot_inbox_id');

            select.empty();

            select.append(
                '<option value="">{{ __("Select WhatsApp Inbox") }}</option>'
            );


            response.inboxes.forEach(function(inbox) {

                let label = inbox.name;

                if (inbox.phone_number) {
                    label += ' - ' + inbox.phone_number;
                }

                select.append(
                    '<option value="' + inbox.id + '">' +
                    label +
                    '</option>'
                );

            });


            $('#chatwoot-inbox-section').show();


            show_toastr(
                'Success',
                response.message,
                'success'
            );

        },

        error: function(xhr) {

            button.prop('disabled', false);

            button.text(
                '{{ $integration ? __("Refresh Connection") : __("Connect Chatwoot") }}'
            );


            let message = 'Connection failed.';


            if (
                xhr.responseJSON &&
                xhr.responseJSON.message
            ) {

                message = xhr.responseJSON.message;

            }


            show_toastr(
                'Error',
                message,
                'error'
            );

        }

    });

});

</script>


<script>

$(document).on('click', '#chatwoot-save-inbox-btn', function () {

    let inboxId = $('#chatwoot_inbox_id').val();


    if (!inboxId) {

        show_toastr(
            'Error',
            '{{ __("Please select a WhatsApp inbox.") }}',
            'error'
        );

        return;

    }


    let button = $(this);


    button.prop('disabled', true);


    $.ajax({

        url: '{{ route("chatwoot.inbox.save") }}',

        type: 'POST',

        data: {

            _token: '{{ csrf_token() }}',

            inbox_id: inboxId

        },

        success: function(response) {

            button.prop('disabled', false);


            show_toastr(
                'Success',
                response.message,
                'success'
            );


            location.reload();

        },

        error: function(xhr) {

            button.prop('disabled', false);


            let message = 'Unable to save inbox.';


            if (
                xhr.responseJSON &&
                xhr.responseJSON.message
            ) {

                message = xhr.responseJSON.message;

            }


            show_toastr(
                'Error',
                message,
                'error'
            );

        }

    });

});

</script>

@if($integration && $integration->inbox_id)

<script>

let chatwootTemplates = [];

/*
|--------------------------------------------------------------------------
| Variables available from our store/order system
|--------------------------------------------------------------------------
|
| لاحقاً بالـ Backend نحن نحول هذه المفاتيح للقيم الحقيقية من الطلب.
|
*/
const chatwootVariableOptions = [

    {
        key: 'customer_name',
       label: '{{ __("chatwoot.customer_name") }}'
    },

    {
        key: 'customer_phone',
        label: '{{ __("Customer Phone") }}'
    },

    {
        key: 'order_number',
        label: '{{ __("Order Number") }}'
    },

    {
        key: 'order_status',
        label: '{{ __("Order Status") }}'
    },

    {
        key: 'order_total',
        label: '{{ __("Order Total") }}'
    },

    {
        key: 'payment_status',
        label: '{{ __("Payment Status") }}'
    },

    {
        key: 'payment_type',
        label: '{{ __("Payment Type") }}'
    },

    {
        key: 'order_date',
        label: '{{ __("Order Date") }}'
    },

    {
        key: 'delivery_date',
        label: '{{ __("Delivery Date") }}'
    },

    {
        key: 'store_name',
        label: '{{ __("Store Name") }}'
    }

];




const abandonedCartVariableOptions = [

    {
        key: 'customer_name',
        label: '{{ __("Customer Name") }}'
    },

    {
        key: 'customer_phone',
        label: '{{ __("Customer Phone") }}'
    },

    {
        key: 'customer_email',
        label: '{{ __("Customer Email") }}'
    },

    {
        key: 'cart_total',
        label: '{{ __("Cart Total") }}'
    },

    {
        key: 'items_count',
        label: '{{ __("Items Count") }}'
    },

    {
        key: 'cart_last_activity',
        label: '{{ __("Cart Last Activity") }}'
    },

    {
        key: 'store_name',
        label: '{{ __("Store Name") }}'
    }

];





/*
|--------------------------------------------------------------------------
| Load templates
|--------------------------------------------------------------------------
*/

$(document).ready(function () {

    loadChatwootTemplates();

});


function loadChatwootTemplates()
{
    $.ajax({

        url: '{{ route("chatwoot.templates") }}',

        type: 'GET',

        success: function(response) {

            $('#chatwoot-templates-loading').hide();

            chatwootTemplates = response.templates || [];

            renderTemplateList();

            renderChatwootEventTemplates();
loadSavedNotificationSettings();
        },

        error: function(xhr) {

            $('#chatwoot-templates-loading').hide();

            let message = 'Unable to load templates.';

            if (
                xhr.responseJSON &&
                xhr.responseJSON.message
            ) {
                message = xhr.responseJSON.message;
            }

            $('#chatwoot-templates-list').html(
                '<div class="alert alert-danger">' +
                    escapeHtml(message) +
                '</div>'
            );

        }

    });
}


/*
|--------------------------------------------------------------------------
| Display Chatwoot templates
|--------------------------------------------------------------------------
*/

function renderTemplateList()
{
    let container = $('#chatwoot-templates-list');

    container.empty();


    if (
        !chatwootTemplates ||
        chatwootTemplates.length === 0
    ) {

        container.html(
            '<div class="alert alert-warning">' +
                '{{ __("No approved WhatsApp templates found.") }}' +
            '</div>'
        );

        return;
    }


    chatwootTemplates.forEach(function(template) {

        let body = '';
        let header = '';

        if (template.components) {

            let bodyComponent =
                template.components.find(function(component) {
                    return component.type === 'BODY';
                });


            let headerComponent =
                template.components.find(function(component) {
                    return component.type === 'HEADER';
                });


            if (bodyComponent) {
                body = bodyComponent.text || '';
            }

            if (headerComponent) {
                header = headerComponent.text || '';
            }
        }


        container.append(`

            <div class="card mb-3">

                <div class="card-body">

                    <h6>
                        ${escapeHtml(template.name || '')}
                    </h6>


                    <div class="mb-2">

                        <strong>
                            {{ __("Language") }}:
                        </strong>

                        ${escapeHtml(template.language || '')}

                    </div>


                    <div class="mb-2">

                        <strong>
                            {{ __("Category") }}:
                        </strong>

                        ${escapeHtml(template.category || '')}

                    </div>


                    ${
                        header
                        ? `
                            <div class="mb-2">

                                <strong>
                                    {{ __("Header") }}:
                                </strong>

                                ${escapeHtml(header)}

                            </div>
                        `
                        : ''
                    }


                    <div>

                        <strong>
                            {{ __("Body") }}:
                        </strong>

                        <br>

                        ${escapeHtml(body).replace(/\n/g, '<br>')}

                    </div>

                </div>

            </div>

        `);

    });
}


/*
|--------------------------------------------------------------------------
| Notification Events
|--------------------------------------------------------------------------
*/

function renderChatwootEventTemplates()
{
   const events = [

    {
        key: 'order_created',
        label: '{{ __("chatwoot.order_created") }}'
    },

    {
        key: 'order_status_changed',
        label: '{{ __("chatwoot.order_status_changed") }}'
    },

    {
        key: 'order_confirmed',
        label: '{{ __("chatwoot.order_confirmed") }}'
    },

    {
        key: 'order_picked_up',
        label: '{{ __("chatwoot.order_picked_up") }}'
    },

    {
        key: 'order_shipped',
        label: '{{ __("chatwoot.order_shipped") }}'
    },

    {
        key: 'order_delivered',
        label: '{{ __("chatwoot.order_delivered") }}'
    },

    {
        key: 'order_cancelled',
        label: '{{ __("chatwoot.order_cancelled") }}'
    },

    {
        key: 'return_requested',
        label: '{{ __("chatwoot.return_requested") }}'
    },

    {
        key: 'abandoned_cart',
        label: '{{ __("chatwoot.abandoned_cart_reminder") }}'
    }

];


    let container =
        $('#chatwoot-event-template-settings');


    container.empty();


    events.forEach(function(event) {

        let options = `

            <option value="">
                {{ __("No template selected") }}
            </option>

        `;


        chatwootTemplates.forEach(function(template) {

            let templateName =
                escapeHtml(template.name || '');

            let language =
                escapeHtml(template.language || '');

            let category =
                escapeHtml(template.category || '');


            options += `

                <option
                    value="${templateName}"
                    data-language="${language}"
                    data-category="${category}"
                >
                    ${templateName} (${language})
                </option>

            `;

        });


        container.append(`

            <div class="border rounded p-3 mb-3">

                <div class="row align-items-center">


                    <div class="col-md-4 mb-2">

                        <strong>
                            ${event.label}
                        </strong>

                        <div class="text-muted small">
                            ${event.key}
                        </div>

                    </div>


                    <div class="col-md-6 mb-2">

                        <select
                            class="form-control chatwoot-event-template"
                            data-event="${event.key}"
                        >

                            ${options}

                        </select>

                    </div>


                    <div class="col-md-2 mb-2">

                        <div class="form-check form-switch">

                            <input
                                class="form-check-input chatwoot-event-enabled"
                                type="checkbox"
                                data-event="${event.key}"
                                checked
                            >

                        </div>

                    </div>


                </div>


                <!--
                    هون رح تظهر Variables الخاصة
                    بالـ Template المختار
                -->
                <div
                    class="chatwoot-variable-mapping"
                    data-event="${event.key}"
                ></div>


            </div>

        `);

    });


    container.append(`

        <div class="text-end">

            <button
                type="button"
                class="btn btn-primary"
                id="chatwoot-save-template-settings"
            >

                {{ __("Save Notification Templates") }}

            </button>

        </div>

    `);
}


/*
|--------------------------------------------------------------------------
| Detect template variables
|--------------------------------------------------------------------------
|
| مثال:
|
| Hello {{1}}
| Order {{2}} is now {{3}}
|
| ترجع:
|
| ["1", "2", "3"]
|
*/

function getTemplateVariables(template)
{
    let variables = [];


    if (
        !template ||
        !template.components
    ) {
        return variables;
    }


    template.components.forEach(function(component) {

        let text = component.text || '';

        let matches =
            text.match(/\{\{(\d+)\}\}/g);


        if (!matches) {
            return;
        }


     matches.forEach(function(match) {

    let numberMatch = match.match(/\d+/);

    if (!numberMatch) {
        return;
    }

    let number = numberMatch[0];

    if (!variables.includes(number)) {
        variables.push(number);
    }

});
    });


    variables.sort(function(a, b) {
        return parseInt(a) - parseInt(b);
    });


    return variables;
}


/*
|--------------------------------------------------------------------------
| Render Variable Mapping
|--------------------------------------------------------------------------
*/

function renderVariableMapping(
    eventType,
    templateName
) {

    let container = $(
        '.chatwoot-variable-mapping[data-event="' +
        eventType +
        '"]'
    );


    container.empty();


    if (!templateName) {
        return;
    }


    let template =
        chatwootTemplates.find(function(item) {

            return item.name === templateName;

        });


    if (!template) {
        return;
    }


    let variables =
        getTemplateVariables(template);


    /*
     * Template doesn't contain {{1}}, {{2}}, ...
     */
    if (variables.length === 0) {

        container.html(`

            <div class="alert alert-light mt-3 mb-0">

                <small class="text-muted">

                    {{ __("This template has no variables.") }}

                </small>

            </div>

        `);

        return;
    }


    let html = `

        <div class="border rounded p-3 mt-3">

            <h6 class="mb-3">

                {{ __("Template Variables") }}

            </h6>


            <div class="alert alert-info py-2">

                {{ __('chatwoot.choose_template_for_status') }}

            </div>


            <div class="row">

    `;


    variables.forEach(function(variable) {

        let options = `

            <option value="">
                {{ __("Select variable") }}
            </option>

        `;


   let variableOptions =
    eventType === 'abandoned_cart'
        ? abandonedCartVariableOptions
        : chatwootVariableOptions;

variableOptions.forEach(function(item) {

    options += `

        <option value="${item.key}">
            ${item.label}
        </option>

    `;

});


        html += `

            <div class="col-md-6 mb-3">

                <label class="form-label">

              {{ __("Template Variable") }}
<span>&#123;&#123;${variable}&#125;&#125;</span>
                  

                </label>


                <select
                    class="form-control chatwoot-variable-source"
                    data-event="${eventType}"
                    data-variable="${variable}"
                >

                    ${options}

                </select>


            </div>

        `;

    });


    html += `

            </div>

        </div>

    `;


    container.html(html);
}


/*
|--------------------------------------------------------------------------
| When user selects a template
|--------------------------------------------------------------------------
*/

$(document).on(
    'change',
    '.chatwoot-event-template',
    function () {

        let eventType =
            $(this).data('event');

        let templateName =
            $(this).val();


        renderVariableMapping(
            eventType,
            templateName
        );

    }
);

function loadSavedNotificationSettings()
{
    $.ajax({

        url: '{{ route("chatwoot.notification.templates") }}',

        type: 'GET',

        success: function(response) {

            if (
                !response.success ||
                !response.settings
            ) {
                return;
            }


            response.settings.forEach(function(setting) {

                let eventType =
                    setting.event_type;

                let templateName =
                    setting.template_name;


                /*
                 * Select saved template
                 */
                let templateSelect =
                    $('.chatwoot-event-template[data-event="' +
                    eventType +
                    '"]');


                templateSelect.val(templateName);


                /*
                 * Enable / disable switch
                 */
                let enabled =
                    parseInt(setting.is_enabled) === 1 ||
                    setting.is_enabled === true;


                $('.chatwoot-event-enabled[data-event="' +
                    eventType +
                '"]')
                .prop(
                    'checked',
                    enabled
                );


                /*
                 * Render variable fields
                 */
                renderVariableMapping(
                    eventType,
                    templateName
                );


                /*
                 * Restore variable mapping
                 */
                let parameters =
                    setting.parameters || {};


                if (
                    typeof parameters === 'string'
                ) {

                    try {

                        parameters =
                            JSON.parse(parameters);

                    } catch (e) {

                        parameters = {};

                    }

                }


                Object.keys(parameters)
                    .forEach(function(variable) {

                        let value =
                            parameters[variable];


                        $('.chatwoot-variable-source' +
                            '[data-event="' + eventType + '"]' +
                            '[data-variable="' + variable + '"]'
                        ).val(value);

                    });

            });

        },

        error: function(xhr) {

            console.log(
                'Unable to load saved Chatwoot notification settings.',
                xhr.responseText
            );

        }

    });
}
/*
|--------------------------------------------------------------------------
| Save notification settings
|--------------------------------------------------------------------------
*/

$(document).on(
    'click',
    '#chatwoot-save-template-settings',
    function () {

        let button = $(this);

        let settings = [];

        let validationError = false;


        $('.chatwoot-event-template').each(function () {

            let select = $(this);

            let eventType =
                select.data('event');

            let templateName =
                select.val();

            let selectedOption =
                select.find('option:selected');


            let language =
                selectedOption.data('language') || '';

            let category =
                selectedOption.data('category') || '';


            let enabled =
                $('.chatwoot-event-enabled[data-event="' +
                    eventType +
                '"]')
                .is(':checked')
                ? 1
                : 0;


            /*
             * Collect parameter mapping
             *
             * مثال:
             *
             * {
             *   "1": "customer_name",
             *   "2": "order_number"
             * }
             */
            let parameters = {};


            $(
                '.chatwoot-variable-source[data-event="' +
                eventType +
                '"]'
            ).each(function () {

                let variable =
                    $(this).data('variable');

                let source =
                    $(this).val();


                if (!source && templateName) {

                    validationError = true;

                    $(this).addClass('is-invalid');

                } else {

                    $(this).removeClass('is-invalid');

                }


                if (source) {

                    parameters[variable] = source;

                }

            });


            settings.push({

                event_type: eventType,

                template_name: templateName,

                template_language: language,

                template_category: category,

                is_enabled: enabled,

                parameters: parameters

            });

        });


        /*
         * إذا في Template فيها variables
         * والمستخدم ما اختار مصدرها
         */
        if (validationError) {

            show_toastr(
                'Error',
                '{{ __("Please select a value for all template variables.") }}',
                'error'
            );

            return;
        }


        button.prop(
            'disabled',
            true
        );


        button.text(
            '{{ __("Saving...") }}'
        );


        $.ajax({

            url:
                '{{ route("chatwoot.notification.templates.save") }}',

            type:
                'POST',

            data: {

                _token:
                    '{{ csrf_token() }}',

                settings:
                    settings

            },

            success: function(response) {

                button.prop(
                    'disabled',
                    false
                );


                button.text(
                    '{{ __("Save Notification Templates") }}'
                );


                show_toastr(
                    'Success',
                    response.message,
                    'success'
                );

            },


            error: function(xhr) {

                button.prop(
                    'disabled',
                    false
                );


                button.text(
                    '{{ __("Save Notification Templates") }}'
                );


                let message =
                    'Unable to save notification templates.';


                if (
                    xhr.responseJSON &&
                    xhr.responseJSON.message
                ) {

                    message =
                        xhr.responseJSON.message;

                }


                show_toastr(
                    'Error',
                    message,
                    'error'
                );

            }

        });

    }
);


/*
|--------------------------------------------------------------------------
| HTML escape helper
|--------------------------------------------------------------------------
*/

function escapeHtml(text)
{
    return $('<div>')
        .text(text)
        .html();
}

</script>

@endif

<script>
$(document).ready(function () {

    $('.chatwoot-main-tab').on('click', function () {

        const target = $(this).attr('data-target');

        // active tab
        $('.chatwoot-main-tab').removeClass('active');
        $(this).addClass('active');

        // hide both sections
        $('#chatwoot-settings-pane').hide();
        $('#abandoned-carts-pane').hide();

        // show selected section
        if (target === 'abandoned-carts-pane') {

            $('#abandoned-carts-pane').show();

        } else {

            $('#chatwoot-settings-pane').show();

        }

    });

});
</script>