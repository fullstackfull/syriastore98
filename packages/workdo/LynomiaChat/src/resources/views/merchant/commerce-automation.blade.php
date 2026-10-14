@extends('layouts.app')

@section('page-title', __('Commerce automation'))

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('lynomia.overview') }}">{{ __('Communication & Marketing') }}</a></li>
    <li class="breadcrumb-item">{{ __('Commerce automation') }}</li>
@endsection

{{--
    COMMERCE AUTOMATION AND CONSENT.

    TWO TABS, BECAUSE THEY ARE TWO HALVES OF ONE QUESTION: what the store sends on its own, and
    whom it is allowed to send it to. Setting up an order-shipped message and recording that a
    customer asked to stop are the same person on the same afternoon; splitting them across two
    menu entries invites the first to be configured without the second ever being looked at.

    A RULE IS NOT A FLOW. A flow answers a conversation the customer started -- that is the Flow
    Builder. A rule reacts to something that happened and takes an action, and the Commerce
    triggers this integration exists for are rules. They are different models upstream and they
    are different screens here.

    NOTHING IS OFFERED THAT CANNOT WORK. The triggers come from the server with a flag saying
    whether each one can actually fire for THIS store -- Store Syria's abandoned carts are still
    pre-UAT, so that trigger is shown and disabled with its reason rather than quietly listed --
    and only the actions the server describes are offered at all.

    Blade, Bootstrap 5 and jQuery, as the rest of this dashboard is. Logical utilities
    throughout, so it flips with the page in Arabic. Merchant-typed text via .text(), never
    .html().
--}}

@push('css')
<style>
    .lync-rule { border: 1px solid rgba(0,0,0,.09); border-radius: .5rem; }
    .lync-pill { font-size: .72rem; text-transform: uppercase; letter-spacing: .02em; }
    .lync-cond { border-inline-start: 3px solid rgba(13,110,253,.35); }
    .lync-act { border-inline-start: 3px solid rgba(25,135,84,.35); }
    .lync-result { max-height: 16rem; overflow-y: auto; }
    @media (max-width: 575.98px) { .lync-actions > .btn { flex: 1 1 auto; } }
</style>
@endpush

@section('content')
<div id="lync-root"
     data-endpoint-rules="{{ route('lynomia.automation.api.rules') }}"
     data-endpoint-consents="{{ route('lynomia.automation.api.consents') }}"
     data-endpoint-consent-search="{{ route('lynomia.automation.api.consents.search') }}"
     data-can-consent="{{ $canManageConsent ? '1' : '0' }}">

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="lync-tab-rules" data-bs-toggle="tab" data-bs-target="#lync-pane-rules"
                    type="button" role="tab" aria-controls="lync-pane-rules" aria-selected="true">
                {{ __('Rules') }}
            </button>
        </li>
        @if ($canManageConsent)
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="lync-tab-consent" data-bs-toggle="tab" data-bs-target="#lync-pane-consent"
                        type="button" role="tab" aria-controls="lync-pane-consent" aria-selected="false">
                    {{ __('Consent') }}
                </button>
            </li>
        @endif
    </ul>

    <div class="tab-content">
        {{-- ------------------------------------------------------------ rules --}}
        <div class="tab-pane fade show active" id="lync-pane-rules" role="tabpanel" aria-labelledby="lync-tab-rules">
            <div class="card">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div>
                        <h5 class="mb-1">{{ __('Rules') }}</h5>
                        <small class="text-muted">
                            {{ __('A rule watches for something happening in your store and does something about it.') }}
                        </small>
                    </div>
                    <div class="d-flex gap-2 lync-actions">
                        <button type="button" class="btn btn-sm btn-light" id="lync-refresh"
                                aria-label="{{ __('Refresh') }}"><i class="ti ti-refresh" aria-hidden="true"></i></button>
                        <button type="button" class="btn btn-sm btn-primary" id="lync-new">
                            <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ __('New rule') }}
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div id="lync-alert" class="alert alert-danger d-none" role="alert" aria-live="polite"></div>
                    <div id="lync-loading" class="text-center text-muted py-5">{{ __('Loading…') }}</div>
                    <div id="lync-empty" class="text-center py-5" hidden>
                        <p class="mb-1">{{ __('No rules yet.') }}</p>
                        <p class="small text-muted mb-0">
                            {{ __('For example: when an order is shipped, send the customer an approved template.') }}
                        </p>
                    </div>
                    <div class="d-grid gap-3" id="lync-list"></div>
                </div>
            </div>
        </div>

        {{-- ------------------------------------------------------------ consent --}}
        @if ($canManageConsent)
            <div class="tab-pane fade" id="lync-pane-consent" role="tabpanel" aria-labelledby="lync-tab-consent">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-1">{{ __('Consent') }}</h5>
                        <small class="text-muted">
                            {{ __('Order updates are sent unless a customer asks you to stop. Offers are sent only to customers who agreed.') }}
                        </small>
                    </div>
                    <div class="card-body">
                        <div id="lync-consent-alert" class="alert alert-danger d-none" role="alert" aria-live="polite"></div>

                        <label for="lync-consent-search" class="form-label small">{{ __('Find a customer') }}</label>
                        <div class="d-flex gap-2 mb-3">
                            <input type="search" class="form-control form-control-sm" id="lync-consent-search"
                                   placeholder="{{ __('Name, phone or email') }}" autocomplete="off" maxlength="100">
                            <button type="button" class="btn btn-sm btn-light" id="lync-consent-go">{{ __('Search') }}</button>
                        </div>
                        <div class="lync-result d-grid gap-1 mb-3" id="lync-consent-results"></div>

                        <div id="lync-consent-detail" hidden></div>

                        <hr>
                        <h6 class="mb-2">{{ __('Recent decisions') }}</h6>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col">{{ __('Customer') }}</th>
                                        <th scope="col">{{ __('Kind') }}</th>
                                        <th scope="col">{{ __('Decision') }}</th>
                                        <th scope="col">{{ __('Recorded by') }}</th>
                                        <th scope="col">{{ __('When') }}</th>
                                    </tr>
                                </thead>
                                <tbody id="lync-consent-log"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>

