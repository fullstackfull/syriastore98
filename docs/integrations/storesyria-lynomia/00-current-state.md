# Store Syria × Lynomia Chat — current state

Evidence-based reading of both repositories at the commits below. Every claim names the file
and the method it rests on. Nothing here is inferred from a report; where a fact could only be
established against production, it is marked as such and left unasserted.

| | |
|---|---|
| Store Syria | `fullstackfull/syriastore98` @ `3ff9b7d7` (`main`, and the production merge named in the brief) |
| Lynomia Chat | `fullstackfull/lynomiachat` @ `62f21be3` (`lynomia-custom`) |
| Working branch, both | `claude/zen-pascal-9mt448` |

## 1. Where the integration lives

**Store Syria** — one Workdo module, `packages/workdo/LynomiaChat`, 126 tracked files at
`3ff9b7d7`. Everything the integration owns is inside it: 19 controllers, 27 services, 11
models, 20 migrations, 5 console commands, its own middleware, its own config file, its own two
dictionaries. No integration code
sits outside the module except the core `Store`/`User` models it reads and
`App\Http\Middleware\PlatformOperatorOnly`, which guards the Super Admin routes.

**Lynomia Chat** — the overlay directory is `custom/`, not `enterprise/`. Commerce is
`custom/app/services/commerce/**` (85 files), its controllers are under
`custom/app/controllers/api/v1/accounts/commerce/**` and
`custom/app/controllers/api/v1/accounts/conversations/commerce/**`, its platform-facing
endpoints are `custom/app/controllers/platform/api/v1/**`, and routes are drawn from
`config/routes/commerce.rb` plus the `namespace :platform` block in `config/routes.rb`.
Store Syria is the fifth of five commerce providers
(`custom/app/services/commerce/providers.rb` → `REGISTRY`).

## 2. Capability matrix

Legend: **done** implemented and covered by tests · **untested** implemented, no test ·
**partial** implemented for some paths only · **missing** not implemented ·
**external** blocked on something outside the code.

| Capability | Store Syria | Lynomia Chat | Working? | Missing work |
|---|---|---|---|---|
| Workspace provisioning | `Services/AccountProvisioner.php::provision` — resumable, adopt-before-create, conditional-UPDATE lock, per-step persistence | `Platform::Api::V1::AccountsController#create` + `platform_app_permissibles` | **done** (`tests/Feature/Lynomia/LynomiaConnectionTest.php`) | none |
| Existing-workspace linking | `Services/AccountLinker.php` (551 lines) + `Http/Requests/LinkExistingAccountRequest.php`; code hash only, `ownership_proven_at` set by redemption | `Integrations::AccountLinkCode` + `Platform::Api::V1::AccountLinksController` | **done** | none |
| Platform authentication | `Services/PlatformApiClient.php` + `LynomiaHttp` (`api_access_token` header, env-only token) | `PlatformController#ensure_access_token` / `#set_platform_app` | **done**; brief records a live `HTTP 200` | none |
| Merchant SSO | `Services/SsoLinkIssuer.php`, `IdentityProvisioner` (per-person identity, `lynomia_store_identities`) | `Platform::Api::V1::UsersController#login` / `#scoped_access_token` | **done** | none |
| Commerce credential issuance | `Services/CommerceCredential.php::issue` (derived `lsc_{store}_{version}_{hmac}`, never stored) + `SuperAdmin/IntegrationController::issueCommerceCredential` | `Api::V1::Accounts::Commerce::StoresController::CREDENTIAL_FORMATS['storesyria']` validates the exact shape | **partial — defective** | version is bumped **before** the credential is derived (§4) |
| Commerce installation | nothing calls Lynomia to create a store | `Commerce::StoreConnection#connect` / `#attach`, reachable only from `Api::V1::Accounts::Commerce::StoresController#create` (session + account **administrator** + pasted token) | **missing server-to-server** | the central gap (§3) |
| Automatic Commerce connection | **missing** | **missing** — `Commerce::InstallationLifecycle` says so in its own header: *"WHAT IS DELIBERATELY NOT OFFERED: `activate` from nothing"* | **missing** | the central gap (§3) |
| Tenant isolation | `Http/Middleware/AuthenticateCommerceApi.php` — no store id is accepted from the caller at all; the credential names the store | `commerce_stores` UNIQUE `(provider, external_store_id)`; `Commerce::Providers::Storesyria#health` refuses an identity that does not match the row's `external_store_id`; `Support/StoreIdentity.php` qualifies the id by installation | **done** (`LynomiaCommerceApiTest`, `LynomiaPhase3SecurityTest`) | installation path must bind the declared id the same way |
| Credential rotation | version bump invalidates every earlier token with no deploy (`2026_10_18_000100` migration) | `Commerce::StoreConnection#rotate_credentials` (health-checked before the write) | **partial** | nothing carries a rotation across the boundary; a merchant-side rotation silently breaks a working installation |
| Disconnect | `Services/InstallationLifecycle.php` pushes `suspend` / `resume` / `disconnect` via `PlatformApiClient::installationLifecycle` | `Platform::Api::V1::InstallationLifecycleController#update` → `Commerce::InstallationLifecycle` | **done** for the workspace (`LynomiaInstallationLifecycleTest`) | no merchant-initiated **Commerce** disconnect; operator-only today, so a merchant who wants their order data to stop being shared has to ask support |
| Health checks | `SuperAdmin/IntegrationController::remoteHealth` → `PlatformApiClient::integrationHealth` | `Platform::Api::V1::IntegrationHealthController#show` → `Operations::IntegrationHealth` | **done** | merchant sees none of it; the merchant page has no Commerce row at all |
| Failure recovery | provisioning resumes; `Console/Commands/ReconcileLynomia*.php` (5 sweeps) cover events, deliveries and consents | lifecycle is idempotent; `Commerce::StoreLock` serialises per store | **partial** | nothing reconciles **Commerce installation** state |
| Agent authorization | — | `Commerce::StorePolicy` (administrator-only writes); `Conversations::CapabilityScope` for reads | **done** | none |
| Customer and order lookup | `Http/Controllers/CommerceApiController.php` + `Services/CommerceReader.php`, read-only | `Commerce::Providers::Storesyria` + `Normalizer`; `supports_actions? == false` | **done** (`LynomiaCommerceDataTest`) | none |

