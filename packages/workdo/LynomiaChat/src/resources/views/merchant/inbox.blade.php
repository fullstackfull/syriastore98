@extends('layouts.app')

@section('page-title', __('Inbox'))

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('lynomia.overview') }}">{{ __('Communication & Marketing') }}</a></li>
    <li class="breadcrumb-item">{{ __('Inbox') }}</li>
@endsection

{{--
    THE NATIVE INBOX.

    Built in the stack this dashboard actually renders with -- Blade, Bootstrap 5 and jQuery,
    loaded from public/assets by layouts/app.blade.php. The repository also carries Vite, Alpine
    and Tailwind, but no dashboard view invokes the Vite directive and the layout loads none of
    them, so
    building here in Tailwind would have produced an unstyled page. Measured, not assumed.

    DIRECTION IS INHERITED, NOT RESTATED. layouts/app.blade.php already sets <html dir="rtl"> and
    swaps in style-rtl.css whenever the user's language is Arabic or Hebrew, so this markup uses
    Bootstrap's logical utilities (ms-*, me-*, text-start, text-end) and flips with the page
    instead of carrying a second RTL stylesheet of its own.

    EVERY PIECE OF CUSTOMER TEXT IS WRITTEN WITH .text(), NEVER .html(). A conversation is
    attacker-supplied content by definition -- anyone who can message the store can put a string
    in it -- so the renderer below builds nodes and sets textContent. The one exception is
    structural markup this file authors itself.
--}}

