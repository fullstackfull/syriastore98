# Phase 6 — completion matrix

Every capability approved for Integration Phase 6, with its state, the commits that produced
it and the tests that were actually executed. Maintained across increments; the consolidated
narrative is `PHASE-6-COMPLETION-REPORT.md`.

**Legend**

| State | Meaning |
|---|---|
| IMPLEMENTED | code is written and unit/feature tested offline |
| VERIFIED | IMPLEMENTED, plus browser verification and/or cross-repository contract replay |
| PARTIAL | some of the approved scope is built; what is missing is named |
| BLOCKED | cannot be completed without an owner action or an external dependency |
| NOT STARTED | no code yet |

**Two standing caveats.**

Nothing here is end-to-end verified against two deployed systems. Every cross-system claim
rests on a scriptable fake or the shared contract artifact. That is contract verification,
not deployment verification, and **staging blocker A1 stays open**.

**No message has been transmitted to any merchant.** The platform communication capability is
implemented and exercised against faked HTTP and against an unconfigured installation. It has
never carried a message, and the phase was forbidden from letting it.

Last updated: 2026-10-16, after P6F — two adversarial rounds, their thirteen corrections,
browser verification of the operator console, and the regression sweeps.

---

## Prerequisites the approval required to be settled first

| Capability | State | Evidence |
|---|---|---|
| B3.1 — the composer asks what the person agreed to | VERIFIED | Lynomia `7db45646`; `spec/services/whatsapp/composer_consent_spec.rb` (9) |
| B3.2 — the consent retry is bounded and raises an operator-visible signal | VERIFIED | Store Syria `cc213d58`; `LynomiaConsentSyncTest` |
| Neither silently bypassed to make platform communications work | VERIFIED | Both closed before P6D/P6E; register §C, C15 and C16 |

---

## P6A — operational visibility across the boundary

| Capability | State | Evidence |
|---|---|---|
| `GET /platform/api/v1/accounts/:id/integration_health`, confined by `platform_app_permissibles` | VERIFIED | Lynomia `3fab5203`; `spec/requests/platform/api/v1/integration_health_spec.rb` (12) |
| A projection of `Operations::Health` / `Commerce::StoreHealth` / `Operations::Signal`, not a second health model | VERIFIED | `custom/app/services/operations/integration_health.rb`; the same object backs both consoles |
| Store Syria Super Admin reads it per row, on demand | VERIFIED | Store Syria `cc213d58`; `LynomiaIntegrationHealthTest` (10); `tests/Browser/p6-operator-console.mjs` — reachable, 502 and connection-refused |
| An unreachable Lynomia never produces a health state | VERIFIED | Browser, all three cases |
| Consent figures confined to the calling app | VERIFIED | `platform_app_id` scoping on `partner_consents` and their event log |
| No credential, customer, phone number or provider body in the payload | VERIFIED | Written-out allow-list, not `as_json` |
| `signals.open` is the real count, not the length of the capped list | VERIFIED | Lynomia `c99ba9b5` — it saturated at 20 while `by_source` in the same payload counted them all |
| An unreadable health answer is a refusal, never zeros | VERIFIED | `1e8a8e44`; the browser harness has a fourth remote-health case |
| Per-app scoping of `installations` | PARTIAL | `commerce_stores` has no `platform_app_id`; account-scoped. Not reachable in the current product shape — report §9 |

---

## P6B — partner and installation lifecycle

| Capability | State | Evidence |
|---|---|---|
| `POST /platform/api/v1/accounts/:id/installation_lifecycle` | VERIFIED | Lynomia `010be18c`; `spec/requests/platform/api/v1/installation_lifecycle_spec.rb` (13) |
| A facade over `Commerce::StoreConnection`, not a second lifecycle | VERIFIED | `custom/app/services/commerce/installation_lifecycle.rb` |
| Idempotent — a state already held succeeds with `applied: false` | VERIFIED | Spec |
| Decided under `store.with_lock` on the Lynomia side | VERIFIED | Spec |
| Suspend / resume with a reason, distinct from revoke | VERIFIED | Store Syria `6137b93c`; `LynomiaInstallationLifecycleTest` (18) |
| Re-authenticate: a new credential, resume attempted, credential returned either way | VERIFIED | Same |
| Decommission, terminal, keeping conversations and consent evidence | VERIFIED | Same; browser asserts the terminal row offers no control |
| Every transition carried to Lynomia before the local write | VERIFIED | Same |
| Decided under the row lock on the Store Syria side | VERIFIED | `ff8a362b`; `test_a_transition_decided_on_a_stale_read_is_refused_under_the_lock` |
| The merchant notice sent after the commit, not inside it | VERIFIED | `ff8a362b` |
| Every transition a POST, measured on the booted router | VERIFIED | `LynomiaPhase6SecurityTest` |
| Per-state controls, CSRF, required reasons, and the confirmation on decommission | VERIFIED | `ad92d33d`; browser |
| Revoking a LINKED workspace stops the installation before returning the grant | VERIFIED | `1e8a8e44`; the order of the two recorded calls is asserted, because both happen either way |
| A partner suspend leaves a store the merchant disconnected alone | VERIFIED | Lynomia `e08f24e8`; rewriting it to `disabled` made the merchant's own store unreconnectable |
| A reissue yields its credential however the resume fails | VERIFIED | `a24c974f`; both sources of failure raise the one exception the catch handles |

