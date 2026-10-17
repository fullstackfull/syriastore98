{{--
    THE COMMERCE TOKEN CONTROLS for one store, in the Merchant Accounts table.

    A commerce token lets Lynomia's commerce adapter read this store's orders and customers. It
    is minted by IntegrationController::issueCommerceCredential / revokeCommerceCredential through
    the existing operator-only routes -- this partial adds no new issuing mechanism, it only
    surfaces the ones already there.

    THE TOKEN IS NEVER RENDERED HERE. Nothing in this cell knows the token; it is derived, shown
    once by the reveal banner after an issue, and never stored. This cell shows only STATUS
    (derived from the version and issued-at columns) and the buttons that act on it.

    Extracted into a partial so it can be rendered and asserted headlessly -- the full Super Admin
    page cannot render without the Workdo layout, but this cell can, which is how the
    button-visibility rules are tested.

    @var \Workdo\LynomiaChat\app\Models\LynomiaStoreLink $row
--}}
@php
    $link = $row;
    $status = $link->commerceCredentialStatus();
    $canIssue = $link->canIssueCommerceCredential();
    $statuses = \Workdo\LynomiaChat\app\Models\LynomiaStoreLink::class;
@endphp
<div class="lyn-commerce-credential">
    @if ($status === $statuses::COMMERCE_CREDENTIAL_ACTIVE)
        <span class="badge bg-success" data-credential-status="active">{{ __('Active') }}</span>
        @if ($link->commerce_credential_issued_at)
            <div class="text-muted small mt-1">
                {{ __('Issued :when', ['when' => $link->commerce_credential_issued_at->diffForHumans()]) }}
            </div>
        @endif
    @elseif ($status === $statuses::COMMERCE_CREDENTIAL_REVOKED)
        <span class="badge bg-danger" data-credential-status="revoked">{{ __('Revoked') }}</span>
    @else
        <span class="badge bg-secondary" data-credential-status="not_issued">{{ __('Not issued') }}</span>
    @endif

    @if ($canIssue)
        <div class="mt-2 d-flex flex-column gap-1">
            @if ($status === $statuses::COMMERCE_CREDENTIAL_ACTIVE)
                {{-- A live key: rotate (issue a replacement, invalidating this one) or revoke. Both
                     confirm first, because both stop the current token the instant they run. --}}
                <form method="POST" action="{{ route('lynomia.admin.commerce.issue', $link->id) }}"
                      onsubmit="return confirm({{ Js::from(__('Rotating issues a new token and immediately stops the current one. Any Lynomia store using the old token will disconnect until you paste the new one. Continue?')) }})">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-primary lyn-credential-rotate">{{ __('Rotate Token') }}</button>
                </form>
                <form method="POST" action="{{ route('lynomia.admin.commerce.revoke', $link->id) }}"
                      onsubmit="return confirm({{ Js::from(__('Revoking stops the current token immediately. The connected Lynomia store will stop syncing until you issue and paste a new one. Continue?')) }})">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-sm btn-outline-danger lyn-credential-revoke">{{ __('Revoke Token') }}</button>
                </form>
            @else
                {{-- Never issued, or revoked: offer a fresh issue. Issuing the first time needs no
                     warning -- there is nothing to invalidate yet. --}}
                <form method="POST" action="{{ route('lynomia.admin.commerce.issue', $link->id) }}">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-primary lyn-credential-issue">{{ __('Issue Commerce Token') }}</button>
                </form>
            @endif
        </div>
    @else
        <div class="text-muted small mt-2 lyn-credential-blocked">
            {{ __('Connect the workspace before issuing a commerce token.') }}
        </div>
    @endif
</div>
