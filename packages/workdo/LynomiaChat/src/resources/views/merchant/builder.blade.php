@extends('layouts.app')

@section('page-title', __('Flow Builder'))

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('lynomia.overview') }}">{{ __('Communication & Marketing') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('lynomia.automation') }}">{{ __('Automation Studio') }}</a></li>
    <li class="breadcrumb-item">{{ __('Flow Builder') }}</li>
@endsection

{{--
    THE VISUAL FLOW BUILDER.

    A DEPENDENCY-FREE SVG CANVAS, and that is a measured decision rather than a preference. This
    repository has no node-graph library of any kind -- no jsPlumb, Drawflow, Cytoscape, LiteGraph,
    React Flow, dagre, D3 or mermaid -- and no working JS build: resources/js/app.js imports
    laravel-echo and pusher-js, which are in neither package.json nor node_modules, so `vite build`
    cannot succeed, `@vite` appears zero times across 1,232 Blade files, and public/build does not
    exist. Lynomia's own editor is @vue-flow/core inside a Vuex application and cannot be lifted:
    its condition editor throws without nine populated Vuex modules, its API client scrapes the
    account id out of window.location.pathname, and it authenticates with a devise-token-auth
    cookie a Store Syria page does not have. All of that is recorded with file references in
    docs/development/lynomia-integration/PHASE-4A-CAPABILITY-MATRIX.md.

    So the canvas is SVG and pointer events, in the stack this dashboard actually renders with:
    Blade, Bootstrap 5 and jQuery, loaded from public/assets by layouts/app.blade.php.

    THE FLOW RUNS TOP TO BOTTOM, WHICH IS WHY IT NEEDS NO MIRRORING. A left-to-right graph would
    have to be flipped for Arabic, and flipping it means either rewriting every stored x on load
    (so the same flow has two different graphs) or drawing it backwards from what is saved. A
    vertical flow reads the same in both directions; only the chrome around it flips, which
    Bootstrap's logical utilities do on their own.

    EVERYTHING THE PALETTE AND THE CONFIGURATION FORMS OFFER COMES FROM THE SERVER. The node types,
    the fields of each type, their limits, their allowed values, the variables, the templates and
    the condition vocabulary are all served by Lynomia and rendered generically here. Nothing about
    a node's shape is written in this file -- which is the difference between a form that matches
    the server and one that drifts from it and offers a setting that will be refused at publish.

    NOTHING MERCHANT-SUPPLIED IS EVER WRITTEN AS HTML. Node labels, option titles and validation
    details go through textContent or SVG text nodes.
--}}

