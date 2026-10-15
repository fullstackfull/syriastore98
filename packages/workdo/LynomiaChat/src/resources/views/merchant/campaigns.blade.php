@extends('layouts.app')

@section('page-title', __('Campaigns'))

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('lynomia.overview') }}">{{ __('Communication & Marketing') }}</a></li>
    <li class="breadcrumb-item">{{ __('Campaigns') }}</li>
@endsection

{{--
    CAMPAIGNS: sending an approved message to a chosen audience.

    THE PREVIEW IS THE SAME QUERY AS THE SEND. The number shown before sending is produced by
    the same audience resolution the sender uses, with the same consent filter and the same
    store scope -- so it already excludes everyone who has not agreed, has withdrawn, is
    suppressed or is blocked. A number computed any other way would be larger and would be a
    promise the send cannot keep.

    CANCELLING KEEPS EVERYTHING. Stopping a campaign is not deleting it: the campaign and its
    per-recipient results stay, because who was reached before it stopped is the single thing
    an operator who cancelled a half-sent campaign needs afterwards.

    THE RESULTS SHOW WHY SOMEBODY WAS NOT REACHED. "40 of 180 sent" reads as a failure; "140
    skipped: no marketing consent on record" is an answer a merchant can act on. The reasons
    are fixed strings from the server and carry no customer data.

    Blade, Bootstrap 5 and jQuery, as the rest of this dashboard is. Logical utilities
    throughout, so it flips with the page in Arabic. Merchant-typed text via .text(), never
    .html().
--}}

@push('css')
<style>
    .lync-camp { border: 1px solid rgba(0,0,0,.09); border-radius: .5rem; }
    .lync-num { font-variant-numeric: tabular-nums; }
    @media (max-width: 575.98px) { .lync-camp-actions > .btn { flex: 1 1 auto; } }
</style>
@endpush

@section('content')
<div id="lyncp-root"
     data-endpoint-index="{{ route('lynomia.campaigns.api.index') }}"
     data-endpoint-store="{{ route('lynomia.campaigns.api.store') }}"
     data-endpoint-preview="{{ route('lynomia.campaigns.api.preview') }}"
     data-endpoint-audiences="{{ route('lynomia.audiences.api.index') }}"
     data-update-template="{{ route('lynomia.campaigns.api.update', ['campaign' => 0]) }}"
     data-cancel-template="{{ route('lynomia.campaigns.api.cancel', ['campaign' => 0]) }}"
     data-destroy-template="{{ route('lynomia.campaigns.api.destroy', ['campaign' => 0]) }}"
     data-results-template="{{ route('lynomia.campaigns.api.results', ['campaign' => 0]) }}">

    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-start mb-3">
        <div>
            <h5 class="mb-1">{{ __('Campaigns') }}</h5>
            <small class="text-muted">
                {{ __('One message sent to a chosen audience. The number shown before you send is the number that will actually be reached.') }}
            </small>
        </div>
        <button type="button" class="btn btn-primary btn-sm" id="lyncp-new">{{ __('New campaign') }}</button>
    </div>

    <div id="lyncp-alert" class="alert alert-danger d-none" role="alert" aria-live="polite"></div>
    <div id="lyncp-note" class="alert alert-info d-none" role="status" aria-live="polite"></div>

    <div id="lyncp-list" class="d-grid gap-2"></div>
</div>