{{-- ------------------------------------------------------------------ rule editor --}}
<div class="modal fade" id="lync-rule-modal" tabindex="-1" aria-labelledby="lync-rule-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <form class="modal-content" id="lync-rule-form" novalidate>
            <div class="modal-header">
                <h5 class="modal-title" id="lync-rule-title">{{ __('New rule') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>
            <div class="modal-body">
                <div id="lync-rule-errors" class="alert alert-danger d-none" role="alert"></div>

                <div class="mb-3">
                    <label class="form-label small" for="lync-rule-name">{{ __('Name') }}</label>
                    <input type="text" class="form-control form-control-sm" id="lync-rule-name" maxlength="255" required>
                </div>

                <div class="mb-3">
                    <label class="form-label small" for="lync-rule-event">{{ __('When this happens') }}</label>
                    <select class="form-select form-select-sm" id="lync-rule-event" required></select>
                    <div class="form-text small" id="lync-rule-event-note"></div>
                </div>

                <div class="mb-3">
                    <div class="form-label small">{{ __('Only if') }} <span class="text-muted">({{ __('optional') }})</span></div>
                    <div id="lync-rule-conditions"></div>
                </div>

                <div class="mb-3">
                    <div class="form-label small">{{ __('Then do this') }}</div>
                    <div id="lync-rule-actions"></div>
                </div>

                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="lync-rule-active">
                    <label class="form-check-label small" for="lync-rule-active">{{ __('Switch this rule on') }}</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="submit" class="btn btn-primary" id="lync-rule-save">{{ __('Save') }}</button>
            </div>
        </form>
    </div>
</div>

{{-- ------------------------------------------------------------------ confirm --}}
<div class="modal fade" id="lync-confirm-modal" tabindex="-1" aria-labelledby="lync-confirm-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="lync-confirm-title"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>
            <div class="modal-body"><p id="lync-confirm-body" class="mb-0"></p></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="button" class="btn btn-danger" id="lync-confirm-ok">{{ __('Confirm') }}</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function ($) {
    'use strict';

    var root = document.getElementById('lync-root');
    if (!root) { return; }

    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') } });

    var RULES = root.getAttribute('data-endpoint-rules');
    var CONSENTS = root.getAttribute('data-endpoint-consents');
    var CONSENT_SEARCH = root.getAttribute('data-endpoint-consent-search');
    var CAN_CONSENT = root.getAttribute('data-can-consent') === '1';
    var PAGE_LOCALE = document.documentElement.getAttribute('lang') || 'en';

    var T = {
        failed: @json(__('Lynomia Chat could not complete that request. Please contact support.')),
        newRule: @json(__('New rule')),
        editRule: @json(__('Edit rule')),
        confirm: @json(__('Confirm')),
        remove: @json(__('Delete')),
        removeTitle: @json(__('Delete this rule?')),
        removeBody: @json(__('This rule will stop running and cannot be brought back.')),
        edit: @json(__('Edit')),
        on: @json(__('On')),
        off: @json(__('Paused')),
        turnOn: @json(__('Switch on')),
        turnOff: @json(__('Pause')),
        addCondition: @json(__('Add condition')),
        addAction: @json(__('Add action')),
        none: @json(__('None')),
        noteRequired: @json(__('Please say where this decision came from.')),
        nameRequired: @json(__('Please give this rule a name.')),
        actionRequired: @json(__('A rule needs at least one action.')),
        actionsDropped: @json(__('This trigger cannot do :actions, so it was removed.')),
        // Why a trigger cannot fire here. Codes from the server, sentences from this page.
        reasons: {
            extensions_disabled: @json(__('Commerce automation is switched off for this installation.')),
            commerce_disabled: @json(__('Your plan does not include store automation.')),
            no_store: @json(__('Connect your store to Lynomia first.')),
            recovery_unavailable: @json(__('Abandoned baskets are not collected for this store yet.'))
        },
        events: {
            conversation_created: @json(__('A conversation starts')),
            conversation_updated: @json(__('A conversation changes')),
            conversation_opened: @json(__('A conversation is reopened')),
            conversation_resolved: @json(__('A conversation is resolved')),
            message_created: @json(__('A message arrives')),
            commerce_order_placed: @json(__('An order is placed')),
            commerce_order_paid: @json(__('An order is paid')),
            commerce_order_shipped: @json(__('An order is shipped')),
            commerce_order_delivered: @json(__('An order is delivered')),
            commerce_order_cancelled: @json(__('An order is cancelled')),
            commerce_order_refunded: @json(__('An order is refunded')),
            commerce_order_failed: @json(__('An order fails')),
            commerce_cart_abandoned: @json(__('A basket is abandoned'))
        },
        actions: {
            send_message: @json(__('Send a message')),
            add_label: @json(__('Add a label')),
            remove_label: @json(__('Remove a label')),
            assign_agent: @json(__('Assign to a person')),
            assign_team: @json(__('Assign to a team')),
            remove_assigned_agent: @json(__('Unassign the person')),
            remove_assigned_team: @json(__('Unassign the team')),
            change_priority: @json(__('Set the priority')),
            mute_conversation: @json(__('Mute the conversation')),
            snooze_conversation: @json(__('Snooze the conversation')),
            resolve_conversation: @json(__('Resolve the conversation')),
            open_conversation: @json(__('Reopen the conversation')),
            pending_conversation: @json(__('Mark it pending')),
            add_private_note: @json(__('Leave a note for the team')),
            send_whatsapp_template: @json(__('Send an approved template'))
        },
        operators: {
            equal_to: @json(__('is')), not_equal_to: @json(__('is not')), contains: @json(__('contains')),
            does_not_contain: @json(__('does not contain')), is_present: @json(__('is set')),
            is_not_present: @json(__('is not set')), is_greater_than: @json(__('is more than')),
            is_less_than: @json(__('is less than')), days_before: @json(__('is more days ago than')),
            starts_with: @json(__('starts with')), attribute_changed: @json(__('changed'))
        },
        unavailable: @json(__('Not available on this workspace')),
        // Consent
        transactional: @json(__('Order updates')),
        promotional: @json(__('Offers')),
        granted: @json(__('Agreed')),
        withdrawn: @json(__('Asked to stop')),
        suppressed: @json(__('Blocked by the provider')),
        notRecorded: @json(__('Nothing recorded')),
        willSend: @json(__('Will be sent')),
        willNotSend: @json(__('Will not be sent')),
        refusals: {
            contact_unavailable: @json(__('This customer cannot be messaged.')),
            consent_withdrawn: @json(__('They asked to stop.')),
            messaging_suppressed: @json(__('The provider says this number cannot receive messages.')),
            promotional_consent_missing: @json(__('They have not agreed to offers.'))
        },
        recordGranted: @json(__('Record that they agreed')),
        recordWithdrawn: @json(__('Record that they asked to stop')),
        clearSuppression: @json(__('Clear the provider block')),
        notePrompt: @json(__('Where did this decision come from?')),
        noResults: @json(__('No customers matched.')),
        searchHint: @json(__('Type at least two characters.'))
    };

    function text(v) { return v === null || v === undefined ? '' : String(v); }
    function eventLabel(name) { return T.events[name] || name; }
    function actionLabel(name) { return T.actions[name] || name; }
    function failureOf(xhr) {
        var body = xhr && xhr.responseJSON;
        return (body && typeof body.error === 'string' && body.error) || T.failed;
    }
    function when(value) {
        if (!value) { return ''; }
        var parsed = new Date(value);
        return isNaN(parsed.getTime()) ? '' : parsed.toLocaleString(PAGE_LOCALE);
    }

    var state = { rules: [], vocabulary: { events: [], actions: [], conditions: [] }, editing: null, draft: null };
    var ruleModal = new bootstrap.Modal(document.getElementById('lync-rule-modal'));
    var confirmModal = new bootstrap.Modal(document.getElementById('lync-confirm-modal'));
    var confirmAction = null;

    $('#lync-confirm-ok').on('click', function () {
        var action = confirmAction;
        confirmAction = null;
        confirmModal.hide();
        if (action) { action(); }
    });

    function ask(title, body, onConfirm) {
        $('#lync-confirm-title').text(title);
        $('#lync-confirm-body').text(body);
        confirmAction = onConfirm;
        confirmModal.show();
    }

    // ------------------------------------------------------------------ rules

    function loadRules() {
        $('#lync-alert').addClass('d-none').text('');
        $('#lync-loading').prop('hidden', false);

        $.getJSON(RULES).done(function (body) {
            state.rules = body.rules || [];
            state.vocabulary = body.vocabulary || state.vocabulary;
            renderRules();
        }).fail(function (xhr) {
            $('#lync-alert').text(failureOf(xhr)).removeClass('d-none');
            $('#lync-empty').prop('hidden', true);
        }).always(function () {
            $('#lync-loading').prop('hidden', true);
        });
    }

    function renderRules() {
        var $list = $('#lync-list').empty();
        $('#lync-empty').prop('hidden', state.rules.length > 0);

        state.rules.forEach(function (rule) {
            var $card = $('<div>').addClass('lync-rule p-3');

            $card.append($('<div>').addClass('d-flex flex-wrap align-items-start justify-content-between gap-2 mb-2')
                .append($('<div>')
                    .append($('<div>').addClass('fw-semibold').text(rule.name))
                    .append($('<div>').addClass('small text-muted').text(eventLabel(rule.event_name))))
                .append($('<span>').addClass('badge lync-pill ' +
                    (rule.active ? 'bg-success-subtle text-success-emphasis' : 'bg-secondary-subtle text-secondary-emphasis'))
                    .text(rule.active ? T.on : T.off)));

            (rule.conditions || []).forEach(function (condition) {
                $card.append($('<div>').addClass('small ps-2 mb-1 lync-cond')
                    .text(condition.attribute_key + ' ' +
                          (T.operators[condition.filter_operator] || condition.filter_operator) + ' ' +
                          (condition.values || []).join(', ')));
            });

            (rule.actions || []).forEach(function (action) {
                $card.append($('<div>').addClass('small ps-2 mb-1 lync-act').text(actionLabel(action.action_name)));
            });

            $card.append($('<div>').addClass('d-flex flex-wrap gap-2 mt-2')
                .append($('<button>').attr('type', 'button').addClass('btn btn-sm btn-light')
                    .text(T.edit).on('click', function () { openRule(rule); }))
                .append($('<button>').attr('type', 'button').addClass('btn btn-sm btn-light')
                    .text(rule.active ? T.turnOff : T.turnOn)
                    .on('click', function () { saveRule($.extend({}, rule, { active: !rule.active }), rule.id); }))
                .append($('<button>').attr('type', 'button').addClass('btn btn-sm btn-light text-danger')
                    .text(T.remove).on('click', function () {
                        ask(T.removeTitle, T.removeBody, function () { removeRule(rule.id); });
                    })));

            $list.append($card);
        });
    }

    function openRule(rule) {
        state.editing = rule ? rule.id : null;
        state.draft = rule
            ? { conditions: (rule.conditions || []).map(function (c) { return $.extend(true, {}, c); }),
                actions: (rule.actions || []).map(function (a) { return $.extend(true, {}, a); }) }
            : { conditions: [], actions: [] };

        $('#lync-rule-title').text(rule ? T.editRule : T.newRule);
        $('#lync-rule-errors').addClass('d-none').empty();
        $('#lync-rule-name').val(rule ? rule.name : '');
        $('#lync-rule-active').prop('checked', rule ? !!rule.active : false);

        renderEventPicker(rule ? rule.event_name : null);
        renderConditionRows();
        renderActionRows();
        ruleModal.show();
    }

    // A TRIGGER THAT CANNOT FIRE IS SHOWN AND DISABLED, WITH ITS REASON. Hiding it would tell a
    // merchant the capability does not exist; offering it would give them a rule that saves and
    // never runs, which is what the capability review called the first trap.
    function renderEventPicker(selected) {
        var $select = $('#lync-rule-event').empty();

        (state.vocabulary.events || []).forEach(function (event) {
            var $option = $('<option>').val(event.name).text(eventLabel(event.name));
            if (!event.available) {
                $option.prop('disabled', true).text(eventLabel(event.name) + ' — ' + (T.reasons[event.reason] || T.unavailable));
            }
            $select.append($option);
        });

        if (selected) { $select.val(selected); }
        $select.trigger('change');
    }

    $('#lync-rule-event').on('change', function () {
        var chosen = (state.vocabulary.events || []).find(function (event) { return event.name === $(this).val(); }.bind(this));
        $('#lync-rule-event-note').text(chosen && !chosen.available ? (T.reasons[chosen.reason] || T.unavailable) : '');

        // CHANGING THE TRIGGER CAN FORBID AN ACTION ALREADY IN THE DRAFT. A commerce trigger
        // refuses a free-form customer message, so a rule built on a conversation trigger and
        // then switched to an order event was carrying an action the new trigger will not
        // accept -- and because the select had no option for it, the form SHOWED something else
        // while still submitting the old name. Found in a browser. Such actions are dropped and
        // the merchant is told, rather than being left to discover it at save.
        var offerable = offerableActions().map(function (action) { return action.name; });
        var kept = state.draft.actions.filter(function (row) { return offerable.indexOf(row.action_name) >= 0; });

        if (kept.length !== state.draft.actions.length) {
            var dropped = state.draft.actions
                .filter(function (row) { return offerable.indexOf(row.action_name) < 0; })
                .map(function (row) { return actionLabel(row.action_name); })
                .filter(function (label, index, all) { return all.indexOf(label) === index; });
            state.draft.actions = kept;
            showRuleErrors([T.actionsDropped.split(':actions').join(dropped.join(', '))]);
        }

        renderActionRows();
    });

    function isCommerceEvent(name) { return String(name).indexOf('commerce_') === 0; }

    function conditionEntry(key) {
        return (state.vocabulary.conditions || []).find(function (entry) { return entry.key === key; }) || null;
    }

    function renderConditionRows() {
        var $box = $('#lync-rule-conditions').empty();

        state.draft.conditions.forEach(function (row, index) {
            var entry = conditionEntry(row.attribute_key);
            var $row = $('<div>').addClass('d-flex flex-wrap gap-1 mb-2 align-items-center');

            var $key = $('<select>').addClass('form-select form-select-sm').css('max-width', '12rem')
                .append($('<option>').val('').text(T.none));
            (state.vocabulary.conditions || []).forEach(function (candidate) {
                var $option = $('<option>').val(candidate.key).text(candidate.key);
                if (candidate.available === false) { $option.prop('disabled', true).text(candidate.key + ' — ' + T.unavailable); }
                $key.append($option);
            });
            $key.val(text(row.attribute_key)).on('change', function () {
                row.attribute_key = $(this).val();
                var picked = conditionEntry(row.attribute_key);
                row.filter_operator = picked ? (picked.operators || [])[0] : null;
                row.values = [];
                renderConditionRows();
            });

            var $operator = $('<select>').addClass('form-select form-select-sm').css('max-width', '10rem');
            ((entry && entry.operators) || []).forEach(function (operator) {
                $operator.append($('<option>').val(operator).text(T.operators[operator] || operator));
            });
            $operator.val(text(row.filter_operator)).on('change', function () { row.filter_operator = $(this).val(); });

            $row.append($key).append($operator);

            if (['is_present', 'is_not_present', 'attribute_changed'].indexOf(row.filter_operator) < 0) {
                $row.append(valueControl(entry, row));
            }

            $row.append($('<button>').attr('type', 'button').addClass('btn btn-sm btn-light text-danger')
                .text('×').on('click', function () {
                    state.draft.conditions.splice(index, 1);
                    renderConditionRows();
                }));

            $box.append($row);
        });

        if (state.draft.conditions.length < 10) {
            $box.append($('<button>').attr('type', 'button').addClass('btn btn-sm btn-light')
                .text(T.addCondition).on('click', function () {
                    state.draft.conditions.push({ attribute_key: '', filter_operator: null, values: [],
                                                  query_operator: state.draft.conditions.length ? 'AND' : null });
                    renderConditionRows();
                }));
        }
    }

    function valueControl(entry, row) {
        if (entry && Array.isArray(entry.values) && entry.values.length) {
            var $select = $('<select>').addClass('form-select form-select-sm').css('max-width', '12rem')
                .append($('<option>').val('').text(T.none));
            entry.values.forEach(function (value) { $select.append($('<option>').val(value).text(value)); });
            return $select.val(text((row.values || [])[0]))
                .on('change', function () { row.values = $(this).val() ? [$(this).val()] : []; });
        }

        return $('<input>').attr({ type: 'text', maxlength: 200 }).addClass('form-control form-control-sm')
            .css('max-width', '12rem').val(text((row.values || [])[0]))
            .on('input', function () { row.values = $(this).val() === '' ? [] : [$(this).val()]; });
    }

    // ONLY THE ACTIONS THE SERVER DESCRIBES, and on a commerce trigger only the ones it accepts.
    // The free-form message actions are refused upstream for a commerce rule -- a store event is
    // not a customer message and the channel's window may be shut -- so offering one would be
    // offering a rule that cannot be saved.
    function offerableActions() {
        var commerce = isCommerceEvent($('#lync-rule-event').val());
        return (state.vocabulary.actions || []).filter(function (action) {
            return !commerce || action.commerce_safe;
        });
    }

    function renderActionRows() {
        var $box = $('#lync-rule-actions').empty();
        var offerable = offerableActions();

        state.draft.actions.forEach(function (row, index) {
            var $row = $('<div>').addClass('border rounded p-2 mb-2');

            var $name = $('<select>').addClass('form-select form-select-sm mb-1');
            offerable.forEach(function (action) {
                $name.append($('<option>').val(action.name).text(actionLabel(action.name)));
            });
            $name.val(text(row.action_name)).on('change', function () {
                row.action_name = $(this).val();
                row.action_params = [];
                renderActionRows();
            });
            $row.append($name);

            var action = offerable.find(function (candidate) { return candidate.name === row.action_name; });
            (action ? action.fields : []).forEach(function (field) {
                $row.append(actionField(row, field));
            });

            $row.append($('<button>').attr('type', 'button').addClass('btn btn-sm btn-light text-danger')
                .text('×').on('click', function () {
                    state.draft.actions.splice(index, 1);
                    renderActionRows();
                }));

            $box.append($row);
        });

        if (state.draft.actions.length < 10 && offerable.length) {
            $box.append($('<button>').attr('type', 'button').addClass('btn btn-sm btn-light')
                .text(T.addAction).on('click', function () {
                    state.draft.actions.push({ action_name: offerable[0].name, action_params: [] });
                    renderActionRows();
                }));
        }
    }

    // `wrapped` says the engine reads action_params[0] rather than a named key -- change_priority
    // takes ['urgent'], not { priority: 'urgent' }. The server says which; this does not guess.
    function actionField(row, field) {
        var current = field.wrapped ? (row.action_params || [])[0] : ((row.action_params || [])[0] || {})[field.key];

        var $control = field.kind === 'select'
            ? $('<select>').addClass('form-select form-select-sm')
            : $('<input>').attr({ type: 'text', maxlength: field.max || 255 }).addClass('form-control form-control-sm');

        if (field.kind === 'select') {
            (field.values || []).forEach(function (value) { $control.append($('<option>').val(value).text(value)); });
        }

        $control.val(text(current)).on('input change', function () {
            var value = $(this).val();
            row.action_params = field.wrapped ? [value] : [$.extend({}, (row.action_params || [])[0], (function () {
                var patch = {};
                patch[field.key] = value;
                return patch;
            })())];
        });

        return $('<div>').addClass('mb-1')
            .append($('<label>').addClass('form-label small mb-0').text(field.key))
            .append($control);
    }

    $('#lync-new').on('click', function () { openRule(null); });
    $('#lync-refresh').on('click', loadRules);

    $('#lync-rule-form').on('submit', function (event) {
        event.preventDefault();

        var name = $.trim($('#lync-rule-name').val());
        if (!name) { showRuleErrors([T.nameRequired]); return; }
        if (!state.draft.actions.length) { showRuleErrors([T.actionRequired]); return; }

        saveRule({
            name: name,
            event_name: $('#lync-rule-event').val(),
            active: $('#lync-rule-active').is(':checked'),
            conditions: state.draft.conditions.filter(function (row) { return row.attribute_key && row.filter_operator; }),
            actions: state.draft.actions.filter(function (row) { return row.action_name; })
        }, state.editing);
    });

    function showRuleErrors(messages) {
        var $box = $('#lync-rule-errors').empty().removeClass('d-none');
        messages.forEach(function (message) { $box.append($('<div>').text(message)); });
    }

    function saveRule(rule, id) {
        var payload = {
            name: rule.name, event_name: rule.event_name, active: !!rule.active,
            conditions: rule.conditions || [], actions: rule.actions || []
        };

        $.ajax({
            url: id ? RULES + '/' + encodeURIComponent(id) : RULES,
            method: id ? 'PATCH' : 'POST',
            contentType: 'application/json', dataType: 'json', data: JSON.stringify(payload)
        }).done(function (body) {
            if (body.ok === false) {
                showRuleErrors(Object.keys(body.errors || {}).map(function (key) {
                    return key + ': ' + (body.errors[key] || []).join(', ');
                }));
                return;
            }
            ruleModal.hide();
            loadRules();
        }).fail(function (xhr) {
            showRuleErrors([failureOf(xhr)]);
        });
    }

    function removeRule(id) {
        $.ajax({ url: RULES + '/' + encodeURIComponent(id), method: 'DELETE', dataType: 'json' })
            .done(loadRules)
            .fail(function (xhr) { $('#lync-alert').text(failureOf(xhr)).removeClass('d-none'); });
    }

    // ------------------------------------------------------------------ consent

    function loadConsentLog() {
        $.getJSON(CONSENTS).done(function (body) {
            var $log = $('#lync-consent-log').empty();
            (body.decisions || []).forEach(function (row) {
                $log.append($('<tr>')
                    .append($('<td>').text(row.contact_name))
                    .append($('<td>').text(T[row.purpose] || row.purpose))
                    .append($('<td>').text(T[row.state] || row.state))
                    .append($('<td>').text(row.source))
                    .append($('<td>').addClass('small text-muted').text(when(row.decided_at))));
            });
        }).fail(function (xhr) {
            $('#lync-consent-alert').text(failureOf(xhr)).removeClass('d-none');
        });
    }

    function searchContacts() {
        var query = $.trim($('#lync-consent-search').val());
        var $results = $('#lync-consent-results').empty();
        if (query.length < 2) { $results.append($('<div>').addClass('small text-muted').text(T.searchHint)); return; }

        $.getJSON(CONSENT_SEARCH, { q: query }).done(function (body) {
            if (!(body.contacts || []).length) {
                $results.append($('<div>').addClass('small text-muted').text(T.noResults));
                return;
            }
            body.contacts.forEach(function (contact) {
                $results.append($('<button>').attr('type', 'button')
                    .addClass('btn btn-sm btn-light text-start')
                    .text(contact.name + (contact.identifier ? ' · ' + contact.identifier : ''))
                    .on('click', function () { showConsent(contact.id); }));
            });
        }).fail(function (xhr) {
            $('#lync-consent-alert').text(failureOf(xhr)).removeClass('d-none');
        });
    }

    function showConsent(contactId) {
        $.getJSON(CONSENTS + '/' + encodeURIComponent(contactId))
            .done(function (body) { renderConsent(body.consent || {}); })
            .fail(function (xhr) { $('#lync-consent-alert').text(failureOf(xhr)).removeClass('d-none'); });
    }

    function renderConsent(consent) {
        var $box = $('#lync-consent-detail').empty().prop('hidden', false);
        $box.append($('<h6>').addClass('mb-2').text(consent.contact_name || ''));

        Object.keys(consent.purposes || {}).forEach(function (purpose) {
            var entry = consent.purposes[purpose];
            var $card = $('<div>').addClass('border rounded p-2 mb-2');

            $card.append($('<div>').addClass('d-flex justify-content-between align-items-center')
                .append($('<strong>').text(T[purpose] || purpose))
                .append($('<span>').addClass('badge ' + (entry.refusal ? 'bg-danger-subtle text-danger-emphasis'
                                                                        : 'bg-success-subtle text-success-emphasis'))
                    .text(entry.refusal ? T.willNotSend : T.willSend)));

            $card.append($('<div>').addClass('small text-muted')
                .text((entry.state ? (T[entry.state] || entry.state) : T.notRecorded) +
                      (entry.source ? ' · ' + entry.source : '') +
                      (entry.decided_at ? ' · ' + when(entry.decided_at) : '')));

            if (entry.refusal) {
                $card.append($('<div>').addClass('small text-danger').text(T.refusals[entry.refusal] || entry.refusal));
            }
            if (entry.note) {
                $card.append($('<div>').addClass('small fst-italic').text(entry.note));
            }

            var $buttons = $('<div>').addClass('d-flex flex-wrap gap-1 mt-2');
            $buttons.append(decisionButton(consent.contact_id, purpose, 'granted', T.recordGranted, 'btn-outline-success'));
            $buttons.append(decisionButton(consent.contact_id, purpose, 'withdrawn', T.recordWithdrawn, 'btn-outline-danger'));
            // A provider block is this system's, not the shopper's, and is cleared on its own.
            if (entry.state === 'suppressed') {
                $buttons.append($('<button>').attr('type', 'button').addClass('btn btn-sm btn-light')
                    .text(T.clearSuppression).on('click', function () { clearSuppression(consent.contact_id); }));
            }
            $card.append($buttons);

            $box.append($card);
        });
    }

    function decisionButton(contactId, purpose, state, label, variant) {
        return $('<button>').attr('type', 'button').addClass('btn btn-sm ' + variant).text(label)
            .on('click', function () {
                // THE NOTE IS NOT OPTIONAL. A grant recorded by a merchant is a claim about a
                // conversation that happened somewhere else; without a note saying where, it is
                // indistinguishable from adding somebody to a marketing list.
                var note = window.prompt(T.notePrompt, '');
                if (note === null) { return; }
                if (!$.trim(note)) { window.alert(T.noteRequired); return; }

                $.ajax({
                    url: CONSENTS + '/' + encodeURIComponent(contactId), method: 'PUT',
                    contentType: 'application/json', dataType: 'json',
                    data: JSON.stringify({ purpose: purpose, state: state, note: $.trim(note) })
                }).done(function (body) {
                    renderConsent(body.consent || {});
                    loadConsentLog();
                }).fail(function (xhr) {
                    $('#lync-consent-alert').text(failureOf(xhr)).removeClass('d-none');
                });
            });
    }

    function clearSuppression(contactId) {
        $.ajax({ url: CONSENTS + '/' + encodeURIComponent(contactId), method: 'DELETE', dataType: 'json' })
            .done(function (body) { renderConsent(body.consent || {}); loadConsentLog(); })
            .fail(function (xhr) { $('#lync-consent-alert').text(failureOf(xhr)).removeClass('d-none'); });
    }

    $('#lync-consent-go').on('click', searchContacts);
    $('#lync-consent-search').on('keydown', function (event) {
        if (event.key === 'Enter') { event.preventDefault(); searchContacts(); }
    });

    loadRules();
    if (CAN_CONSENT) { loadConsentLog(); }
})(jQuery);
</script>
@endpush
