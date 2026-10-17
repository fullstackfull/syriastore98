# Automatic Commerce activation — architecture

Companion to `00-current-state.md` (what existed) and `02-threat-model.md` (why it is safe).
This document is the decision record: what is added, where, and what was rejected.

## 1. The one thing being added

A **server-to-server Commerce installation operation** on Lynomia Chat, and a Store Syria
orchestrator that calls it on the merchant's behalf.

```
merchant browser ──POST /lynomia/commerce/connect──► Store Syria
  (session cookie, CSRF, no identifiers in the body)      │
                                                          │  mints/reuses lsc_… server-side
                                                          │  (never leaves the backend)
                                                          ▼
                     PUT /platform/api/v1/accounts/{id}/commerce_installation
                     header: api_access_token  (Platform App #4)
                                                          │
                                                          ▼
                                                   Lynomia Chat
                                                          │  Commerce::PartnerInstallation
                                                          │    → adapter health  ──GET /api/lynomia/commerce/v1/store──┐
                                                          │    → Commerce::StoreConnection#attach                      │
                                                          ▼                                                           │
                                               commerce_stores row  ◄───────────────────────────────────────────────────┘
                                                                        (Store Syria answers with its own identity;
                                                                         the credential is what names the store)
```

The merchant's browser never sees the credential, never sends a store id, never sends an
account id, and never talks to Lynomia Chat directly.

## 2. Why orchestration lives on the Store Syria side

Rejected: orchestration in Lynomia Chat (Lynomia pulling a credential from Store Syria).

Chosen: orchestration in Store Syria (`Services/CommerceActivator.php`), because

* Store Syria already owns the three facts the decision needs — the merchant's session, the
  store's ownership, and the credential's derivation — and two of them are things Lynomia
  cannot see. Pulling would mean exporting the first two across the boundary.
* Store Syria already orchestrates the other half of the same onboarding
  (`AccountProvisioner`), with the resumption, locking and adopt-before-create machinery this
  needs. A second orchestrator in the other repository would be a second place for the same
  flow to be half-finished.
* Lynomia's platform surface is a **receiving** surface in every existing case
  (`integration_health` answers, `installation_lifecycle` accepts a transition). Making it
  originate an outbound call to a partner for a secret would be a new and much larger trust
  claim than accepting one.

Lynomia keeps everything that is genuinely its own: the account's permissions, the plan
feature, the provider switch, credential encryption, the uniqueness of a store across the
installation, and the final say on whether an installation may exist.

## 3. The Lynomia endpoint

```
PUT /platform/api/v1/accounts/:account_id/commerce_installation
header   api_access_token: <Platform App token>
body     provider           "storesyria"
         external_store_id  "storesyria.com:26"      (installation-qualified; see StoreIdentity)
         base_url           "https://storesyria.com"
         commerce_token     "lsc_26_3_<64 hex>"
→ 200    { provider, external_store_id, commerce_store_id, status, name, applied }
```

`controller: 'commerce_installations'`, action **`update`**. The action name is load-bearing:
`PlatformController` applies `set_resource` and `validate_platform_app_permissible` with
`only: [:update, :show, :destroy]`, so an action named `create` would inherit neither and
accept any valid token for any account id in the URL. Both filters are restated in the
controller as well, exactly as `integration_health` and `installation_lifecycle` do, so
neither a rename nor an edit to the base class can silently remove the check.

`PUT` on a singular resource, not `POST` to a collection, because the request names a desired
end state and repeating it is a no-op. There is no id for the caller to hold.

### What the endpoint checks, in order