<div class="modal fade" id="lyncp-modal" tabindex="-1" aria-labelledby="lyncp-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="lyncp-modal-title">{{ __('New campaign') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>
            <div class="modal-body">
                <div id="lyncp-modal-errors" class="alert alert-danger d-none" role="alert" aria-live="polite"></div>

                <label for="lyncp-title" class="form-label small">{{ __('Name') }}</label>
                <input type="text" class="form-control form-control-sm mb-3" id="lyncp-title" maxlength="255"
                       placeholder="{{ __('for example Spring sale') }}">

                <label for="lyncp-audience" class="form-label small">{{ __('Audience') }}</label>
                <select class="form-select form-select-sm mb-2" id="lyncp-audience"></select>

                {{-- The count, before the send, from the same query the send uses. --}}
                <p class="small mb-3 lync-num" id="lyncp-preview"></p>

                <label for="lyncp-message" class="form-label small">{{ __('Message') }}</label>
                <textarea class="form-control form-control-sm mb-3" id="lyncp-message" rows="4" maxlength="10000"></textarea>

                <label for="lyncp-schedule" class="form-label small">{{ __('Send at') }}</label>
                <input type="datetime-local" class="form-control form-control-sm" id="lyncp-schedule">
                <p class="text-muted small mt-1 mb-0">{{ __('Leave empty to send as soon as it is saved.') }}</p>
            </div>
            <div class="modal-footer lync-camp-actions d-flex gap-2">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="button" class="btn btn-primary btn-sm" id="lyncp-save">{{ __('Save campaign') }}</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="lyncp-results" tabindex="-1" aria-labelledby="lyncp-results-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="lyncp-results-title">{{ __('Results') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>
            <div class="modal-body">
                <div id="lyncp-metrics" class="d-flex flex-wrap gap-3 mb-3 lync-num"></div>

                {{-- WHY THE UNSENT WERE UNSENT. The counters above say 40 of 180; this says
                     140 had no marketing consent on record. The first reads as a failure and
                     sends a merchant looking for a fault; the second is a fact about a
                     working system and tells them what to do next. --}}
                <div id="lyncp-skips" class="mb-3"></div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Customer') }}</th>
                                <th scope="col">{{ __('Outcome') }}</th>
                                <th scope="col">{{ __('Why not') }}</th>
                            </tr>
                        </thead>
                        <tbody id="lyncp-recipients"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function ($) {
    'use strict';

    var root = document.getElementById('lyncp-root');
    if (!root) { return; }

    var INDEX = root.getAttribute('data-endpoint-index');
    var STORE = root.getAttribute('data-endpoint-store');
    var PREVIEW = root.getAttribute('data-endpoint-preview');
    var AUDIENCES = root.getAttribute('data-endpoint-audiences');
    var UPDATE_TEMPLATE = root.getAttribute('data-update-template');
    var CANCEL_TEMPLATE = root.getAttribute('data-cancel-template');
    var DESTROY_TEMPLATE = root.getAttribute('data-destroy-template');
    var RESULTS_TEMPLATE = root.getAttribute('data-results-template');
    var PAGE_LOCALE = document.documentElement.getAttribute('lang') || 'en';

    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') } });

    var T = {
        failed: @json(__('Lynomia Chat could not complete that request. Please contact support.')),
        empty: @json(__('No campaigns yet.')),
        saved: @json(__('Saved.')),
        cancelled: @json(__('Cancelled. Nothing further will be sent.')),
        deleted: @json(__('Deleted.')),
        confirmCancel: @json(__('Stop this campaign? Messages already sent cannot be recalled, and the results are kept.')),
        confirmDelete: @json(__('Delete this campaign and its results?')),
        titleRequired: @json(__('Give the campaign a name.')),
        messageRequired: @json(__('Write the message.')),
        audienceRequired: @json(__('Choose an audience.')),
        willReach: @json(__('This will reach :count customers. Everyone who has not agreed, has opted out or cannot be messaged is already excluded.')),
        counting: @json(__('counting…')),
        statuses: {
            active: @json(__('Scheduled')),
            processing: @json(__('Sending now')),
            completed: @json(__('Finished')),
            blocked: @json(__('Held — WhatsApp campaigns are not available on this plan')),
            cancelled: @json(__('Cancelled'))
        },
        outcomes: {
            sent: @json(__('Sent')), delivered: @json(__('Delivered')), read: @json(__('Read')),
            failed: @json(__('Failed')), skipped: @json(__('Not sent')), pending: @json(__('Waiting'))
        },
        metrics: {
            audience: @json(__('Audience')), sent: @json(__('Sent')), delivered: @json(__('Delivered')),
            read: @json(__('Read')), failed: @json(__('Failed')), skipped: @json(__('Not sent'))
        },
        whyNotSent: @json(__('Why they were not sent to')),
        allSent: @json(__('Everyone in the audience was sent to.')),
        actions: {
            edit: @json(__('Edit')), cancel: @json(__('Stop')),
            results: @json(__('Results')), destroy: @json(__('Delete'))
        },
        scheduledFor: @json(__('due :when')),
        notScheduled: @json(__('no send time set'))
    };

    function text(v) { return v === null || v === undefined ? '' : String(v); }
    function failureOf(xhr) {
        var body = xhr && xhr.responseJSON;
        return (body && typeof body.error === 'string' && body.error) || T.failed;
    }
    function alertNow(xhr) { $('#lyncp-alert').text(failureOf(xhr)).removeClass('d-none'); }
    function noteNow(message) { $('#lyncp-note').text(message).removeClass('d-none'); }
    function urlFor(template, id) { return template.replace(/\/0(?=\/|$)/, '/' + String(id)); }
    function when(value) {
        if (!value) { return ''; }
        var parsed = new Date(value);
        return isNaN(parsed.getTime()) ? '' : parsed.toLocaleString(PAGE_LOCALE);
    }

    var state = { campaigns: [], audiences: [], editing: null };

    function renderList() {
        var $list = $('#lyncp-list').empty();

        if (!state.campaigns.length) {
            $list.append($('<p>').addClass('text-muted mb-0').text(T.empty));

            return;
        }

        state.campaigns.forEach(function (campaign) {
            var $card = $('<div>').addClass('lync-camp p-3');

            var $head = $('<div>').addClass('d-flex flex-wrap gap-2 justify-content-between align-items-start mb-2');
            $head.append($('<div>').addClass('fw-semibold').text(campaign.title));
            $head.append($('<span>').addClass('badge ' + tone(campaign)).text(T.statuses[campaign.campaign_status] || campaign.campaign_status));
            $card.append($head);

            $card.append($('<div>').addClass('small text-muted mb-2')
                .text(campaign.scheduled_at ? T.scheduledFor.replace(':when', when(campaign.scheduled_at)) : T.notScheduled));

            // WHY IT IS NOT SENDING, when something stopped it. An operator's cancellation
            // note goes in the same field as the capability reason, because both answer the
            // one question a merchant asks of a campaign that did nothing.
            if (campaign.blocked_reason) {
                $card.append($('<div>').addClass('small text-danger mb-2').text(campaign.blocked_reason));
            }

            $card.append(actionsFor(campaign));
            $list.append($card);
        });
    }

    function tone(campaign) {
        if (campaign.campaign_status === 'completed') { return 'bg-success'; }
        if (campaign.campaign_status === 'processing') { return 'bg-primary'; }
        if (['cancelled', 'blocked'].indexOf(campaign.campaign_status) !== -1) { return 'bg-danger'; }

        return 'bg-secondary';
    }

    // WHAT MAY BE DONE FOLLOWS THE STATUS. A finished campaign cannot be edited or stopped;
    // a cancelled one cannot be stopped again. The server refuses either way -- this keeps
    // the page from offering a button whose only outcome is an error.
    function actionsFor(campaign) {
        var $actions = $('<div>').addClass('d-flex gap-2 flex-wrap lync-camp-actions');
        var running = campaign.campaign_status === 'processing';
        var finished = campaign.campaign_status === 'completed' || campaign.campaign_status === 'cancelled';

        if (!finished && !running) {
            $actions.append(button(T.actions.edit, 'btn-light', function () { openEditor(campaign); }));
        }
        if (!finished) {
            $actions.append(button(T.actions.cancel, 'btn-outline-danger', function () { stop(campaign); }));
        }
        $actions.append(button(T.actions.results, 'btn-light', function () { showResults(campaign); }));
        if (finished || campaign.campaign_status === 'active') {
            $actions.append(button(T.actions.destroy, 'btn-outline-danger', function () { remove(campaign); }));
        }

        return $actions;
    }

    function button(label, variant, handler) {
        return $('<button>').addClass('btn btn-sm ' + variant).text(label).on('click', handler);
    }

    function stop(campaign) {
        if (!window.confirm(T.confirmCancel)) { return; }

        $.ajax({ url: urlFor(CANCEL_TEMPLATE, campaign.display_id), method: 'POST', dataType: 'json' })
            .done(function () { noteNow(T.cancelled); load(); })
            .fail(alertNow);
    }

    function remove(campaign) {
        if (!window.confirm(T.confirmDelete)) { return; }

        $.ajax({ url: urlFor(DESTROY_TEMPLATE, campaign.display_id), method: 'DELETE', dataType: 'json' })
            .done(function () { noteNow(T.deleted); load(); })
            .fail(alertNow);
    }

    // ---------------------------------------------------------------- results

    function showResults(campaign) {
        $.getJSON(urlFor(RESULTS_TEMPLATE, campaign.id || campaign.display_id))
            .done(function (body) {
                renderMetrics((body && body.metrics) || {});
                renderSkipReasons((body && body.skip_reasons) || []);
                renderRecipients((body && body.recipients) || []);
                bootstrap.Modal.getOrCreateInstance(document.getElementById('lyncp-results')).show();
            })
            .fail(alertNow);
    }

    function renderMetrics(metrics) {
        var $wrap = $('#lyncp-metrics').empty();
        Object.keys(T.metrics).forEach(function (key) {
            var value = metrics[key];
            if (value === undefined && metrics.status_counts) { value = metrics.status_counts[key]; }
            if (value === undefined) { return; }

            $wrap.append($('<div>').addClass('me-3')
                .append($('<div>').addClass('fw-semibold').text(text(value)))
                .append($('<div>').addClass('text-muted small').text(T.metrics[key])));
        });
    }

    function renderSkipReasons(reasons) {
        var $wrap = $('#lyncp-skips').empty();

        if (!reasons.length) {
            return $wrap.append($('<p>').addClass('text-muted small mb-0').text(T.allSent));
        }

        $wrap.append($('<div>').addClass('small fw-semibold mb-1').text(T.whyNotSent));
        var $list = $('<ul>').addClass('list-unstyled small mb-0');
        reasons.forEach(function (row) {
            // The reason is a fixed string from the server carrying no customer data, so it
            // is shown as it is rather than mapped -- a map here would silently drop a reason
            // added upstream.
            $list.append($('<li>').addClass('lync-num')
                .append($('<span>').addClass('fw-semibold me-2').text(text(row.count)))
                .append($('<span>').addClass('text-muted').text(text(row.reason))));
        });
        $wrap.append($list);
    }

    function renderRecipients(rows) {
        var $body = $('#lyncp-recipients').empty();

        rows.forEach(function (row) {
            $body.append($('<tr>')
                .append($('<td>').text(row.contact_name))
                .append($('<td>').text(T.outcomes[row.status] || row.status))
                // THE REASON, which is the half a delivery count cannot tell.
                .append($('<td>').addClass('small text-muted').text(text(row.reason))));
        });
    }

    // ---------------------------------------------------------------- the editor

    function openEditor(campaign) {
        state.editing = campaign || null;
        $('#lyncp-modal-errors').addClass('d-none').text('');
        $('#lyncp-modal-title').text(campaign ? @json(__('Edit campaign')) : @json(__('New campaign')));
        $('#lyncp-title').val(campaign ? campaign.title : '');
        $('#lyncp-message').val(campaign ? campaign.message : '');
        $('#lyncp-schedule').val('');
        renderAudiences(campaign);
        previewAudience();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('lyncp-modal')).show();
    }

    function renderAudiences(campaign) {
        var $select = $('#lyncp-audience').empty();
        state.audiences.forEach(function (audience) {
            $select.append($('<option>').attr('value', audience.id).text(audience.name));
        });

        var chosen = campaign && (campaign.audience || []).filter(function (entry) {
            return entry.type === 'Audience';
        })[0];
        if (chosen) { $select.val(chosen.id); }
    }

    function chosenAudience() {
        var id = $('#lyncp-audience').val();

        return id ? [{ type: 'Audience', id: id }] : [];
    }

    // THE SAME QUERY THE SEND USES, so the number is the number.
    function previewAudience() {
        var audience = chosenAudience();
        if (!audience.length) { return $('#lyncp-preview').text(''); }

        $('#lyncp-preview').text(T.counting);
        $.ajax({ url: PREVIEW, method: 'POST', dataType: 'json', data: { audience: audience } })
            .done(function (body) { $('#lyncp-preview').text(T.willReach.replace(':count', text(body.count))); })
            .fail(function () { $('#lyncp-preview').text(''); });
    }

    $('#lyncp-audience').on('change', previewAudience);
    $('#lyncp-new').on('click', function () { openEditor(null); });

    $('#lyncp-save').on('click', function () {
        var title = $.trim($('#lyncp-title').val());
        if (!title) { return $('#lyncp-modal-errors').text(T.titleRequired).removeClass('d-none'); }
        var message = $.trim($('#lyncp-message').val());
        if (!message) { return $('#lyncp-modal-errors').text(T.messageRequired).removeClass('d-none'); }
        var audience = chosenAudience();
        if (!audience.length) { return $('#lyncp-modal-errors').text(T.audienceRequired).removeClass('d-none'); }

        var editing = state.editing;
        var payload = { title: title, message: message, audience: audience };
        var scheduled = $('#lyncp-schedule').val();
        if (scheduled) { payload.scheduled_at = scheduled; }
        if (!editing) { payload.inbox_id = $('#lyncp-audience').attr('data-inbox') || 1; }

        $.ajax({
            url: editing ? urlFor(UPDATE_TEMPLATE, editing.display_id) : STORE,
            method: editing ? 'PATCH' : 'POST',
            dataType: 'json',
            data: payload
        }).done(function () {
            bootstrap.Modal.getOrCreateInstance(document.getElementById('lyncp-modal')).hide();
            noteNow(T.saved);
            load();
        }).fail(function (xhr) {
            $('#lyncp-modal-errors').text(failureOf(xhr)).removeClass('d-none');
        });
    });

    function load() {
        $('#lyncp-alert').addClass('d-none');
        $.getJSON(AUDIENCES)
            .done(function (body) { state.audiences = (body && body.audiences) || []; })
            .always(function () {
                $.getJSON(INDEX)
                    .done(function (body) { state.campaigns = (body && body.campaigns) || []; renderList(); })
                    .fail(alertNow);
            });
    }

    load();
})(jQuery);
</script>
@endpush