## 3. The gap, stated precisely

There is exactly one way a `commerce_stores` row can come into existence for the `storesyria`
provider: `Api::V1::Accounts::Commerce::StoresController#create`. Reaching it requires

1. a browser session on Lynomia Chat as a user who is an **administrator** of the target
   account (`Commerce::StorePolicy#create?`),
2. the account to have the `lynomia_commerce` plan feature (`#ensure_commerce_enabled`),
3. the installation-wide `STORESYRIA_ENABLED` switch (`Commerce::Storesyria::Config`), and
4. the `lsc_…` token **pasted into a form field** — which today only
   `SuperAdmin/IntegrationController::issueCommerceCredential` can mint.

Every one of those four is correct on its own. Together they mean the only route from
"merchant clicked Connect in Store Syria" to "Commerce is active" runs through a Store Syria
platform operator minting a token and a human pasting it into Lynomia Chat. That is the
manual step the brief exists to remove, and it is the whole of the missing feature. The two
platform endpoints that already exist (`integration_health`, `installation_lifecycle`) prove
the shape the missing one should take, including the authorization trap their headers
document (an action not named `show`/`update`/`destroy` inherits **neither**
`set_resource` nor `validate_platform_app_permissible` from `PlatformController`).

Production store #26 / account #135 is the manual path having been walked by hand.

## 4. The credential-issuance defect, confirmed in source

`SuperAdmin/IntegrationController.php:618-637`:

```php
$previous = (int) $model->commerce_credential_version;

$model->forceFill([
    'commerce_credential_version' => $previous + 1,
    'commerce_credential_issued_at' => now(),
])->save();                                    // version has advanced

AuditLog::record(...);

return back()->with('lynomia_commerce_credential', CommerceCredential::issue($model->fresh()));
//                                                 ^ throws RuntimeException when
//                                                   LYNOMIA_WEBHOOK_MASTER_SECRET is unset
```

`CommerceCredential::signature()` raises `RuntimeException('LYNOMIA_WEBHOOK_MASTER_SECRET is not
configured.')` — which is the HTTP 500 recorded in the brief. The version was already committed,
so the store's previously working credential had been revoked by an operation that reported
failure. `revokeCommerceCredential` has the same structure but cannot fail the same way: it
derives nothing.