@push('css')
<style>
    .lyn-inbox { display: flex; gap: 0; height: calc(100vh - 230px); min-height: 32rem; }
    .lyn-pane { display: flex; flex-direction: column; min-width: 0; background: var(--bs-body-bg, #fff); }
    .lyn-list { width: 22rem; flex: 0 0 22rem; border-inline-end: 1px solid rgba(0,0,0,.09); }
    .lyn-thread { flex: 1 1 auto; }
    .lyn-context { width: 19rem; flex: 0 0 19rem; border-inline-start: 1px solid rgba(0,0,0,.09); }
    .lyn-scroll { overflow-y: auto; flex: 1 1 auto; }
    .lyn-conv { cursor: pointer; border-bottom: 1px solid rgba(0,0,0,.05); }
    .lyn-conv:hover, .lyn-conv.active { background: rgba(0,0,0,.035); }
    .lyn-bubble { max-width: 78%; border-radius: .75rem; padding: .5rem .75rem; word-break: break-word; white-space: pre-wrap; }
    .lyn-in  { background: rgba(0,0,0,.06); margin-inline-end: auto; }
    .lyn-out { background: rgba(13,110,253,.12); margin-inline-start: auto; }
    .lyn-note { background: rgba(255,193,7,.18); margin-inline-start: auto; border: 1px dashed rgba(255,193,7,.6); }
    .lyn-activity { font-size: .75rem; opacity: .7; text-align: center; margin: .35rem auto; }
    @media (max-width: 991.98px) {
        .lyn-inbox { flex-direction: column; height: auto; }
        .lyn-list, .lyn-context { width: 100%; flex: 1 1 auto; border: 0; }
        .lyn-scroll { max-height: 22rem; }
        .lyn-context[data-empty="true"] { display: none; }
    }
</style>
@endpush

@section('content')
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-body p-0">
                <div class="lyn-inbox" id="lyn-inbox"
                     data-endpoint-conversations="{{ route('lynomia.inbox.api.conversations') }}"
                     data-endpoint-agents="{{ route('lynomia.inbox.api.agents') }}"
                     data-endpoint-events="{{ route('lynomia.inbox.api.events') }}"
                     data-poll-seconds="{{ max(5, (int) config('lynomia.inbox_poll_seconds', 10)) }}"
                     data-can-reply="{{ $canReply ? '1' : '0' }}"
                     data-can-assign="{{ $canAssign ? '1' : '0' }}">

                    {{-- ------------------------------------------------ conversation list --}}
                    <div class="lyn-pane lyn-list">
                        <div class="p-3 border-bottom">
                            <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                <strong class="text-truncate">{{ $workspaceName ?: __('Inbox') }}</strong>
                                <button type="button" class="btn btn-sm btn-light" id="lyn-refresh"
                                        title="{{ __('Refresh') }}" aria-label="{{ __('Refresh') }}">
                                    <i class="ti ti-refresh" aria-hidden="true"></i>
                                </button>
                            </div>
                            <label for="lyn-search" class="visually-hidden">{{ __('Search conversations') }}</label>
                            <input type="search" id="lyn-search" class="form-control form-control-sm mb-2"
                                   placeholder="{{ __('Search conversations') }}" autocomplete="off">
                            <div class="d-flex gap-2">
                                <label for="lyn-status" class="visually-hidden">{{ __('Status') }}</label>
                                <select id="lyn-status" class="form-select form-select-sm">
                                    <option value="open">{{ __('Open') }}</option>
                                    <option value="pending">{{ __('Pending') }}</option>
                                    <option value="resolved">{{ __('Resolved') }}</option>
                                    <option value="all">{{ __('All') }}</option>
                                </select>
                                <label for="lyn-assignee" class="visually-hidden">{{ __('Assignment') }}</label>
                                <select id="lyn-assignee" class="form-select form-select-sm">
                                    <option value="all">{{ __('Everyone') }}</option>
                                    <option value="me">{{ __('Assigned to me') }}</option>
                                    <option value="unassigned">{{ __('Unassigned') }}</option>
                                </select>
                            </div>
                        </div>

                        <div class="lyn-scroll" id="lyn-conversations" role="list"
                             aria-label="{{ __('Conversations') }}" tabindex="0"></div>

                        <div class="p-2 border-top text-center">
                            <button type="button" class="btn btn-sm btn-light w-100 d-none" id="lyn-more">
                                {{ __('Load more') }}
                            </button>
                        </div>
                    </div>

                    {{-- ------------------------------------------------ active conversation --}}
                    <div class="lyn-pane lyn-thread">
                        <div class="p-3 border-bottom d-flex align-items-center justify-content-between gap-2 flex-wrap"
                             id="lyn-thread-header" hidden>
                            <div class="min-w-0">
                                <div class="fw-semibold text-truncate" id="lyn-thread-title"></div>
                                <small class="text-muted" id="lyn-thread-sub"></small>
                            </div>
                            <div class="d-flex gap-2 flex-wrap">
                                @if ($canAssign)
                                    <label for="lyn-assign-to" class="visually-hidden">{{ __('Assign to') }}</label>
                                    <select id="lyn-assign-to" class="form-select form-select-sm" style="width:auto">
                                        <option value="">{{ __('Unassigned') }}</option>
                                    </select>
                                    <label for="lyn-set-status" class="visually-hidden">{{ __('Set status') }}</label>
                                    <select id="lyn-set-status" class="form-select form-select-sm" style="width:auto">
                                        <option value="open">{{ __('Open') }}</option>
                                        <option value="pending">{{ __('Pending') }}</option>
                                        <option value="resolved">{{ __('Resolved') }}</option>
                                    </select>
                                @endif
                            </div>
                        </div>

                        <div class="lyn-scroll p-3 d-flex flex-column gap-2" id="lyn-messages"
                             role="log" aria-live="polite" aria-label="{{ __('Messages') }}">
                            <div class="text-center text-muted my-auto" id="lyn-thread-empty">
                                {{ __('Select a conversation to read it.') }}
                            </div>
                        </div>

                        @if ($canReply)
                            <form class="p-3 border-top" id="lyn-composer" hidden>
                                <label for="lyn-reply" class="visually-hidden">{{ __('Write a reply') }}</label>
                                <textarea id="lyn-reply" class="form-control mb-2" rows="2" maxlength="20000"
                                          placeholder="{{ __('Write a reply…') }}"></textarea>
                                <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="lyn-private">
                                        <label class="form-check-label small" for="lyn-private">
                                            {{ __('Internal note (the customer will not see this)') }}
                                        </label>
                                    </div>
                                    <button type="submit" class="btn btn-primary btn-sm" id="lyn-send">
                                        {{ __('Send') }}
                                    </button>
                                </div>
                            </form>
                        @else
                            <div class="p-3 border-top text-muted small">
                                {{ __('You can read these conversations but not reply to them.') }}
                            </div>
                        @endif
                    </div>

                    {{-- ------------------------------------------------ customer context --}}
                    <div class="lyn-pane lyn-context" id="lyn-context" data-empty="true">
                        <div class="p-3 border-bottom"><strong>{{ __('Customer') }}</strong></div>
                        <div class="lyn-scroll p-3" id="lyn-context-body">
                            <p class="text-muted small mb-0">{{ __('Select a conversation to see the customer.') }}</p>
                        </div>
                    </div>
                </div>

                <div class="alert alert-danger m-3 d-none" id="lyn-error" role="alert"></div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function ($) {
    'use strict';

    var root = document.getElementById('lyn-inbox');
    if (!root) { return; }

    // Every string the interface can show, translated server-side. Nothing user-supplied is in
    // here -- this is the product's own copy.
    // Lynomia names a channel by its MODEL CLASS -- 'Channel::Whatsapp'. That is an internal
    // identifier and must not reach a merchant, so it is mapped to the provider's own name.
    // These are brand names and are deliberately NOT translated; an unknown channel falls back
    // to the bare suffix rather than showing the full class path.
    // The page's language, not the browser's: a merchant who set Arabic should not get US
    // date formatting because their laptop is configured in English.
    var PAGE_LOCALE = document.documentElement.getAttribute('lang') || 'en';

    var CHANNEL_LABELS = {
        'Channel::Whatsapp': 'WhatsApp', 'Channel::Email': 'Email', 'Channel::Sms': 'SMS',
        'Channel::TwilioSms': 'Twilio', 'Channel::FacebookPage': 'Facebook',
        'Channel::Instagram': 'Instagram', 'Channel::Telegram': 'Telegram',
        'Channel::Line': 'LINE', 'Channel::Tiktok': 'TikTok',
        'Channel::TwitterProfile': 'X', 'Channel::WebWidget': 'Live chat', 'Channel::Api': 'API'
    };
    // The same leak as the channel: 'open' is an API value, and the filter buttons above
    // already have translated copy for exactly these four. Reuse it rather than shipping a
    // second vocabulary, and fall back to the raw value for a status this build does not know.
    function statusLabel(value) {
        if (!value) { return ''; }
        return (T.statuses && T.statuses[value]) || value;
    }

    function channelLabel(value) {
        if (!value) { return ''; }
        return CHANNEL_LABELS[value] || String(value).replace(/^Channel::/, '');
    }

    var T = {
        failed: @json(__('Lynomia Chat could not complete that request. Please contact support.')),
        empty: @json(__('No conversations here yet.')),
        loading: @json(__('Loading…')),
        you: @json(__('You')),
        statuses: {
            open: @json(__('Open')), pending: @json(__('Pending')),
            resolved: @json(__('Resolved')), snoozed: @json(__('Snoozed'))
        },
        customer: @json(__('Customer')),
        note: @json(__('Internal note')),
        unassigned: @json(__('Unassigned')),
        noMessages: @json(__('No messages in this conversation yet.')),
        sendFailed: @json(__('Your reply may not have been sent. Check the conversation before sending it again.')),
        attachment: @json(__('Attachment')),
        channel: @json(__('Channel')),
        status: @json(__('Status'))
    };

    var state = { conversationId: null, page: 1, status: 'open', assignee: 'all', search: '', loading: false, lastCount: 0 };
    // The rows the last fetch produced, so opening one needs no second round trip for facts the
    // list already carried.
    var lastById = {};
    var canReply = root.dataset.canReply === '1';
    var canAssign = root.dataset.canAssign === '1';

    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') } });

    function showError(message) {
        $('#lyn-error').removeClass('d-none').text(message || T.failed);
    }
    function clearError() { $('#lyn-error').addClass('d-none').text(''); }

    // One place that turns a failed request into a sentence. The server already decided what a
    // merchant may be told; this never invents a message from a status code it does not know.
    function errorFrom(xhr) {
        var body = xhr && xhr.responseJSON;
        return (body && typeof body.error === 'string' && body.error) ? body.error : T.failed;
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        // textContent, never innerHTML: this is where customer-controlled strings land.
        if (text !== undefined && text !== null) { node.textContent = String(text); }
        return node;
    }

    function timeLabel(value) {
        if (!value) { return ''; }
        // Upstream sends unix seconds for message timestamps and ISO for conversations.
        var date = typeof value === 'number' ? new Date(value * 1000) : new Date(value);
        return isNaN(date.getTime()) ? '' : date.toLocaleString(PAGE_LOCALE);
    }

    // ---------------------------------------------------------------- conversation list

    function conversationRow(conversation) {
        var row = el('div', 'lyn-conv p-3 d-flex flex-column gap-1');
        row.setAttribute('role', 'listitem');
        row.setAttribute('tabindex', '0');
        row.dataset.id = conversation.id;
        if (state.conversationId === conversation.id) { row.classList.add('active'); }

        var top = el('div', 'd-flex align-items-center justify-content-between gap-2');
        top.appendChild(el('span', 'fw-semibold text-truncate', (conversation.contact && conversation.contact.name) || T.customer));
        if (conversation.unread_count > 0) {
            top.appendChild(el('span', 'badge bg-primary rounded-pill', conversation.unread_count));
        }
        row.appendChild(top);

        row.appendChild(el('small', 'text-muted text-truncate', conversation.preview || ''));

        var meta = el('div', 'd-flex align-items-center gap-2 flex-wrap');
        if (conversation.channel) { meta.appendChild(el('span', 'badge bg-light text-dark', channelLabel(conversation.channel))); }
        if (conversation.status) { meta.appendChild(el('span', 'badge bg-light text-dark', statusLabel(conversation.status))); }
        meta.appendChild(el('small', 'text-muted ms-auto', timeLabel(conversation.last_activity_at)));
        row.appendChild(meta);

        if (conversation.assignee && conversation.assignee.name) {
            row.appendChild(el('small', 'text-muted', conversation.assignee.name));
        }

        return row;
    }

    function matchesSearch(conversation) {
        if (!state.search) { return true; }
        var needle = state.search.toLowerCase();
        var name = ((conversation.contact && conversation.contact.name) || '').toLowerCase();
        return name.indexOf(needle) !== -1 || (conversation.preview || '').toLowerCase().indexOf(needle) !== -1;
    }

    // ---------------------------------------------------------------- thread

    function messageNode(message) {
        if (message.message_type === 2) {
            return el('div', 'lyn-activity', message.content);
        }

        var outgoing = message.message_type === 1 || message.message_type === 3;
        var wrap = el('div', 'd-flex flex-column ' + (outgoing ? 'align-items-end' : 'align-items-start'));
        var bubble = el('div', 'lyn-bubble ' + (message.private ? 'lyn-note' : (outgoing ? 'lyn-out' : 'lyn-in')));

        if (message.content) { bubble.appendChild(el('div', null, message.content)); }

        (message.attachments || []).forEach(function (attachment) {
            if (!attachment.data_url) { return; }
            var link = el('a', 'd-block small', T.attachment + (attachment.file_type ? ' (' + attachment.file_type + ')' : ''));
            link.href = attachment.data_url;
            link.target = '_blank';
            // noopener: the attachment is served from Lynomia, which is another origin.
            link.rel = 'noopener noreferrer';
            bubble.appendChild(link);
        });

        wrap.appendChild(bubble);

        var who = message.private ? T.note
            : (outgoing ? ((message.sender && message.sender.name) || T.you) : ((message.sender && message.sender.name) || T.customer));
        wrap.appendChild(el('small', 'text-muted mt-1', who + ' · ' + timeLabel(message.created_at)));

        return wrap;
    }

    function openConversation(conversationId, conversationRowData) {
        state.conversationId = conversationId;
        $('.lyn-conv').removeClass('active');
        $('.lyn-conv[data-id="' + conversationId + '"]').addClass('active');

        $('#lyn-thread-header').prop('hidden', false);
        $('#lyn-thread-empty').hide();
        if (canReply) { $('#lyn-composer').prop('hidden', false); }

        var title = (conversationRowData && conversationRowData.contact && conversationRowData.contact.name) || T.customer;
        $('#lyn-thread-title').text(title);
        $('#lyn-thread-sub').text([conversationRowData && conversationRowData.channel, conversationRowData && conversationRowData.status]
            .filter(Boolean).join(' · '));

        if (canAssign && conversationRowData) {
            $('#lyn-set-status').val(conversationRowData.status || 'open');
            $('#lyn-assign-to').val(conversationRowData.assignee ? String(conversationRowData.assignee.id) : '');
        }

        renderContext(conversationRowData);
        loadMessages(conversationId);
        markRead(conversationId);
    }

    function loadMessages(conversationId) {
        var pane = document.getElementById('lyn-messages');
        pane.textContent = '';
        pane.appendChild(el('div', 'text-muted small', T.loading));

        $.getJSON(url('messages', conversationId)).done(function (data) {
            pane.textContent = '';
            var messages = data.messages || [];
            if (messages.length === 0) {
                pane.appendChild(el('div', 'text-muted small', T.noMessages));
                return;
            }
            messages.forEach(function (message) { pane.appendChild(messageNode(message)); });
            pane.scrollTop = pane.scrollHeight;
        }).fail(function (xhr) {
            pane.textContent = '';
            showError(errorFrom(xhr));
        });
    }

    function renderContext(conversation) {
        var body = document.getElementById('lyn-context-body');
        body.textContent = '';
        var context = document.getElementById('lyn-context');

        if (!conversation || !conversation.contact) {
            context.dataset.empty = 'true';
            body.appendChild(el('p', 'text-muted small mb-0', T.customer));
            return;
        }
        context.dataset.empty = 'false';

        body.appendChild(el('div', 'fw-semibold', conversation.contact.name || T.customer));
        if (conversation.channel) {
            body.appendChild(el('div', 'small text-muted', T.channel + ': ' + channelLabel(conversation.channel)));
        }
        if (conversation.status) {
            body.appendChild(el('div', 'small text-muted', T.status + ': ' + statusLabel(conversation.status)));
        }

        if (conversation.contact.id) {
            $.getJSON(url('contact', conversation.contact.id)).done(function (data) {
                var contact = data.contact || {};
                if (contact.email) { body.appendChild(el('div', 'small mt-2', contact.email)); }
                if (contact.phone_number) { body.appendChild(el('div', 'small', contact.phone_number)); }
            });
        }
    }

    function markRead(conversationId) {
        $.post(url('read', conversationId)).done(function () {
            var badge = $('.lyn-conv[data-id="' + conversationId + '"] .badge.bg-primary');
            badge.remove();
        });
    }

    // Route helpers. The ids are substituted into paths the SERVER generated, so there is no
    // place here where a caller-chosen string becomes part of a URL path segment.
    var templates = {
        messages: @json(route('lynomia.inbox.api.messages', ['conversation' => '__ID__'])),
        reply: @json(route('lynomia.inbox.api.reply', ['conversation' => '__ID__'])),
        assign: @json(route('lynomia.inbox.api.assign', ['conversation' => '__ID__'])),
        status: @json(route('lynomia.inbox.api.status', ['conversation' => '__ID__'])),
        read: @json(route('lynomia.inbox.api.read', ['conversation' => '__ID__'])),
        contact: @json(route('lynomia.inbox.api.contact', ['contact' => '__ID__']))
    };
    function url(name, id) { return templates[name].replace('__ID__', encodeURIComponent(id)); }

    // ---------------------------------------------------------------- events

    $('#lyn-conversations').on('click keydown', '.lyn-conv', function (event) {
        if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== ' ') { return; }
        event.preventDefault();
        var id = parseInt(this.dataset.id, 10);
        openConversation(id, lastById[id]);
    });

    function loadConversations(append) {
        clearError();
        if (state.loading) { return; }
        state.loading = true;
        var list = document.getElementById('lyn-conversations');
        if (!append) { list.textContent = ''; list.appendChild(el('div', 'p-3 text-muted small', T.loading)); lastById = {}; }

        $.getJSON(root.dataset.endpointConversations, {
            status: state.status, assignee_type: state.assignee, page: state.page
        }).done(function (data) {
            if (!append) { list.textContent = ''; }
            var all = data.conversations || [];
            state.lastCount = all.length;
            all.forEach(function (conversation) { lastById[conversation.id] = conversation; });

            var shown = all.filter(matchesSearch);
            if (!append && shown.length === 0) { list.appendChild(el('div', 'p-3 text-muted small', T.empty)); }
            shown.forEach(function (conversation) { list.appendChild(conversationRow(conversation)); });
            $('#lyn-more').toggleClass('d-none', state.lastCount < 25);
        }).fail(function (xhr) {
            if (!append) { list.textContent = ''; }
            showError(errorFrom(xhr));
        }).always(function () { state.loading = false; });
    }

    $('#lyn-status').on('change', function () { state.status = this.value; state.page = 1; loadConversations(false); });
    $('#lyn-assignee').on('change', function () { state.assignee = this.value; state.page = 1; loadConversations(false); });
    $('#lyn-refresh').on('click', function () { state.page = 1; loadConversations(false); });
    $('#lyn-more').on('click', function () { state.page += 1; loadConversations(true); });

    var searchTimer = null;
    $('#lyn-search').on('input', function () {
        var value = this.value;
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () { state.search = value; state.page = 1; loadConversations(false); }, 250);
    });

    $('#lyn-composer').on('submit', function (event) {
        event.preventDefault();
        if (!state.conversationId) { return; }
        var content = $('#lyn-reply').val();
        if (!content || !content.trim()) { return; }

        $('#lyn-send').prop('disabled', true);
        $.post(url('reply', state.conversationId), {
            content: content, private: $('#lyn-private').is(':checked') ? 1 : 0
        }).done(function (data) {
            $('#lyn-reply').val('');
            var pane = document.getElementById('lyn-messages');
            if (data && data.message) { pane.appendChild(messageNode(data.message)); pane.scrollTop = pane.scrollHeight; }
        }).fail(function (xhr) {
            // A send is never retried for the caller: a dropped answer may mean it DID land, and
            // a second attempt would post a second reply to a real customer.
            showError(xhr.status === 0 ? T.sendFailed : errorFrom(xhr));
        }).always(function () { $('#lyn-send').prop('disabled', false); });
    });

    if (canAssign) {
        $('#lyn-set-status').on('change', function () {
            if (!state.conversationId) { return; }
            $.post(url('status', state.conversationId), { status: this.value })
                .fail(function (xhr) { showError(errorFrom(xhr)); })
                .done(function () { loadConversations(false); });
        });

        $('#lyn-assign-to').on('change', function () {
            if (!state.conversationId) { return; }
            $.post(url('assign', state.conversationId), { assignee_id: this.value === '' ? '' : this.value })
                .fail(function (xhr) { showError(errorFrom(xhr)); })
                .done(function () { loadConversations(false); });
        });

        $.getJSON(root.dataset.endpointAgents).done(function (data) {
            var select = document.getElementById('lyn-assign-to');
            (data.agents || []).forEach(function (agent) {
                var option = el('option', null, agent.name);
                option.value = agent.id;
                select.appendChild(option);
            });
        });
    }

    // ---------------------------------------------------------------- live updates
    //
    // THE TRANSPORT IS A CURSOR, NOT A SOCKET, and that is a measured choice rather than a
    // shortcut. This dashboard loads no Echo client: resources/js/echo.js imports laravel-echo
    // and pusher-js, neither is in package.json, `npx vite build` fails on that import, and no
    // dashboard view invokes the Vite directive at all. Reverb's server is in composer.json but
    // BROADCAST_CONNECTION defaults to null and no deployment here runs the daemon. Shipping a
    // socket would have meant shipping an inbox that silently never updates.
    //
    // What the cursor gives up is sub-second latency. What it keeps is everything the approved
    // guarantees actually ask for: reconnection and missed-event recovery are the SAME call as
    // an ordinary poll, so the recovery path is exercised constantly instead of only after a
    // failure; duplicates and out-of-order delivery are harmless because the client re-reads
    // state rather than applying a diff; and when the server is unreachable the inbox degrades
    // to exactly what it was a moment ago rather than to a broken page.
    //
    // An event carries only ids. Whatever it names is re-fetched through the ordinary authorized
    // routes, so a misrouted event could not show this merchant another store's message.
    var cursor = 0;
    var pollTimer = null;
    var pollMs = Math.max(5, parseInt(root.dataset.pollSeconds, 10) || 10) * 1000;

    function applyEvents(events) {
        if (!events.length) { return; }

        var touchesOpenThread = state.conversationId !== null && events.some(function (event) {
            return event.conversation_id === state.conversationId;
        });

        // Re-read rather than patch. The list's counts, ordering and previews all move together
        // and re-deriving them is both simpler and correct under out-of-order delivery.
        loadConversations(false);
        if (touchesOpenThread) { loadMessages(state.conversationId); }
    }

    function poll() {
        $.getJSON(root.dataset.endpointEvents, { after: cursor }).done(function (data) {
            cursor = data.cursor || cursor;
            applyEvents(data.events || []);
        }).always(function () {
            // Scheduled after the answer, not on an interval: a slow or failing server must not
            // accumulate overlapping requests behind it.
            pollTimer = setTimeout(poll, pollMs);
        });
    }

    // Stop while the tab is hidden. A background tab polling forever is the thing that makes an
    // inbox expensive to leave open, and the cursor means resuming costs exactly one request.
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            clearTimeout(pollTimer);
            pollTimer = null;
        } else if (pollTimer === null) {
            poll();
        }
    });

    loadConversations(false);
    poll();
}(jQuery));
</script>
@endpush
