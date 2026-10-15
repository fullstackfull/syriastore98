# Phase 5 — completion matrix

Maintained across increments. Every capability approved for Phase 5, with its state, the
commits that produced it and the tests that were actually executed.

**Legend**

| State | Meaning |
|---|---|
| IMPLEMENTED | code is written and unit/feature tested offline |
| VERIFIED | IMPLEMENTED, plus browser verification and/or cross-repository contract replay |
| PARTIAL | some of the approved scope is built; what is missing is named |
| BLOCKED | cannot be completed without an owner action or an external dependency |
| NOT STARTED | no code yet |

**One standing caveat.** Nothing here is end-to-end verified against two deployed systems.
Every cross-system claim rests on either a scriptable fake or the shared contract artifact.
That is contract verification, not deployment verification, and **staging blocker A1 stays
open** (`PRODUCTION-READINESS-BLOCKERS.md`).

Last updated: 2026-10-15, after P5B.

---

## P5A — capability mapping and integration contracts

| Capability | State | Evidence |
|---|---|---|
| Capability matrix across both repositories | VERIFIED | `PHASE-5A-CAPABILITY-MATRIX.md`, Store Syria `c9476304` |
| Switch inventory (what is off as deployed) | VERIFIED | matrix §0.1 |
| Permission model inventory | VERIFIED | matrix §0.2 |

## P5E — partner consent, messaging eligibility, bidirectional sync, throttling

| Capability | State | Evidence |
|---|---|---|
| Partner-scoped consent model (ADR-001) | IMPLEMENTED | Lynomia `custom/app/models/partner_consent.rb`, `partner_consent_event.rb`; `bb915a4a` |
| Server-authoritative ordering and re-consent | IMPLEMENTED | `custom/app/services/partners/consents.rb`; `46714c37`; `spec/services/partners/consents_spec.rb` |
| Shared eligibility engine (negatives union) | IMPLEMENTED | `custom/app/services/partners/eligibility.rb`, `campaigns/send_guard.rb` |
| Enforcement at the single audience entry point | IMPLEMENTED | `custom/app/models/custom/campaign_audience.rb`, `campaigns/eligibility.rb` |
| Final send-boundary revalidation, all four senders | VERIFIED | `Campaigns::SendGuard.clear_to_send`; Lynomia `2b768b11`; `spec/services/campaigns` 65 examples |
| — pacing before the guard (ordering defect) | FIXED | `2b768b11`; `THROTTLE-SAFETY.md` §3 |
| — stale account / store / contact reads | FIXED | `2b768b11`; `THROTTLE-SAFETY.md` §4 |
| Campaign send throttling | VERIFIED | `custom/app/services/campaigns/throttle.rb`; `THROTTLE-SAFETY.md` all 8 properties |
| Outbound kill switch (installation + account) | IMPLEMENTED | `custom/app/services/campaigns/outbound.rb` |
| Double-send and ceiling safety under concurrency | VERIFIED | `spec/models/campaign_send_concurrency_spec.rb`, 3 examples, real threads |
| Contact-merge consent preservation | IMPLEMENTED | `custom/app/services/contacts/consent_merge.rb` |
| Consent change feed (Lynomia → partner) | IMPLEMENTED | `custom/app/services/consents/change_feed.rb` |
| Cross-repository contract test | VERIFIED | one real-producer artifact, shared SHA256, real ingress replay, mutation-verified; Lynomia `7d8acb96`, Store Syria `c984d268` |
| Store Syria checkout marketing opt-in | VERIFIED | `CheckoutConsentCapture`, `CheckoutConsentObserver`, `ViewComposer`, `storefront/marketing-optin.blade.php`; 12 feature tests + 10 browser checks |
| Consent outbox delivery | IMPLEMENTED | `ConsentDecisionDispatcher`, `lynomia:deliver-consent-decisions` |
| Bidirectional reconciliation | VERIFIED | `ConsentReconciler`, `lynomia:reconcile-consents`; 12 feature tests |
| — native decisions exposed for reconciliation | IMPLEMENTED | Lynomia `partner_consents_controller#index` now returns `native`; 2 request specs |
| — per-origin state scoping (revision defect) | FIXED | migration `2026_10_21_000200`; `ConsentStateProjector`; `LynomiaConsentState::effectiveFor` |
| Consent-state visibility (merchant) | VERIFIED | `StoreConsentController`, consent tab panel; 15 feature tests + 18 browser checks |
| Native merchant consent management, ar + en | VERIFIED | consent tab (P4E) + store ledger (P5E); 573 keys in both `ar.json` and `en.json`; browser-verified RTL at 390px |
| Checkout opt-in is never pre-ticked | VERIFIED | browser check, both locales |
| Tenant isolation on every new surface | VERIFIED | `LynomiaStoreConsentVisibilityTest`: anonymous, staff-without-permission, cross-tenant rows, cross-store customer names |

