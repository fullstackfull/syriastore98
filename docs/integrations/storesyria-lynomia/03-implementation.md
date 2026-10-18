# Implementation report

Every file changed or added, and why. Two repositories, one branch name:
`claude/zen-pascal-9mt448`.

## Lynomia Chat (`fullstackfull/lynomiachat`)

| File | | Why |
|---|---|---|
| `custom/app/services/commerce/partner_installation.rb` | **new** | The operation `Commerce::InstallationLifecycle` deliberately refuses to offer (`activate` from nothing), with the checks that refusal was protecting: provider allow-list, provider switch, plan feature, platform-owned account, declared-vs-proved store identity, cross-account claim, plan store limit. A facade over `Commerce::StoreConnection#attach` / `#rotate_credentials`, under `Commerce::StoreLock`. |
| `custom/app/controllers/platform/api/v1/commerce_installations_controller.rb` | **new** | `PUT …/accounts/:account_id/commerce_installation`. Action named `update` and both authorization filters restated, for the reason its two sibling endpoints document. Validates parameter presence and the credential's shape at the boundary; maps refusals to 422/409. |
| `config/routes.rb` | +7 | `resource :commerce_installation, only: [:update]` inside `resources :accounts`, beside `installation_lifecycle`. |
| `custom/app/services/commerce/providers/storesyria.rb` | +11 | `TOKEN_FORMAT` — the credential's shape, declared by the provider that consumes it. Two surfaces now accept this credential and a regex written out twice is one they can drift apart on. |
| `custom/app/controllers/api/v1/accounts/commerce/stores_controller.rb` | ±4 | Its `storesyria` entry now references `TOKEN_FORMAT` instead of repeating the regex. Behaviour unchanged. |
| `spec/requests/platform/api/v1/commerce_installations_spec.rb` | **new** | 28 examples. Authorization, boundary validation, the happy path, the ownership proof, idempotency across all seven row states, tenancy, the plan limit, and the audit row. |
| `docs/commerce/36-partner-commerce-installation.md` | **new** | The contract the other repository is written against, including the refusal table and the idempotency matrix. |

Nothing existing was removed, and no existing behaviour was changed: the only edit to a
pre-existing code path replaces a duplicated regex literal with a reference to the same pattern.

## Store Syria (`fullstackfull/syriastore98`)

### The credential defect

| File | | Why |
|---|---|---|
| `src/app/Services/CommerceCredential.php` | +80 | `mint()` — the one writer of the version on the issuing side. Asserts the master secret **before** anything is written and shares a transaction with the derivation, so a failure cannot leave a bumped version with no derivable token. `isConfigured()` for the pre-flight check. `issue()` is now documented as the read (derive at the current version, no rotation), which is what makes credential reuse possible. |
| `src/app/Http/Controllers/SuperAdmin/IntegrationController.php` | ±30 | `issueCommerceCredential` mints through `mint()` instead of writing the two columns itself, and reports a refusal as a sentence that names no environment variable. The panel, its buttons, the one-time reveal and the audit row are otherwise untouched. |

### Automatic activation

| File | | Why |
|---|---|---|
| `src/app/Services/CommerceActivator.php` | **new** | The orchestrator. Gates on `allowsSso()`, takes a per-link claim with the conditional-UPDATE idiom `AccountProvisioner` uses, **reuses an active credential instead of rotating it**, calls the new platform endpoint, and records the result or a merchant-safe reason. `verify()` is the same idempotent call. `disconnect()` revokes the credential **first** — the unilateral stop that holds while Lynomia is unreachable — then tells Lynomia through the existing lifecycle endpoint, best effort. |
| `src/app/Services/PlatformApiClient.php` | +55 | `installCommerce()`. |
| `src/app/Support/CommerceState.php` | **new** | The merchant-facing state, derived from the columns and stored nowhere. Eight states, branch order documented — including the one that matters most: an installation whose credential is not active reads as `disconnected`, not `connected`, because it cannot authenticate. |
| `src/app/Http/Controllers/CommerceConnectionController.php` | **new** | The merchant's two actions. Accepts no identifiers; flashes no credential. |
| `src/routes/web.php` | +27 | `POST /lynomia/commerce/connect`, `/commerce/verify` and `/commerce/disconnect`, inside the existing `web`+`auth`+`xss`+`verified`+module-permission group, behind `EnsureStoreOwnsLink:existing`. |
| `src/app/Http/Controllers/ConnectionController.php` | ±35 | Provisioning now activates commerce in the same request, so one click yields both halves — best effort, with a different sentence for each outcome. |
| `src/app/Http/Controllers/CommunicationCenterController.php` | +7 | Passes `commerceState`. |
| `src/resources/views/merchant/overview.blade.php` | +140 | The **Store data** panel: connected (verify / stop sharing) · connecting · suspended · needs-attention · failed with its reason and a support reference · disconnected · not-connected · unavailable — each with the action that is honest for it, and no action where none would be. No token in any state. |
| `src/app/Models/LynomiaStoreLink.php` | +25 | Casts for the new columns, and — the important part — the `saving` hook now clears the commerce facts when `lynomia_account_id` changes, beside the reconciliation watermark it already cleared. Carried across a re-point they describe another workspace's installation, which reads as CONNECTED. |
| `src/app/Exceptions/LynomiaApiException.php` | +28 | Merchant sentences for the five refusals Lynomia defines for this endpoint, matched on `error_code` exactly as the two link-code refusals already were. |
| `src/database/migrations/2026_11_03_000100_…` | **new** | Six additive nullable columns, no index, no backfill. |
| `src/app/Console/Commands/ReconcileLynomiaCommerce.php` | **new** | The hourly belief-repair sweep. Reads `integration_health`; **never** installs, mints or rotates; skips any link that is not `allowsSso()`. |
| `src/app/Providers/LynomiaChatServiceProvider.php` | +12 | Registers and schedules it. |
| `src/resources/lang/en.json`, `ar.json` | +31 each | Every new source string, in both dictionaries, with real Arabic. Enforced by `LynomiaTranslationCoverageTest`. |
| `tests/Feature/Lynomia/InteractsWithLynomia.php` | +16 | A scriptable `commerce_installation` stub in the shared fake. |
| `tests/Feature/Lynomia/LynomiaCommerceActivationTest.php` | **new** | 32 examples. |
| `tests/Feature/Lynomia/LynomiaCommerceReconciliationTest.php` | **new** | 11 examples. |
| `tests/Feature/Lynomia/LynomiaCommerceCredentialUiTest.php` | +70 | 3 examples pinning the issuance defect as fixed. |

### Not changed, deliberately

* No existing migration was edited. No column was dropped or renamed.
* The Super Admin panel keeps every control it had.
* `AccountLinker`, `IdentityProvisioner`, `SsoLinkIssuer`, `InstallationLifecycle`,
  `CommerceReader`, `CommerceApiController`, `AuthenticateCommerceApi` and
  `EnsureStoreOwnsLink` are untouched.
* No production `.env`, secret or `APP_KEY` was read, written or regenerated.
* `composer.json` and `composer.lock` are byte-identical to `main` (a temporary local
  `repositories` stub was needed to install dependencies in this sandbox — see
  `05-open-risks.md` — and both files were restored; `git status` confirms them clean).
