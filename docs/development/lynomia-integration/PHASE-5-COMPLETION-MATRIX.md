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

Last updated: 2026-10-15, after P5H-FINAL (the verification sweep and the re-review of P5H's
own fixes).

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
| Native template management (create, edit, delete, duplicate) | VERIFIED | Store Syria `TemplateApiController`, `merchant/templates.blade.php`; 17 feature tests + 20 browser checks |
| Submission to the provider for review | VERIFIED | `submit`, never transport-retried — a retry Meta saw starts a second review; proven by counting attempts on a failed first try |
| Approval status visibility | VERIFIED | Meta's `meta_status`, `rejected_reason`, `rejection_info` and `quality_score` shown verbatim, **with the age of the mirror** beside them |
| Provider capability / lifecycle rules | VERIFIED | `allowed_actions` is the server's answer, rendered not re-derived; a second copy of Meta's transition rules here would fall behind and cost a review cycle |
| Pre-submission validation | VERIFIED | `validation_problems` shown, and the page states in words that an empty list is not a promise of approval |
| A template deleted at the provider | VERIFIED | `missing_at_meta` called out rather than lingering as "approved" |
| Identity fields immutable after creation | VERIFIED | name, language and category locked on an edit; browser-verified |
| No third copy of a provider-owned status | VERIFIED | asserted: no table in this module has "template" in its name |
| Per-person permission | VERIFIED | `Manage Lynomia Templates`; staff holding chat + audiences + automation are refused |

## P5D — campaign management

| Capability | State | Evidence |
|---|---|---|
| Native campaign creation and editing | VERIFIED | Store Syria `CampaignApiController`, `merchant/campaigns.blade.php`; 17 feature tests + 18 browser checks |
| Scheduling | VERIFIED | `scheduled_at` set here; Lynomia's own sweep fires it — no second scheduler |
| Eligibility preview | VERIFIED | the same query as the send: audience resolution + consent filter + store scope; the page states the unreachable are already excluded |
| Cancellation | VERIFIED | Lynomia `campaign_status: cancelled` (`5b1f269e`), enforced at `Campaigns::SendGuard` step 2; 10 specs including a run in flight stopping at its **next** recipient |
| — the gap `THROTTLE-SAFETY.md` §3 deferred | CLOSED | the state it needed did not exist; it does now and the guard reads it live |
| Execution controls | VERIFIED | a finished campaign cannot be edited or stopped; a cancelled one keeps its reason **and** its results |
| Cancelling keeps the record | VERIFIED | a POST, never a DELETE — the recipient snapshot is what an operator who stopped a half-sent campaign came for |
| Monitoring and history | VERIFIED | per-campaign metrics and per-recipient outcomes, with the skip reason |
| Per-person permission | VERIFIED | `Manage Lynomia Campaigns`, the heaviest of the four; staff holding audiences + templates are refused |

## P5F — analytics

| Capability | State | Evidence |
|---|---|---|
| Campaign delivery analytics | VERIFIED | audience, sent, delivered, read, failed, skipped from `Campaigns::RecipientMetrics`, rendered per campaign |
| Per-recipient outcomes | VERIFIED | one row per addressed contact with its outcome and timestamps |
| Suppression and consent reporting | VERIFIED | `skip_reasons` breakdown added to `RecipientMetrics`, surfaced largest-first; 2 feature tests + 2 browser checks |
| Audience performance | VERIFIED | per audience, "40 customers of this store" against "180 across all your stores" — the gap is the performance signal |
| Attribution beyond delivery | NOT SUPPORTED — documented | neither platform records a conversion event tied to a campaign message; claiming attribution would mean inventing the data. Recorded here rather than shown as an empty chart |

## P5G — commercial entitlements

| Capability | State | Evidence |
|---|---|---|
| Configurable marketing plans | IMPLEMENTED | `plans.lynomia_campaign_sends`, operator-set, null ≠ zero; 19 Store Syria feature tests |
| Hard sending quotas | IMPLEMENTED | Lynomia `Billing::MeteredUsage`, enforced at `Campaigns::SendGuard` after eligibility and **fails closed** |
| — kept out of `Campaigns::Throttle` | HONOURED | `THROTTLE-SAFETY.md` §1 required it; the quota lives with `Billing::ResourceLimit` and takes the same account lock |
| Metered usage, period-anchored | IMPLEMENTED | `billing_usage_counters`, anchored to the subscription period, calendar month when there is none |
| Usage visible to the merchant | IMPLEMENTED | read from Lynomia (the enforcing side), with this side's add-on ledger beside it |
| Add-on catalogue | IMPLEMENTED | `lynomia_marketing_addons` + `lynomia_store_addons`, operator-granted |
| Trials | IMPLEMENTED | a trial is an add-on with `expires_at` — one mechanism, one expiry rule |
| Store Syria ↔ Lynomia commercial integration | IMPLEMENTED | `MarketingAllowanceSync`: included + add-ons summed here, granted as one number, composed upstream by MAX |
| Real purchase flow | NOT BUILT — deliberate | every payment gateway in this installation is off and activating one is outside this phase's authorization; add-ons are operator-granted and `price_cents` is recorded, not charged |

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

## P5H — hardening, adversarial review and closure

