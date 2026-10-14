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
                                    <td>
                                        @if ($row->state === \Workdo\LynomiaChat\app\Support\LinkState::PENDING_VERIFICATION)
                                            <form method="POST" action="{{ route('lynomia.admin.links.approve', $row->id) }}" class="d-flex gap-2">
                                                @csrf
                                                <input type="text" name="verification_code" class="form-control form-control-sm"
                                                       placeholder="{{ __('Code from merchant') }}" required style="max-width:180px">
                                                <button class="btn btn-sm btn-primary">{{ __('Approve') }}</button>
                                            </form>
                                        @elseif ($row->state === \Workdo\LynomiaChat\app\Support\LinkState::ACTIVE)
                                            <form method="POST" action="{{ route('lynomia.admin.links.revoke', $row->id) }}">
                                                @csrf
                                                <button class="btn btn-sm btn-outline-danger">{{ __('Revoke') }}</button>
                                            </form>
                                        @elseif ($row->state === \Workdo\LynomiaChat\app\Support\LinkState::REVOKED)
                                            {{-- Without this, revoking for a billing hold was a one way door. --}}
                                            <form method="POST" action="{{ route('lynomia.admin.links.restore', $row->id) }}">
                                                @csrf
                                                <button class="btn btn-sm btn-outline-secondary">{{ __('Restore') }}</button>
                                            </form>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted">{{ __('No stores are connected yet.') }}</td></tr>
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
