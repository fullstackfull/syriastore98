# Threat model — automatic Commerce activation

Scope: the path from a merchant clicking **Connect** in Store Syria to a `commerce_stores` row
existing in Lynomia Chat, and every read that row then authorises. Each row names the control
and the file it is in, so the claim can be checked rather than believed.

## Trust boundaries

```
 ① merchant browser → Store Syria          session + CSRF + store ownership. Hostile input.
 ② Store Syria → Lynomia platform API      platform token over TLS. Mutually named parties.
 ③ Lynomia → Store Syria commerce API      lsc_ credential. Lynomia is a client here.
 ④ Store Syria outbox → Lynomia webhook    per-store HMAC + timestamp + delivery id.
```

The browser is outside every boundary. It holds no credential, declares no store id, declares
no account id, and never reaches boundary ② or ③ at all.

## Threats

| # | Threat | What stops it | Where |
|---|---|---|---|
| 1 | **Forged store id** — merchant posts another tenant's `store_id` | no route accepts one. The store comes from `getCurrentStore()` and is then authorised against `Store.created_by` **before** any link lookup and whether or not a link exists | `Http/Middleware/EnsureStoreOwnsLink.php` |
| 2 | **Forged Lynomia account id** — merchant names an account to install into | the account id is read from `lynomia_store_links.lynomia_account_id` for the authorised store; there is no field for it in the request | `Services/CommerceActivator.php`, routes carry no `{link}` |
| 3 | **Platform App scope bypass** — install into an account this app was never granted | `validate_platform_app_permissible`, restated in the controller, and the action is named `update` so the base class's `only:` list actually covers it | `commerce_installations_controller.rb` |
| 4 | **Cross-tenant installation** — Store A's credential installs Store B | Lynomia presents the credential back to Store Syria and compares the identity it answers with against the declared `external_store_id`; mismatch is `AUTH_INVALID`. The credential's HMAC covers store **and** version, so it cannot be edited to name another store | `providers/storesyria.rb#health`, `Services/CommerceCredential.php` |
| 5 | **Store theft** — install over an installation another account holds | global UNIQUE `(provider, external_store_id)`; `StoreConnection#claim` refuses any row that is not `disconnected`; the service refuses before that with `installation_claimed_elsewhere` | `models/commerce/store.rb`, `store_connection.rb` |
| 6 | **Cross-installation store-id collision** — two Store Syria deployments both have store 26 | the external id is installation-qualified (`{installation_ref}:{store_id}`) and, once sent, **read back from the column** rather than recomposed, so a domain or env change cannot move it | `Support/StoreIdentity.php`, migration `2026_10_26_000100` |
| 7 | **Replay of the installation request** | the operation is idempotent by construction (`PUT`, keyed on `(provider, external_store_id)`); a replay with the current credential is `applied: false`, and one with a superseded credential fails the version check inside the HMAC | `partner_installation.rb`, `CommerceCredential::resolveStoreId` |
| 8 | **Concurrent requests → duplicate rows** | three independent layers: the per-link conditional-UPDATE claim on this side, `Commerce::StoreLock` (Redis, keyed by provider + external id, holds across accounts and processes) on that side, and the unique index underneath both | `CommerceActivator`, `store_lock.rb`, unique index |
| 9 | **Token leakage to the merchant** | the credential is minted inside the activator, passed to one HTTP call, and never flashed, rendered, logged, put in a URL, or returned. The merchant endpoints return a redirect with a translated sentence. `config/initializers/filter_parameter_logging.rb` covers `commerce_token`; the Store Syria side logs no request bodies | `CommerceActivator`, `LynomiaHttp` |
| 10 | **Token in an error message** | `LynomiaApiException` carries a typed `kind`; the merchant sees a sentence chosen from `merchantMessage()`, never a provider body. `Commerce::Error#as_json` is `{code, reason}` with no free text | `ConnectionController::merchantMessage`, `commerce/error.rb` |
| 11 | **Token rotation race** — a rotation lands while an install is in flight | the activator reuses the active credential rather than rotating, so the ordinary path creates no race. An operator rotation mid-flight makes the in-flight token fail its version check and the install fails closed; nothing is left half-written because the bump and the derivation are one transaction | `CommerceCredential::mint`, `AuthenticateCommerceApi` |
| 12 | **Credential generation failure leaves an inconsistent state** | the master secret is asserted first and the bump + derivation share a transaction, so a failure rolls the version back and the previous credential keeps working | `CommerceCredential::mint` |
| 13 | **Stolen merchant session** | the attacker inherits exactly the merchant's rights: they can connect the merchant's own store to the merchant's own workspace. No credential is disclosed to the session, so nothing is exfiltrated and nothing is escalated |  — by design |
| 14 | **CSRF** | the routes are `POST`/`DELETE` inside the `web` middleware group, so `VerifyCsrfToken` applies; forms carry `@csrf`. No route is added outside the group and none is excluded | `src/routes/web.php` |
| 15 | **Unauthorised staff member activating** | the activator is reached only behind the module's existing permission middleware, and the identity the workspace is provisioned for is the **tenant owner** (`creatorId()`), never the clicking staff member | route group, `AccountProvisioner::tenantOwner` |
| 16 | **Privilege escalation via a merchant role** | nothing in the new path writes a Lynomia role, creates a Lynomia user, or touches `lynomia_store_identities`. Commerce installation and seat admission are separate operations | `partner_installation.rb` touches only `commerce_stores` |
| 17 | **SSRF via merchant-supplied base URL** | the merchant supplies no URL: `base_url` is `config('app.url')` on this side, and on the other side it still goes through `Commerce::StoreUrl.parse` (https, default port, no IP literal, no userinfo/query/fragment) and every request through `Commerce::HttpClient` (SsrfFilter, pinned-IP connect, no redirects, body cap, timeouts) | `CommerceActivator`, `store_url.rb`, `http_client.rb` |
| 18 | **Webhook forgery / replay** | unchanged and out of this path: per-store HMAC derived from the master secret, signed timestamp, delivery-id uniqueness, per-IP unverified ceiling. No webhook can create or activate an installation — the only writer is the platform endpoint above | `GuardEventIngress`, `EventSignature` |
| 19 | **Compromised integration endpoint** — the platform token leaks | blast radius is this app's permissibles; the token still cannot install a store it cannot prove ownership of (threat 4), cannot read a merchant's commerce data (that needs the `lsc_` credential, which lives only in Store Syria's process memory), and cannot grant itself the `lynomia_commerce` feature | permissible scope, check 8 |
| 20 | **Access after suspension or revocation** | enforced on both sides independently, so it holds while either is down: `AuthenticateCommerceApi` closes the data source on this side; `Commerce::InstallationLifecycle` drives `commerce_stores.status` on that side; the credential version is a third, unilateral stop | three controls, no shared dependency |
| 20b | **A merchant disconnect that Lynomia never hears about** | the revocation is done first and locally, so every issued token stops verifying before the push is attempted; the push failing changes nothing about that, and the merchant's page reads `disconnected` from the revoked credential rather than from the unanswered push | `CommerceActivator::disconnect`, `CommerceState` |
| 20c | **A revoked credential still showing as connected** | `CommerceState` reads "installation recorded, credential not active" as `disconnected`, ahead of the far side's own status — so an operator's Super Admin revocation cannot leave a green tick over a dead connection | `CommerceState::of` |
| 21 | **Reconciliation used as a back door** | the sweep records and clears beliefs only. It never installs, mints or rotates, and it skips any link that is not `allowsSso()`, so a deliberately revoked store cannot be swept back into service | `ReconcileLynomiaCommerce` |
| 22 | **Enumeration** — probing which account ids exist | a non-existent account and a non-permissible one get the identical `401 non_permissible_resource`, following the precedent `integration_health_controller.rb` sets and explains | `commerce_installations_controller.rb#set_resource` |
| 23 | **Installing into the installation's own workspace** | `platform_owned?` accounts are refused outright, matching the filter `AccountsController#index` already applies for the same reason | check 3 |
| 24 | **Timing oracle on the stored credential** | the already-installed fast path compares with `ActiveSupport::SecurityUtils.secure_compare` | `partner_installation.rb` |

## Negative cases that are tested, not just argued

`tests/Feature/Lynomia/LynomiaCommerceActivationTest.php` and
`spec/requests/platform/api/v1/commerce_installations_spec.rb` assert refusals, not only the happy path:
a foreign store id, a link that is not active, a credential for another store, an installation
held by another account, a disabled provider, an account without the feature, a missing master
secret, two concurrent activations, a replay, and a revoked link attempting to reconnect.

## Residual risk

* **The platform token is a bearer credential in the environment.** Unchanged by this work, and
  the reason `config/lynomia.php` reads it from the process environment on every use rather
  than persisting it. Its blast radius is bounded by the permissible scope and by threat 19.
* **`lynomia_commerce` must be in the merchant's plan** for activation to complete. A refusal is
  surfaced honestly; it is not worked around.
* **`APP_KEY` on the Store Syria installation is published and unrotated**
  (`docs/security/SECRET-ROTATION-CHECKLIST.md`). This is why nothing in this module stores a
  secret behind Laravel's `encrypted` cast, including everything added here.