| Capability | State | Evidence |
|---|---|---|
| Super Admin marketing settings | VERIFIED | plan allowance, add-on catalogue, add-on grants, per-permission reach, commerce and event health, every action audited |
| Eight-dimension adversarial review | DONE | 52 candidates, 7 survived independent verification, all 7 fixed — see the report §7 |
| One canonical partner store identifier | FIXED | `app/Support/StoreIdentity`; four call sites had invented a bare-id form the other side never uses |
| Campaign cancellation reachable at all | FIXED | `CampaignPolicy#cancel?`; the endpoint 500'd for every caller before |
| A campaign belongs to its installation | FIXED | `commerce_store_id` permitted and validated on create, sent by Store Syria |
| A partner-only grant can produce a recipient | FIXED | `Partners::Eligibility.filter` wired into `audience_contacts`; it had zero callers |
| A cancelled campaign stays cancelled | FIXED | `completed!` refuses under the lock, reading the row not the attribute |
| The send quota is actually hard | FIXED | `counter.with_lock` spans read and write; 4 of 4 workers passed a ceiling of 1 before |
| The quota is spent only on messages that go | FIXED | `SendGuard.spend` at each sender's provider boundary; per-channel spec |
| Consent revision is monotonic | FIXED | `commerce_messaging_consents.revision`; every native change after the first was dropped before |
| A suppressed link is not a customer | FIXED | one `linked` scope, used by all four readers |
| Consent survives neither more nor less than its subject | FIXED | cascading foreign keys; a contact who had opted in could not be deleted before |
| Only a shopper can tick the checkout box | FIXED | back-office requests no longer capture |
| Marketing allowance re-synced on a schedule | FIXED | rides `lynomia:reconcile-events`, so an expired trial reduces the ceiling |
| Second cross-repository contract artifact | ADDED | `audience_fields.v1.json`, SHA-pinned in both repositories |
| Browser verification, 8 suites | VERIFIED | 189 checks, Arabic and English, phone width, 0 failures |

## P5H-FINAL — the verification pass, and the re-review of the fixes above

The six defects marked FIXED above were re-verified adversarially rather than taken on
trust. **All six came back PARTIALLY_CORRECTED**: each was corrected along the path its bug
report described and left open along at least one other. Nineteen residual holes survived
independent verification; eleven are fixed here, eight are open in
`PRODUCTION-READINESS-BLOCKERS.md` §B2. The full RSpec sweep found a further defect the
review did not.

| Capability | State | Evidence |
|---|---|---|
| Full Lynomia RSpec sweep on a frozen tree | VERIFIED | **11,211 examples, 0 failures**, 70 pending (the MFA/encryption guard), 47m19s, at `1d797ed9` |
| The campaign's class is the provider's, not the client's | FIXED | `purpose_for` read `template_params['category']`, which the dashboard defaulted to `UTILITY`; a MARKETING template declared UTILITY needed no marketing consent |
| ADR-001 §3.3 step 8 takes a grant from EITHER store | FIXED | a native account-wide grant did not satisfy a store-scoped campaign; the negatives still union |
| The usage period is keyed by data, not by the reader | FIXED | `period_start` is part of a UNIQUE index and was derived through thread-local `Time.zone` — two callers, two rows, two allowances |
| A manual grant's expiry is not a billing cycle | FIXED | adding an expiry to a comp reissued the whole allowance in the same calendar month, repeatably |
| The quota is spent only on messages that reach a provider | FIXED AGAIN | the earlier fix moved the spend below the consent gate but still above two OFFLINE refusals, so an unapproved template burned the whole period and sent nothing |
| A cancelled ONGOING campaign stops | FIXED | `SendGuard` is not in the website path at all; the builder and the widget list now check |
| Cancelling a blocked campaign keeps its reason and closes its signal | FIXED | `blocked_reason` was erased and the Operations signal stayed open forever |
| The native consent revision survives its row | FIXED | `clear_suppression` destroys the row and the replacement published `1`; the counter is now the table's |
| A contact merge is a consent change a partner can see | FIXED | the merge wrote the row directly, bumping nothing and publishing nothing |
| An unavailable installation refuses a campaign, not widens it | FIXED | a `needs_reauth` store silently produced an ACCOUNT-WIDE campaign with a 200 |
| An empty `200` is success | FIXED | five `head :ok` endpoints answered 503 to the merchant over work that had succeeded |
| The dashboard preview asks the send's question | FIXED | it sent only the audience, so every preview resolved as SMS and promotional |
| A cancelled campaign reads as cancelled | FIXED | it read as “Scheduled” in amber, and its Results button was hidden |

## Open blockers

| Id | What | Why it is still open |
|---|---|---|
| A1 | deployed end-to-end verification between two non-production systems | every cross-system test uses a scriptable fake or the shared artifacts; neither proves DNS, TLS, real credentials, Sidekiq delivery, clock skew between hosts, or a webhook subscription that was never created. **P5H is the argument for it**: four features passed every test on both sides and would have failed on first contact with a real deployment, because each side's fake agreed with its own author. **P5H-FINAL is the second argument**: the HTTP double sent `[]` where Rails sends nothing, and five delete endpoints therefore reported failure to the merchant over work that had succeeded — a shape no amount of offline testing on either side could expose, because both sides' tests used the same wrong body. |
| B2.1–B2.8 | eight residuals the re-review confirmed and the final pass did not close, one of them a capability that does not work at all | listed in full in `PRODUCTION-READINESS-BLOCKERS.md` §B2. **B2.7**: a WhatsApp campaign created from Store Syria sends to nobody, because the campaign modal never attaches a template. |
