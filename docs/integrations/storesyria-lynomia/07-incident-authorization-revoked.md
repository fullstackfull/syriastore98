# P0 — Store Syria Integration authorization revoked on account 135

Root cause, recovery, and prevention. Every claim names the file it rests on.

| | |
|---|---|
| Store Syria | `fullstackfull/syriastore98` @ `343feeae` |
| Lynomia Chat | `fullstackfull/lynomiachat` @ `28de9eeb` |
| Affected store | Store Syria store `26` → Lynomia account `135` (`al atalssian`) |
| Symptom | `PlatformApiClient::showAccount(135)` → **HTTP 401**; merchant page still reads connected |

## 1. What actually happened

The merchant opened Lynomia Chat → Settings → Integrations → **Connected platforms**, saw
"Store Syria Integration" listed with a **Revoke** button, and pressed it. That deleted the one
row that authorises the whole integration.

The chain, end to end:

| # | Where | What |
|---|---|---|
| 1 | `lynomiachat/app/javascript/dashboard/routes/dashboard/settings/integrations/AccountLink.vue` | renders a grant row with a Revoke button **for every** PlatformApp holding a grant — no notion of a connection the platform manages |
| 2 | `lynomiachat/app/javascript/dashboard/api/accountGrants.js` → `revoke()` | `DELETE /api/v1/accounts/135/integrations/grants/4` |
| 3 | `lynomiachat/config/routes/integrations.rb:31` | `resources :grants, only: [:index, :destroy]` |
| 4 | `lynomiachat/custom/app/controllers/api/v1/accounts/integrations/grants_controller.rb` `#destroy` | the **only** gate is `before_action :check_admin_authorization?` — any account administrator |
| 5 | `lynomiachat/custom/app/services/integrations/account_grants.rb` `.revoke` | `platform_app.platform_app_permissibles.where(permissible: account).destroy_all` |
| 6 | `lynomiachat/app/controllers/platform_controller.rb:32-36` | `validate_platform_app_permissible` now finds nothing → `401 {"error":"Non permissible resource"}` |
| 7 | `syriastore98/packages/workdo/LynomiaChat/src/app/Services/PlatformApiClient.php` | **every** platform call for account 135 is now 401: `showAccount`, `issueSsoUrl`, `issueScopedAccessToken`, `integrationHealth`, `installCommerce`, `installationLifecycle` |

Nothing is corrupt. `accounts`, `users`, `account_users`, `inboxes`, `conversations`,
`commerce_stores` and Store Syria's `lynomia_store_links` row are all intact. **One
`platform_app_permissibles` row is missing**, and it is load-bearing for everything.

## 2. Why the merchant still sees "connected" — three separate defects

### 2.1 Store Syria cannot tell the two 401s apart

`platform_controller.rb` renders both of its refusals as a bare 401 with **no `error_code`**:

```ruby
render json: { error: 'Invalid access_token' }, status: :unauthorized          # line 24
render json: { error: 'Non permissible resource' }, status: :unauthorized      # line 35
```

`LynomiaHttp::errorCode` reads only `error_code`, so both arrive as `null`, and
`LynomiaApiException::fromStatus` collapses them into one kind whose own message admits the
ambiguity: *"Lynomia rejected the platform credential, **or** this app is not permitted on that
account."* `KIND_NOT_PERMISSIBLE` is declared in `LynomiaApiException.php` and **never
constructed** — the distinction was anticipated and had no wire signal to key on.

Consequence: a revoked grant is indistinguishable from a bad platform token, so it cannot be
surfaced as the different, merchant-recoverable thing it is.

### 2.2 Nothing records the loss, so the page keeps claiming health

The merchant page renders from `lynomia_store_links`. That row still says
`state = active`, `lynomia_account_id = 135`, `lynomia_commerce_store_id` set, and
`commerce_last_error = null` — all true as of the last successful call. `LynomiaStoreLink::allowsSso()`
returns true and `CommerceState::of()` returns `CONNECTED`. There is no column for "the far side
no longer lets us in", so the one fact that changed is the one fact nothing stores.

### 2.3 There is no recovery path — the existing one refuses this exact case

`syriastore98/.../app/Services/AccountLinker.php::assertClaimable` (line 241):

```php
if ($existing->link_mode === LynomiaStoreLink::MODE_PROVISION) {
    throw new LynomiaApiException(KIND_CONFLICT,
        $existing->state === LinkState::ACTIVE
            ? 'This store already has a Lynomia workspace.' : ...);
}
```

Store 26's link is `link_mode = provision`, `state = active`. So the one mechanism that *can*
restore a permissible — a merchant-minted connection code redeemed through
`POST /platform/api/v1/account_links` — is **structurally refused** for it. The refusal is
correct for its own purpose (an unfinished provision must not be converted into a claim on a
different account; see its comment) and simply has no case for *re-authorising the account this
link already holds*.

**That is the P0: the merchant can revoke, and nothing can restore.**

## 3. The authorization model, as it actually is

`PlatformApp` (`lynomiachat/app/models/platform_app.rb`) `has_many :platform_app_permissibles`.
`PlatformAppPermissible` is a polymorphic join (`platform_app_id`, `permissible_type`,
`permissible_id`) with `unique_permissibles_index`. There is **no** role, scope or expiry on it:
the row's existence *is* the authorization, for Accounts and for Users alike.

Four places create an Account permissible, and the difference between them is the whole of §5:

