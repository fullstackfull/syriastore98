@extends('layouts.app')

@section('page-title', __('Automation Studio'))

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('lynomia.overview') }}">{{ __('Communication & Marketing') }}</a></li>
    <li class="breadcrumb-item">{{ __('Automation Studio') }}</li>
@endsection

{{--
    THE AUTOMATION STUDIO.

    Built in the stack this dashboard actually renders with -- Blade, Bootstrap 5 and jQuery,
    loaded from public/assets by layouts/app.blade.php. The repository carries Vite, Alpine and
    Tailwind, but no dashboard view invokes the Vite directive, the layout loads none of them,
    and `vite build` cannot currently succeed at all (resources/js/app.js imports laravel-echo
    and pusher-js, which are in neither package.json nor node_modules). Measured in the Phase 4A
    capability matrix, not assumed.

    DIRECTION IS INHERITED, NOT RESTATED. layouts/app.blade.php already sets <html dir="rtl"> and
    swaps in style-rtl.css for Arabic and Hebrew, so this markup uses Bootstrap's logical
    utilities (ms-*, me-*, text-start, text-end) and flips with the page.

    EVERY MERCHANT-SUPPLIED STRING IS WRITTEN WITH .text(), NEVER .html(). A flow name is typed
    by a person and may contain anything; the renderer below builds nodes and sets textContent.

    NOTHING HERE IS AN AUTHORIZATION CONTROL. The page asks the BFF what it may do and shows the
    answer; every action is gated again on its route, and again by Lynomia, which allows flow
    management only to a workspace administrator -- the store owner.
--}}