| # | Check | Refusal |
|---|---|---|
| 1 | valid Platform App token | `401 Invalid access_token` (base class) |
| 2 | `platform_app_permissibles` covers this account | `401 non_permissible_resource` |
| 3 | account is not the installation's own platform workspace | `422 platform_owned_account` |
| 4 | `provider` is partner-installable (`storesyria` only) | `422 provider_not_installable` |
| 5 | `commerce_token` matches `\Alsc_\d{1,19}_\d{1,19}_[0-9a-f]{64}\z` | `422 invalid_credential` |
| 6 | `base_url` parses (`Commerce::StoreUrl`: https, default port, no IP literal) | `422 invalid_store_url` |
| 7 | provider enabled installation-wide (`STORESYRIA_ENABLED`) | `422 provider_disabled` |
| 8 | account has the `lynomia_commerce` feature | `422 commerce_disabled` |
| 9 | the store is not held by another account | `409 installation_claimed_elsewhere` |
| 10 | the credential resolves, at Store Syria, to **this** `external_store_id` | `422 auth_invalid` |

Check 5 is the same regex the merchant-facing form already validates against
(`StoresController::CREDENTIAL_FORMATS`), reused rather than restated. Check 10 is the
ownership proof and it is the important one: it is not a claim the caller makes, it is an
answer Store Syria gives when Lynomia presents the credential back to it. A credential minted
for store A cannot install store B, because `Commerce::Providers::Storesyria#health` compares
the identity the store answers with against the `external_store_id` on the row and raises
`AUTH_INVALID` on a mismatch.

Check 8 is a refusal rather than a silent success. `lynomia_commerce` is a plan feature
(`config/features.yml`), so an account without it can hold a `commerce_stores` row that every
read path then refuses — a green light over a dead connection. Refusing is what lets Store
Syria say something true to the merchant. **Operational consequence:** for automatic
activation to succeed for every merchant, the plan a provisioned workspace lands on must
include `lynomia_commerce`. That is operator configuration, recorded in
`04-release-checklist.md`; this code does not grant features, because
`Platform::Api::V1::AccountsController#update_resource_features` establishes that a capability
a plan sells is written only through `Billing::OverrideGrant`, with an audited reason.

### Idempotency and the states it has to cope with

`Commerce::PartnerInstallation#apply` runs inside
`Commerce::StoreLock.with(provider, external_store_id)` — a Redis lock keyed by the provider's
own store id, so it holds across accounts and processes, which is what makes two simultaneous
requests produce one connection rather than two.

| Existing `commerce_stores` row | Action | `applied` |
|---|---|---|
| none | health-check the credential, then `StoreConnection#attach` | `true` |
| this account, `active`, same credential | nothing | `false` |
| this account, `active`, different credential | `rotate_credentials` (health-checked first) | `true` |
| this account, `needs_reauth` | `rotate_credentials` — this is the repair | `true` |
| this account, `disconnected` | `attach` reuses the row (`StoreConnection#claim`) | `true` |
| this account, `disabled` | **nothing**; answer `status: disabled` | `false` |
| another account, `disconnected` | `attach` releases it (`claim` destroys the dead row) | `true` |
| another account, anything else | refuse `installation_claimed_elsewhere` | — |

`disabled` is left alone deliberately. It is a state an operator chose, and a partner call
that quietly re-enabled it would be a partner reaching across the boundary to undo a Lynomia
decision. Store Syria renders it as "suspended in Lynomia Chat", which is what it is.

The same-credential comparison uses `ActiveSupport::SecurityUtils.secure_compare`, so the
"is this already installed?" fast path cannot be turned into an oracle on a stored secret.

## 4. The Store Syria side

### 4.1 Credential issuance, repaired

`CommerceCredential::mint(LynomiaStoreLink): string` is new and is now the only writer of the
version:

```php
self::assertConfigured();                 // 1. the secret must exist BEFORE anything is written
DB::transaction(function () { … });       // 2. bump + derive together; a throw rolls the bump back
```

The derivation happens inside the transaction, so a failure anywhere — missing secret, bad
config, database error — leaves the version where it was, and the previously working
credential keeps working. `SuperAdmin/IntegrationController::issueCommerceCredential` now
calls `mint()` instead of writing the columns itself, so the operator path and the automatic
path cannot diverge. Revocation is unchanged: it derives nothing and so could never fail this
way.

### 4.2 `CommerceActivator`

One service, `activate(LynomiaStoreLink, User): CommerceOutcome`, which:

