@extends('layouts.app')

@section('page-title', __('Lynomia Chat Integration'))

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Lynomia Chat Integration') }}</li>
@endsection

@section('content')
<div class="row">
    <div class="col-12">

        {{-- ONE PAGE, TABS INSIDE IT. Phase 1 ships the two tabs that have content. The
             remaining approved tabs are added here as their phases land, so the integration
             never grows a second menu entry somewhere else. --}}
        <ul class="nav nav-tabs mb-3" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" data-bs-toggle="tab" href="#lynomia-overview" role="tab">{{ __('Overview') }}</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#lynomia-seats" role="tab">{{ __('Agent Seats') }}</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#lynomia-automation" role="tab">{{ __('Automation') }}</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#lynomia-notices" role="tab">{{ __('Merchant notices') }}</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#lynomia-accounts" role="tab">
                    {{ __('Merchant Accounts') }}
                    @if ($pendingCount > 0)
                        <span class="badge bg-warning">{{ $pendingCount }}</span>
                    @endif
                </a>
            </li>
        </ul>

        <div class="tab-content">

            <div class="tab-pane fade show active" id="lynomia-overview" role="tabpanel">
                <div class="card">
                    <div class="card-body">
                        <table class="table mb-0">
                            <tr>
                                <th style="width:260px">{{ __('Integration enabled') }}</th>
                                <td>
                                    @if ($integrationEnabled)
                                        <span class="badge bg-success">{{ __('Yes') }}</span>
                                    @else
                                        <span class="badge bg-secondary">{{ __('No') }}</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th>{{ __('Credentials present') }}</th>
                                <td>
                                    @if ($integrationConfigured)
                                        <span class="badge bg-success">{{ __('Yes') }}</span>
                                    @else
                                        <span class="badge bg-warning">{{ __('No') }}</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th>{{ __('Connected stores') }}</th>
                                <td>{{ $links->total() }}</td>
                            </tr>
                            <tr>
                                <th>{{ __('Awaiting approval') }}</th>
                                <td>{{ $pendingCount }}</td>
                            </tr>
                        </table>
                        {{-- Deliberately booleans only: an operator needs to know whether the
                             integration is usable, not what the credential is. The base URL
                             and token are never rendered. --}}
                        <p class="text-muted small mt-3 mb-0">
                            {{ __('Credentials are read from the server environment and are never stored in the database or shown here.') }}
                        </p>
                    </div>
                </div>
            </div>

            {{-- AGENT SEATS. Store Syria decides what a package includes; Lynomia enforces the
                 effective number. This tab shows the first half -- what each plan says -- and
                 the seats actually handed out, read from this side so the page answers even
                 when Lynomia is unreachable, which is when an operator usually opens it. --}}
            <div class="tab-pane fade" id="lynomia-seats" role="tabpanel">
                <div class="alert alert-light border">
                    {{ __('Seats included in a plan are granted to that merchant\'s Lynomia workspace. Lynomia takes the HIGHER of this number and any subscription the merchant bought there directly, so raising it here never reduces what they already paid for, and an operator override in Lynomia beats both.') }}
                </div>

                <h6>{{ __('Included seats per plan') }}</h6>
                <div class="table-responsive mb-4">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>{{ __('Plan') }}</th>
                                <th>{{ __('Included Lynomia agent seats') }}</th>
                                <th style="width:200px">{{ __('Change') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($planSeats as $plan)
                                <tr>
                                    <td>{{ $plan->name }}</td>
                                    <td>
                                        @if ($plan->lynomia_agent_seats === null)
                                            {{-- Null is not zero: the plan says nothing, so nothing is
                                                 granted and nobody is locked out. --}}
                                            <span class="text-muted">{{ __('Not set — no seats are granted or withheld') }}</span>
                                        @else
                                            <span class="badge bg-primary">{{ $plan->lynomia_agent_seats }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        {{-- An empty field is a real answer: it means "grant and
                                             withhold nothing", which is not the same statement as
                                             zero. Zero is a deliberate lockout. --}}
                                        <form method="POST" action="{{ route('lynomia.admin.seats.update') }}" class="d-flex gap-2 align-items-center">
                                            @csrf
                                            <input type="hidden" name="plan_id" value="{{ $plan->id }}">
                                            <label class="visually-hidden" for="seats-{{ $plan->id }}">{{ __('Included Lynomia agent seats') }}</label>
                                            <input type="number" min="0" max="10000" inputmode="numeric"
                                                   class="form-control form-control-sm" style="max-width:7rem"
                                                   id="seats-{{ $plan->id }}" name="seats"
                                                   value="{{ $plan->lynomia_agent_seats }}"
                                                   placeholder="{{ __('Not set') }}">
                                            <button type="submit" class="btn btn-sm btn-primary">{{ __('Save') }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-muted">{{ __('No plans yet.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <h6>{{ __('Seats in use') }}</h6>
                <p class="text-muted small">{{ __('One Lynomia identity per person per workspace. A staff member consumes a seat the first time they open the inbox.') }}</p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr><th>{{ __('Store') }}</th><th>{{ __('Seats used') }}</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($seatedStores as $storeId => $seats)
                                <tr><td>#{{ $storeId }}</td><td>{{ $seats }}</td></tr>
                            @empty
                                <tr><td colspan="2" class="text-muted">{{ __('No seats used yet.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <hr>
                <h6>{{ __('Event delivery') }}</h6>
                <p class="text-muted small mb-1">
                    {{ __('Lynomia posts a signed notification whenever a conversation changes. These counts are what this installation accepted.') }}
                </p>
                <ul class="list-unstyled mb-0">
                    <li>{{ __('Deliveries accepted') }}: <strong>{{ $eventHealth['total'] }}</strong></li>
                    <li>{{ __('Stores reporting') }}: <strong>{{ $eventHealth['stores_reporting'] }}</strong></li>
                    <li>
                        {{ __('Last delivery') }}:
                        <strong>{{ $eventHealth['last_at'] ?? __('never') }}</strong>
                    </li>
                </ul>

                <hr>
                <h6>{{ __('Commerce events') }}</h6>
                <p class="text-muted small mb-1">
                    {{ __('Order changes this installation sends to Lynomia. An event that could not be delivered is still here, with the store it belongs to.') }}
                </p>
                <ul class="list-unstyled mb-0">
                    <li>
                        {{ __('Feed') }}:
                        <strong>{{ $commerceHealth['enabled'] ? __('On') : __('Off') }}</strong>
                    </li>
                    <li>{{ __('Waiting to be delivered') }}: <strong>{{ $commerceHealth['pending'] }}</strong></li>
                    <li>
                        {{ __('Oldest waiting') }}:
                        <strong>{{ $commerceHealth['oldest'] ?? __('none') }}</strong>
                    </li>
                </ul>
                @if (! empty($commerceHealth['stores']))
                    {{-- The one thing on this page an operator has to act on: a store whose credential has almost
                         certainly been revoked or rotated on the Lynomia side, so nothing will deliver until it is
                         reissued above. --}}
                    <div class="alert alert-warning mt-2 mb-0" role="alert">
                        <strong>{{ __('Stores whose events are not being delivered') }}</strong>
                        <ul class="mb-0">
                            @foreach ($commerceHealth['stores'] as $storeId => $waiting)
                                <li>
                                    {{ __('Store') }} #{{ $storeId }} &mdash;
                                    {{ trans_choice('{1} :count event waiting|[2,*] :count events waiting', $waiting, ['count' => $waiting]) }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

            {{-- -------------------------------------------------------------- consent --}}
            {{--
                OUTBOUND CONSENT DECISIONS, which fail differently from commerce events.

                A waiting decision is usually not a fault. The common transient is that Lynomia
                has no customer link for the shopper yet, and nothing in an order event creates
                one -- the link appears when the shopper first writes in, or when an agent makes
                it. That can take weeks and the decision is correctly held, not lost.

                The number to act on is the last one: decisions the dispatcher has given up on
                after thirty days. Those are not retried again, and the merchant's own consent
                screen shows them as "Not sent".
            --}}
            <div class="col-12 col-xl-6">
                <h6 class="mb-2">{{ __('Consent decisions going out') }}</h6>
                <ul class="list-unstyled small mb-0">
                    <li>{{ __('Waiting to be delivered') }}: <strong>{{ $consentHealth['pending'] }}</strong></li>
                    <li>
                        {{ __('Oldest waiting') }}:
                        <strong>{{ $consentHealth['oldest'] ?? __('none') }}</strong>
                    </li>
                    <li>
                        {{ __('Given up on after 30 days') }}:
                        <strong>{{ $consentHealth['expired'] }}</strong>
                    </li>
                </ul>
                @if ($consentHealth['expired'] > 0)
                    <div class="alert alert-warning mt-2 mb-0" role="alert">
                        {{ __('Some shoppers\' consent decisions were never accepted by Lynomia and are no longer being retried. They are shown as not sent on the merchant\'s consent screen.') }}
                    </div>
                @endif
            </div>

            {{-- ------------------------------------------------------------ automation --}}
            {{--
                WHAT THIS SIDE ACTUALLY CONTROLS, and where the rest lives.

                The Automation Studio's engine, its ceilings and its kill switches are Lynomia's
                and are administered in Lynomia's own Super Admin. What Store Syria decides is
                WHICH of a merchant's people reach the Studio at all, and that is a grant in this
                database -- so that is what this tab reports, without reaching Lynomia. The times
                an operator opens this page are the times Lynomia may be the thing that is wrong.

                COUNTS, NOT NAMES. A list of everyone holding a permission is a list of other
                tenants' employees. The store count is what makes the role count readable: four
                roles across four stores is ordinary, four roles in one store is worth a look.
            --}}
            <div class="tab-pane fade" id="lynomia-automation" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-1">{{ __('Who can build automations') }}</h5>
                        <small class="text-muted">
                            {{ __('Granted in each merchant\'s own roles. Counts only — never the people.') }}
                        </small>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col">{{ __('Permission') }}</th>
                                        <th scope="col" class="text-end">{{ __('Roles holding it') }}</th>
                                        <th scope="col" class="text-end">{{ __('Platform-wide roles') }}</th>
                                        <th scope="col" class="text-end">{{ __('Stores covered') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($automationAccess as $row)
                                        <tr>
                                            <td>{{ __($row['name']) }}</td>
                                            <td class="text-end">{{ $row['roles'] }}</td>
                                            <td class="text-end">{{ $row['platform_wide'] }}</td>
                                            <td class="text-end">{{ $row['stores'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <hr>

                        <h6>{{ __('Administered in Lynomia, not here') }}</h6>
                        <p class="small text-muted mb-2">
                            {{ __('These are deliberately not duplicated in this page: a second switch for the same thing is a switch that disagrees.') }}
                        </p>
                        <ul class="small mb-0">
                            <li>{{ __('How many automations a workspace may have — its plan\'s flow ceiling.') }}</li>
                            <li>{{ __('Whether the Flow Builder and store automation are included at all — the workspace\'s features.') }}</li>
                            <li>{{ __('Whether abandoned-basket events are collected for a provider — the commerce recovery switches.') }}</li>
                            <li>{{ __('The engine\'s own safety limits on steps, messages and session length.') }}</li>
                        </ul>
                    </div>
                </div>
            </div>

            {{-- --------------------------------------------------- merchant notices (P6E) --}}
            {{--
                OPERATIONAL COMMUNICATION TO MERCHANTS, from Store Syria's own Lynomia
                workspace: onboarding, a broken integration, a security notice. Essential
                notices only -- the workspace refuses anything Meta does not classify as
                UTILITY, so there is no promotional capability here to switch on.

                HISTORY FIRST, because the question an operator arrives with is almost always
                "did this merchant get told?" rather than "tell them". The failures and the
                reasons are shown beside the successes: the commonest reason a merchant is not
                reachable is that Store Syria has no usable phone number for them, and that is
                a fact worth seeing rather than an absence of rows.
            --}}
            <div class="tab-pane fade" id="lynomia-notices" role="tabpanel">
                @if (! $platformComms['configured'])
                    <div class="alert alert-secondary" role="alert">
                        {{ __('Platform communications are not set up. An operator provisions the workspace in Lynomia and records its ids before any notice can be sent.') }}
                    </div>
                @endif

                <ul class="list-unstyled small">
                    <li>{{ __('Notices sent') }}: <strong>{{ $platformComms['sent'] }}</strong></li>
                    <li>{{ __('Not delivered') }}: <strong>{{ $platformComms['failed'] }}</strong></li>
                    <li>
                        {{ __('Awaiting an operator') }}:
                        <strong>{{ $platformComms['indeterminate'] }}</strong>
                    </li>
                </ul>

                @if ($platformComms['indeterminate'] > 0)
                    <div class="alert alert-warning" role="alert">
                        {{ __('Some notices were sent but their answer was lost, so nobody knows whether they arrived. They are not retried automatically: a repeat would reach the merchant twice, and nothing downstream would recognise it as a repeat.') }}
                    </div>
                @endif

                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('When') }}</th>
                            <th>{{ __('Store') }}</th>
                            <th>{{ __('About') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th>{{ __('Reason') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse ($platformComms['recent'] as $notice)
                        <tr>
                            <td>{{ $notice->created_at?->diffForHumans() }}</td>
                            <td>#{{ $notice->store_id }}</td>
                            <td>{{ $notice->kind }}</td>
                            <td>{{ $notice->status }}</td>
                            <td class="text-muted">{{ $notice->failure_reason ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted">{{ __('No notice has been sent yet.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="tab-pane fade" id="lynomia-accounts" role="tabpanel">
                <div class="card">
                    <div class="card-body table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ __('Store') }}</th>
                                    <th>{{ __('Lynomia account') }}</th>
                                    <th>{{ __('Mode') }}</th>
                                    <th>{{ __('State') }}</th>
                                    {{-- The far side's view of this installation, fetched only when asked for. --}}
                                    <th>{{ __('In Lynomia') }}</th>
                                    <th>{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                            @forelse ($links as $row)
                                <tr>
                                    <td>#{{ $row->store_id }}</td>
                                    <td>{{ $row->lynomia_account_id ?? '—' }}</td>
                                    <td>{{ $row->link_mode }}</td>
                                    <td>{{ $row->state }}</td>
                                    <td class="lyn-health" data-health-url="{{ $row->lynomia_account_id ? route('lynomia.admin.links.remote_health', $row->id) : '' }}">
                                        @if ($row->lynomia_account_id)
                                            <button type="button" class="btn btn-sm btn-outline-secondary lyn-health-check">
                                                {{ __('Check') }}
                                            </button>
                                            <div class="lyn-health-out small mt-1"></div>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($row->state === \Workdo\LynomiaChat\app\Support\LinkState::PENDING_VERIFICATION)
                                            <form method="POST" action="{{ route('lynomia.admin.links.approve', $row->id) }}" class="d-flex gap-2">
                                                @csrf
                                                <input type="text" name="verification_code" class="form-control form-control-sm"
                                                       placeholder="{{ __('Code from merchant') }}" required style="max-width:180px">
                                                <button class="btn btn-sm btn-primary">{{ __('Approve') }}</button>
                                            </form>
                                        @elseif ($row->state === \Workdo\LynomiaChat\app\Support\LinkState::ACTIVE)
                                            {{-- Suspend is the reversible hold; revoke withdraws the arrangement.
                                                 Both now stop Lynomia's commerce installation, which neither did
                                                 before P6B. Each asks why, because the next operator reads it. --}}
                                            <form method="POST" action="{{ route('lynomia.admin.links.suspend', $row->id) }}" class="d-flex gap-1 mb-1">
                                                @csrf
                                                <input type="text" name="reason" class="form-control form-control-sm"
                                                       placeholder="{{ __('Why?') }}" required style="max-width:140px">
                                                <button class="btn btn-sm btn-outline-warning">{{ __('Suspend') }}</button>
                                            </form>
                                            <form method="POST" action="{{ route('lynomia.admin.links.revoke', $row->id) }}">
                                                @csrf
                                                <button class="btn btn-sm btn-outline-danger">{{ __('Revoke') }}</button>
                                            </form>
                                        @elseif ($row->state === \Workdo\LynomiaChat\app\Support\LinkState::SUSPENDED)
                                            <form method="POST" action="{{ route('lynomia.admin.links.resume', $row->id) }}" class="d-flex gap-1 mb-1">
                                                @csrf
                                                <input type="text" name="reason" class="form-control form-control-sm"
                                                       placeholder="{{ __('Why?') }}" required style="max-width:140px">
                                                <button class="btn btn-sm btn-outline-success">{{ __('Resume') }}</button>
                                            </form>
                                            {{-- The way out of a rejected credential: mint a replacement and bring
                                                 the installation back, with Lynomia checking it can be reached. --}}
                                            <form method="POST" action="{{ route('lynomia.admin.links.reauthenticate', $row->id) }}" class="mb-1">
                                                @csrf
                                                <button class="btn btn-sm btn-outline-secondary">{{ __('Re-authenticate') }}</button>
                                            </form>
                                            {{-- Js::from, not @json: @json leaves the double quotes raw, so inside a
                                                 double-quoted attribute the handler is cut off at the first one and the
                                                 only transition with no way back submits unguarded. Found by clicking it
                                                 in a browser; nothing server-side could have noticed. --}}
                                            <form method="POST" action="{{ route('lynomia.admin.links.decommission', $row->id) }}" class="d-flex gap-1"
                                                  onsubmit="return confirm({{ Js::from(__('Decommissioning cannot be undone. The workspace, its conversations and its consent records are kept, but the installation stops for good.')) }})">
                                                @csrf
                                                <input type="text" name="reason" class="form-control form-control-sm"
                                                       placeholder="{{ __('Why?') }}" required style="max-width:140px">
                                                <button class="btn btn-sm btn-outline-dark">{{ __('Decommission') }}</button>
                                            </form>
                                        @elseif ($row->state === \Workdo\LynomiaChat\app\Support\LinkState::REVOKED)
                                            {{-- Without this, revoking for a billing hold was a one way door. --}}
                                            <form method="POST" action="{{ route('lynomia.admin.links.restore', $row->id) }}">
                                                @csrf
                                                <button class="btn btn-sm btn-outline-secondary">{{ __('Restore') }}</button>
                                            </form>
                                        @elseif ($row->state === \Workdo\LynomiaChat\app\Support\LinkState::DECOMMISSIONED)
                                            {{-- Terminal. Lynomia dropped its credentials, so there is nothing to
                                                 bring back; the history stays reachable. --}}
                                            <span class="text-muted">{{ __('Ended') }}</span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted">{{ __('No stores are connected yet.') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                        {{ $links->links() }}
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>
@endsection

@push('scripts')
{{--
    THE FAR SIDE'S VIEW, FETCHED WHEN AN OPERATOR ASKS FOR IT.

    Everything else on this page is read from local tables so that it still renders when
    Lynomia is unreachable -- which is exactly when an operator opens it. This one panel does
    reach across, so it is a separate request per row: its failure is a sentence in one cell
    rather than a page that will not load.

    Blade parses {{ }} inside a script tag as well as in markup, so every literal JavaScript
    mustache below is written @{{ -- the error otherwise is an undefined-constant 500 at
    render time, not a JavaScript one.
--}}
<script>
(function () {
    var T = {
        checking: @json(__('Checking…')),
        failed: @json(__('Could not reach Lynomia')),
        noneOpen: @json(__('no open issues')),
        issues: @json(__('open issues')),
        stores: @json(__('installations')),
    };

    // Lynomia's own four-state vocabulary, said in the operator's language. The STATE is
    // Lynomia's -- a fifth one is not invented here, and an unrecognised one is printed as it
    // arrived rather than being rounded to the nearest known word. Only the wording is ours,
    // because an Arabic operator reading "critical" is reading an untranslated page.
    var LABEL = {
        healthy: @json(__('Healthy')),
        warning: @json(__('Needs attention')),
        critical: @json(__('Critical')),
        unknown: @json(__('Not known')),
    };

    document.querySelectorAll('.lyn-health-check').forEach(function (button) {
        button.addEventListener('click', function () {
            var cell = button.closest('.lyn-health');
            var out = cell.querySelector('.lyn-health-out');
            var url = cell.getAttribute('data-health-url');
            if (!url) { return; }

            button.disabled = true;
            out.textContent = T.checking;

            fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (response) { return response.json().then(function (body) { return { ok: response.ok, body: body }; }); })
                .then(function (result) {
                    button.disabled = false;
                    if (!result.ok) {
                        out.textContent = result.body && result.body.error ? result.body.error : T.failed;
                        out.className = 'lyn-health-out small mt-1 text-danger';
                        return;
                    }
                    render(out, result.body);
                })
                .catch(function () {
                    button.disabled = false;
                    out.textContent = T.failed;
                    out.className = 'lyn-health-out small mt-1 text-danger';
                });
        });
    });

    // Lynomia's own four-state vocabulary, not a second one invented here.
    var TONE = { healthy: 'text-success', warning: 'text-warning', critical: 'text-danger', unknown: 'text-muted' };

    function render(out, health) {
        var status = health.status || 'unknown';
        var open = (health.signals && health.signals.open) || 0;
        var stores = (health.installations || []).length;

        out.className = 'lyn-health-out small mt-1 ' + (TONE[status] || 'text-muted');
        out.textContent = (LABEL[status] || status) + ' — ' + stores + ' ' + T.stores + ', '
            + (open ? open + ' ' + T.issues : T.noneOpen);
    }
})();
</script>
@endpush