---

## P6C — Lynomia-side integration administration

| Capability | State | Evidence |
|---|---|---|
| Every partner integration, its workspaces and its open signals | VERIFIED | Lynomia `4fb1ac5b`; `spec/controllers/super_admin/partner_integrations_controller_spec.rb` (11) |
| Per-installation detail using the same projection the partner reads | VERIFIED | `Operations::PartnerOverview#workspace` |
| What the app is permitted, counted by type and never as a roster of people | VERIFIED | Spec |
| Recent partner activity from the audit trail | VERIFIED | Spec, now with a row in the table — the page called `entry.user_name`, which does not exist, and every earlier example rendered it empty (`c99ba9b5`) |
| Read only — every route a GET | VERIFIED | `config/routes/partner_integrations.rb` |
| Super Admin only, through `authenticate_super_admin!` | VERIFIED | `SuperAdmin::ApplicationController` |
| The Administrate navigation exclusion shipped in the same commit | VERIFIED | `_navigation.html.erb`; without it every Super Admin page raises |
| No new authorisation model | VERIFIED | `PlatformApp` + `platform_app_permissibles` unchanged |

---

## P6D — the platform workspace and its commercial isolation

| Capability | State | Evidence |
|---|---|---|
| `accounts.platform_owned`, set at creation, with `platform_owned` / `tenant` scopes | VERIFIED | Lynomia `e454cb4e`; `db/migrate/20261027000100_add_platform_owned_to_accounts.rb` |
| Provisioned deliberately by a service, never by signup | VERIFIED | `Billing::PlatformWorkspace`; `spec/services/billing/platform_workspace_spec.rb` (15) |
| One per installation; a second is refused | VERIFIED | Spec |
| Independent paid subscription, `source: 'manual'`, no Stripe identifiers | VERIFIED | Spec |
| The plan must not be sellable | VERIFIED | `refuse_sellable_plan!`, condition copied from `Billing::Checkout` / `PlanChange` / the upgrade list |
| The plan must meter | VERIFIED | `refuse_unmetered_plan!` — without `campaign_sends`, `MeteredUsage` writes no counter at all |
| Service identity + both permissibles created with the account | VERIFIED | Spec; without the pair the workspace cannot send |
| Excluded from the merchant trial and the trial backfill | VERIFIED | `Custom::Account#start_billing_trial`, `Billing::TrialStarter.backfill!` |
| Excluded from merchant account health and every billing figure | VERIFIED | `Operations::AccountHealth`, `Billing::StatsController#tenant_subscriptions` |
| Never listed to a partner among its merchants | VERIFIED | Lynomia `d49f0008`; `spec/controllers/platform/api/v1/accounts_controller_spec.rb` |
| Still addressable by id through `show`, permissible-checked | VERIFIED | Same spec |
| Store Syria records which workspace is its own | VERIFIED | Store Syria `e33d5f65` |
| Anything verifies that the configured id IS that workspace | BLOCKED | Register B5; mitigated by naming the ids on the notices tab |
| No real charge, no production payment | VERIFIED | Nothing on this path calls Stripe |
| The allowance invariant holds after provisioning, not only at it | VERIFIED | Lynomia `c99ba9b5` — a platform-owned account with no `campaign_sends` ceiling is refused the send, because the plan can be edited from under a live workspace |

---

## P6E — platform communication operations