@push('css')
<style>
    .lyn-studio-card { transition: box-shadow .15s ease-in-out; }
    .lyn-studio-card:hover { box-shadow: 0 .35rem 1.1rem rgba(0,0,0,.08); }
    .lyn-state { font-size: .72rem; letter-spacing: .02em; text-transform: uppercase; }
    .lyn-meter { height: .35rem; border-radius: .35rem; background: rgba(0,0,0,.08); overflow: hidden; }
    .lyn-meter > span { display: block; height: 100%; background: var(--bs-primary, #0d6efd); }
    .lyn-meter[data-full="true"] > span { background: var(--bs-danger, #dc3545); }
    .lyn-flow-name { word-break: break-word; }
    .lyn-errors { max-height: 16rem; overflow-y: auto; }
    @media (max-width: 575.98px) {
        .lyn-studio-actions { width: 100%; }
        .lyn-studio-actions > .btn { flex: 1 1 auto; }
    }
</style>
@endpush

@section('content')
<div class="row" id="lyn-studio"
     data-endpoint-flows="{{ route('lynomia.automation.api.flows') }}"
     data-builder-url="{{ route('lynomia.automation.builder', ['flow' => 0]) }}"
     data-workspace="{{ $workspaceName }}">
    <div class="col-12">

        <div class="card">
            <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div>
                    <h5 class="mb-1">{{ __('Automation Studio') }}</h5>
                    <small class="text-muted">
                        {{ __('Build automations that answer your customers on their own.') }}
                    </small>
                </div>
                <div class="d-flex align-items-center gap-2 lyn-studio-actions">
                    <button type="button" class="btn btn-sm btn-light" id="lyn-studio-refresh"
                            title="{{ __('Refresh') }}" aria-label="{{ __('Refresh') }}">
                        <i class="ti ti-refresh" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-primary" id="lyn-studio-new" disabled>
                        <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ __('New automation') }}
                    </button>
                </div>
            </div>

            {{-- Usage against the allowance Lynomia enforces, not a number counted here. --}}
            <div class="card-body border-bottom py-3" id="lyn-usage" hidden>
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="small text-muted" id="lyn-usage-text"></span>
                    <span class="small text-danger" id="lyn-usage-warning" hidden>
                        {{ __('You have used every automation your plan includes.') }}
                    </span>
                </div>
                <div class="lyn-meter" id="lyn-usage-meter" role="img" aria-label="{{ __('Automations used') }}">
                    <span style="width:0"></span>
                </div>
            </div>

            <div class="card-body">
                <div id="lyn-studio-alert" class="alert alert-danger d-none" role="alert" aria-live="polite"></div>
                <div id="lyn-studio-loading" class="text-center text-muted py-5">{{ __('Loading…') }}</div>
                <div id="lyn-studio-empty" class="text-center py-5" hidden>
                    <i class="ti ti-route" style="font-size:2.25rem;opacity:.35" aria-hidden="true"></i>
                    <p class="mt-3 mb-1">{{ __('No automations yet.') }}</p>
                    <p class="text-muted small mb-0">
                        {{ __('An automation replies to customers for you — a welcome message, a menu, an order question.') }}
                    </p>
                </div>
                <div class="row g-3" id="lyn-studio-list"></div>
            </div>
        </div>
    </div>
</div>

{{-- ------------------------------------------------------------------ create / rename --}}
<div class="modal fade" id="lyn-flow-modal" tabindex="-1" aria-labelledby="lyn-flow-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="lyn-flow-form" novalidate>
            <div class="modal-header">
                <h5 class="modal-title" id="lyn-flow-modal-title">{{ __('New automation') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="lyn-flow-name">{{ __('Name') }}</label>
                    <input type="text" class="form-control" id="lyn-flow-name" maxlength="255" required
                           autocomplete="off">
                    <div class="invalid-feedback">{{ __('Please give this automation a name.') }}</div>
                </div>
                <div>
                    <label class="form-label" for="lyn-flow-description">
                        {{ __('Description') }} <span class="text-muted small">({{ __('optional') }})</span>
                    </label>
                    <textarea class="form-control" id="lyn-flow-description" rows="2" maxlength="1000"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="submit" class="btn btn-primary" id="lyn-flow-save">{{ __('Save') }}</button>
            </div>
        </form>
    </div>
</div>

{{-- ------------------------------------------------------------------ confirm / errors --}}
<div class="modal fade" id="lyn-confirm-modal" tabindex="-1" aria-labelledby="lyn-confirm-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="lyn-confirm-title"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>
            <div class="modal-body">
                <p id="lyn-confirm-body" class="mb-0"></p>
                <ul class="list-unstyled small mt-3 mb-0 lyn-errors" id="lyn-confirm-errors" hidden></ul>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal" id="lyn-confirm-cancel">
                    {{ __('Cancel') }}
                </button>
                <button type="button" class="btn btn-primary" id="lyn-confirm-ok">{{ __('Confirm') }}</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function ($) {
    'use strict';

    var root = document.getElementById('lyn-studio');
    if (!root) { return; }

    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') } });

    var ENDPOINT = root.getAttribute('data-endpoint-flows');
    // The builder's url with 0 standing in for the flow, so the PATH is Laravel's and this page
    // substitutes an id into it rather than assembling a route out of string pieces.
    var BUILDER_URL = root.getAttribute('data-builder-url');

    function builderUrlFor(id) {
        return BUILDER_URL.replace(/\/0\/builder$/, '/' + encodeURIComponent(id) + '/builder');
    }
    // The page's language, not the browser's: a merchant who set Arabic should not get US date
    // formatting because their laptop is configured in English.
    var PAGE_LOCALE = document.documentElement.getAttribute('lang') || 'en';

    // Every string the interface can show, translated server-side. Nothing merchant-supplied is
    // in here -- this is the product's own copy.
    var T = {
        failed: @json(__('Lynomia Chat could not complete that request. Please contact support.')),
        newTitle: @json(__('New automation')),
        renameTitle: @json(__('Rename automation')),
        duplicateSuffix: @json(__('copy')),
        confirm: @json(__('Confirm')),
        cancel: @json(__('Cancel')),
        close: @json(__('Close')),
        never: @json(__('Never published')),
        states: {
            published: @json(__('lynomia-chat::studio.state_published')),
            draft: @json(__('Draft')),
            disabled: @json(__('lynomia-chat::studio.state_disabled'))
        },
        channels: @json(__('Channels')),
        noChannel: @json(__('Not connected to a channel')),
        running: @json(__('running now')),
        publishedOn: @json(__('Published :date')),
        usage: @json(__(':used of :limit automations used')),
        usageUnlimited: @json(__(':used automations')),
        publish: @json(__('Publish')),
        publishBody: @json(__('Publishing makes this automation answer customers straight away. Continue?')),
        publishFailed: @json(__('This automation cannot be published yet:')),
        disable: @json(__('Turn off')),
        disableBody: @json(__('Customers will stop being answered by this automation. Conversations already running are handed to your team. Continue?')),
        remove: @json(__('Delete')),
        removeBody: @json(__('This automation and its versions will be deleted. This cannot be undone. Continue?')),
        removeBlocked: @json(__('Turn this automation off before deleting it.')),
        duplicate: @json(__('Duplicate')),
        rename: @json(__('Rename')),
        edit: @json(__('Open builder')),
        limitReached: @json(__('You have used every automation your plan includes. Delete one, or ask support to raise your limit.')),
        // The graph validator's codes, rendered here so the merchant reads their own language
        // and never a sentence Lynomia wrote. An unrecognised code falls back to the code
        // itself, which is still something support can act on.
        errors: {
            invalid_graph: @json(__('The automation could not be read.')),
            graph_too_large: @json(__('This automation is too large.')),
            too_many_nodes: @json(__('This automation has too many steps.')),
            too_many_edges: @json(__('This automation has too many connections.')),
            invalid_node: @json(__('A step is not valid.')),
            invalid_edge: @json(__('A connection is not valid.')),
            invalid_position: @json(__('A step has no position on the canvas.')),
            unknown_node_type: @json(__('A step uses a type this version does not know.')),
            duplicate_node_id: @json(__('Two steps share the same id.')),
            duplicate_edge_id: @json(__('Two connections share the same id.')),
            duplicate_output: @json(__('A step output is connected twice.')),
            dangling_edge: @json(__('A connection points at a step that is not there.')),
            edge_into_start: @json(__('Nothing may lead back into the start step.')),
            start_count: @json(__('An automation needs exactly one start step.')),
            unknown_output: @json(__('A connection leaves an output this step does not have.')),
            unconnected_output: @json(__('A step output leads nowhere.')),
            invalid_target: @json(__('A Go to step points at a step that is not there.')),
            unreachable: @json(__('A step cannot be reached from the start.')),
            loop_without_wait: @json(__('A loop here would never stop — add a question or a delay inside it.')),
            text_required: @json(__('This step needs a message.')),
            text_too_long: @json(__('This message is too long for the channel.')),
            invalid_options: @json(__('This step needs between one and the allowed number of choices.')),
            option_title: @json(__('A choice needs a short title.')),
            option_description: @json(__('A choice description is too long.')),
            button_label_too_long: @json(__('A button label is too long for the channel.')),
            invalid_reply_type: @json(__('This answer type is not valid.')),
            invalid_attempts: @json(__('The number of retries is not valid.')),
            invalid_timeout: @json(__('The waiting time is not valid.')),
            invalid_delay: @json(__('The delay is not valid.')),
            invalid_number: @json(__('A number here is not valid.')),
            invalid_mode: @json(__('This option is not valid.')),
            invalid_resolve: @json(__('This option is not valid.')),
            invalid_priority: @json(__('This priority is not valid.')),
            invalid_keywords: @json(__('The keywords are not valid.')),
            keywords_required: @json(__('This step needs at least one keyword.')),
            invalid_labels: @json(__('The labels are not valid.')),
            labels_required: @json(__('This step needs at least one label.')),
            unknown_label: @json(__('A label on this step no longer exists.')),
            unknown_agent: @json(__('A team member on this step no longer exists.')),
            agent_required: @json(__('This step needs a team member.')),
            unknown_team: @json(__('A team on this step no longer exists.')),
            team_required: @json(__('This step needs a team.')),
            unknown_attribute: @json(__('A field on this step no longer exists.')),
            invalid_attribute_value: @json(__('The value for this field is not valid.')),
            unknown_variable: @json(__('This step uses a value that is not available here.')),
            unknown_field: @json(__('A setting on this step is not recognised.')),
            invalid_conditions: @json(__('The conditions on this step are not valid.')),
            invalid_url: @json(__('This web address is not valid.')),
            webhooks_unavailable: @json(__('Web requests are not available on this plan.')),
            commerce_disabled: @json(__('Store lookups are not switched on for this workspace.')),
            invalid_store: @json(__('The store on this step is not connected.')),
            reason_too_long: @json(__('The handover note is too long.')),
            unsupported_channel: @json(__('A channel this automation is connected to cannot run this step.')),
            no_inbox: @json(__('Connect this automation to a channel before publishing it.')),
            flow_active: @json(__('Turn this automation off before deleting it.'))
        }
    };

    var $list = $('#lyn-studio-list');
    var $alert = $('#lyn-studio-alert');
    var $loading = $('#lyn-studio-loading');
    var $empty = $('#lyn-studio-empty');
    var $newButton = $('#lyn-studio-new');
    var flowModal = new bootstrap.Modal(document.getElementById('lyn-flow-modal'));
    var confirmModal = new bootstrap.Modal(document.getElementById('lyn-confirm-modal'));
    var editing = null;      // the flow being renamed, or null when creating
    var confirmAction = null;
    var usage = { used: 0, limit: null };

    function text(value) { return value === null || value === undefined ? '' : String(value); }

    function fill(template, values) {
        return Object.keys(values).reduce(function (carry, key) {
            return carry.split(':' + key).join(values[key]);
        }, template);
    }

    function errorSentence(error) {
        return (T.errors && T.errors[error.code]) || error.code;
    }

    function showAlert(message) {
        $alert.text(message).removeClass('d-none');
    }

    function clearAlert() {
        $alert.addClass('d-none').text('');
    }

    // A failure from the BFF carries a translated sentence in `error`; anything else (a proxy
    // page, a dropped connection) gets the product's own copy rather than raw HTML.
    function failureOf(xhr) {
        var body = xhr && xhr.responseJSON;
        return (body && typeof body.error === 'string' && body.error) || T.failed;
    }

    function formatDate(value) {
        if (!value) { return ''; }
        var parsed = new Date(value);
        if (isNaN(parsed.getTime())) { return ''; }
        return parsed.toLocaleDateString(PAGE_LOCALE, { year: 'numeric', month: 'short', day: 'numeric' });
    }

    // ------------------------------------------------------------------ rendering

    function stateBadge(state) {
        var tone = state === 'published' ? 'success' : (state === 'disabled' ? 'secondary' : 'warning');
        return $('<span>').addClass('badge lyn-state bg-' + tone + '-subtle text-' + tone + '-emphasis')
            .text(T.states[state] || state);
    }

    function renderFlow(flow) {
        var $card = $('<div>').addClass('col-12 col-lg-6 col-xxl-4');
        var $body = $('<div>').addClass('card lyn-studio-card h-100 mb-0').append($('<div>').addClass('card-body'));
        var $inner = $body.find('.card-body');

        var $head = $('<div>').addClass('d-flex align-items-start justify-content-between gap-2 mb-2');
        $head.append($('<h6>').addClass('mb-0 lyn-flow-name').text(flow.name));
        $head.append(stateBadge(flow.state));
        $inner.append($head);

        if (flow.description) {
            $inner.append($('<p>').addClass('text-muted small mb-2').text(flow.description));
        }

        var channels = (flow.inboxes || []).map(function (inbox) { return inbox.name; });
        $inner.append($('<p>').addClass('small mb-1')
            .append($('<span>').addClass('text-muted me-1').text(T.channels + ':'))
            .append($('<span>').text(channels.length ? channels.join(' · ') : T.noChannel)));

        var meta = [];
        if (flow.published && flow.published.published_at) {
            meta.push(fill(T.publishedOn, { date: formatDate(flow.published.published_at) }));
        } else if (flow.state !== 'published') {
            meta.push(T.never);
        }
        if (flow.live_sessions > 0) {
            meta.push(flow.live_sessions + ' ' + T.running);
        }
        $inner.append($('<p>').addClass('small text-muted mb-3').text(meta.join(' · ')));

        var $actions = $('<div>').addClass('d-flex flex-wrap gap-2');
        $actions.append($('<a>').addClass('btn btn-sm btn-outline-secondary')
            .attr('href', builderUrlFor(flow.id))
            .text(T.edit));
        if (flow.state === 'published') {
            $actions.append(button('btn-outline-secondary', T.disable, 'disable', flow));
        } else {
            $actions.append(button('btn-outline-primary', T.publish, 'publish', flow));
        }
        $actions.append(button('btn-light', T.rename, 'rename', flow));
        $actions.append(button('btn-light', T.duplicate, 'duplicate', flow));
        $actions.append(button('btn-light text-danger', T.remove, 'remove', flow));
        $inner.append($actions);

        return $card.append($body);
    }

    function button(classes, label, action, flow) {
        return $('<button>').attr('type', 'button').addClass('btn btn-sm ' + classes)
            .attr('data-action', action).attr('data-flow', flow.id).text(label);
    }

    function renderUsage() {
        var $usage = $('#lyn-usage');
        var unlimited = usage.limit === null;
        $('#lyn-usage-text').text(unlimited
            ? fill(T.usageUnlimited, { used: usage.used })
            : fill(T.usage, { used: usage.used, limit: usage.limit }));

        var full = !unlimited && usage.used >= usage.limit;
        var percent = unlimited ? 0 : Math.min(100, Math.round((usage.used / Math.max(1, usage.limit)) * 100));
        $('#lyn-usage-meter').attr('data-full', full ? 'true' : 'false').find('span').css('width', percent + '%');
        $('#lyn-usage-warning').prop('hidden', !full);
        $usage.prop('hidden', false);

        // The ceiling is enforced by Lynomia, not here: this only stops a merchant filling in a
        // form that is going to be refused. A create that races past it is still refused there.
        $newButton.prop('disabled', full).attr('title', full ? T.limitReached : '');
    }

    function render(flows) {
        $list.empty();
        $empty.prop('hidden', flows.length > 0);
        flows.forEach(function (flow) { $list.append(renderFlow(flow)); });
    }

    // ------------------------------------------------------------------ requests

    function load() {
        clearAlert();
        $loading.prop('hidden', false);

        $.getJSON(ENDPOINT).done(function (body) {
            usage = body.usage || usage;
            renderUsage();
            render(body.flows || []);
        }).fail(function (xhr) {
            showAlert(failureOf(xhr));
            $empty.prop('hidden', true);
        }).always(function () {
            $loading.prop('hidden', true);
        });
    }

    function send(method, path, payload) {
        return $.ajax({
            url: ENDPOINT + path,
            method: method,
            contentType: 'application/json',
            dataType: 'json',
            data: payload === undefined ? undefined : JSON.stringify(payload)
        });
    }

    // ------------------------------------------------------------------ actions

    $('#lyn-studio-refresh').on('click', load);

    $newButton.on('click', function () {
        editing = null;
        $('#lyn-flow-modal-title').text(T.newTitle);
        $('#lyn-flow-name').val('').removeClass('is-invalid');
        $('#lyn-flow-description').val('').closest('div').prop('hidden', false);
        flowModal.show();
    });

    $('#lyn-flow-form').on('submit', function (event) {
        event.preventDefault();
        var name = $.trim($('#lyn-flow-name').val());
        if (!name) { $('#lyn-flow-name').addClass('is-invalid'); return; }
        $('#lyn-flow-name').removeClass('is-invalid');

        var $save = $('#lyn-flow-save').prop('disabled', true);
        var request = editing
            ? send('PATCH', '/' + editing.id, { name: name })
            : send('POST', '', { name: name, description: $.trim($('#lyn-flow-description').val()) || null });

        request.done(function (body) {
            flowModal.hide();
            // A create the ceiling refused answers 200 with flow:null and the fresh allowance --
            // the numbers, not an English sentence, so the message below is in the merchant's
            // own language.
            if (body && Object.prototype.hasOwnProperty.call(body, 'flow') && body.flow === null) {
                usage = body.usage || usage;
                renderUsage();
                showAlert(T.limitReached);
                return;
            }
            load();
        }).fail(function (xhr) {
            flowModal.hide();
            showAlert(failureOf(xhr));
        }).always(function () {
            $save.prop('disabled', false);
        });
    });

    function ask(title, body, okClass, onConfirm) {
        $('#lyn-confirm-title').text(title);
        $('#lyn-confirm-body').text(body);
        $('#lyn-confirm-errors').prop('hidden', true).empty();
        $('#lyn-confirm-cancel').prop('hidden', false).text(T.cancel);
        $('#lyn-confirm-ok').prop('hidden', false).removeClass('btn-primary btn-danger')
            .addClass(okClass).text(T.confirm);
        confirmAction = onConfirm;
        confirmModal.show();
    }

    // The same dialog, used to REPORT rather than to ask: a publish that failed validation has
    // a list of reasons and no decision left to make.
    function report(title, body, errors) {
        $('#lyn-confirm-title').text(title);
        $('#lyn-confirm-body').text(body);
        var $errors = $('#lyn-confirm-errors').empty().prop('hidden', !errors.length);
        errors.forEach(function (error) {
            var $item = $('<li>').addClass('mb-1').text('• ' + errorSentence(error));
            if (error.node_id) {
                $item.append($('<span>').addClass('text-muted ms-1').text('(' + error.node_id + ')'));
            }
            $errors.append($item);
        });
        $('#lyn-confirm-cancel').prop('hidden', true);
        $('#lyn-confirm-ok').prop('hidden', false).removeClass('btn-danger').addClass('btn-primary').text(T.close);
        confirmAction = null;
        confirmModal.show();
    }

    $('#lyn-confirm-ok').on('click', function () {
        var action = confirmAction;
        confirmAction = null;
        confirmModal.hide();
        if (action) { action(); }
    });

    $list.on('click', '[data-action]', function () {
        var id = $(this).attr('data-flow');
        var action = $(this).attr('data-action');
        var name = $(this).closest('.card-body').find('.lyn-flow-name').text();
        clearAlert();

        if (action === 'rename') {
            editing = { id: id };
            $('#lyn-flow-modal-title').text(T.renameTitle);
            $('#lyn-flow-name').val(name).removeClass('is-invalid');
            $('#lyn-flow-description').closest('div').prop('hidden', true);
            flowModal.show();
            return;
        }

        if (action === 'duplicate') {
            send('POST', '/' + id + '/duplicate', { name: (name + ' (' + T.duplicateSuffix + ')').slice(0, 255) })
                .done(function (body) {
                    if (body && body.flow === null) {
                        usage = body.usage || usage;
                        renderUsage();
                        showAlert(T.limitReached);
                        return;
                    }
                    load();
                })
                .fail(function (xhr) { showAlert(failureOf(xhr)); });
            return;
        }

        if (action === 'publish') {
            ask(T.publish, T.publishBody, 'btn-primary', function () {
                send('POST', '/' + id + '/publish').done(function (body) {
                    if (body && body.ok === false) {
                        report(T.publish, T.publishFailed, body.errors || []);
                        return;
                    }
                    load();
                }).fail(function (xhr) { showAlert(failureOf(xhr)); });
            });
            return;
        }

        if (action === 'disable') {
            ask(T.disable, T.disableBody, 'btn-primary', function () {
                send('POST', '/' + id + '/disable')
                    .done(load)
                    .fail(function (xhr) { showAlert(failureOf(xhr)); });
            });
            return;
        }

        if (action === 'remove') {
            ask(T.remove, T.removeBody, 'btn-danger', function () {
                send('DELETE', '/' + id).done(function (body) {
                    if (body && body.ok === false) {
                        report(T.remove, T.removeBlocked, body.errors || []);
                        return;
                    }
                    load();
                }).fail(function (xhr) { showAlert(failureOf(xhr)); });
            });
        }
    });

    load();
})(jQuery);
</script>
@endpush
