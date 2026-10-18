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

        {{-- ========================================= authorization needs recovery

             THE ONE FAILURE THE MERCHANT CAUSED AND THE MERCHANT CAN FIX. Removing "Store Syria
             Integration" from Lynomia's own Connected platforms page deletes the grant this
             whole integration runs on. Nothing here is broken -- the workspace, its people, its
             conversations and this store's link are all intact -- but every call to it answers
             401, and before this banner the page went on saying "connected" because every column
             it renders from was still true as of the last call that worked.

             Shown ABOVE the workspace card and not inside it, because while this is true nothing
             in that card can be acted on: Open, the commerce controls and the identity list are
             all reporting a workspace nobody here can reach. --}}
        @if ($authorizationLost)
            <div class="alert alert-danger">
                <strong>{{ __('Integration authorization needs recovery') }}</strong>
                <p class="mb-2">
                    {{ __('Lynomia Chat no longer allows Store Syria to open this workspace. Your workspace, your team and your conversations are all safe -- only the connection between the two systems was removed.') }}
                </p>
                <p class="mb-2 small">
                    {{ __('In Lynomia Chat open Settings, then Integrations, then generate a connection code, and paste it here. Generate it inside the workspace this store is already connected to.') }}
                    @if ($link?->lynomia_account_name)
                        <span class="text-muted">({{ $link->lynomia_account_name }})</span>
                    @endif
                </p>
                <form method="POST" action="{{ route('lynomia.connect.recover') }}" class="row g-2 align-items-start">
                    @csrf
                    <div class="col-12 col-md-5">
                        <input type="text" name="connection_code" class="form-control form-control-sm"
                               autocomplete="off" spellcheck="false" maxlength="19"
                               placeholder="{{ __('XXXX-XXXX-XXXX-XXXX') }}" required>
                        @error('connection_code')
                            <small class="text-danger">{{ $message }}</small>
                        @enderror
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm btn-primary" @disabled(!$integrationConfigured)>
                            {{ __('Restore connection') }}
                        </button>
                    </div>
                </form>
            </div>
        @endif

        <div class="card">
            <div class="card-header">
                <h5>{{ __('Lynomia Chat workspace') }}</h5>
            </div>
            <div class="card-body">

                @if ($authorizationLost)
                    {{-- Deliberately NOT the ordinary "not connected" copy: this store IS
                         connected to a workspace, and offering it the first-time Create/Connect
                         choice would invite a merchant to provision a SECOND workspace while
                         their real one sits there, reachable again the moment they paste a code. --}}
                    <p class="mb-0 text-muted">
                        {{ __('Restore the connection above to open this workspace again.') }}
                    </p>
                @elseif ($link && $link->isActive())
                    <p class="mb-3">
                        {{ __('This store is connected to Lynomia Chat.') }}
                        @if ($link->lynomia_account_name)
                            <span class="text-muted">({{ $link->lynomia_account_name }})</span>
                        @endif
                    </p>

                    <a href="{{ route('lynomia.open') }}" class="btn btn-primary">
                        {{ __('Open Lynomia Chat') }}
                    </a>

                    {{-- ------------------------------------------------ store data (commerce)

                         WHY THIS IS A SECOND LINE AND NOT A SECOND TICK ON THE FIRST ONE. A
                         connected workspace is a chat inbox; a connected STORE is the orders and
                         customers inside it, held on the other system as its own installation
                         with its own state. They fail separately and they recover separately, so
                         showing one reading for both is how a merchant ends up looking at a green
                         tick with no orders in their inbox and nothing to press.

                         Every state here comes from CommerceState, which derives it from the row.
                         There is no success message to get stuck on and no spinner that outlives
                         the attempt that started it.

                         NO TOKEN APPEARS ANYWHERE ON THIS PAGE, in any state. The merchant never
                         holds one -- that is the entire point of the Connect button. --}}
                    <div class="mt-4 pt-3 border-top">
                        <h6 class="mb-2">{{ __('Store data') }}</h6>

                        @php($commerce = \Workdo\LynomiaChat\app\Support\CommerceState::class)

                        @switch($commerceState)
                            @case($commerce::UNAVAILABLE)
                                {{-- The workspace is ACTIVE but not yet usable: a claimed
                                     workspace whose ownership Lynomia has not confirmed, or a link
                                     an operator has paused. Commerce cannot be connected to it and
                                     the commerce API would refuse it, so no button is offered --
                                     there is nothing the merchant could press that would work. --}}
                                <p class="mb-0">
                                    <span class="badge bg-secondary">{{ __('Not connected') }}</span>
                                    {{ __('Your Lynomia Chat connection is not ready for store data yet. Please contact support.') }}
                                </p>
                                @break

                            @case($commerce::CONNECTED)
                                <p class="mb-2">
                                    <span class="badge bg-success">{{ __('Connected') }}</span>
                                    {{ __('Your orders, customers and abandoned carts are available to your team in Lynomia Chat.') }}
                                </p>
                                {{-- A VERIFY THAT FAILED OVER A CONNECTION THAT IS WORKING. Worth
                                     mentioning and not a disconnection, so it is a note beside the
                                     green badge rather than a red state replacing it -- which is
                                     what it used to be, taking the Stop-sharing control with it. --}}
                                @if ($commerceNote)
                                    <p class="text-muted small mb-2">
                                        {{ __('The last check did not get through:') }} {{ $commerceNote }}
                                    </p>
                                @endif

                                <div class="d-flex gap-2 flex-wrap">
                                    <form method="POST" action="{{ route('lynomia.commerce.verify') }}">
                                        @csrf
                                        <button type="submit" class="btn btn-outline-secondary btn-sm" @disabled(!$commerceConfigured)>
                                            {{ __('Verify connection') }}
                                        </button>
                                    </form>

                                    {{-- ENDS THE STORE-DATA HALF ONLY. The workspace, the
                                         conversations and the team stay -- which is why this sits
                                         here and not beside "Open Lynomia Chat". Confirmed first,
                                         because it revokes the credential and the merchant's team
                                         loses the order panel the moment it is pressed. --}}
                                    <form method="POST" action="{{ route('lynomia.commerce.disconnect') }}"
                                          onsubmit="return confirm('{{ __('Stop sharing your store data with Lynomia Chat? Your conversations stay, and you can connect it again at any time.') }}');">
                                        @csrf
                                        <button type="submit" class="btn btn-outline-danger btn-sm">
                                            {{ __('Stop sharing store data') }}
                                        </button>
                                    </form>
                                </div>
                                @break

                            @case($commerce::CONNECTING)
                                {{-- An attempt is genuinely in flight: the claim on the row says so,
                                     and it goes stale on its own, so this cannot persist after the
                                     process that set it has died. No progress bar is shown, because
                                     the backend exposes no progress to show. --}}
                                <p class="mb-0">
                                    <span class="badge bg-secondary">{{ __('Connecting') }}</span>
                                    {{ __('We are connecting your store data. This usually takes a few seconds -- refresh to see the result.') }}
                                </p>
                                @break

                            @case($commerce::SUSPENDED)
                                {{-- Switched off on the Lynomia side by an operator. Offering a
                                     retry here would be offering the merchant a button that
                                     deliberately does nothing. --}}
                                <p class="mb-0">
                                    <span class="badge bg-warning">{{ __('Unavailable') }}</span>
                                    {{ __('Your store data connection has been paused. Please contact support.') }}
                                </p>
                                @break

                            @case($commerce::NEEDS_REPAIR)
                                <p class="mb-2">
                                    <span class="badge bg-warning">{{ __('Needs attention') }}</span>
                                    {{ __('Lynomia Chat can no longer read your store data. Reconnecting fixes this.') }}
                                </p>
                                <form method="POST" action="{{ route('lynomia.commerce.connect') }}">
                                    @csrf
                                    <button type="submit" class="btn btn-primary btn-sm" @disabled(!$commerceConfigured)>
                                        {{ __('Reconnect store data') }}
                                    </button>
                                </form>
                                @break

                            @case($commerce::FAILED)
                                <p class="mb-2">
                                    <span class="badge bg-danger">{{ __('Not connected') }}</span>
                                    {{-- The column stores a locale-free REASON CODE; the sentence
                                         is produced here, in this reader's language. It used to
                                         store the rendered string, which meant the row carried
                                         whichever language the failing request ran under and showed
                                         it to everyone else in the tenant. A provider response body
                                         never reaches either. --}}
                                    <span class="small">{{ \Workdo\LynomiaChat\app\Support\CommerceState::reason($link) }}</span>
                                </p>
                                <form method="POST" action="{{ route('lynomia.commerce.connect') }}">
                                    @csrf
                                    <button type="submit" class="btn btn-primary btn-sm" @disabled(!$commerceConfigured)>
                                        {{ __('Try again') }}
                                    </button>
                                </form>
                                {{-- The reference an operator can find this attempt by. Not an
                                     internal exception and not an id the merchant has to understand
                                     -- it is the store, which is what support will ask for. --}}
                                <p class="text-muted small mt-2 mb-0">
                                    {{ __('If this keeps happening, give support this reference:') }}
                                    <code>LYN-{{ $link->store_id }}-{{ $link->commerce_attempts }}</code>
                                </p>
                                @break

                            @case($commerce::DISCONNECTED)
                                {{-- Stopped on purpose -- by this merchant, or by an operator
                                     revoking the credential. Its own branch because the wording
                                     "complete setup" is wrong for something that was set up and
                                     then ended, and because saying so is what tells a merchant
                                     who did not do it that somebody did. --}}
                                <p class="mb-2">
                                    <span class="badge bg-secondary">{{ __('Not connected') }}</span>
                                    {{ __('Your store data is not being shared with Lynomia Chat.') }}
                                </p>
                                <form method="POST" action="{{ route('lynomia.commerce.connect') }}">
                                    @csrf
                                    <button type="submit" class="btn btn-primary btn-sm" @disabled(!$commerceConfigured)>
                                        {{ __('Connect store data') }}
                                    </button>
                                </form>
                                @break

                            @default
                                {{-- NOT_CONNECTED. One button, and it does the whole thing: the
                                     credential is minted, carried and installed server side, so
                                     there is nothing for the merchant to copy and nobody for them
                                     to ask. --}}
                                <p class="text-muted small mb-2">
                                    {{ __("Connect your store so your team can see a customer's orders and deliveries while they are talking to them.") }}
                                </p>
                                <form method="POST" action="{{ route('lynomia.commerce.connect') }}">
                                    @csrf
                                    <button type="submit" class="btn btn-primary btn-sm" @disabled(!$commerceConfigured)>
                                        {{ __('Complete store data setup') }}
                                    </button>
                                </form>
                        @endswitch
                    </div>

                    {{-- WHO FROM THIS STORE HAS A SEAT, AND WITH WHAT RIGHTS.
                         Everyone used to arrive in Lynomia as one shared administrator, so nothing
                         anyone did there was attributable and a warehouse packer had the run of the
                         workspace. People now get their own identity with their own role, and the
                         merchant can see exactly that rather than being asked to trust it. --}}
                    @if ($identities->isNotEmpty())
                        <div class="mt-4">
                            <h6>{{ __('Who has access') }}</h6>
                            <p class="text-muted small">{{ __('Each person opens Lynomia Chat as themselves. Staff can handle conversations; only the store owner can change the workspace settings.') }}</p>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>{{ __('Person') }}</th>
                                            <th>{{ __('Role in Lynomia Chat') }}</th>
                                            <th>{{ __('Last opened') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($identities as $identity)
                                            <tr>
                                                <td>{{ $identity->person_name }}</td>
                                                <td>
                                                    @if ($identity->isAdministrator())
                                                        <span class="badge bg-primary">{{ __('Owner') }}</span>
                                                    @else
                                                        <span class="badge bg-secondary">{{ __('Staff') }}</span>
                                                    @endif
                                                </td>
                                                <td class="text-muted small">
                                                    {{ $identity->last_sso_at ? $identity->last_sso_at->diffForHumans() : __('Not yet') }}
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif

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

        {{-- ------------------------------------------------- how we reach you (B4) --}}
        {{--
            WHY A MERCHANT IS ASKED TO CONFIRM A NUMBER AT ALL, said on the page rather than
            assumed: the things the platform sends here are about THIS integration breaking,
            and a number nobody has confirmed is a number that belongs to whoever actually
            holds it. Until Super Admin store creation was fixed it wrote 7878787878 for every
            merchant it made, so most merchants on this installation are in exactly that state.

            The number itself is edited where it always was, on the profile page. This panel
            proves it. Changing it there clears the proof, which is the point.
        --}}
        <div class="card mt-3">
            <div class="card-body">
                <h6 class="mb-2">{{ __('How we reach you') }}</h6>

                @if ($contact->reachable())
                    <p class="mb-0">
                        <span class="badge bg-success">{{ __('Confirmed') }}</span>
                        {{ __('We can tell you if this integration stops working.') }}
                    </p>
                @else
                    <p class="text-muted small">
                        {{ __('We tell you here when your integration breaks, needs reconnecting, or something about your account needs you. We will only message a number you have confirmed is yours.') }}
                    </p>

                    <p class="mb-2">
                        <span class="badge bg-warning">{{ __('Not confirmed') }}</span>
                        <span class="small">{{ $contact->explanation() }}</span>
                    </p>

                    <form method="POST" action="{{ route('lynomia.contact.verify.send') }}" class="row g-2 align-items-end mb-2">
                        @csrf
                        <div class="col-12 col-md-5">
                            <label class="form-label small mb-1" for="lync-contact-mobile">{{ __('Mobile number') }}</label>
                            <input type="text" class="form-control form-control-sm" id="lync-contact-mobile"
                                   name="mobile" required maxlength="20"
                                   value="{{ old('mobile', $contactChallenge->candidate_phone ?? auth()->user()->mobile) }}">
                        </div>
                        <div class="col-auto">
                            <button class="btn btn-sm btn-primary">{{ __('Send me a code') }}</button>
                        </div>
                    </form>

                    @if ($contactChallenge && $contactChallenge->open())
                        <form method="POST" action="{{ route('lynomia.contact.verify.confirm') }}" class="row g-2 align-items-end">
                            @csrf
                            <div class="col-12 col-md-3">
                                <label class="form-label small mb-1" for="lync-contact-code">{{ __('The code we sent') }}</label>
                                <input type="text" class="form-control form-control-sm" id="lync-contact-code"
                                       name="code" required maxlength="12" autocomplete="one-time-code">
                            </div>
                            <div class="col-auto">
                                <button class="btn btn-sm btn-outline-primary">{{ __('Confirm') }}</button>
                            </div>
                        </form>
                    @endif
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
