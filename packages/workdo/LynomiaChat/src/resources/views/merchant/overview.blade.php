@extends('layouts.app')

@section('page-title', __('Communication & Marketing'))

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Communication & Marketing') }}</li>
@endsection

@section('content')
<div class="row">
    <div class="col-12">

        @if (!$integrationConfigured)
            {{-- Honest, not decorative. The integration ships disabled and stays disabled
                 until the owner has supplied credentials for a non-production environment. --}}
            <div class="alert alert-warning">
                <strong>{{ __('Not available yet') }}</strong>
                <div>{{ __('Lynomia Chat has not been configured for this installation. Please contact support.') }}</div>
            </div>
        @endif

        @if (!empty($verificationCode))
            {{-- Shown exactly once. Only a hash is stored, so this cannot be retrieved again. --}}
            <div class="alert alert-info">
                <strong>{{ __('Your verification code') }}</strong>
                <div class="h4 mt-2 mb-2"><code>{{ $verificationCode }}</code></div>
                <div>{{ __('Give this code to support to confirm you own the Lynomia workspace. It is shown only once and expires shortly.') }}</div>
            </div>
        @endif

        <div class="card">
            <div class="card-header">
                <h5>{{ __('Lynomia Chat workspace') }}</h5>
            </div>
            <div class="card-body">

                @if ($link && $link->isActive())
                    <p class="mb-3">
                        {{ __('This store is connected to Lynomia Chat.') }}
                        @if ($link->lynomia_account_name)
                            <span class="text-muted">({{ $link->lynomia_account_name }})</span>
                        @endif
                    </p>

                    <a href="{{ route('lynomia.open') }}" class="btn btn-primary">
                        {{ __('Open Lynomia Chat') }}
                    </a>

                @elseif ($link && $link->state === \Workdo\LynomiaChat\app\Support\LinkState::PENDING_VERIFICATION)
                    <div class="alert alert-secondary mb-0">
                        @if ($link->link_mode === \Workdo\LynomiaChat\app\Models\LynomiaStoreLink::MODE_LINK_EXISTING)
                            <strong>{{ __('Awaiting approval') }}</strong>
                            <div>
                                {{ __('Lynomia has confirmed you own this workspace. Support is reviewing the connection and you will be able to open it once it is approved.') }}
                                @if ($link->lynomia_account_name)
                                    <span class="text-muted">({{ $link->lynomia_account_name }})</span>
                                @endif
                            </div>
                        @else
                            {{-- A provisioning attempt that did not finish. Saying so, and offering the
                                 retry, is the difference between a resumable operation and a dead end. --}}
                            <strong>{{ __('Setting up your workspace') }}</strong>
                            <div class="mb-2">{{ __('We could not finish creating your Lynomia Chat workspace. Nothing was lost -- trying again continues from where it stopped.') }}</div>
                            <form method="POST" action="{{ route('lynomia.connect.provision') }}">
                                @csrf
                                <button type="submit" class="btn btn-primary btn-sm" @disabled(!$integrationConfigured)>
                                    {{ __('Continue setup') }}
                                </button>
                            </form>
                        @endif
                    </div>


                @else
                    <p>{{ __('Connect this store to Lynomia Chat to manage conversations, customers and campaigns.') }}</p>

                    <div class="row mt-4">
                        <div class="col-md-6">
                            <div class="border rounded p-3 h-100">
                                <h6>{{ __('Create a new workspace') }}</h6>
                                <p class="text-muted small">{{ __('We will set up a fresh Lynomia Chat workspace for this store.') }}</p>
                                <form method="POST" action="{{ route('lynomia.connect.provision') }}">
                                    @csrf
                                    <button type="submit" class="btn btn-primary btn-sm" @disabled(!$integrationConfigured)>
                                        {{ __('Create workspace') }}
                                    </button>
                                </form>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="border rounded p-3 h-100">
                                <h6>{{ __('Connect an existing workspace') }}</h6>
                                {{-- The merchant is NOT asked which account they own. They prove it, by
                                     pasting a code only an administrator of that workspace can generate.
                                     An account id field here would make the claimant the source of truth
                                     about their own identity, which is the whole thing this avoids. --}}
                                <p class="text-muted small">
                                    {{ __('Already use Lynomia Chat? In Lynomia, open Settings and generate a connection code, then paste it here. Connecting an existing workspace also requires approval by support.') }}
                                </p>
                                <form method="POST" action="{{ route('lynomia.connect.link') }}">
                                    @csrf
                                    <div class="mb-2">
                                        <input type="text" name="connection_code" class="form-control form-control-sm"
                                               autocomplete="off" spellcheck="false" maxlength="19"
                                               placeholder="{{ __('XXXX-XXXX-XXXX-XXXX') }}" required>
                                        @error('connection_code')
                                            <small class="text-danger">{{ $message }}</small>
                                        @enderror
                                    </div>
                                    <button type="submit" class="btn btn-secondary btn-sm" @disabled(!$integrationConfigured)>
                                        {{ __('Request connection') }}
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{-- The remaining approved sections (Unified Inbox, Customers, Connected Channels,
             Campaigns, Flows, Reports and the rest) belong to later phases. They are named
             here rather than rendered as empty shells, because a navigable section that does
             nothing reads as a broken feature. --}}
        <div class="card mt-3">
            <div class="card-body">
                <h6 class="mb-2">{{ __('Coming in later phases') }}</h6>
                <p class="text-muted small mb-0">
                    {{ __('Unified Inbox, Customers, Connected Channels, Orders & Tracking, Templates, Bots, Flow Builder, Automation, Campaigns, Audiences, Abandoned Carts, Reports, Team & Permissions, Subscription & Usage, Integration Health.') }}
                </p>
            </div>
        </div>

    </div>
</div>
@endsection
