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
                                </tr>
                            @empty
                                <tr><td colspan="2" class="text-muted">{{ __('No plans yet.') }}</td></tr>
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
