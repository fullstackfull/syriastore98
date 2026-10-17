@extends('layouts.app')

@section('page-title', __('Audiences'))

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('lynomia.overview') }}">{{ __('Communication & Marketing') }}</a></li>
    <li class="breadcrumb-item">{{ __('Audiences') }}</li>
@endsection

{{--
    AUDIENCES: who a campaign may be sent to.

    AN AUDIENCE IS A QUERY, NOT A LIST. It is evaluated by Lynomia Chat against its own
    contacts and its own order summaries at the moment a campaign sends -- so an audience
    written today selects whoever qualifies the day it is used. The page never shows the
    people in an audience, only how many there are: the contacts belong to the conversation
    system, and a merchant does not need a downloadable list of customers to decide whether a
    segment is the right size.

    TWO COUNTS, AND THE DIFFERENCE IS THE POINT. "Customers of this store" is what a campaign
    from here will actually reach; "across all your stores" is what the same conditions select
    account-wide. A merchant with one store sees the same number twice and ignores it; a
    merchant with several sees immediately that their audience is mostly somebody else's
    customers, which is the single most useful thing this page can tell them.

    THE CONDITION VOCABULARY COMES FROM THE SERVER. Which commerce fields exist, which stores
    and currencies they can be built from, and how many contacts have not been read yet are
    all answers Lynomia gives. Nothing is hardcoded here, so a field added upstream appears
    without this page being edited and a field removed stops being offered.

    Blade, Bootstrap 5 and jQuery, as the rest of this dashboard is. Logical utilities
    throughout, so it flips with the page in Arabic. Merchant-typed text via .text(), never
    .html().
--}}

@push('css')
<style>
    .lyna-aud { border: 1px solid rgba(0,0,0,.09); border-radius: .5rem; }
    .lyna-cond { border-inline-start: 3px solid rgba(13,110,253,.35); }
    .lyna-count { font-variant-numeric: tabular-nums; }
    @media (max-width: 575.98px) { .lyna-actions > .btn { flex: 1 1 auto; } }
</style>
@endpush

@section('content')
<div id="lyna-root"
     data-endpoint-index="{{ route('lynomia.audiences.api.index') }}"
     data-endpoint-references="{{ route('lynomia.audiences.api.references') }}"
     data-endpoint-store="{{ route('lynomia.audiences.api.store') }}"
     data-endpoint-preview="{{ route('lynomia.audiences.api.preview') }}"
     data-update-template="{{ route('lynomia.audiences.api.update', ['audience' => 0]) }}"
     data-destroy-template="{{ route('lynomia.audiences.api.destroy', ['audience' => 0]) }}">

    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-start mb-3">
        <div>
            <h5 class="mb-1">{{ __('Audiences') }}</h5>
            <small class="text-muted">
                {{ __('Groups of customers your campaigns can be sent to. Each one is worked out again every time it is used.') }}
            </small>
        </div>
        <button type="button" class="btn btn-primary btn-sm" id="lyna-new">{{ __('New audience') }}</button>
    </div>

    <div id="lyna-alert" class="alert alert-danger d-none" role="alert" aria-live="polite"></div>
    <div id="lyna-note" class="alert alert-info d-none" role="status" aria-live="polite"></div>

    <div id="lyna-list" class="d-grid gap-2"></div>
</div>

{{-- The editor. A modal rather than a page: an audience is a handful of conditions, and
     losing the dashboard to edit three rows is worse than a dialog. --}}