@push('css')
<style>
    .lynb { display: flex; flex-direction: column; height: calc(100vh - 190px); min-height: 34rem; }
    .lynb-bar { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; padding: .6rem .85rem;
                border-bottom: 1px solid rgba(0,0,0,.09); background: var(--bs-body-bg, #fff); }
    .lynb-body { display: flex; flex: 1 1 auto; min-height: 0; }
    .lynb-palette { width: 14.5rem; flex: 0 0 14.5rem; overflow-y: auto; border-inline-end: 1px solid rgba(0,0,0,.09); padding: .6rem; }
    .lynb-stage { flex: 1 1 auto; position: relative; min-width: 0; background:
        radial-gradient(circle, rgba(0,0,0,.10) 1px, transparent 1px) 0 0 / 22px 22px; }
    .lynb-stage svg { width: 100%; height: 100%; display: block; touch-action: none; }
    .lynb-inspector { width: 21rem; flex: 0 0 21rem; overflow-y: auto; border-inline-start: 1px solid rgba(0,0,0,.09); padding: .85rem; }
    .lynb-node rect { fill: #fff; stroke: rgba(0,0,0,.25); stroke-width: 1.2; }
    .lynb-node.is-selected rect { stroke: var(--bs-primary, #0d6efd); stroke-width: 2.4; }
    .lynb-node.has-error rect { stroke: var(--bs-danger, #dc3545); stroke-width: 2.4; }
    .lynb-node:focus { outline: none; }
    .lynb-node:focus rect { stroke: var(--bs-primary, #0d6efd); stroke-dasharray: 4 3; stroke-width: 2.4; }
    .lynb-node text { font-size: 12px; fill: #1d2129; pointer-events: none; user-select: none; }
    .lynb-node .lynb-kind { font-size: 10px; fill: #6c757d; letter-spacing: .02em; }
    .lynb-node .lynb-bar-accent { stroke: none; }
    .lynb-handle { fill: #fff; stroke: rgba(0,0,0,.4); stroke-width: 1.2; cursor: crosshair; }
    .lynb-handle:hover, .lynb-handle.is-active { fill: var(--bs-primary, #0d6efd); stroke: var(--bs-primary, #0d6efd); }
    .lynb-handle-label { font-size: 9px; fill: #6c757d; pointer-events: none; user-select: none; }
    .lynb-edge { fill: none; stroke: rgba(0,0,0,.38); stroke-width: 1.8; }
    .lynb-edge-hit { fill: none; stroke: transparent; stroke-width: 14; cursor: pointer; }
    .lynb-edge.is-selected { stroke: var(--bs-danger, #dc3545); stroke-width: 2.6; }
    .lynb-linking { fill: none; stroke: var(--bs-primary, #0d6efd); stroke-width: 2; stroke-dasharray: 5 4; }
    .lynb-tool { position: absolute; inset-block-end: .75rem; inset-inline-start: .75rem; display: flex; gap: .35rem; }
    .lynb-chip { cursor: pointer; width: 100%; text-align: start; }
    .lynb-group-title { font-size: .7rem; text-transform: uppercase; letter-spacing: .04em; opacity: .6; margin: .6rem .25rem .3rem; }
    .lynb-errors { max-height: 9rem; overflow-y: auto; }
    .lynb-errors button { text-align: start; }
    .lynb-test-log { max-height: 15rem; overflow-y: auto; }
    .lynb-bubble { max-width: 85%; border-radius: .7rem; padding: .4rem .6rem; margin-bottom: .35rem; white-space: pre-wrap; word-break: break-word; }
    .lynb-bubble-bot { background: rgba(0,0,0,.06); margin-inline-end: auto; }
    .lynb-bubble-customer { background: rgba(13,110,253,.12); margin-inline-start: auto; }
    .lynb-dirty::after { content: '•'; color: var(--bs-warning, #ffc107); margin-inline-start: .25rem; font-size: 1.4rem; line-height: 0; }
    @media (max-width: 991.98px) {
        .lynb { height: auto; }
        .lynb-body { flex-direction: column; }
        .lynb-palette, .lynb-inspector { width: 100%; flex: 1 1 auto; border: 0; max-height: 18rem; }
        .lynb-palette { border-bottom: 1px solid rgba(0,0,0,.09); }
        .lynb-inspector { border-top: 1px solid rgba(0,0,0,.09); }
        .lynb-stage { height: 60vh; min-height: 22rem; }
        .lynb-collapsible[hidden] { display: none; }
    }
</style>
@endpush

@section('content')
<div class="card mb-0" id="lynb-root"
     data-flow="{{ $flowId }}"
     data-endpoint-base="{{ url('lynomia/automation/api') }}"
     data-endpoint-references="{{ route('lynomia.automation.api.references') }}"
     data-studio-url="{{ route('lynomia.automation') }}">
    <div class="lynb">

        {{-- ------------------------------------------------------------ toolbar --}}
        <div class="lynb-bar">
            <a href="{{ route('lynomia.automation') }}" class="btn btn-sm btn-light" aria-label="{{ __('Back') }}">
                <i class="ti ti-arrow-left" aria-hidden="true"></i>
            </a>
            <strong id="lynb-name" class="text-truncate" style="max-width:16rem"></strong>
            <span id="lynb-state" class="badge bg-secondary-subtle text-secondary-emphasis"></span>
            <span id="lynb-dirty" class="small text-muted" hidden>{{ __('Unsaved changes') }}</span>

            <div class="ms-auto d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-sm btn-light" id="lynb-toggle-panels"
                        data-bs-toggle="collapse" aria-expanded="true">
                    <i class="ti ti-layout-sidebar" aria-hidden="true"></i>
                    <span class="d-none d-sm-inline ms-1">{{ __('Panels') }}</span>
                </button>
                <button type="button" class="btn btn-sm btn-light" id="lynb-test">{{ __('Test') }}</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="lynb-save">{{ __('Save draft') }}</button>
                <button type="button" class="btn btn-sm btn-primary" id="lynb-publish">{{ __('Publish') }}</button>
            </div>
        </div>

        <div id="lynb-alert" class="alert alert-danger mb-0 rounded-0 d-none" role="alert" aria-live="polite"></div>
        <div id="lynb-notice" class="alert alert-success mb-0 rounded-0 d-none" role="status" aria-live="polite"></div>

        {{-- ------------------------------------------------------------ body --}}
        <div class="lynb-body">

            <aside class="lynb-palette lynb-collapsible" id="lynb-palette" aria-label="{{ __('Steps') }}">
                <div class="small text-muted mb-2">{{ __('Click a step to add it to the canvas.') }}</div>
                <div id="lynb-palette-list"></div>
            </aside>

            <div class="lynb-stage" id="lynb-stage">
                <svg id="lynb-canvas" role="application" tabindex="0"
                     aria-label="{{ __('Flow canvas. Use Tab to move between steps, Enter to configure, Delete to remove.') }}">
                    <g id="lynb-viewport">
                        <g id="lynb-edges"></g>
                        <g id="lynb-nodes"></g>
                        <path id="lynb-linking" class="lynb-linking" d="" style="display:none"></path>
                    </g>
                </svg>
                <div class="lynb-tool">
                    <button type="button" class="btn btn-sm btn-light" id="lynb-zoom-out" aria-label="{{ __('Zoom out') }}">−</button>
                    <button type="button" class="btn btn-sm btn-light" id="lynb-zoom-in" aria-label="{{ __('Zoom in') }}">+</button>
                    <button type="button" class="btn btn-sm btn-light" id="lynb-fit">{{ __('Fit') }}</button>
                </div>
            </div>

            <aside class="lynb-inspector lynb-collapsible" id="lynb-inspector" aria-label="{{ __('Step settings') }}">
                <div id="lynb-inspector-empty" class="text-muted small py-4 text-center">
                    {{ __('Select a step to configure it.') }}
                </div>
                <div id="lynb-inspector-body" hidden></div>
            </aside>
        </div>

        {{-- ------------------------------------------------------------ problems --}}
        <div class="border-top p-2" id="lynb-problems" hidden>
            <div class="small fw-semibold mb-1 text-danger" id="lynb-problems-title"></div>
            <div class="lynb-errors d-grid gap-1" id="lynb-problems-list"></div>
        </div>
    </div>
</div>

{{-- ------------------------------------------------------------------ test mode --}}
<div class="modal fade" id="lynb-test-modal" tabindex="-1" aria-labelledby="lynb-test-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="lynb-test-title">{{ __('Test this automation') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">
                    {{ __('This runs your unsaved draft against the real engine. No message is sent and no conversation is created.') }}
                </p>
                <div class="lynb-test-log border rounded p-2 mb-2 d-flex flex-column" id="lynb-test-log"></div>
                <div id="lynb-test-choices" class="d-flex flex-wrap gap-1 mb-2"></div>
                <form class="d-flex gap-2" id="lynb-test-form">
                    <label for="lynb-test-input" class="visually-hidden">{{ __('Customer message') }}</label>
                    <input type="text" class="form-control form-control-sm" id="lynb-test-input"
                           placeholder="{{ __('Type what a customer would say') }}" maxlength="1024" autocomplete="off">
                    <button type="submit" class="btn btn-sm btn-primary">{{ __('Send') }}</button>
                    <button type="button" class="btn btn-sm btn-light" id="lynb-test-timer"
                            title="{{ __('Let the waiting time pass') }}">{{ __('Wait') }}</button>
                </form>
                <div class="small text-muted mt-2" id="lynb-test-status" aria-live="polite"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" id="lynb-test-reset">{{ __('Start over') }}</button>
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">{{ __('Close') }}</button>
            </div>
        </div>
    </div>
</div>

{{-- ------------------------------------------------------------------ conflict --}}
<div class="modal fade" id="lynb-conflict-modal" tabindex="-1" aria-labelledby="lynb-conflict-title" aria-hidden="true"
     data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="lynb-conflict-title">{{ __('Somebody else saved first') }}</h5>
            </div>
            <div class="modal-body">
                <p class="mb-0">
                    {{ __('This automation was changed while you were editing it. Your work is still on screen — choose which version to keep.') }}
                </p>
                <p class="small text-muted mb-0 mt-2" id="lynb-conflict-when"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" id="lynb-conflict-theirs">{{ __('Use their version') }}</button>
                <button type="button" class="btn btn-primary" id="lynb-conflict-mine">{{ __('Keep mine and save') }}</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function ($) {
    'use strict';

    var root = document.getElementById('lynb-root');
    if (!root) { return; }

    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') } });

    var SVGNS = 'http://www.w3.org/2000/svg';
    var FLOW_ID = root.getAttribute('data-flow');
    var BASE = root.getAttribute('data-endpoint-base');
    var REFERENCES_URL = root.getAttribute('data-endpoint-references');
    var STUDIO_URL = root.getAttribute('data-studio-url');

    // Node geometry. One place, because the edge router, the hit areas and the handle positions
    // all have to agree -- three copies of 190 is how an edge ends up pointing at nothing.
    var NODE_W = 190, NODE_H = 64, HANDLE_R = 6, GRID = 10;
    var MIN_SCALE = 0.35, MAX_SCALE = 2.2;

    // Every string the builder can show. Server-side, so Arabic is Arabic, and nothing
    // merchant-supplied is in here.
    var T = {
        failed: @json(__('Lynomia Chat could not complete that request. Please contact support.')),
        saved: @json(__('Draft saved.')),
        published: @json(__('Published. This automation is now answering customers.')),
        unsaved: @json(__('Unsaved changes')),
        leave: @json(__('You have unsaved changes.')),
        problems: @json(__('This automation cannot be published yet:')),
        shapeProblems: @json(__('This draft could not be saved:')),
        selectStep: @json(__('Select a step to configure it.')),
        remove: @json(__('Remove step')),
        removeEdge: @json(__('Remove connection')),
        cannotRemoveStart: @json(__('Every automation needs its start step.')),
        connectFrom: @json(__('Drag from a step output to another step to connect them.')),
        states: { published: @json(__('lynomia-chat::studio.state_published')), draft: @json(__('Draft')), disabled: @json(__('lynomia-chat::studio.state_disabled')) },
        add: @json(__('Add')),
        none: @json(__('None')),
        yes: @json(__('lynomia-chat::studio.yes')),
        no: @json(__('lynomia-chat::studio.no')),
        unset: @json(__('Not set')),
        addOption: @json(__('Add choice')),
        addCondition: @json(__('Add condition')),
        addWord: @json(__('Add keyword')),
        optionTitle: @json(__('Title')),
        optionDescription: @json(__('Description')),
        attribute: @json(__('Attribute')),
        operator: @json(__('Operator')),
        value: @json(__('Value')),
        scopeContact: @json(__('Contact field')),
        scopeConversation: @json(__('Conversation field')),
        scopeContext: @json(__('Just for this run')),
        storeKey: @json(__('Name')),
        variables: @json(__('Insert a value')),
        unavailable: @json(__('Not available on this workspace')),
        noTemplates: @json(__('No approved template is available on this channel yet.')),
        template: @json(__('Template')),
        testEnded: @json(__('The run ended: :reason')),
        testWaiting: @json(__('Waiting for the customer.')),
        unsupportedHere: @json(__('This step cannot run on :channel.')),
        // Node type names and the groups they sit in. Keyed by the server's own type ids, so a
        // type the server adds and this build does not know still appears, under its raw id,
        // rather than vanishing from the palette.
        types: {
            start: @json(__('Start')), send_message: @json(__('Send a message')),
            send_template: @json(__('Send a template')), question: @json(__('Ask a question')),
            buttons: @json(__('Offer buttons')), list: @json(__('Offer a list')),
            condition: @json(__('If / otherwise')), audience_condition: @json(__('If in audience')),
            commerce_condition: @json(__('If store history')), set_contact_attribute: @json(__('Set a contact field')),
            set_conversation_attribute: @json(__('Set a conversation field')), add_label: @json(__('Add a label')),
            remove_label: @json(__('Remove a label')), assign_agent: @json(__('Assign to a person')),
            assign_team: @json(__('Assign to a team')), commerce_lookup: @json(__('Look up an order')),
            webhook: @json(__('Call a web address')), delay: @json(__('Wait')),
            handoff: @json(__('Hand over to a person')), goto: @json(__('Go to a step')), end: @json(__('End'))
        },
        groups: {
            flow: @json(__('Flow')), message: @json(__('Messages')), ask: @json(__('Questions')),
            logic: @json(__('Logic')), data: @json(__('Data')), people: @json(__('People')),
            store: @json(__('Store')), other: @json(__('Other'))
        },
        fields: {
            text: @json(__('Message')), retry_text: @json(__('If the answer is not understood')),
            options: @json(__('Choices')), button_label: @json(__('Button label')),
            keywords: @json(__('Keywords')), conditions: @json(__('Conditions')),
            reply_type: @json(__('Expected answer')), store_as: @json(__('Keep the answer as')),
            max_attempts: @json(__('How many tries')), timeout_minutes: @json(__('Give up after (minutes)')),
            labels: @json(__('Labels')), agent_id: @json(__('Person')), team_id: @json(__('Team')),
            key: @json(__('Field')), value: @json(__('Value')), mode: @json(__('Look up by')),
            number: @json(__('Order number')), url: @json(__('Web address')), seconds: @json(__('Wait (seconds)')),
            priority: @json(__('Priority')), reason: @json(__('Note for the team')),
            target: @json(__('Go to')), resolve: @json(__('Mark the conversation resolved')),
            name: @json(__('Template')), language: @json(__('Language')), params: @json(__('Template values'))
        },
        // The outputs a node is connected from. The server names them; these are the words.
        outputs: {
            next: @json(__('next')), reply: @json(__('answered')), invalid: @json(__('not understood')),
            timeout: @json(__('no answer')), other: @json(__('anything else')), failed: @json(__('failed')),
            'true': @json(__('yes')), 'false': @json(__('no')), found: @json(__('found')),
            not_found: @json(__('not found')), unavailable: @json(__('unavailable'))
        },
        values: {
            any: @json(__('anything')), number: @json(__('a number')), email: @json(__('an email address')),
            phone: @json(__('a phone number')), keywords: @json(__('one of my keywords')),
            latest_order: @json(__('their latest order')), order_number: @json(__('a specific order number')),
            low: @json(__('Low')), medium: @json(__('Medium')), high: @json(__('High')), urgent: @json(__('Urgent'))
        },
        operators: {
            equal_to: @json(__('is')), not_equal_to: @json(__('is not')), contains: @json(__('contains')),
            does_not_contain: @json(__('does not contain')), is_present: @json(__('is set')),
            is_not_present: @json(__('is not set')), is_greater_than: @json(__('is more than')),
            is_less_than: @json(__('is less than')), days_before: @json(__('is more days ago than')),
            starts_with: @json(__('starts with')), attribute_changed: @json(__('changed'))
        }
    };

    // The validation codes, rendered in the merchant's language. Shared with the Studio list, and
    // written out here rather than imported because these two pages do not share an asset bundle.
    var ERRORS = {
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
        conditions_required: @json(__('This step needs at least one condition.')),
        too_many_conditions: @json(__('This step has too many conditions.')),
        condition_not_allowed: @json(__('A condition here is not allowed on this step.')),
        invalid_store: @json(__('Where to keep the answer is not valid.')),
        invalid_url: @json(__('This web address is not valid.')),
        webhooks_unavailable: @json(__('Web requests are not available on this plan.')),
        commerce_disabled: @json(__('Store lookups are not switched on for this workspace.')),
        reason_too_long: @json(__('The handover note is too long.')),
        unsupported_channel: @json(__('A channel this automation is connected to cannot run this step.')),
        no_inbox: @json(__('Connect this automation to a channel before publishing it.')),
        flow_active: @json(__('Turn this automation off before deleting it.')),
        template_required: @json(__('Choose a template.')),
        template_no_inbox: @json(__('No WhatsApp channel here can send a template.')),
        template_not_found: @json(__('That template is not on this channel.')),
        template_not_approved: @json(__('That template is not approved yet.')),
        template_not_allowed: @json(__('That template cannot be sent by an automation.')),
        template_param_missing: @json(__('A template value is missing.')),
        invalid_template_params: @json(__('The template values are not valid.')),
        invalid_media_url: @json(__('The media link is not valid.')),
        copy_code_too_long: @json(__('The copy code is too long.'))
    };

    // Which palette group each type belongs to. Presentation only -- the set of types still comes
    // from the server, and a type this map does not know falls into "other" rather than vanishing.
    var GROUPS = {
        start: 'flow', end: 'flow', goto: 'flow',
        send_message: 'message', send_template: 'message',
        question: 'ask', buttons: 'ask', list: 'ask',
        condition: 'logic', audience_condition: 'logic', commerce_condition: 'logic', delay: 'logic',
        set_contact_attribute: 'data', set_conversation_attribute: 'data', add_label: 'data',
        remove_label: 'data', webhook: 'data',
        assign_agent: 'people', assign_team: 'people', handoff: 'people',
        commerce_lookup: 'store'
    };
    var GROUP_ORDER = ['flow', 'message', 'ask', 'logic', 'data', 'people', 'store', 'other'];
    var ACCENTS = { flow: '#6c757d', message: '#0d6efd', ask: '#6f42c1', logic: '#fd7e14',
                    data: '#20c997', people: '#d63384', store: '#198754', other: '#adb5bd' };

    // ------------------------------------------------------------------ state

    var state = {
        flow: null,
        graph: { nodes: [], edges: [] },
        catalogue: { node_types: {}, node_fields: {}, store_scopes: [], capabilities: {}, variables: [], templates: [] },
        references: null,
        errors: [],
        lockVersion: 0,
        selected: null,       // node id
        selectedEdge: null,   // edge id
        dirty: false,
        view: { x: 60, y: 40, scale: 1 }
    };

    var el = {
        canvas: document.getElementById('lynb-canvas'),
        viewport: document.getElementById('lynb-viewport'),
        nodes: document.getElementById('lynb-nodes'),
        edges: document.getElementById('lynb-edges'),
        linking: document.getElementById('lynb-linking'),
        stage: document.getElementById('lynb-stage')
    };

    function text(value) { return value === null || value === undefined ? '' : String(value); }

    function typeLabel(type) { return T.types[type] || type; }

    function outputLabel(name) { return T.outputs[name] || name; }

    function valueLabel(value) { return T.values[value] || value; }

    function fieldLabel(key) { return T.fields[key] || key; }

    function errorSentence(error) {
        var sentence = ERRORS[error.code] || error.code;
        return error.detail ? sentence + ' (' + error.detail + ')' : sentence;
    }

    function svg(name, attrs) {
        var node = document.createElementNS(SVGNS, name);
        Object.keys(attrs || {}).forEach(function (key) { node.setAttribute(key, attrs[key]); });
        return node;
    }

    function markDirty() {
        state.dirty = true;
        $('#lynb-dirty').prop('hidden', false);
    }

    function markClean() {
        state.dirty = false;
        $('#lynb-dirty').prop('hidden', true);
    }

    function showAlert(message) { $('#lynb-alert').text(message).removeClass('d-none'); }
    function clearAlert() { $('#lynb-alert').addClass('d-none').text(''); }

    function showNotice(message) {
        $('#lynb-notice').text(message).removeClass('d-none');
        window.setTimeout(function () { $('#lynb-notice').addClass('d-none'); }, 4000);
    }

    function failureOf(xhr) {
        var body = xhr && xhr.responseJSON;
        return (body && typeof body.error === 'string' && body.error) || T.failed;
    }

    function nodeById(id) {
        for (var i = 0; i < state.graph.nodes.length; i++) {
            if (state.graph.nodes[i].id === id) { return state.graph.nodes[i]; }
        }
        return null;
    }

    // Ids are generated here and must match Flows::NodeTypes::ID (letters, digits, _ and -, at
    // most 64). A counter plus the type keeps them readable in validation messages, which name
    // the node id and nothing else.
    function freshId(prefix) {
        var n = 1;
        var taken = {};
        state.graph.nodes.forEach(function (node) { taken[node.id] = true; });
        state.graph.edges.forEach(function (edge) { taken[edge.id] = true; });
        while (taken[prefix + '_' + n]) { n++; }
        return prefix + '_' + n;
    }

    // ------------------------------------------------------------------ geometry

    function outputsOf(node) {
        var spec = state.catalogue.node_types[node.type];
        if (!spec) { return []; }
        if (spec.outputs !== 'options') { return (spec.outputs || []).slice(); }

        var ids = (node.data && Array.isArray(node.data.options) ? node.data.options : [])
            .map(function (option) { return option && option.id; })
            .filter(function (id) { return !!id; });
        return ids.concat(spec.extra || []);
    }

    function handlePoint(node, outputName) {
        var outputs = outputsOf(node);
        var index = outputs.indexOf(outputName);
        if (index < 0) { return { x: node.position.x + NODE_W / 2, y: node.position.y + NODE_H }; }
        var step = NODE_W / (outputs.length + 1);
        return { x: node.position.x + step * (index + 1), y: node.position.y + NODE_H };
    }

    function inputPoint(node) {
        return { x: node.position.x + NODE_W / 2, y: node.position.y };
    }

    function edgePath(from, to) {
        var dy = Math.max(30, Math.abs(to.y - from.y) / 2);
        return 'M ' + from.x + ' ' + from.y +
               ' C ' + from.x + ' ' + (from.y + dy) + ', ' + to.x + ' ' + (to.y - dy) + ', ' + to.x + ' ' + to.y;
    }

    // Client coordinates -> graph coordinates, through the current pan and zoom.
    function toGraph(clientX, clientY) {
        var box = el.canvas.getBoundingClientRect();
        return {
            x: (clientX - box.left - state.view.x) / state.view.scale,
            y: (clientY - box.top - state.view.y) / state.view.scale
        };
    }

    function snap(value) { return Math.round(value / GRID) * GRID; }

    function applyView() {
        el.viewport.setAttribute('transform',
            'translate(' + state.view.x + ',' + state.view.y + ') scale(' + state.view.scale + ')');
    }

    function zoomBy(factor) {
        var box = el.canvas.getBoundingClientRect();
        var centre = toGraph(box.left + box.width / 2, box.top + box.height / 2);
        state.view.scale = Math.min(MAX_SCALE, Math.max(MIN_SCALE, state.view.scale * factor));
        var after = toGraph(box.left + box.width / 2, box.top + box.height / 2);
        state.view.x += (after.x - centre.x) * state.view.scale;
        state.view.y += (after.y - centre.y) * state.view.scale;
        applyView();
    }

    function fitToContent() {
        if (!state.graph.nodes.length) { state.view = { x: 60, y: 40, scale: 1 }; applyView(); return; }

        var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
        state.graph.nodes.forEach(function (node) {
            minX = Math.min(minX, node.position.x);
            minY = Math.min(minY, node.position.y);
            maxX = Math.max(maxX, node.position.x + NODE_W);
            maxY = Math.max(maxY, node.position.y + NODE_H);
        });

        var box = el.canvas.getBoundingClientRect();
        var pad = 40;
        var scale = Math.min(MAX_SCALE, Math.max(MIN_SCALE,
            Math.min((box.width - pad * 2) / Math.max(1, maxX - minX), (box.height - pad * 2) / Math.max(1, maxY - minY))));
        state.view.scale = scale;
        state.view.x = pad - minX * scale + Math.max(0, (box.width - pad * 2 - (maxX - minX) * scale) / 2);
        state.view.y = pad - minY * scale;
        applyView();
    }

    // ------------------------------------------------------------------ rendering

    function errorsByNode() {
        var map = {};
        state.errors.forEach(function (error) {
            if (!error.node_id) { return; }
            (map[error.node_id] = map[error.node_id] || []).push(error);
        });
        return map;
    }

    function render() {
        var byNode = errorsByNode();
        el.nodes.textContent = '';
        el.edges.textContent = '';

        state.graph.edges.forEach(function (edge) {
            var from = nodeById(edge.source), to = nodeById(edge.target);
            if (!from || !to) { return; }

            var d = edgePath(handlePoint(from, edge.sourceHandle), inputPoint(to));
            var hit = svg('path', { d: d, class: 'lynb-edge-hit' });
            hit.setAttribute('data-edge', edge.id);
            el.edges.appendChild(hit);
            el.edges.appendChild(svg('path', {
                d: d, class: 'lynb-edge' + (state.selectedEdge === edge.id ? ' is-selected' : '')
            }));
        });

        state.graph.nodes.forEach(function (node) {
            el.nodes.appendChild(renderNode(node, byNode[node.id] || []));
        });

        renderProblems();
    }

    function renderNode(node, nodeErrors) {
        var group = GROUPS[node.type] || 'other';
        var classes = 'lynb-node' + (state.selected === node.id ? ' is-selected' : '') +
                      (nodeErrors.length ? ' has-error' : '');
        var g = svg('g', {
            class: classes,
            transform: 'translate(' + node.position.x + ',' + node.position.y + ')',
            tabindex: '0', role: 'button'
        });
        g.setAttribute('data-node', node.id);
        g.setAttribute('aria-label', typeLabel(node.type) + ' — ' + node.id);

        g.appendChild(svg('rect', { width: NODE_W, height: NODE_H, rx: 8 }));
        g.appendChild(svg('rect', { width: 5, height: NODE_H, rx: 2.5, fill: ACCENTS[group], class: 'lynb-bar-accent' }));

        var kind = svg('text', { x: 14, y: 20, class: 'lynb-kind' });
        kind.appendChild(document.createTextNode(typeLabel(node.type)));
        g.appendChild(kind);

        var summary = svg('text', { x: 14, y: 40, class: 'lynb-summary' });
        summary.appendChild(document.createTextNode(summaryOf(node)));
        g.appendChild(summary);

        var id = svg('text', { x: 14, y: 55, class: 'lynb-kind' });
        id.appendChild(document.createTextNode(node.id));
        g.appendChild(id);

        // The input, except on Start, which nothing may lead into.
        if (node.type !== 'start') {
            g.appendChild(svg('circle', { cx: NODE_W / 2, cy: 0, r: HANDLE_R - 1, class: 'lynb-handle', 'pointer-events': 'none' }));
        }

        var outputs = outputsOf(node);
        var step = NODE_W / (outputs.length + 1);
        outputs.forEach(function (name, index) {
            var cx = step * (index + 1);
            var handle = svg('circle', { cx: cx, cy: NODE_H, r: HANDLE_R, class: 'lynb-handle' });
            handle.setAttribute('data-output', name);
            handle.setAttribute('data-node', node.id);
            g.appendChild(handle);

            if (outputs.length <= 4) {
                var label = svg('text', { x: cx, y: NODE_H + 20, class: 'lynb-handle-label', 'text-anchor': 'middle' });
                label.appendChild(document.createTextNode(outputLabel(name)));
                g.appendChild(label);
            }
        });

        return g;
    }

    // One line describing what a step is set to do, so the canvas is readable without opening
    // every node. Truncated with a real ellipsis rather than overflowing the box.
    function summaryOf(node) {
        var data = node.data || {};
        var value = data.text || data.name || data.url || data.reason ||
            (data.labels && data.labels.join(', ')) ||
            (data.seconds !== undefined && data.seconds !== null && data.seconds !== '' ? data.seconds + 's' : '') ||
            (data.mode ? valueLabel(data.mode) : '') ||
            (data.target || '') ||
            (Array.isArray(data.conditions) && data.conditions.length ? data.conditions.length + '' : '');

        value = text(value).replace(/\s+/g, ' ').trim();
        return value.length > 26 ? value.slice(0, 25) + '…' : value;
    }

    function renderProblems() {
        var $panel = $('#lynb-problems');
        if (!state.errors.length) { $panel.prop('hidden', true); return; }

        $('#lynb-problems-title').text(T.problems);
        var $list = $('#lynb-problems-list').empty();
        state.errors.forEach(function (error) {
            var $button = $('<button>').attr('type', 'button')
                .addClass('btn btn-sm btn-light border-0 py-1 px-2 small')
                .text(errorSentence(error) + (error.node_id ? '  ·  ' + error.node_id : ''));
            if (error.node_id) {
                $button.on('click', function () { select(error.node_id, true); });
            }
            $list.append($button);
        });
        $panel.prop('hidden', false);
    }

    function select(nodeId, focus) {
        state.selected = nodeId;
        state.selectedEdge = null;
        render();
        renderInspector();
        if (focus) {
            var target = el.nodes.querySelector('[data-node="' + cssEscape(nodeId) + '"]');
            if (target && target.focus) { target.focus(); }
        }
    }

    // CSS.escape is not in every browser this dashboard supports, and a node id is already
    // restricted to letters, digits, _ and - by the server's own pattern -- so this refuses
    // anything else rather than building a selector out of it.
    function cssEscape(value) {
        return /^[A-Za-z0-9_-]+$/.test(value) ? value : '';
    }

    // ------------------------------------------------------------------ canvas interaction

    var drag = null;   // { kind: 'node'|'pan'|'link', ... }

    el.canvas.addEventListener('pointerdown', function (event) {
        var handle = event.target.closest('.lynb-handle[data-output]');
        var nodeEl = event.target.closest('.lynb-node');
        var edgeEl = event.target.closest('.lynb-edge-hit');

        if (handle) {
            event.preventDefault();
            drag = { kind: 'link', from: handle.getAttribute('data-node'), output: handle.getAttribute('data-output') };
            handle.classList.add('is-active');
            el.canvas.setPointerCapture(event.pointerId);
            return;
        }

        if (nodeEl) {
            var node = nodeById(nodeEl.getAttribute('data-node'));
            if (!node) { return; }
            select(node.id, false);
            var start = toGraph(event.clientX, event.clientY);
            drag = { kind: 'node', id: node.id, dx: start.x - node.position.x, dy: start.y - node.position.y, moved: false };
            el.canvas.setPointerCapture(event.pointerId);
            return;
        }

        if (edgeEl) {
            state.selectedEdge = edgeEl.getAttribute('data-edge');
            state.selected = null;
            render();
            renderInspector();
            return;
        }

        state.selected = null;
        state.selectedEdge = null;
        render();
        renderInspector();
        drag = { kind: 'pan', x: event.clientX, y: event.clientY, viewX: state.view.x, viewY: state.view.y };
        el.canvas.setPointerCapture(event.pointerId);
    });

    el.canvas.addEventListener('pointermove', function (event) {
        if (!drag) { return; }

        if (drag.kind === 'pan') {
            state.view.x = drag.viewX + (event.clientX - drag.x);
            state.view.y = drag.viewY + (event.clientY - drag.y);
            applyView();
            return;
        }

        if (drag.kind === 'node') {
            var point = toGraph(event.clientX, event.clientY);
            var node = nodeById(drag.id);
            if (!node) { return; }
            node.position.x = snap(point.x - drag.dx);
            node.position.y = snap(point.y - drag.dy);
            drag.moved = true;
            render();
            return;
        }

        if (drag.kind === 'link') {
            var from = nodeById(drag.from);
            if (!from) { return; }
            var to = toGraph(event.clientX, event.clientY);
            el.linking.setAttribute('d', edgePath(handlePoint(from, drag.output), to));
            el.linking.style.display = '';
        }
    });

    el.canvas.addEventListener('pointerup', function (event) {
        if (!drag) { return; }

        if (drag.kind === 'node' && drag.moved) { markDirty(); }

        if (drag.kind === 'link') {
            el.linking.style.display = 'none';
            el.canvas.querySelectorAll('.lynb-handle.is-active').forEach(function (handle) {
                handle.classList.remove('is-active');
            });
            // BY POSITION, NOT BY event.target. setPointerCapture retargets every subsequent
            // pointer event to the capturing element, so `event.target` here is the canvas and
            // never the node the merchant dropped on -- which made connecting two steps with a
            // mouse silently do nothing. Found in a browser.
            var under = document.elementFromPoint(event.clientX, event.clientY);
            var target = under && under.closest ? under.closest('.lynb-node') : null;
            if (target) { connect(drag.from, drag.output, target.getAttribute('data-node')); }
        }

        drag = null;
        render();
    });

    el.canvas.addEventListener('pointercancel', function () {
        el.linking.style.display = 'none';
        drag = null;
    });

    // Ctrl/⌘ + wheel zooms; a plain wheel scrolls the page, which is what a merchant on a laptop
    // expects from a panel inside a dashboard rather than a full-screen application.
    el.stage.addEventListener('wheel', function (event) {
        if (!event.ctrlKey && !event.metaKey) { return; }
        event.preventDefault();
        zoomBy(event.deltaY < 0 ? 1.1 : 0.9);
    }, { passive: false });

    function connect(sourceId, output, targetId) {
        if (!sourceId || !targetId) { return; }
        var target = nodeById(targetId);
        if (!target || target.type === 'start') { return; }       // nothing leads into Start

        // One edge per output, which is the server's rule too: replacing rather than refusing is
        // what makes re-pointing a branch one gesture instead of delete-then-draw.
        state.graph.edges = state.graph.edges.filter(function (edge) {
            return !(edge.source === sourceId && edge.sourceHandle === output);
        });
        state.graph.edges.push({ id: freshId('e'), source: sourceId, sourceHandle: output, target: targetId });
        markDirty();
        render();
    }

    function addNode(type) {
        var box = el.canvas.getBoundingClientRect();
        var centre = toGraph(box.left + box.width / 2, box.top + box.height / 3);
        var node = {
            id: freshId(type.replace(/[^A-Za-z0-9_-]/g, '')),
            type: type,
            position: { x: snap(centre.x - NODE_W / 2), y: snap(centre.y) },
            data: {}
        };
        state.graph.nodes.push(node);
        markDirty();
        select(node.id, true);
    }

    function removeSelected() {
        if (state.selectedEdge) {
            state.graph.edges = state.graph.edges.filter(function (edge) { return edge.id !== state.selectedEdge; });
            state.selectedEdge = null;
            markDirty();
            render();
            return;
        }

        if (!state.selected) { return; }
        var node = nodeById(state.selected);
        if (!node) { return; }
        if (node.type === 'start') { showAlert(T.cannotRemoveStart); return; }

        state.graph.nodes = state.graph.nodes.filter(function (candidate) { return candidate.id !== node.id; });
        state.graph.edges = state.graph.edges.filter(function (edge) {
            return edge.source !== node.id && edge.target !== node.id;
        });
        state.selected = null;
        markDirty();
        render();
        renderInspector();
    }

    // Keyboard, on the canvas rather than the document, so typing in a configuration field never
    // deletes the step being configured.
    el.canvas.addEventListener('keydown', function (event) {
        var focused = event.target.closest && event.target.closest('.lynb-node');

        if (event.key === 'Delete' || event.key === 'Backspace') {
            event.preventDefault();
            // THE FOCUSED STEP, NOT THE LAST CLICKED ONE. Tab moves focus without selecting, so a
            // keyboard user who tabbed to a step and pressed Delete removed whatever they had
            // clicked before -- a different step from the one they were looking at. Found in a
            // browser.
            if (focused) {
                state.selected = focused.getAttribute('data-node');
                state.selectedEdge = null;
            }
            removeSelected();
            return;
        }
        if (event.key === 'Escape') { state.selected = null; state.selectedEdge = null; render(); renderInspector(); return; }

        if (!focused) { return; }
        var node = nodeById(focused.getAttribute('data-node'));
        if (!node) { return; }

        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            select(node.id, false);
            var first = document.querySelector('#lynb-inspector-body input, #lynb-inspector-body textarea, #lynb-inspector-body select');
            if (first) { first.focus(); }
            return;
        }

        var nudges = { ArrowUp: [0, -GRID], ArrowDown: [0, GRID], ArrowLeft: [-GRID, 0], ArrowRight: [GRID, 0] };
        if (nudges[event.key]) {
            event.preventDefault();
            node.position.x += nudges[event.key][0];
            node.position.y += nudges[event.key][1];
            markDirty();
            render();
            var again = el.nodes.querySelector('[data-node="' + cssEscape(node.id) + '"]');
            if (again) { again.focus(); }
        }
    });

    $('#lynb-zoom-in').on('click', function () { zoomBy(1.15); });
    $('#lynb-zoom-out').on('click', function () { zoomBy(0.87); });
    $('#lynb-fit').on('click', fitToContent);
    $('#lynb-toggle-panels').on('click', function () {
        var hidden = $('#lynb-palette').prop('hidden');
        $('#lynb-palette, #lynb-inspector').prop('hidden', !hidden);
        $(this).attr('aria-expanded', hidden ? 'true' : 'false');
    });

    // ------------------------------------------------------------------ palette

    function renderPalette() {
        var $list = $('#lynb-palette-list').empty();
        var types = Object.keys(state.catalogue.node_types);
        var grouped = {};
        types.forEach(function (type) {
            var group = GROUPS[type] || 'other';
            (grouped[group] = grouped[group] || []).push(type);
        });

        GROUP_ORDER.forEach(function (group) {
            if (!grouped[group]) { return; }
            $list.append($('<div>').addClass('lynb-group-title').text(T.groups[group] || group));
            grouped[group].forEach(function (type) {
                // Start is created with the flow and there is exactly one; offering a second is
                // offering a graph the server refuses with `start_count`.
                if (type === 'start') { return; }
                $list.append($('<button>')
                    .attr('type', 'button')
                    .addClass('btn btn-sm btn-light lynb-chip mb-1 d-flex align-items-center gap-2')
                    .append($('<span>').css({ width: '.5rem', height: '.5rem', borderRadius: '50%',
                                              background: ACCENTS[group], display: 'inline-block' }))
                    .append($('<span>').text(typeLabel(type)))
                    .on('click', function () { addNode(type); }));
            });
        });
    }

    // ------------------------------------------------------------------ inspector
    //
    // EVERY FORM IS BUILT FROM THE SERVER'S FIELD SCHEMA. There is no per-node-type form in this
    // file: `node_fields` says which keys a type has, what each one is, its limits and its allowed
    // values, and the renderers below turn a `kind` into a control. A node type added upstream
    // therefore gets a working configuration form here with no change to this page, and -- more
    // importantly -- a limit changed upstream is enforced here in the same deploy instead of
    // drifting until a merchant hits it at publish.

    function renderInspector() {
        var $body = $('#lynb-inspector-body').empty();
        var node = state.selected ? nodeById(state.selected) : null;

        if (!node) {
            $('#lynb-inspector-empty').prop('hidden', false);
            $body.prop('hidden', true);
            return;
        }

        $('#lynb-inspector-empty').prop('hidden', true);
        $body.prop('hidden', false);

        $body.append($('<div>').addClass('d-flex align-items-start justify-content-between gap-2 mb-2')
            .append($('<div>')
                .append($('<div>').addClass('fw-semibold').text(typeLabel(node.type)))
                .append($('<div>').addClass('small text-muted').text(node.id)))
            .append(node.type === 'start' ? $() : $('<button>').attr('type', 'button')
                .addClass('btn btn-sm btn-light text-danger')
                .text(T.remove)
                .on('click', function () { select(node.id, false); removeSelected(); })));

        // What this flow's own channels cannot run, said here rather than discovered at publish.
        (state.flow && state.flow.channel_eligibility ? state.flow.channel_eligibility : []).forEach(function (inbox) {
            var blocked = !inbox.runs_flows || (inbox.unsupported_nodes || []).indexOf(node.type) >= 0;
            if (!blocked) { return; }
            $body.append($('<div>').addClass('alert alert-warning py-2 px-2 small')
                .text(T.unsupportedHere.split(':channel').join(inbox.name)));
        });

        var nodeErrors = errorsByNode()[node.id] || [];
        if (nodeErrors.length) {
            var $errors = $('<ul>').addClass('list-unstyled small text-danger mb-3');
            nodeErrors.forEach(function (error) { $errors.append($('<li>').text('• ' + errorSentence(error))); });
            $body.append($errors);
        }

        var fields = state.catalogue.node_fields[node.type] || [];
        fields.forEach(function (field) {
            var control = renderField(node, field);
            if (control) { $body.append(control); }
        });

        if (!fields.length) {
            $body.append($('<p>').addClass('small text-muted').text(T.connectFrom));
        }
    }

    function shown(node, field) {
        if (!field.shown_when) { return true; }
        return (node.data || {})[field.shown_when.key] === field.shown_when.value;
    }

    function requiredNow(node, field) {
        if (field.required) { return true; }
        if (!field.required_when) { return false; }
        return (node.data || {})[field.required_when.key] === field.required_when.value;
    }

    function setValue(node, key, value) {
        node.data = node.data || {};
        if (value === null || value === undefined || value === '') { delete node.data[key]; } else { node.data[key] = value; }
        markDirty();
    }

    function wrap(field, node, $control, hint) {
        var id = 'lynb-f-' + field.key;
        $control.attr('id', id);
        var $label = $('<label>').addClass('form-label small mb-1').attr('for', id).text(fieldLabel(field.key));
        if (requiredNow(node, field)) { $label.append($('<span>').addClass('text-danger ms-1').text('*')); }

        var $group = $('<div>').addClass('mb-3').append($label).append($control);
        if (hint) { $group.append($('<div>').addClass('form-text small').text(hint)); }
        return $group;
    }

    function renderField(node, field) {
        if (!shown(node, field)) { return null; }
        var data = node.data || {};
        var value = data[field.key];

        switch (field.kind) {
            case 'text': return renderText(node, field, value, true);
            case 'line': return renderText(node, field, value, false);
            case 'url': return renderText(node, field, value, false);
            case 'number': return renderNumber(node, field, value);
            case 'boolean': return renderBoolean(node, field, value);
            case 'select': return renderSelect(node, field, value);
            case 'words': return renderWords(node, field, value);
            case 'options': return renderOptions(node, field, value);
            case 'labels': return renderMultiReference(node, field, value, 'labels', 'name');
            case 'agent': return renderReference(node, field, value, 'agents');
            case 'team': return renderReference(node, field, value, 'teams');
            case 'attribute': return renderAttribute(node, field, value);
            case 'store_target': return renderStoreTarget(node, field, value);
            case 'node': return renderNodeTarget(node, field, value);
            case 'conditions': return renderConditions(node, field, value);
            case 'template': return renderTemplate(node, field, value);
            case 'template_language': return null;                      // set by the picker above
            case 'template_params': return renderTemplateParams(node, field, value);
            default: return renderText(node, field, value, false);
        }
    }

    function renderText(node, field, value, multiline) {
        var $control = multiline
            ? $('<textarea>').addClass('form-control form-control-sm').attr('rows', 3)
            : $('<input>').attr('type', field.kind === 'url' ? 'url' : 'text').addClass('form-control form-control-sm');

        if (field.max) { $control.attr('maxlength', field.max); }
        $control.val(text(value));
        $control.on('input', function () { setValue(node, field.key, $(this).val()); refreshSummary(node); });

        var $group = wrap(field, node, $control, field.max ? field.max + '' : null);
        if (field.variables) { $group.append(variablePicker(node, field, $control)); }
        return $group;
    }

    // The variable allow-list, inserted at the cursor. `flow`-only fields take only flow values,
    // because that is what the server accepts there -- a contact variable in an attribute value is
    // rejected with `unknown_variable`, and offering it would be offering a refusal.
    function variablePicker(node, field, $control) {
        var names = (state.catalogue.variables || []).filter(function (name) {
            return field.variables !== 'flow' || name.indexOf('flow.') === 0;
        });
        if (!names.length) { return $(); }

        var $select = $('<select>').addClass('form-select form-select-sm mt-1')
            .append($('<option>').val('').text(T.variables));
        names.forEach(function (name) { $select.append($('<option>').val(name).text(variableToken(name))); });

        $select.on('change', function () {
            var name = $(this).val();
            if (!name) { return; }
            var input = $control[0];
            var token = variableToken(name);
            var at = typeof input.selectionStart === 'number' ? input.selectionStart : $control.val().length;
            var next = $control.val().slice(0, at) + token + $control.val().slice(at);
            $control.val(next).trigger('input');
            $(this).val('');
            input.focus();
            if (input.setSelectionRange) { input.setSelectionRange(at + token.length, at + token.length); }
        });

        return $select;
    }

    // THE BRACES ARE BUILT, NEVER WRITTEN. A literal pair of opening braces in this file is a
    // Blade echo tag: written out whole, Blade compiled the token away and the picker inserted
    // "+ name +" into the merchant's message; written with Blade's @ escape, the @ survived into
    // the message. Concatenating them means Blade never sees a tag at all, which is the only
    // version of this that does not depend on knowing how the compiler treats an escape. Both
    // wrong versions were found by opening the page in a browser.
    function variableToken(name) { return '{' + '{ ' + name + ' }' + '}'; }

    function renderNumber(node, field, value) {
        var $control = $('<input>').attr({ type: 'number', min: field.min, max: field.max })
            .addClass('form-control form-control-sm')
            .val(value === undefined || value === null ? '' : value);

        $control.on('input', function () {
            var raw = $(this).val();
            setValue(node, field.key, raw === '' ? null : Number(raw));
        });

        var hint = (field.min !== undefined ? field.min : '') + ' – ' + (field.max !== undefined ? field.max : '');
        return wrap(field, node, $control, hint.trim() === '–' ? null : hint);
    }

    // Tri-state, because the server's own contract is nil / true / false: `end.resolve` unset
    // means "leave the conversation as it is", which a checkbox cannot say.
    function renderBoolean(node, field, value) {
        var $control = $('<select>').addClass('form-select form-select-sm')
            .append($('<option>').val('').text(T.unset))
            .append($('<option>').val('yes').text(T.yes))
            .append($('<option>').val('no').text(T.no));
        $control.val(value === true ? 'yes' : (value === false ? 'no' : ''));
        $control.on('change', function () {
            var raw = $(this).val();
            node.data = node.data || {};
            if (raw === '') { delete node.data[field.key]; } else { node.data[field.key] = raw === 'yes'; }
            markDirty();
        });
        return wrap(field, node, $control);
    }

    function renderSelect(node, field, value) {
        var $control = $('<select>').addClass('form-select form-select-sm');
        if (!field.required) { $control.append($('<option>').val('').text(T.none)); }
        (field.values || []).forEach(function (option) {
            $control.append($('<option>').val(option).text(valueLabel(option)));
        });
        $control.val(value === undefined || value === null ? (field.default || '') : value);
        $control.on('change', function () {
            setValue(node, field.key, $(this).val());
            renderInspector();   // a select can show or require another field
            render();
        });
        return wrap(field, node, $control);
    }

    function renderWords(node, field, value) {
        var words = Array.isArray(value) ? value.slice() : [];
        var $box = $('<div>');

        function redraw() {
            $box.empty();
            words.forEach(function (word, index) {
                var $row = $('<div>').addClass('input-group input-group-sm mb-1');
                var $input = $('<input>').attr({ type: 'text', maxlength: field.max_length || 64 })
                    .addClass('form-control').val(word)
                    .on('input', function () { words[index] = $(this).val(); commit(); });
                $row.append($input).append($('<button>').attr('type', 'button').addClass('btn btn-light')
                    .text('×').attr('aria-label', T.remove)
                    .on('click', function () { words.splice(index, 1); commit(); redraw(); }));
                $box.append($row);
            });

            if (words.length < (field.max_items || 20)) {
                $box.append($('<button>').attr('type', 'button').addClass('btn btn-sm btn-light')
                    .text(T.addWord).on('click', function () { words.push(''); commit(); redraw(); }));
            }
        }

        function commit() {
            var cleaned = words.map(function (word) { return text(word).trim(); }).filter(Boolean);
            setValue(node, field.key, cleaned.length ? cleaned : null);
        }

        redraw();
        return wrap(field, node, $box);
    }

    function renderOptions(node, field, value) {
        var options = Array.isArray(value) ? value.map(function (option) { return $.extend({}, option); }) : [];
        var $box = $('<div>');

        function redraw() {
            $box.empty();
            options.forEach(function (option, index) {
                var $card = $('<div>').addClass('border rounded p-2 mb-2');
                $card.append($('<div>').addClass('d-flex justify-content-between align-items-center mb-1')
                    .append($('<span>').addClass('small text-muted').text(option.id || ''))
                    .append($('<button>').attr('type', 'button').addClass('btn btn-sm btn-light text-danger py-0 px-1')
                        .text('×').attr('aria-label', T.remove)
                        .on('click', function () { options.splice(index, 1); commit(); redraw(); render(); })));

                $card.append($('<input>').attr({ type: 'text', maxlength: field.title_max, placeholder: T.optionTitle })
                    .addClass('form-control form-control-sm mb-1').val(text(option.title))
                    .on('input', function () { options[index].title = $(this).val(); commit(); }));

                if (field.description_max > 0) {
                    $card.append($('<input>').attr({ type: 'text', maxlength: field.description_max,
                                                     placeholder: T.optionDescription })
                        .addClass('form-control form-control-sm').val(text(option.description))
                        .on('input', function () { options[index].description = $(this).val(); commit(); }));
                }

                $box.append($card);
            });

            if (options.length < (field.max_items || 3)) {
                $box.append($('<button>').attr('type', 'button').addClass('btn btn-sm btn-light')
                    .text(T.addOption).on('click', function () {
                        options.push({ id: 'o' + (options.length + 1), title: '' });
                        commit();
                        redraw();
                        render();   // a new choice is a new output handle on the canvas
                    }));
            }
        }

        function commit() { setValue(node, field.key, options.length ? options : null); }

        redraw();
        return wrap(field, node, $box, field.max_items + ' · ' + field.title_max);
    }

    function referenceList(name) {
        return (state.references && state.references[name]) || [];
    }

    function renderReference(node, field, value, collection) {
        var $control = $('<select>').addClass('form-select form-select-sm')
            .append($('<option>').val('').text(T.none));
        referenceList(collection).forEach(function (record) {
            $control.append($('<option>').val(record.id).text(record.name));
        });
        $control.val(value === undefined || value === null ? '' : String(value));
        $control.on('change', function () {
            var raw = $(this).val();
            setValue(node, field.key, raw === '' ? null : Number(raw));
        });
        return wrap(field, node, $control);
    }

    function renderMultiReference(node, field, value, collection, key) {
        var chosen = Array.isArray(value) ? value.slice() : [];
        var $box = $('<div>');

        referenceList(collection).forEach(function (record) {
            var id = 'lynb-' + collection + '-' + record.id;
            var $check = $('<div>').addClass('form-check form-check-sm');
            var $input = $('<input>').attr({ type: 'checkbox', id: id }).addClass('form-check-input')
                .prop('checked', chosen.indexOf(record[key]) >= 0)
                .on('change', function () {
                    if ($(this).is(':checked')) {
                        if (chosen.indexOf(record[key]) < 0) { chosen.push(record[key]); }
                    } else {
                        chosen = chosen.filter(function (entry) { return entry !== record[key]; });
                    }
                    setValue(node, field.key, chosen.length ? chosen : null);
                });
            $check.append($input).append($('<label>').addClass('form-check-label small').attr('for', id).text(record[key]));
            $box.append($check);
        });

        if (!referenceList(collection).length) {
            $box.append($('<div>').addClass('small text-muted').text(T.none));
        }

        return wrap(field, node, $box);
    }

    function renderAttribute(node, field, value) {
        var collection = field.model === 'contact_attribute' ? 'contact_attributes' : 'conversation_attributes';
        var $control = $('<select>').addClass('form-select form-select-sm')
            .append($('<option>').val('').text(T.none));
        referenceList(collection).forEach(function (definition) {
            $control.append($('<option>').val(definition.key).text(definition.name));
        });
        $control.val(text(value));
        $control.on('change', function () { setValue(node, field.key, $(this).val()); });
        return wrap(field, node, $control);
    }

    // Where a Question keeps the answer: a contact field, a conversation field, or a value that
    // lives only for this run. The scopes come from the server, so a fourth cannot be invented here.
    function renderStoreTarget(node, field, value) {
        var current = (value && typeof value === 'object') ? $.extend({}, value) : {};
        var labels = { contact: T.scopeContact, conversation: T.scopeConversation, context: T.scopeContext };
        var $box = $('<div>');

        var $scope = $('<select>').addClass('form-select form-select-sm mb-1')
            .append($('<option>').val('').text(T.none));
        (state.catalogue.store_scopes || []).forEach(function (scope) {
            $scope.append($('<option>').val(scope).text(labels[scope] || scope));
        });
        $scope.val(text(current.scope));

        var $key = $('<input>').attr({ type: 'text', placeholder: T.storeKey, maxlength: 40 })
            .addClass('form-control form-control-sm').val(text(current.key));
        var $pick = $('<select>').addClass('form-select form-select-sm');

        function redraw() {
            var scope = $scope.val();
            $key.prop('hidden', scope !== 'context');
            $pick.prop('hidden', scope === 'context' || scope === '');

            if (scope === 'contact' || scope === 'conversation') {
                var collection = scope === 'contact' ? 'contact_attributes' : 'conversation_attributes';
                $pick.empty().append($('<option>').val('').text(T.none));
                referenceList(collection).forEach(function (definition) {
                    $pick.append($('<option>').val(definition.key).text(definition.name));
                });
                $pick.val(text(current.key));
            }
        }

        function commit() {
            var scope = $scope.val();
            if (!scope) { setValue(node, field.key, null); return; }
            var key = scope === 'context' ? $key.val() : $pick.val();
            setValue(node, field.key, key ? { scope: scope, key: key } : null);
        }

        $scope.on('change', function () { current.key = ''; $key.val(''); redraw(); commit(); });
        $key.on('input', function () { current.key = $(this).val(); commit(); });
        $pick.on('change', function () { current.key = $(this).val(); commit(); });

        $box.append($scope).append($pick).append($key);
        redraw();
        return wrap(field, node, $box);
    }

    function renderNodeTarget(node, field, value) {
        var $control = $('<select>').addClass('form-select form-select-sm')
            .append($('<option>').val('').text(T.none));
        state.graph.nodes.forEach(function (candidate) {
            if (candidate.id === node.id) { return; }   // the server refuses a Go to pointing at itself
            $control.append($('<option>').val(candidate.id).text(typeLabel(candidate.type) + ' · ' + candidate.id));
        });
        $control.val(text(value));
        $control.on('change', function () { setValue(node, field.key, $(this).val()); render(); });
        return wrap(field, node, $control);
    }

    // THE CONDITION EDITOR, built from the vocabulary the server serves.
    //
    // Which attributes exist, which operators each one takes, and where its values come from are
    // all answers from Lynomia -- the same filter_keys.yml its own condition validator reads. A
    // list written here would offer a merchant an attribute the server rejects, which is exactly
    // what the Phase 4A review found Lynomia's own dashboard doing with a second copy in JS.
    function vocabularyFor(scope) {
        var all = (state.references && state.references.vocabulary && state.references.vocabulary.conditions) || [];
        if (scope === 'contact_audience') { return all.filter(function (entry) { return entry.key === 'contact_audience'; }); }
        if (scope === 'commerce') { return all.filter(function (entry) { return entry.key.indexOf('commerce_') === 0; }); }
        return all;
    }

    function renderConditions(node, field, value) {
        var rows = Array.isArray(value) ? value.map(function (row) { return $.extend({}, row); }) : [];
        var vocabulary = vocabularyFor(field.scope);
        var $box = $('<div>');

        function entryFor(key) {
            for (var i = 0; i < vocabulary.length; i++) { if (vocabulary[i].key === key) { return vocabulary[i]; } }
            return null;
        }

        function valuesControl(row, index, entry) {
            // An id-valued key binds to a collection the workspace already serves; an enumerated
            // key carries its values; anything else is free text.
            if (entry && entry.options_from) {
                var $select = $('<select>').addClass('form-select form-select-sm')
                    .append($('<option>').val('').text(T.none));
                referenceList(entry.options_from).forEach(function (record) {
                    var optionValue = record.id !== undefined && record.id !== null ? String(record.id) : record.name;
                    $select.append($('<option>').val(optionValue).text(record.name));
                });
                $select.val(text((row.values || [])[0]));
                return $select.on('change', function () {
                    rows[index].values = $(this).val() ? [$(this).val()] : [];
                    commit();
                });
            }

            if (entry && Array.isArray(entry.values) && entry.values.length) {
                var $enum = $('<select>').addClass('form-select form-select-sm')
                    .append($('<option>').val('').text(T.none));
                entry.values.forEach(function (option) {
                    $enum.append($('<option>').val(option).text(valueLabel(option)));
                });
                $enum.val(text((row.values || [])[0]));
                return $enum.on('change', function () {
                    rows[index].values = $(this).val() ? [$(this).val()] : [];
                    commit();
                });
            }

            return $('<input>').attr({ type: 'text', placeholder: T.value }).addClass('form-control form-control-sm')
                .val(text((row.values || [])[0]))
                .on('input', function () {
                    rows[index].values = $(this).val() === '' ? [] : [$(this).val()];
                    commit();
                });
        }

        function redraw() {
            $box.empty();

            rows.forEach(function (row, index) {
                var entry = entryFor(row.attribute_key);
                var $card = $('<div>').addClass('border rounded p-2 mb-2');

                $card.append($('<div>').addClass('d-flex justify-content-between align-items-center mb-1')
                    .append($('<span>').addClass('small text-muted').text(index === 0 ? '' : (row.query_operator || 'AND')))
                    .append($('<button>').attr('type', 'button').addClass('btn btn-sm btn-light text-danger py-0 px-1')
                        .text('×').attr('aria-label', T.remove)
                        .on('click', function () { rows.splice(index, 1); commit(); redraw(); })));

                var $attribute = $('<select>').addClass('form-select form-select-sm mb-1')
                    .append($('<option>').val('').text(T.attribute));
                vocabulary.forEach(function (candidate) {
                    var $option = $('<option>').val(candidate.key).text(candidate.key);
                    // Present but refused: a merchant who cannot use a Commerce condition should
                    // see why, not wonder where it went.
                    if (candidate.available === false) {
                        $option.prop('disabled', true).text(candidate.key + ' — ' + T.unavailable);
                    }
                    $attribute.append($option);
                });
                $attribute.val(text(row.attribute_key));
                $attribute.on('change', function () {
                    rows[index].attribute_key = $(this).val();
                    var picked = entryFor($(this).val());
                    rows[index].filter_operator = picked ? (picked.operators || [])[0] : null;
                    rows[index].values = [];
                    commit();
                    redraw();
                });
                $card.append($attribute);

                var $operator = $('<select>').addClass('form-select form-select-sm mb-1');
                ((entry && entry.operators) || []).forEach(function (operator) {
                    $operator.append($('<option>').val(operator).text(T.operators[operator] || operator));
                });
                $operator.val(text(row.filter_operator));
                $operator.on('change', function () { rows[index].filter_operator = $(this).val(); commit(); });
                $card.append($operator);

                // is_present / is_not_present take no value, so no empty box is offered for them.
                if (['is_present', 'is_not_present', 'attribute_changed'].indexOf(row.filter_operator) < 0) {
                    $card.append(valuesControl(row, index, entry));
                }

                $box.append($card);
            });

            if (rows.length < (field.max_items || 10)) {
                $box.append($('<button>').attr('type', 'button').addClass('btn btn-sm btn-light')
                    .text(T.addCondition).on('click', function () {
                        rows.push({ attribute_key: '', filter_operator: null, values: [],
                                    query_operator: rows.length ? 'AND' : null });
                        commit();
                        redraw();
                    }));
            }
        }

        function commit() {
            var cleaned = rows.filter(function (row) { return row.attribute_key && row.filter_operator; });
            setValue(node, field.key, cleaned.length ? cleaned : null);
        }

        redraw();
        return wrap(field, node, $box);
    }

    // THE TEMPLATE PICKER. The templates and the values each one takes are served per flow, by the
    // same code path that validates them at publish, so the picker cannot offer one the publish
    // will refuse nor miss a value it demands.
    function renderTemplate(node, field, value) {
        var templates = state.catalogue.templates || [];
        if (!templates.length) {
            return $('<div>').addClass('alert alert-warning py-2 px-2 small').text(T.noTemplates);
        }

        var $control = $('<select>').addClass('form-select form-select-sm')
            .append($('<option>').val('').text(T.none));
        templates.forEach(function (template, index) {
            $control.append($('<option>').val(index).text(template.name + ' · ' + template.language));
        });

        templates.forEach(function (template, index) {
            if (template.name === value && template.language === (node.data || {}).language) { $control.val(index); }
        });

        $control.on('change', function () {
            var picked = templates[Number($(this).val())];
            node.data = node.data || {};
            if (!picked) {
                delete node.data.name;
                delete node.data.language;
            } else {
                // Name and language travel together: Lynomia finds a template by both, and a name
                // set without its language is a node that cannot resolve to anything.
                node.data.name = picked.name;
                node.data.language = picked.language;
            }
            delete node.data.params;
            markDirty();
            renderInspector();
            render();
        });

        return wrap(field, node, $control);
    }

    function currentTemplate(node) {
        var data = node.data || {};
        var found = null;
        (state.catalogue.templates || []).forEach(function (template) {
            if (template.name === data.name && template.language === data.language) { found = template; }
        });
        return found;
    }

    function renderTemplateParams(node, field) {
        var template = currentTemplate(node);
        if (!template || !template.slots.length) { return null; }

        var params = $.extend(true, {}, (node.data || {}).params || {});
        var $box = $('<div>');

        template.slots.forEach(function (slot) {
            var id = 'lynb-slot-' + slot.section + '-' + slot.key;
            var $input = $('<input>').attr({ type: 'text', id: id, maxlength: 1024 })
                .addClass('form-control form-control-sm mb-2')
                .val(text(readSlot(params, slot)));

            $input.on('input', function () {
                writeSlot(params, slot, $(this).val());
                setValue(node, 'params', params);
            });

            $box.append($('<label>').addClass('form-label small mb-1').attr('for', id)
                .text(slot.section + ' · ' + slot.key));
            $box.append($input);
        });

        return $('<div>').addClass('mb-3')
            .append($('<div>').addClass('form-label small mb-1').text(fieldLabel(field.key)))
            .append($box);
    }

    // Buttons are a LIST keyed by position upstream, everything else is a map -- the same shape
    // Flows::Template.value reads, so what is written here is what the validator looks for.
    function readSlot(params, slot) {
        if (slot.section === 'buttons') {
            var entry = (params.buttons || [])[Number(slot.key)] || {};
            return entry.parameter;
        }
        return (params[slot.section] || {})[slot.key];
    }

    function writeSlot(params, slot, value) {
        if (slot.section === 'buttons') {
            params.buttons = params.buttons || [];
            params.buttons[Number(slot.key)] = $.extend({}, params.buttons[Number(slot.key)],
                { type: slot.kind === 'copy_code' ? 'copy_code' : 'url', parameter: value });
            return;
        }
        params[slot.section] = params[slot.section] || {};
        params[slot.section][slot.key] = value;
    }

    function refreshSummary(node) {
        var target = el.nodes.querySelector('[data-node="' + cssEscape(node.id) + '"] .lynb-summary');
        if (target) { target.textContent = summaryOf(node); }
    }

    // ------------------------------------------------------------------ loading and saving

    function url(path) { return BASE + '/flows/' + FLOW_ID + path; }

    function applyFlow(flow) {
        state.flow = flow;
        state.errors = flow.errors || [];
        state.lockVersion = (flow.draft && flow.draft.lock_version !== null && flow.draft.lock_version !== undefined)
            ? Number(flow.draft.lock_version)
            : 0;
        $('#lynb-name').text(flow.name || '');
        $('#lynb-state').text(T.states[flow.state] || flow.state);
    }

    function load() {
        clearAlert();

        $.when($.getJSON(url('/builder')), $.getJSON(REFERENCES_URL))
            .done(function (builderResult, referencesResult) {
                var builder = builderResult[0];
                state.references = referencesResult[0];
                state.catalogue = builder.catalogue || state.catalogue;
                state.graph = builder.graph || { nodes: [], edges: [] };
                applyFlow(builder.flow || {});
                renderPalette();
                render();
                renderInspector();
                fitToContent();
                markClean();
            })
            .fail(function (xhr) { showAlert(failureOf(xhr)); });
    }

    function send(method, path, payload) {
        return $.ajax({
            url: url(path), method: method, contentType: 'application/json', dataType: 'json',
            data: payload === undefined ? undefined : JSON.stringify(payload)
        });
    }

    function saveDraft(onSaved) {
        clearAlert();
        var $save = $('#lynb-save').prop('disabled', true);

        send('PUT', '/draft', { graph: state.graph, lock_version: state.lockVersion })
            .done(function (body) {
                if (body.ok === false && body.conflict) { openConflict(body.conflict); return; }
                if (body.ok === false) {
                    state.errors = body.errors || [];
                    renderProblems();
                    showAlert(T.shapeProblems);
                    return;
                }
                applyFlow(body.flow || {});
                markClean();
                render();
                renderInspector();
                showNotice(T.saved);
                if (onSaved) { onSaved(); }
            })
            .fail(function (xhr) { showAlert(failureOf(xhr)); })
            .always(function () { $save.prop('disabled', false); });
    }

    // SOMEBODY ELSE SAVED FIRST. The merchant's own graph stays on screen -- losing what they were
    // drawing because a colleague saved is the thing the optimistic lock exists to prevent -- and
    // they choose. "Keep mine" re-saves against the token the server just handed back, which is
    // deliberate and visible rather than an automatic overwrite.
    var conflictModal = new bootstrap.Modal(document.getElementById('lynb-conflict-modal'));
    var pendingConflict = null;

    function openConflict(conflict) {
        pendingConflict = conflict;
        $('#lynb-conflict-when').text(conflict.updated_at ? new Date(conflict.updated_at).toLocaleString(
            document.documentElement.getAttribute('lang') || 'en') : '');
        conflictModal.show();
    }

    $('#lynb-conflict-mine').on('click', function () {
        if (!pendingConflict) { return; }
        state.lockVersion = Number(pendingConflict.lock_version) || 0;
        pendingConflict = null;
        conflictModal.hide();
        saveDraft();
    });

    $('#lynb-conflict-theirs').on('click', function () {
        if (!pendingConflict) { return; }
        state.graph = pendingConflict.graph || { nodes: [], edges: [] };
        state.lockVersion = Number(pendingConflict.lock_version) || 0;
        pendingConflict = null;
        conflictModal.hide();
        markClean();
        state.selected = null;
        render();
        renderInspector();
        fitToContent();
    });

    $('#lynb-save').on('click', function () { saveDraft(); });

    // PUBLISHING SAVES FIRST. A merchant who edits and then publishes means "publish what I am
    // looking at"; publishing the last saved draft instead would quietly ship something else.
    $('#lynb-publish').on('click', function () {
        var publish = function () {
            var $publish = $('#lynb-publish').prop('disabled', true);
            send('POST', '/publish')
                .done(function (body) {
                    if (body.ok === false) {
                        state.errors = body.errors || [];
                        render();
                        renderInspector();
                        showAlert(T.problems);
                        return;
                    }
                    state.errors = [];
                    applyFlow($.extend({}, state.flow, body.flow || {}));
                    render();
                    showNotice(T.published);
                })
                .fail(function (xhr) { showAlert(failureOf(xhr)); })
                .always(function () { $publish.prop('disabled', false); });
        };

        if (state.dirty) { saveDraft(publish); } else { publish(); }
    });

    window.addEventListener('beforeunload', function (event) {
        if (!state.dirty) { return undefined; }
        event.preventDefault();
        event.returnValue = T.leave;
        return T.leave;
    });

    // ------------------------------------------------------------------ test mode

    var testModal = new bootstrap.Modal(document.getElementById('lynb-test-modal'));
    var testInputs = [];

    function renderTest(body) {
        var $log = $('#lynb-test-log').empty();
        (body.transcript || []).forEach(function (entry) {
            $log.append($('<div>')
                .addClass('lynb-bubble ' + (entry.from === 'bot' ? 'lynb-bubble-bot' : 'lynb-bubble-customer'))
                .text(entry.text));
        });
        $log.scrollTop($log[0].scrollHeight);

        var last = (body.transcript || []).slice().reverse().find(function (entry) {
            return entry.from === 'bot' && entry.items && entry.items.length;
        });
        var $choices = $('#lynb-test-choices').empty();
        (last ? last.items : []).forEach(function (item) {
            $choices.append($('<button>').attr('type', 'button').addClass('btn btn-sm btn-outline-primary')
                .text(item.title)
                .on('click', function () { pushInput({ text: item.title, reply_id: item.value }); }));
        });

        var session = body.session || {};
        $('#lynb-test-status').text(session.status === 'active'
            ? T.testWaiting
            : T.testEnded.split(':reason').join(session.end_reason || session.failure_code || session.status || ''));
    }

    function runTest() {
        $('#lynb-test-status').text('…');
        // Test Mode runs the DRAFT upstream, so the graph on screen has to be saved first --
        // otherwise a merchant tests the version before the change they just made.
        var run = function () {
            send('POST', '/simulate', { inputs: testInputs })
                .done(renderTest)
                .fail(function (xhr) { $('#lynb-test-status').text(failureOf(xhr)); });
        };

        if (state.dirty) { saveDraft(run); } else { run(); }
    }

    function pushInput(input) {
        if (testInputs.length >= 20) { return; }
        testInputs.push(input);
        runTest();
    }

    $('#lynb-test').on('click', function () {
        testInputs = [];
        $('#lynb-test-log').empty();
        $('#lynb-test-choices').empty();
        $('#lynb-test-status').text('');
        testModal.show();
    });

    $('#lynb-test-form').on('submit', function (event) {
        event.preventDefault();
        var value = $.trim($('#lynb-test-input').val());
        if (!value) { return; }
        $('#lynb-test-input').val('');
        pushInput({ text: value });
    });

    $('#lynb-test-timer').on('click', function () { pushInput({ timer: true }); });
    $('#lynb-test-reset').on('click', function () {
        testInputs = [];
        $('#lynb-test-log').empty();
        $('#lynb-test-choices').empty();
        $('#lynb-test-status').text('');
    });

    load();
})(jQuery);
</script>
@endpush