1. refuses unless `$link->allowsSso()` — the same gate that guards SSO and the commerce API,
   so a pending, suspended, revoked or unproven link cannot install Commerce;
2. takes a per-link claim with the same conditional-UPDATE idiom `AccountProvisioner` uses
   (`commerce_activation_started_at`), so a double click is one call;
3. **reuses the current credential when one is active** and only mints a new one when there
   is none. This is §9.3 of the brief: rotating on every attempt would break a working
   installation in order to repair one that was not broken. The credential is derived, not
   stored, so "reuse" is a re-derivation from the current version — nothing is read back out
   of a database;
4. calls `PlatformApiClient::installCommerce`;
5. records the result: `lynomia_commerce_store_id` (Lynomia's own id for the row — the same
   column `CampaignApiController::ownStoreId` writes when it observes it), `commerce_installed_at`,
   `commerce_verified_at`, and on failure `commerce_last_error` + `commerce_attempts`;
6. is called **after** a successful provision (so one merchant click yields both halves) and
   from the merchant's own retry, and in both cases a failure leaves the workspace intact.

`verify(LynomiaStoreLink, User)` is the non-mutating half: it replays the same `PUT`, which is
idempotent, and records `commerce_verified_at`. For an already-connected store this is the
safe no-op the brief asks for in scenario C — `applied: false`, no rotation, no second row.

`disconnect(LynomiaStoreLink, User)` is the merchant ending the store-data half. **The local
half goes first and it is the one that enforces:** revoking the credential changes the HMAC
input, and the commerce middleware re-reads the version on every request, so every token
already issued stops verifying at once — without a deploy, without a cache expiry and without
Lynomia's cooperation. Telling Lynomia (`disconnect` through the existing
`installation_lifecycle` endpoint) is the tidy-up, so a failed push is **not** reported as a
failed disconnect: nothing is readable either way, and Lynomia's own next provider call moves
the store to `needs_reauth` by itself. The workspace, the conversations and the team are
untouched; reconnecting is one button and mints a new credential.

### 4.3 Commerce state is derived, not stored

No new state machine. `Support/CommerceState.php` reads the columns that already exist plus
the four added here and answers one of `not_connected`, `connecting`, `connected`,
`suspended`, `failed`, `unavailable`. A stored enum would be a seventh thing that can
disagree with the other six; the derivation cannot.

```
workspace not allowsSso()                       → unavailable
installation recorded, credential not active    → disconnected      (see below)
upstream answered `disabled`                    → suspended
upstream answered `needs_reauth`                → needs_repair
upstream answered `disconnected`                → disconnected
commerce_last_error set                         → failed
lynomia_commerce_store_id set                   → connected
activation claim held and not stale             → connecting
otherwise                                       → not_connected
```

The second branch is load-bearing and its position is deliberate: **an installation that cannot
authenticate is not a connection**, whatever Lynomia's row still says. It is what an operator
revoking in Super Admin leaves behind, and without it the merchant would be shown a green
"Connected" over a credential that verifies nothing. Reading it as `disconnected` is both true
and actionable — reconnecting mints a new credential and re-installs.

### 4.4 Migration

One additive migration, four nullable columns and one unsigned integer default 0, no index,
no backfill, no data movement:

`commerce_installed_at`, `commerce_verified_at`, `commerce_last_error`,
`commerce_activation_started_at`, `commerce_attempts`.

Every existing row reads as `not_connected` until something observes otherwise, which is the
honest default and is also why the rollback is a column drop with nothing to undo.

## 5. Lifecycle, and what enforces revocation

Workspace and Commerce stay distinct, and Commerce follows the workspace downwards but never
upwards:

| Workspace | Commerce | Merchant sees |
|---|---|---|
| not connected | — | Connect Lynomia Chat |
| provisioning / pending | — | Setting up your workspace |
| active | not connected | Complete Commerce setup |
| active | connecting | Connecting your store data |
| active | connected | Connected |
| active | failed | Retry (with the reason category) |
| active | suspended | Unavailable — contact support |
| active | disconnected (merchant stopped sharing, or credential revoked) | Connect store data |
| suspended / revoked / decommissioned | anything | Connection unavailable / reconnect required |