## P5B — audiences and segmentation

| Capability | State | Evidence |
|---|---|---|
| Native audience management (create, edit, delete) | VERIFIED | Store Syria `AudienceApiController`, `AudienceController`, `merchant/audiences.blade.php`; 15 feature tests + 18 browser checks |
| Store-scoped customer segmentation | VERIFIED | Lynomia `Audience::CommerceCondition` store predicate; 13 specs covering counts, spend, statuses, presence and completeness |
| — the aggregates summed across every store (defect) | FIXED | the scope is threaded through the one SQL fragment every condition is expressed over |
| Store-scoped audience counts | VERIFIED | `audience_preview` takes `commerce_store_id`; 5 request specs, including that the preview agrees with what the campaign resolves |
| Both counts shown to the merchant | VERIFIED | "40 customers of this store" beside "180 across all your stores"; browser-verified |
| Verified identity matching | IMPLEMENTED | pre-existing `Commerce::CustomerLink` (P3B) + `ContactIdentity`; the audience scope reads the same links, and a contact with no link at this store is not counted |
| Condition vocabulary from the server | VERIFIED | `commerce/audience_fields` drives the fields, stores and currencies; a currency the merchant has never traded in is not offered (browser-verified) |
| Unread-contact disclosure | VERIFIED | contacts whose orders Lynomia has not read are reported, so a small count is explainable rather than mysterious |
| Per-person permission | VERIFIED | `Manage Lynomia Audiences`, enforced on every route; staff holding chat + automation + consent are still refused |
| Tenant isolation | VERIFIED | no route takes a store id; a store id naming another account is refused 422 upstream rather than silently counted as zero |
| An audience is a count, never a list | VERIFIED | no contact is rendered; browser-asserted against the rendered list |

## P5C — messaging templates

| Capability | State | Evidence |
|---|---|---|
| Native template management | NOT STARTED | |
| Provider capability reporting | NOT STARTED | |
| Approval status visibility | NOT STARTED | |

## P5D — campaign management

| Capability | State | Evidence |
|---|---|---|
| Native campaign creation and editing | NOT STARTED | |
| Scheduling | NOT STARTED | |
| Eligibility preview | NOT STARTED | |
| Execution controls and cancellation | NOT STARTED | enforcement point reserved at `Campaigns::SendGuard.clear_to_send` (`THROTTLE-SAFETY.md` §3) |
| Monitoring and history | NOT STARTED | |

## P5F — analytics

| Capability | State | Evidence |
|---|---|---|
| Campaign delivery analytics | NOT STARTED | upstream snapshot exists (`Campaigns::RecipientSnapshot`) |
| Suppression and consent reporting | NOT STARTED | skip reasons already recorded per recipient |
| Audience performance | NOT STARTED | |

## P5G — commercial entitlements

| Capability | State | Evidence |
|---|---|---|
| Configurable marketing plans | NOT STARTED | |
| Hard sending quotas | NOT STARTED | must NOT route through `Campaigns::Throttle` — see `THROTTLE-SAFETY.md` §1 |
| Metered usage | NOT STARTED | |
| Trials and add-ons | NOT STARTED | |

---

## Executed tests, as of this entry

| Suite | Result |
|---|---|
| Lynomia — campaigns, partners, consents, contacts, contracts, platform, 3 senders, campaign models | 495 examples, 0 failures |
| Lynomia — `spec/services/campaigns` | 65 examples, 0 failures |
| Lynomia — `spec/requests/platform` | 146 examples, 0 failures (17 in `partner_consents_spec`) |
| Store Syria — `--filter Lynomia` | 339 tests, 4,553 assertions, 0 failures |
| Store Syria — `Contract` suite | 9 tests, 32 assertions, 0 failures |
| Store Syria — browser, 5 suites | 132 checks, 0 failures |

## Open blockers

| Id | What | Why it is still open |
|---|---|---|
| A1 | deployed end-to-end verification between two non-production systems | every cross-system test uses a scriptable fake or the shared artifact; neither proves DNS, TLS, real credentials, Sidekiq delivery, clock skew between hosts, or a webhook subscription that was never created |
