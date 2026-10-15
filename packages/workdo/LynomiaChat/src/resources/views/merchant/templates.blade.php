@extends('layouts.app')

@section('page-title', __('Message templates'))

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('lynomia.overview') }}">{{ __('Communication & Marketing') }}</a></li>
    <li class="breadcrumb-item">{{ __('Message templates') }}</li>
@endsection

{{--
    MESSAGE TEMPLATES: the text WhatsApp approves, and every campaign then sends.

    META OWNS THE STATUS, NOT THIS PAGE. A template is approved, rejected, paused or flagged
    by WhatsApp, and it moves without telling anyone first. What is shown here is Lynomia
    Chat's mirror of that, with WHEN IT WAS LAST CHECKED beside it -- because a status with no
    age is a status a merchant cannot weigh.

    WHAT MAY BE DONE COMES FROM THE SERVER. Meta's rules about which transitions are legal
    from which state are intricate and they change; the server derives them per template and
    enforces them on every endpoint. This page renders the ones it is given and disables the
    rest. It does not decide, because a second copy of those rules here would fall behind and
    the action it wrongly offered would cost a review cycle.

    "NO PROBLEMS FOUND" IS NOT "WILL BE APPROVED", and the page says so. The checks are Meta's
    published rules applied before a submission is spent; Meta's reviewers apply more than
    they publish.

    Blade, Bootstrap 5 and jQuery, as the rest of this dashboard is. Logical utilities
    throughout, so it flips with the page in Arabic. Merchant-typed text via .text(), never
    .html().
--}}

@push('css')
<style>
    .lynt-tpl { border: 1px solid rgba(0,0,0,.09); border-radius: .5rem; }
    .lynt-body { white-space: pre-wrap; word-break: break-word; }
    .lynt-problem { border-inline-start: 3px solid rgba(220,53,69,.45); }
    @media (max-width: 575.98px) { .lynt-actions > .btn { flex: 1 1 auto; } }
</style>
@endpush

@section('content')
<div id="lynt-root"
     data-endpoint-index="{{ route('lynomia.templates.api.index') }}"
     data-endpoint-store="{{ route('lynomia.templates.api.store') }}"
     data-update-template="{{ route('lynomia.templates.api.update', ['template' => 0]) }}"
     data-destroy-template="{{ route('lynomia.templates.api.destroy', ['template' => 0]) }}"
     data-submit-template="{{ route('lynomia.templates.api.submit', ['template' => 0]) }}"
     data-duplicate-template="{{ route('lynomia.templates.api.duplicate', ['template' => 0]) }}">

    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-start mb-3">
        <div>
            <h5 class="mb-1">{{ __('Message templates') }}</h5>
            <small class="text-muted">
                {{ __('WhatsApp must approve the text of any message you start a conversation with. Statuses here come from WhatsApp.') }}
            </small>
        </div>
        <button type="button" class="btn btn-primary btn-sm" id="lynt-new">{{ __('New template') }}</button>
    </div>

    <div id="lynt-alert" class="alert alert-danger d-none" role="alert" aria-live="polite"></div>
    <div id="lynt-note" class="alert alert-info d-none" role="status" aria-live="polite"></div>

    <div id="lynt-list" class="d-grid gap-2"></div>
</div>