Revocation is enforced **independently on both sides**, which is what makes it hold while
either system is unavailable:

* Store Syria: `AuthenticateCommerceApi` refuses every commerce read for a link that is not
  `allowsSso()`. A stale `active` row in Lynomia's `commerce_stores` therefore reads nothing —
  the data source itself is closed.
* Store Syria, unilaterally and immediately: revoking the credential version. This is what a
  merchant's **Stop sharing store data** does first, before telling Lynomia anything, which is
  how a disconnect holds while Lynomia is unreachable.
* Lynomia: `InstallationLifecycle::push` already drives `commerce_stores.status` to `disabled`
  (suspend) or `disconnected` (decommission) through the existing platform endpoint, so the
  send boundary (`Partners::Eligibility`) stops authorising it.
* The credential version is a third, independent stop: bumping it invalidates every token
  already issued, with no deploy and no cooperation from the other side.

## 6. Recovery and reconciliation

Bounded and observable, with no new queue:

* **Retry** is the merchant's button and the same idempotent `PUT`. `commerce_attempts` counts;
  `commerce_last_error` says why.
* **Reconciliation** is `php artisan lynomia:reconcile-commerce`, modelled on the five sweeps
  already in `Console/Commands/`. For each active link it compares what this side believes
  against Lynomia's `integration_health` installations and repairs the belief — it records an
  installation it had not seen, clears a stale error, and marks one that has vanished
  upstream. It **does not** install, mint, or rotate: a sweep that could create an
  installation is a sweep that can create one for a store somebody deliberately disconnected.
* **Authoritative source**: for *whether an installation exists*, Lynomia (it owns the row).
  For *whether it is permitted*, Store Syria (it owns the link state and the credential).
  Reconciliation only ever moves facts in the direction of the system that owns them.
* **Never auto-reconnect a revoked store**: the sweep skips any link that is not
  `allowsSso()`, and the activator refuses one.

## 7. Super Admin keeps everything it had

The Commerce Credential panel — issue, rotate, revoke, status, last issuance, one-time
reveal, copy — is unchanged except that issuance now goes through `mint()`. It remains the
emergency path, and it is now *consistent* with the automatic path rather than parallel to it:
both bump the same version, both derive the same token, and a rotation by an operator is
visible to the merchant page as a Commerce state that needs verifying rather than as a silent
breakage.

## 8. What is deliberately not built

* **No new Platform App.** Platform App #4 already holds the permissible on every account this
  integration provisions, which is exactly the scope the endpoint needs.
* **No new trust mechanism, no second secret.** The endpoint authenticates with the existing
  platform token and proves store ownership with the existing commerce credential, verified by
  a callback to the existing commerce identity endpoint. There was no gap that a new shared
  secret would have closed.
* **No order writes.** `Commerce::Providers::Storesyria.supports_actions?` stays `false`.
* **No merchant-facing token display anywhere**, including in an error.
* **No change to production secrets, `.env` files, or `APP_KEY`.**

## 9. Workspace event subscription (the production warning), addressed

`EventSubscriptionManager::ensureSubscribed` logs and continues on failure, by design:
Lynomia exposes webhooks only on the agent API behind `WebhookPolicy`, so the call is made
with the tenant owner's scoped credential and a failure degrades the inbox from push to pull.
`ReconcileLynomiaEvents` sweeps behind it.

It is **not** on the path for Commerce activation, SSO or commerce data, and the new endpoint
adds no dependency on it: Store Syria pushes commerce events from its own outbox to
`POST /webhooks/storesyria`, which registers nothing in Lynomia
(`Commerce::Providers::Storesyria.registers_webhooks? == false`). So the warning is correctly
non-fatal and correctly out of scope here — but it is a persistent error with a real
consequence (inbox freshness), and it is recorded in `05-open-risks.md` rather than closed.