<div class="modal fade" id="lyna-modal" tabindex="-1" aria-labelledby="lyna-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="lyna-modal-title">{{ __('New audience') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>
            <div class="modal-body">
                <div id="lyna-modal-errors" class="alert alert-danger d-none" role="alert" aria-live="polite"></div>

                <label for="lyna-name" class="form-label small">{{ __('Name') }}</label>
                <input type="text" class="form-control form-control-sm mb-3" id="lyna-name" maxlength="100"
                       placeholder="{{ __('for example Repeat buyers') }}">

                <label for="lyna-match" class="form-label small">{{ __('A customer is in this audience when') }}</label>
                <select class="form-select form-select-sm mb-3" id="lyna-match">
                    <option value="and">{{ __('every condition is true') }}</option>
                    <option value="or">{{ __('any condition is true') }}</option>
                </select>

                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label small mb-0">{{ __('Conditions') }}</label>
                    <small class="text-muted">{{ __('A customer must match all of them.') }}</small>
                </div>
                <div id="lyna-conditions" class="d-grid gap-2 mb-2"></div>
                <button type="button" class="btn btn-sm btn-light" id="lyna-add-condition">{{ __('Add condition') }}</button>

                <p class="text-muted small mt-3 mb-0" id="lyna-unread"></p>
            </div>
            <div class="modal-footer lyna-actions d-flex gap-2">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="button" class="btn btn-primary btn-sm" id="lyna-save">{{ __('Save') }}</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function ($) {
    'use strict';

    var root = document.getElementById('lyna-root');
    if (!root) { return; }

    var INDEX = root.getAttribute('data-endpoint-index');
    var REFERENCES = root.getAttribute('data-endpoint-references');
    var STORE = root.getAttribute('data-endpoint-store');
    var PREVIEW = root.getAttribute('data-endpoint-preview');
    var UPDATE_TEMPLATE = root.getAttribute('data-update-template');
    var DESTROY_TEMPLATE = root.getAttribute('data-destroy-template');
    var PAGE_LOCALE = document.documentElement.getAttribute('lang') || 'en';

    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') } });

    var T = {
        failed: @json(__('Lynomia Chat could not complete that request. Please contact support.')),
        empty: @json(__('No audiences yet. Create one to decide who your campaigns reach.')),
        confirmDelete: @json(__('Delete this audience? Campaigns that use it will have to be pointed somewhere else.')),
        saved: @json(__('Saved.')),
        deleted: @json(__('Deleted.')),
        nameRequired: @json(__('Give the audience a name.')),
        conditionRequired: @json(__('Add at least one condition.')),
        valueRequired: @json(__('Fill in every condition, or remove the empty ones.')),
        thisStore: @json(__('customers of this store')),
        allStores: @json(__('across all your stores')),
        counting: @json(__('counting…')),
        unread: @json(__(':count linked customers have not been read from your store yet, so their order conditions cannot be answered.')),
        remove: @json(__('Remove')),
        fields: {
            commerce_store: @json(__('Store')),
            commerce_provider: @json(__('Store platform')),
            commerce_orders_count: @json(__('Number of orders')),
            commerce_last_purchase_at: @json(__('Last purchase')),
            commerce_active_order: @json(__('Has an order in progress')),
            commerce_order_status: @json(__('Order status')),
            commerce_payment_status: @json(__('Payment status')),
            commerce_shipment_status: @json(__('Delivery status'))
        },
        operators: {
            equal_to: @json(__('is')), not_equal_to: @json(__('is not')),
            is_present: @json(__('is set')), is_not_present: @json(__('is not set')),
            is_greater_than: @json(__('is more than')), is_less_than: @json(__('is less than')),
            days_before: @json(__('is more days ago than'))
        },
        spend: @json(__('Total spent (:currency)'))
    };

    function text(v) { return v === null || v === undefined ? '' : String(v); }
    function failureOf(xhr) {
        var body = xhr && xhr.responseJSON;
        return (body && typeof body.error === 'string' && body.error) || T.failed;
    }
    function alertNow(xhr) { $('#lyna-alert').text(failureOf(xhr)).removeClass('d-none'); }
    function noteNow(message) { $('#lyna-note').text(message).removeClass('d-none'); }
    function urlFor(template, id) { return template.replace(/0(?!.*\d)/, String(id)); }

    var state = { audiences: [], references: { stores: [], currencies: [], unread_contacts: 0 }, editing: null, draft: [] };

    // ---------------------------------------------------------------- the vocabulary
    //
    // Built from what the server reports rather than hardcoded, so a field added upstream
    // appears here without this page being edited, and a currency the merchant has never
    // taken an order in is never offered.
    function fieldOptions() {
        var options = Object.keys(T.fields).map(function (key) { return { key: key, label: T.fields[key] }; });

        (state.references.currencies || []).forEach(function (currency) {
            options.push({
                key: 'commerce_spend_' + String(currency).toLowerCase(),
                label: T.spend.replace(':currency', String(currency).toUpperCase())
            });
        });

        return options;
    }

    var OPERATORS = {
        commerce_store: ['equal_to', 'not_equal_to', 'is_present', 'is_not_present'],
        commerce_provider: ['equal_to', 'not_equal_to'],
        commerce_orders_count: ['equal_to', 'is_greater_than', 'is_less_than'],
        commerce_last_purchase_at: ['is_greater_than', 'is_less_than', 'days_before'],
        commerce_active_order: ['equal_to'],
        commerce_order_status: ['equal_to', 'not_equal_to'],
        commerce_payment_status: ['equal_to', 'not_equal_to'],
        commerce_shipment_status: ['equal_to', 'not_equal_to']
    };
    var SPEND_OPERATORS = ['is_greater_than', 'is_less_than'];

    function operatorsFor(key) {
        return OPERATORS[key] || (key.indexOf('commerce_spend_') === 0 ? SPEND_OPERATORS : ['equal_to']);
    }

    var VALUES = {
        commerce_order_status: ['pending', 'processing', 'on_hold', 'shipped', 'delivered', 'completed',
            'cancelled', 'refunded', 'failed', 'draft', 'other'],
        commerce_payment_status: ['paid', 'unpaid', 'partially_paid', 'failed', 'refunded', 'partially_refunded', 'unknown'],
        commerce_shipment_status: ['pending', 'in_transit', 'out_for_delivery', 'delivered', 'failed', 'cancelled', 'returned', 'other'],
        commerce_active_order: ['true', 'false']
    };

    // ---------------------------------------------------------------- the list

    function renderList() {
        var $list = $('#lyna-list').empty();

        if (!state.audiences.length) {
            $list.append($('<p>').addClass('text-muted mb-0').text(T.empty));

            return;
        }

        state.audiences.forEach(function (audience) {
            var $card = $('<div>').addClass('lyna-aud p-3 d-flex flex-wrap gap-3 justify-content-between align-items-start');
            var $left = $('<div>');
            $left.append($('<div>').addClass('fw-semibold').text(audience.name));
            $left.append($('<div>').addClass('text-muted small').text(summarise(audience.payload)));

            var $counts = $('<div>').addClass('text-end lyna-count');
            $counts.append($('<div>').addClass('fw-semibold').attr('data-count-for', audience.id).text(T.counting));
            $counts.append($('<div>').addClass('text-muted small').attr('data-count-all-for', audience.id).text(''));

            var $actions = $('<div>').addClass('d-flex gap-2 lyna-actions');
            $actions.append($('<button>').addClass('btn btn-sm btn-light').text(@json(__('Edit')))
                .on('click', function () { openEditor(audience); }));
            $actions.append($('<button>').addClass('btn btn-sm btn-outline-danger').text(@json(__('Delete')))
                .on('click', function () { removeAudience(audience); }));

            $list.append($card.append($left).append($counts).append($actions));
            countFor(audience);
        });
    }

    function summarise(payload) {
        return (payload || []).map(function (condition) {
            var field = T.fields[condition.attribute_key]
                || (condition.attribute_key.indexOf('commerce_spend_') === 0
                    ? T.spend.replace(':currency', condition.attribute_key.slice(15).toUpperCase())
                    : condition.attribute_key);

            return field + ' ' + (T.operators[condition.filter_operator] || condition.filter_operator)
                + ' ' + (condition.values || []).join(', ');
        }).join(' · ');
    }

    // TWO COUNTS PER AUDIENCE, and the difference is the useful part: a merchant with several
    // stores learns at a glance that their segment is mostly other stores' customers.
    function countFor(audience) {
        $.ajax({ url: PREVIEW, method: 'POST', dataType: 'json', data: { audience_id: audience.id } })
            .done(function (body) {
                $('[data-count-for="' + audience.id + '"]').text(text(body.count) + ' ' + T.thisStore);
                $('[data-count-all-for="' + audience.id + '"]').text(text(body.count_all_stores) + ' ' + T.allStores);
            })
            .fail(function () { $('[data-count-for="' + audience.id + '"]').text('—'); });
    }

    // ---------------------------------------------------------------- the editor

    function openEditor(audience) {
        state.editing = audience || null;
        state.draft = audience ? JSON.parse(JSON.stringify(audience.payload || [])) : [];
        $('#lyna-modal-errors').addClass('d-none').text('');
        $('#lyna-name').val(audience ? audience.name : '');
        // READ FROM THE AUDIENCE, not assumed. The payload carried `query_operator: 'and'`
        // unconditionally, so editing an audience built with OR anywhere else -- the Lynomia
        // dashboard, the API -- rewrote it to AND and silently narrowed who it selects. The
        // operator belongs to the audience, so it is shown and sent back as it was found.
        $('#lyna-match').val(operatorOf(audience));
        $('#lyna-modal-title').text(audience ? @json(__('Edit audience')) : @json(__('New audience')));
        $('#lyna-unread').text(
            state.references.unread_contacts > 0
                ? T.unread.replace(':count', state.references.unread_contacts)
                : ''
        );
        renderConditions();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('lyna-modal')).show();
    }

    function renderConditions() {
        var $wrap = $('#lyna-conditions').empty();

        state.draft.forEach(function (condition, index) {
            var $row = $('<div>').addClass('lyna-cond ps-2 d-flex flex-wrap gap-2 align-items-center');

            var $field = $('<select>').addClass('form-select form-select-sm').css('max-width', '14rem');
            fieldOptions().forEach(function (option) {
                $field.append($('<option>').attr('value', option.key).text(option.label));
            });
            $field.val(condition.attribute_key).on('change', function () {
                state.draft[index].attribute_key = $(this).val();
                state.draft[index].filter_operator = operatorsFor($(this).val())[0];
                state.draft[index].values = [];
                renderConditions();
            });

            var $operator = $('<select>').addClass('form-select form-select-sm').css('max-width', '12rem');
            operatorsFor(condition.attribute_key).forEach(function (name) {
                $operator.append($('<option>').attr('value', name).text(T.operators[name] || name));
            });
            $operator.val(condition.filter_operator).on('change', function () {
                state.draft[index].filter_operator = $(this).val();
                renderConditions();
            });

            $row.append($field).append($operator).append(valueControl(condition, index));
            $row.append($('<button>').addClass('btn btn-sm btn-link text-danger').text(T.remove)
                .on('click', function () { state.draft.splice(index, 1); renderConditions(); }));

            $wrap.append($row);
        });
    }

    function valueControl(condition, index) {
        // A presence check takes no value, so offering an empty box would invite a merchant
        // to type one that is then discarded.
        if (condition.filter_operator === 'is_present' || condition.filter_operator === 'is_not_present') {
            return $('<span>').addClass('text-muted small').text(@json(__('no value needed')));
        }

        if (condition.attribute_key === 'commerce_store') {
            var $stores = $('<select>').addClass('form-select form-select-sm').css('max-width', '16rem');
            (state.references.stores || []).forEach(function (store) {
                $stores.append($('<option>').attr('value', store.id).text(store.name || store.provider));
            });
            $stores.val((condition.values || [])[0]);
            $stores.on('change', function () { state.draft[index].values = [$(this).val()]; });

            return $stores;
        }

        if (VALUES[condition.attribute_key]) {
            var $choice = $('<select>').addClass('form-select form-select-sm').css('max-width', '16rem');
            VALUES[condition.attribute_key].forEach(function (value) {
                $choice.append($('<option>').attr('value', value).text(value));
            });
            $choice.val((condition.values || [])[0]);
            $choice.on('change', function () { state.draft[index].values = [$(this).val()]; });

            return $choice;
        }

        var $input = $('<input>').addClass('form-control form-control-sm').css('max-width', '12rem')
            .attr('type', condition.attribute_key === 'commerce_last_purchase_at'
                && condition.filter_operator !== 'days_before' ? 'date' : 'text')
            .val((condition.values || [])[0] || '');
        $input.on('input', function () { state.draft[index].values = [$(this).val()]; });

        return $input;
    }

    $('#lyna-add-condition').on('click', function () {
        var first = fieldOptions()[0];
        state.draft.push({ attribute_key: first.key, filter_operator: operatorsFor(first.key)[0], values: [] });
        renderConditions();
    });

    $('#lyna-new').on('click', function () { openEditor(null); });

    // One operator for the whole audience, which is what the control offers and what the
    // conditions upstream are read with. Taken from the first condition that states one: a
    // payload is a list and every entry carries the same value.
    function operatorOf(audience) {
        var conditions = (audience && audience.payload) || [];
        for (var i = 0; i < conditions.length; i++) {
            var value = String(conditions[i].query_operator || '').toLowerCase();
            if (value === 'or' || value === 'and') { return value; }
        }

        return 'and';
    }

    $('#lyna-save').on('click', function () {
        var name = $.trim($('#lyna-name').val());
        if (!name) { return $('#lyna-modal-errors').text(T.nameRequired).removeClass('d-none'); }
        if (!state.draft.length) { return $('#lyna-modal-errors').text(T.conditionRequired).removeClass('d-none'); }

        var incomplete = state.draft.some(function (condition) {
            var needsValue = condition.filter_operator !== 'is_present' && condition.filter_operator !== 'is_not_present';

            return needsValue && !(condition.values || []).filter(function (v) { return $.trim(text(v)) !== ''; }).length;
        });
        if (incomplete) { return $('#lyna-modal-errors').text(T.valueRequired).removeClass('d-none'); }

        var chosen = $('#lyna-match').val() === 'or' ? 'or' : 'and';
        var payload = state.draft.map(function (condition) {
            return {
                attribute_key: condition.attribute_key,
                filter_operator: condition.filter_operator,
                values: condition.values || [],
                query_operator: chosen
            };
        });

        var editing = state.editing;
        $.ajax({
            url: editing ? urlFor(UPDATE_TEMPLATE, editing.id) : STORE,
            method: editing ? 'PATCH' : 'POST',
            dataType: 'json',
            data: { name: name, payload: payload }
        }).done(function () {
            bootstrap.Modal.getOrCreateInstance(document.getElementById('lyna-modal')).hide();
            noteNow(T.saved);
            load();
        }).fail(function (xhr) {
            $('#lyna-modal-errors').text(failureOf(xhr)).removeClass('d-none');
        });
    });

    function removeAudience(audience) {
        if (!window.confirm(T.confirmDelete)) { return; }

        $.ajax({ url: urlFor(DESTROY_TEMPLATE, audience.id), method: 'DELETE', dataType: 'json' })
            .done(function () { noteNow(T.deleted); load(); })
            // Upstream refuses to delete an audience a campaign or a rule still uses and says
            // which -- surfaced rather than pre-checked, because a pre-check can be stale by
            // the time the delete lands.
            .fail(alertNow);
    }

    function load() {
        $('#lyna-alert').addClass('d-none');
        $.getJSON(REFERENCES)
            .done(function (body) { state.references = body || state.references; })
            .always(function () {
                $.getJSON(INDEX)
                    .done(function (body) { state.audiences = (body && body.audiences) || []; renderList(); })
                    .fail(alertNow);
            });
    }

    load();
})(jQuery);
</script>
@endpush