<div class="modal fade" id="lynt-modal" tabindex="-1" aria-labelledby="lynt-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="lynt-modal-title">{{ __('New template') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>
            <div class="modal-body">
                <div id="lynt-modal-errors" class="alert alert-danger d-none" role="alert" aria-live="polite"></div>

                <div class="row g-2 mb-3">
                    <div class="col-12 col-md-6">
                        <label for="lynt-name" class="form-label small">{{ __('Name') }}</label>
                        <input type="text" class="form-control form-control-sm" id="lynt-name" maxlength="512"
                               placeholder="{{ __('lowercase_with_underscores') }}">
                    </div>
                    <div class="col-6 col-md-3">
                        <label for="lynt-language" class="form-label small">{{ __('Language') }}</label>
                        <input type="text" class="form-control form-control-sm" id="lynt-language" maxlength="20"
                               placeholder="ar">
                    </div>
                    <div class="col-6 col-md-3">
                        <label for="lynt-category" class="form-label small">{{ __('Category') }}</label>
                        <select class="form-select form-select-sm" id="lynt-category">
                            <option value="MARKETING">{{ __('Marketing') }}</option>
                            <option value="UTILITY">{{ __('Order updates') }}</option>
                        </select>
                    </div>
                </div>

                {{-- The category is not cosmetic: it decides whether a send needs marketing
                     consent. Said here rather than left to be discovered after a campaign
                     reached nobody. --}}
                <p class="text-muted small" id="lynt-category-note"></p>

                <label for="lynt-inbox" class="form-label small">{{ __('WhatsApp number') }}</label>
                <select class="form-select form-select-sm mb-3" id="lynt-inbox"></select>

                {{-- The WhatsApp placeholders are built in PHP, not written in Blade. A
                     literal double-brace in Blade source -- even inside a string, even
                     inside this comment -- is read as an echo with an unclosed brace and the
                     view will not compile. That is why they are built in PHP below -- and
                     why this comment does not name a Blade directive either, since Blade
                     compiles a directive inside a comment and breaks the comment. --}}
                @php
                    $first = '{' . '{1}}';
                    $second = '{' . '{2}}';
                @endphp
                <label for="lynt-body" class="form-label small">{{ __('Message') }}</label>
                <textarea class="form-control form-control-sm lynt-body" id="lynt-body" rows="5"
                          placeholder="{{ __('Hello :first, your order :second is on its way.', ['first' => $first, 'second' => $second]) }}"></textarea>
                <p class="text-muted small mt-1 mb-0">
                    {{ __('Use :first, :second and so on for the parts that change per customer.', ['first' => $first, 'second' => $second]) }}
                </p>
            </div>
            <div class="modal-footer lynt-actions d-flex gap-2">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="button" class="btn btn-primary btn-sm" id="lynt-save">{{ __('Save draft') }}</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function ($) {
    'use strict';

    var root = document.getElementById('lynt-root');
    if (!root) { return; }

    var INDEX = root.getAttribute('data-endpoint-index');
    var STORE = root.getAttribute('data-endpoint-store');
    var UPDATE_TEMPLATE = root.getAttribute('data-update-template');
    var DESTROY_TEMPLATE = root.getAttribute('data-destroy-template');
    var SUBMIT_TEMPLATE = root.getAttribute('data-submit-template');
    var DUPLICATE_TEMPLATE = root.getAttribute('data-duplicate-template');
    var PAGE_LOCALE = document.documentElement.getAttribute('lang') || 'en';

    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') } });

    var T = {
        failed: @json(__('Lynomia Chat could not complete that request. Please contact support.')),
        empty: @json(__('No templates yet. Create one so your campaigns have something approved to send.')),
        saved: @json(__('Saved.')),
        submitted: @json(__('Sent to WhatsApp for review. A decision usually takes a few minutes but can take longer.')),
        duplicated: @json(__('Copied into a new draft.')),
        deleted: @json(__('Deleted.')),
        confirmSubmit: @json(__('Send this template to WhatsApp for review? You cannot take a review back.')),
        confirmDelete: @json(__('Delete this template?')),
        nameRequired: @json(__('Give the template a name.')),
        bodyRequired: @json(__('Write the message.')),
        inboxRequired: @json(__('Choose which WhatsApp number this template belongs to.')),
        noProblems: @json(__('Nothing in WhatsApp\'s published rules is wrong with this. That is not a promise it will be approved.')),
        problemsFound: @json(__('WhatsApp\'s published rules say this will be refused:')),
        lastChecked: @json(__('status last checked :when')),
        never: @json(__('not checked yet')),
        missing: @json(__('WhatsApp no longer has this template. It was there the last time we looked.')),
        marketingNote: @json(__('Marketing templates are only sent to customers who agreed to receive offers.')),
        utilityNote: @json(__('Order updates are sent unless a customer has asked you to stop.')),
        states: {
            draft: @json(__('Draft — WhatsApp has not seen it')),
            submitting: @json(__('Sent for review — no decision yet')),
            remote: @json(__('With WhatsApp'))
        },
        meta: {
            APPROVED: @json(__('Approved')),
            PENDING: @json(__('Awaiting review')),
            IN_APPEAL: @json(__('Under appeal')),
            REJECTED: @json(__('Refused')),
            PAUSED: @json(__('Paused by WhatsApp')),
            DISABLED: @json(__('Disabled by WhatsApp')),
            PENDING_DELETION: @json(__('Being deleted')),
            DELETED: @json(__('Deleted at WhatsApp'))
        },
        actions: {
            edit: @json(__('Edit')),
            submit: @json(__('Send for review')),
            duplicate: @json(__('Duplicate')),
            destroy: @json(__('Delete'))
        }
    };

    function text(v) { return v === null || v === undefined ? '' : String(v); }
    function failureOf(xhr) {
        var body = xhr && xhr.responseJSON;
        return (body && typeof body.error === 'string' && body.error) || T.failed;
    }
    function alertNow(xhr) { $('#lynt-alert').text(failureOf(xhr)).removeClass('d-none'); }
    function noteNow(message) { $('#lynt-note').text(message).removeClass('d-none'); }
    function urlFor(template, id) { return template.replace(/\/0(?=\/|$)/, '/' + String(id)); }
    function when(seconds) {
        if (!seconds) { return T.never; }
        var parsed = new Date(seconds * 1000);

        return isNaN(parsed.getTime()) ? T.never : parsed.toLocaleString(PAGE_LOCALE);
    }

    var state = { templates: [], inboxes: [], editing: null };

    // ---------------------------------------------------------------- the list

    function statusLabel(template) {
        if (template.state !== 'remote') { return T.states[template.state] || template.state; }

        return T.meta[template.meta_status] || template.meta_status || T.states.remote;
    }

    function statusTone(template) {
        if (template.meta_status === 'APPROVED') { return 'bg-success'; }
        if (['REJECTED', 'PAUSED', 'DISABLED', 'DELETED'].indexOf(template.meta_status) !== -1) { return 'bg-danger'; }
        if (template.state === 'draft') { return 'bg-secondary'; }

        return 'bg-warning text-dark';
    }

    function renderList() {
        var $list = $('#lynt-list').empty();

        if (!state.templates.length) {
            $list.append($('<p>').addClass('text-muted mb-0').text(T.empty));

            return;
        }

        state.templates.forEach(function (template) {
            var $card = $('<div>').addClass('lynt-tpl p-3');

            var $head = $('<div>').addClass('d-flex flex-wrap gap-2 justify-content-between align-items-start mb-2');
            var $title = $('<div>');
            $title.append($('<span>').addClass('fw-semibold me-2').text(template.name));
            $title.append($('<span>').addClass('text-muted small').text(template.language + ' · ' + template.category));
            $head.append($title);
            $head.append($('<span>').addClass('badge ' + statusTone(template)).text(statusLabel(template)));
            $card.append($head);

            $card.append($('<div>').addClass('lynt-body small mb-2').text(bodyOf(template)));

            // WHAT META SAID, VERBATIM. A refusal with no reason sends a merchant guessing.
            if (template.rejected_reason || template.rejection_info) {
                $card.append($('<div>').addClass('text-danger small mb-2')
                    .text([template.rejected_reason, template.rejection_info].filter(Boolean).join(' — ')));
            }
            if (template.submission_error) {
                $card.append($('<div>').addClass('text-danger small mb-2').text(template.submission_error));
            }
            if (template.missing_at_meta) {
                $card.append($('<div>').addClass('text-danger small mb-2').text(T.missing));
            }

            if ((template.validation_problems || []).length) {
                var $problems = $('<ul>').addClass('lynt-problem ps-3 small text-danger mb-2');
                $problems.append($('<li>').addClass('list-unstyled fw-semibold').text(T.problemsFound));
                template.validation_problems.forEach(function (problem) {
                    $problems.append($('<li>').text(typeof problem === 'string' ? problem : JSON.stringify(problem)));
                });
                $card.append($problems);
            }

            // THE AGE OF THE STATUS. Without it, "Approved" could be from a minute ago or a
            // week ago and the merchant cannot tell which.
            $card.append($('<div>').addClass('text-muted small mb-2')
                .text(T.lastChecked.replace(':when', when(template.last_seen_at))));

            $card.append(actionsFor(template));
            $list.append($card);
        });
    }

    function bodyOf(template) {
        var body = (template.components || []).filter(function (component) {
            return String(component.type || '').toUpperCase() === 'BODY';
        })[0];

        return body ? text(body.text) : '';
    }

    // ONLY WHAT THE SERVER ALLOWS. Meta's transition rules are intricate and they change; the
    // server derives them per template and enforces them, so the page renders that answer
    // rather than keeping a copy that would fall behind.
    function actionsFor(template) {
        var $actions = $('<div>').addClass('d-flex gap-2 flex-wrap lynt-actions');
        var allowed = template.allowed_actions || [];

        if (allowed.indexOf('edit') !== -1) {
            $actions.append(button(T.actions.edit, 'btn-light', function () { openEditor(template); }));
        }
        if (allowed.indexOf('submit') !== -1) {
            $actions.append(button(T.actions.submit, 'btn-primary', function () { submit(template); }));
        }
        if (allowed.indexOf('duplicate') !== -1) {
            $actions.append(button(T.actions.duplicate, 'btn-light', function () { duplicate(template); }));
        }
        if (allowed.indexOf('destroy') !== -1) {
            $actions.append(button(T.actions.destroy, 'btn-outline-danger', function () { remove(template); }));
        }

        return $actions;
    }

    function button(label, variant, handler) {
        return $('<button>').addClass('btn btn-sm ' + variant).text(label).on('click', handler);
    }

    // ---------------------------------------------------------------- actions

    function submit(template) {
        if (!window.confirm(T.confirmSubmit)) { return; }

        $.ajax({ url: urlFor(SUBMIT_TEMPLATE, template.id), method: 'POST', dataType: 'json' })
            .done(function () { noteNow(T.submitted); load(); })
            .fail(alertNow);
    }

    function duplicate(template) {
        $.ajax({ url: urlFor(DUPLICATE_TEMPLATE, template.id), method: 'POST', dataType: 'json' })
            .done(function () { noteNow(T.duplicated); load(); })
            .fail(alertNow);
    }

    function remove(template) {
        if (!window.confirm(T.confirmDelete)) { return; }

        $.ajax({ url: urlFor(DESTROY_TEMPLATE, template.id), method: 'DELETE', dataType: 'json' })
            .done(function () { noteNow(T.deleted); load(); })
            .fail(alertNow);
    }

    // ---------------------------------------------------------------- the editor

    function openEditor(template) {
        state.editing = template || null;
        $('#lynt-modal-errors').addClass('d-none').text('');
        $('#lynt-modal-title').text(template ? @json(__('Edit template')) : @json(__('New template')));
        $('#lynt-name').val(template ? template.name : '').prop('disabled', !!template);
        $('#lynt-language').val(template ? template.language : '').prop('disabled', !!template);
        $('#lynt-category').val(template ? template.category : 'MARKETING').prop('disabled', !!template);
        $('#lynt-body').val(template ? bodyOf(template) : '');
        renderInboxes(template);
        categoryNote();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('lynt-modal')).show();
    }

    // Name, language and category are Meta's idea of a template's IDENTITY and cannot change
    // after creation. Disabled rather than hidden, so an editing merchant can still read what
    // they are, and the duplicate action is how you make a changed version.
    function renderInboxes(template) {
        var $select = $('#lynt-inbox').empty().prop('disabled', !!template);
        state.inboxes.forEach(function (inbox) {
            $select.append($('<option>').attr('value', inbox.id).text(inbox.name || inbox.id));
        });
        if (template && (template.inboxes || []).length) {
            $select.append($('<option>').attr('value', template.inboxes[0].id).text(template.inboxes[0].name || ''));
            $select.val(template.inboxes[0].id);
        }
    }

    function categoryNote() {
        $('#lynt-category-note').text($('#lynt-category').val() === 'MARKETING' ? T.marketingNote : T.utilityNote);
    }

    $('#lynt-category').on('change', categoryNote);
    $('#lynt-new').on('click', function () { openEditor(null); });

    $('#lynt-save').on('click', function () {
        var editing = state.editing;
        var body = $.trim($('#lynt-body').val());
        if (!body) { return $('#lynt-modal-errors').text(T.bodyRequired).removeClass('d-none'); }

        var payload = { components: [{ type: 'BODY', text: body }] };

        if (!editing) {
            var name = $.trim($('#lynt-name').val());
            if (!name) { return $('#lynt-modal-errors').text(T.nameRequired).removeClass('d-none'); }
            var inbox = $('#lynt-inbox').val();
            if (!inbox) { return $('#lynt-modal-errors').text(T.inboxRequired).removeClass('d-none'); }

            payload.name = name;
            payload.language = $.trim($('#lynt-language').val()) || 'en';
            payload.category = $('#lynt-category').val();
            payload.inbox_id = inbox;
        }

        $.ajax({
            url: editing ? urlFor(UPDATE_TEMPLATE, editing.id) : STORE,
            method: editing ? 'PATCH' : 'POST',
            dataType: 'json',
            data: payload
        }).done(function () {
            bootstrap.Modal.getOrCreateInstance(document.getElementById('lynt-modal')).hide();
            noteNow(T.saved);
            load();
        }).fail(function (xhr) {
            $('#lynt-modal-errors').text(failureOf(xhr)).removeClass('d-none');
        });
    });

    function load() {
        $('#lynt-alert').addClass('d-none');
        $.getJSON(INDEX)
            .done(function (body) {
                state.templates = (body && body.templates) || [];
                // The WABA contexts carry the inboxes a new template can belong to. Taken
                // from the server rather than guessed, because a number that is not on this
                // WhatsApp Business Account cannot hold a template at all.
                state.inboxes = [];
                ((body && body.waba_contexts) || []).forEach(function (context) {
                    (context.inboxes || []).forEach(function (inbox) { state.inboxes.push(inbox); });
                });
                renderList();
            })
            .fail(alertNow);
    }

    load();
})(jQuery);
</script>
@endpush