## 5. Workspace, Commerce and health are three different states

Confirmed, and worth stating because conflating them is what produced the misleading
`status=unknown` reading in the brief:

* **Workspace** — `lynomia_store_links.state` ∈ `LinkState::ALL`, plus
  `lynomia_account_id`/`ownership_proven_at`. Owned by Store Syria.
* **Commerce credential** — derived from `commerce_credential_version` +
  `commerce_credential_issued_at` (`LynomiaStoreLink::commerceCredentialStatus`). Means "a token
  was minted", which is strictly *before* anything can exist upstream.
* **Commerce installation** — `commerce_stores.status` upstream; observed on this side only as
  `lynomia_commerce_store_id`, written opportunistically by
  `CampaignApiController::ownStoreId` the first time the store appears in Lynomia's
  `audience_fields`. `CampaignApiController::scopedStoreId` carries a long comment explaining
  why the credential columns are the wrong proxy for it, in both directions.
* **Health** — `Operations::IntegrationHealth` is a projection of `Commerce::StoreHealth` and
  `Operations::Signal`. With no installation, `installations: []` and
  `Operations::Health.worst([])` is `unknown`: exactly the reading the brief recorded, and
  correct for the state it was in.

Now that store #26 has an installation, `installations` should carry one row. **That is a
production read and is not asserted here** — this environment holds no production credentials
and performs no production calls.

## 6. Workspace event subscription (the production warning)

`Services/EventSubscriptionManager.php::ensureSubscribed` catches `LynomiaApiException` and logs
`[lynomia] could not subscribe to workspace events`, by design: its own header explains that
Lynomia exposes webhooks only on the **agent** API behind `WebhookPolicy` (account
administrator), so the call is made with the tenant owner's scoped credential rather than the
platform token, and failure degrades the inbox from push to pull rather than failing activation.
`Console/Commands/ReconcileLynomiaEvents.php` sweeps behind it.

Consequences for this task: the warning does **not** block Commerce activation, SSO or commerce
data. It affects inbox freshness only. It is auxiliary, it is already reconciled, and it is out
of scope here — but it is not ignored: §9 of `01-architecture.md` records why.

## 7. What exists that must not be rebuilt

Reuse, in order of how much work each one saves:

* `Commerce::StoreConnection#attach` — plan-limit enforcement, account lock, the
  `claim` rules for reusing a disconnected row, encryption assertion, audit row. The Salla
  installation path (`Commerce::Salla::Installation#connect`) already uses it from a
  non-interactive context, which is the precedent to follow.
* `Commerce::StoreLock` — Redis lock keyed by `(provider, external_store_id)`, so it holds
  across accounts **and** processes. This is the concurrency answer for E2E-06.
* `Commerce::Providers::Storesyria#health` — the cross-store rebinding refusal, already
  written and already load-bearing for rotation.
* `CommerceCredential` — derivation, purpose separation, version-as-revocation.
* `PlatformApiClient` + `LynomiaHttp` — transport, retry policy, typed exceptions.
* `EnsureStoreOwnsLink` — store-then-link authorization, no identifier accepted from the
  caller. Every new merchant route uses it unchanged.
* `LynomiaStoreLink::spendRecoveryWindow` — the single-statement conditional UPDATE idiom
  used for "exactly one caller may proceed".
* 37 existing `tests/Feature/Lynomia/*` files, including `InteractsWithLynomia.php`.

## 8. Blocked by this environment, not by the code

* **Ruby 3.4.4** (`.ruby-version`) cannot be installed: `cache.ruby-lang.org` is refused by the
  session's egress proxy (`curl: (22) … 403`). The system Ruby is 3.3.6, which `Gemfile` rejects.
  The Rails suite therefore cannot be executed here. Lynomia-side specs are written and
  syntax-checked; their execution belongs to CI.
* **No PostgreSQL server and no Redis** are running in this container either, which the Rails
  suite also needs.
* The PHP suite does run: PHP 8.3.6, `phpunit.xml` uses `sqlite :memory:`.
* No production credentials are present and no production call is made, so §2.6's health
  reading and the live store #26 state are reported as unverifiable from here rather than
  claimed.