| Path | File | Meaning |
|---|---|---|
| App provisions a workspace | `platform/api/v1/accounts_controller.rb:35` `find_or_create_by` | the app **created** this account — it manages it |
| Merchant grants by code | `platform/api/v1/account_links_controller.rb:127` `find_or_create_by!` | the merchant **invited** the app into a workspace they already had |
| App creates a user in it | `platform/api/v1/users_controller.rb:19` | incidental |
| Super Admin | `super_admin/platform_apps_controller.rb` | operator |

Two places destroy one: the app handing its own grant back
(`account_links_controller.rb#destroy`, correct and must stay) and the merchant
(`grants_controller.rb#destroy`, the incident).

**Account 135 was provisioned by Platform App 4**, not invited — `AccountProvisioner` stamps
every workspace it creates with `custom_attributes.store_syria_store_id` /
`store_syria_installation` (`AccountProvisioner.php::marker`), and 135 carries store 26's marker.
The merchant revoked a connection they did not establish and whose workspace exists only because
the integration made it.

## 4. The supported, secure recovery

**Redeem a merchant-minted connection code against the account the link already holds.**

Nothing else qualifies. It is the only path that proves merchant consent (`AccountLinkCode` is
minted by an administrator signed in to account 135 and stores only an HMAC), restores the exact
row that is missing, preserves the account and all its data, creates no workspace, changes no id,
rotates no credential, and bypasses no authorization. `account_links#create`'s `grant_one` is
already idempotent (`find_or_create_by!` + `RecordNotUnique` rescue), so a repeated recovery is a
no-op rather than an error.

What has to be built is the **Store Syria half**: a *re-authorisation* that is not a claim, and
whose safety property is a single comparison —

> the redeemed `account_id` **must equal** the link's existing `lynomia_account_id`, or the
> attempt is refused and nothing is written.

That one check is what makes this incapable of cross-tenant takeover: a code for any other
workspace is rejected precisely *because* the link already names 135. The existing claim path has
to guess which account a code is for; recovery does not — it already knows, and uses the code
only as proof.

## 5. Prevention — which connections may be self-service revoked

A grant the merchant **invited** is theirs to withdraw; that is the whole reason
`grants_controller` exists and it should keep working. A connection the platform **provisioned
and manages** is different: revoking it does not return the merchant to a previous state, it
strands a workspace that only exists because of the integration and breaks the merchant's store
dashboard with no path back.

The discriminator already exists in behaviour and needs recording: **did this PlatformApp create
this Account?** Hence `accounts.provisioned_by_platform_app_id`, written at
`accounts_controller#create`, and a refusal in the merchant-facing revoke when it names the app
being revoked. Independently-managed workspaces have it NULL and are unaffected.

Both legitimate disconnects survive: the source platform's own
`DELETE /platform/api/v1/account_links/:id`, and an authorized operator.

## 6. Commerce

`commerce_stores` has a global `UNIQUE (provider, external_store_id)`. The legacy manually
installed record under **account 1** holds Store Syria's external id for store 26, so
`Commerce::PartnerInstallation` answers `409 installation_claimed_elsewhere` for account 135 —
correctly. `StoreConnection#claim` releases a row from another account **only when it is
`disconnected`**, which is the protection working, not a bug.

This must not be auto-adopted. The legacy record is retired by an audited operator action on
account 1 (disconnect it there), after which the ordinary merchant reconnect installs it cleanly
under 135. See §4 of `08-recovery-procedure.md`.

## 7. Longer-term items — found here, deliberately not fixed here

Each of these is real, is outside restoring one merchant's authorization, and would change
behaviour partners may depend on. They are recorded so that fixing them is a decision rather than
a surprise.

### 7.1 The Platform API tells an unauthorized caller whether an account id exists

`lynomiachat/app/controllers/platform/api/v1/accounts_controller.rb#set_resource` is
`Account.find(params[:id])`, which raises before `validate_platform_app_permissible` runs. So a
nonexistent id answers **404** while an id that exists and is not this app's answers **401**. Ids
are sequential, so the difference enumerates accounts.

Narrow in practice — it discloses existence only, to a caller holding a valid platform token, and
nothing about the account — but it is the kind of difference that should not exist. Collapsing the
404 into the 401 is a wire-contract change on an endpoint partners already branch on, which is why
it is not done inside this P0. It is pinned as it actually behaves in
`spec/requests/platform/api/v1/authorization_signals_spec.rb` ("answers a missing account 404,
unlike the 401 a forbidden one gets"), so a later fix is a deliberate edit to that example.

Note that the Commerce and health endpoints added for this integration already use `find_by`, so
they do not have this difference; it is confined to the base accounts controller.

### 7.2 A revoked grant leaves no record on the Lynomia side either

`Integrations::AccountGrants.revoke` audits `integrations.account_link.revoked`, which is how we
know what happened — but nothing on the account itself says "a platform app used to manage this
and the grant was withdrawn". An operator looking at account 135 in Super Admin sees a workspace
with a Store Syria marker in `custom_attributes` and no connection, and has to read the audit log
to learn why. A `revoked_at` on the account, or surfacing the audit row on the Super Admin account
page, would make the state legible without a console.

### 7.3 Nothing notices a managed workspace with no grant

`provisioned_by_platform_app_id` now records which app manages a workspace, so "accounts this app
provisioned on which it currently holds no permissible" becomes an answerable query — and that set
is exactly the population of this incident. Nothing asks it. A periodic check that reports it to
operators would have caught store 26 before the merchant did, rather than after.

### 7.4 Platform-managed revocation is refused, not explained in place

The merchant's page now says the connection is managed and points them at Store Syria, which is
correct but passive: they still have to go and find the equivalent control there. A proper
disconnect initiated from the merchant's Lynomia page — one that asks Store Syria to end both
sides together — is the product-complete version of §5 and needs an endpoint on the Store Syria
side that does not exist yet.