| Capability | State | Evidence |
|---|---|---|
| Notices sent through Lynomia's own channel, templates and send path — no new sender | VERIFIED | Store Syria `c54b4442`; `PlatformWorkspaceGateway` drives the existing clients |
| UTILITY only for the platform workspace, on Meta's own classification | VERIFIED | Lynomia `266b8483`; `spec/services/whatsapp/platform_workspace_sending_spec.rb` (7) |
| No field a caller can set to relabel a template's class | VERIFIED | `PlatformNotifier` takes a template name; the category is read from the channel's synced list |
| Metered on the conversation path, not only the campaign path | VERIFIED | Spec |
| Consent gate runs on every send, with no override parameter anywhere | VERIFIED | `Commerce::MessagingEligibility` called unconditionally |
| Authorised occasions only — five kinds, unknown kinds raise | VERIFIED | `LynomiaPlatformNotice::KINDS`; `LynomiaPlatformNoticeTest` (20) |
| Recipients resolved from the merchant's record; an operator never types a number | VERIFIED | `PlatformRecipients::resolve(link)` — asserted by signature |
| A store that changed hands, or is revoked or decommissioned, is not messaged | VERIFIED | Test |
| One row per occasion (unique `dedupe_key`) | VERIFIED | Test |
| One send per row (conditional `pending → sending`) | VERIFIED | `ff8a362b`; `test_two_attempts_holding_one_pending_row_produce_one_send` |
| A lost answer lands on `indeterminate` and is never retried automatically | VERIFIED | Test |
| An occasion that failed before anything was sent can be tried again | VERIFIED | `ff8a362b`; the common case, because of B4 |
| Automated notice on suspension, keyed on the audit row | VERIFIED | Store Syria `40695d6e` |
| Operator-visible history with failure reasons, and the workspace it sends from | VERIFIED | `ad92d33d`, `ff8a362b`; browser |
| The console does not claim a notice was sent when Lynomia has only accepted it | VERIFIED | `1e8a8e44` — "Handed to Lynomia", the gap stated in one line, and in-flight rows counted for the first time |
| A repeated suspension does not tell the merchant twice | VERIFIED | `1e8a8e44` — the notice is keyed on an audit row, and a repeat writes a fresh one |
| The allowance is spent after the consent gate, not before | VERIFIED | Lynomia `c99ba9b5` |
| Merchant opt-out that is not the shopper surface | PARTIAL | The WhatsApp STOP keyword withdraws both classes and blocks; no merchant-facing screen |
| Operator-initiated notice (onboarding, security) | PARTIAL | Only the automated suspension notice has a trigger |
| Resolution action for an `indeterminate` row | PARTIAL | Counted, warned, listed; not actionable from the page |
| Email as a second channel | NOT STARTED | Excluded by Decision 3; the gateway is the seam for it |
| Any message actually transmitted | BLOCKED | A1, and the phase's own restriction |

---

## P6F — governance, hardening, adversarial review and closure

| Capability | State | Evidence |
|---|---|---|
| Adversarial review of this phase and of the previous round's fixes | VERIFIED | Two rounds, thirteen findings, all fixed; report §13 |
| Round two run independently, with a refutation pass over every finding | VERIFIED | Five lenses, one per owner decision plus concurrency; 12 escalated findings, 10 survived refutation, 6 killed and recorded |
| It found round one's own fix incomplete, which is what the requirement is for | VERIFIED | `AccountLinker::revoke` returned the account grant before pushing the stop, so for a linked workspace the push got 401 and the commerce store stayed authorised — the defect P6B existed to close |
| Every correction red-proofed by restoring its defect | VERIFIED | Each failed only its own example |
| Browser verification of the operator surfaces, English and Arabic | VERIFIED | `tests/Browser/p6-operator-console.mjs`, 62 checks; suite 265 checks, 0 failures |
| Full Store Syria regression, gated | VERIFIED | report §11; the gate confirms the recorded outcome set exactly |
| Full Lynomia regression sweep | VERIFIED | report §11; 70 pending throughout, all the MFA/encryption-keys guard |
| Translation coverage held by a tripwire | VERIFIED | `LynomiaTranslationCoverageTest` (4) |
| Phase 6 security properties held by tripwires | VERIFIED | `LynomiaPhase6SecurityTest` (3) |
| One tab's content renders on one tab | VERIFIED | `1e8a8e44` — the consent panel was a sibling of the panes, so it showed on all five; the harness asserts visibility, not presence |
| Blocker register updated | VERIFIED | C15, C16 closed; B5 opened |
| Completion report | VERIFIED | `PHASE-6-COMPLETION-REPORT.md` |

---

## What Phase 6 was forbidden to do, and did not

| Restriction | Held |
|---|---|
| No merge of either branch into main | Yes — both branches unmerged |
| No deployment | Yes |
| No `.env` modification | Yes |
| No `APP_KEY` change or rotation | Yes |
| No production credential altered | Yes |
| No real WhatsApp provider account connected | Yes |
| No live messaging activated | Yes |
| No production financial transaction | Yes |
| No unofficial WhatsApp integration | Yes — sending is the official Cloud API channel |
| No weakening of consent enforcement | Yes — B3.1 strengthened it |
| No bypass of installation or account identity checks | Yes — the lifecycle guard moved **inside** the lock |
| No administrative credential exposed to a merchant browser | Yes |
| No second messaging, scheduling, automation, template or consent engine | Yes |
| No change to the SHA-pinned contract artifacts | Yes — unchanged, no version bump needed |
| No widening of `platform_app_permissibles` semantics | Yes |
| Store Syria platform Phase 6 (Arabic & Storefront) not begun | Yes |
| Integration Phase 7 not begun | Yes |
