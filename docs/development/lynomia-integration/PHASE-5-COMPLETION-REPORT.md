# Store Syria × Lynomia — Phase 5 completion report

Native campaigns, audiences, messaging templates, marketing analytics and advanced commercial
entitlements, plus the partner consent subsystem P5E was authorised to build.

**What this report is.** One consolidated account of what was built, what was found broken
while building it, what was tested and what is still owed. Per-capability detail lives in
`PHASE-5-COMPLETION-MATRIX.md`, `PHASE-5E-CLOSURE.md` and
`../../../../lynomiachat/docs/partner-consent/THROTTLE-SAFETY.md`; this does not restate them.

**Assessment: CONDITIONAL GO, with one capability gap named in §9.2.** Every approved
capability is implemented and tested offline, and the merchant surfaces are
browser-verified. The condition is blocker **A1**, unchanged and unclosable from inside
these two repositories: nothing here has ever run against a deployed Lynomia. Phase 5
narrowed A1 twice — the consent wire format and the audience-fields contract are each proven
against a real producer artifact rather than against two mocks — but narrowing is not
closing.

**The honest headline, in two parts.**

The adversarial review at the end of the build found that four features reported complete
earlier in the phase did not work on the real wire, and would not have been caught by any
test either side had. §5.1–§5.4 are those, and they are fixed.

Then the **final verification pass** — the full RSpec sweep and an adversarial
**re**-verification of the six defects that review had reported fixed — found more. The
re-review returned **PARTIALLY_CORRECTED on all six**: every one had been corrected along
the path its bug report described and left open along at least one other. Nineteen residual
holes survived independent verification, four of them critical. Eleven are fixed; eight are
listed in §9 as owed. The sweep itself found a seventh defect the review did not — a quota
key derived from thread-local state. §5.8 is the most important part of this document, and
§9.2 names the one capability that does not work at all: **a WhatsApp campaign created from
Store Syria sends to nobody**, because the campaign form never attaches a template.

---

## 1. Feature matrix

Full detail, per capability, in `PHASE-5-COMPLETION-MATRIX.md`. Summary:

| Increment | State |
|---|---|
| P5A — capability mapping and contracts | VERIFIED |
| P5B — audiences and store-scoped segmentation | VERIFIED (store scope was dead on the wire until §5.1; now proven by contract) |
| P5C — message templates and approval visibility | VERIFIED (template creation was unreachable until §5.4) |
| P5D — campaign management and lifecycle | VERIFIED (cancel 500'd for every caller until §5.2) |
| P5E — partner consent, eligibility, bidirectional sync, throttling | VERIFIED (outbound consent was rejected by Lynomia until §5.1) |
| P5F — campaign analytics and delivery outcomes | VERIFIED, with one documented gap |
| P5G — commercial entitlements, trials, add-ons, quotas | VERIFIED, with one deliberate omission |

Two things are **not** built, both stated rather than hidden:

- **Conversion attribution** (part of P5F). Neither platform records a conversion event tied
  to a campaign message, so attribution would mean inventing the data.
- **A merchant-facing purchase flow for add-ons** (part of P5G). Every payment gateway in
  this installation is switched off and activating one is outside this phase's authorization.
  Add-ons are operator-granted; `price_cents` is recorded, never charged.

## 2. What the merchant can do, natively, in Store Syria

Five surfaces, all inside the module, none of them an embedded Lynomia screen:

| Surface | What it does |
|---|---|
| **Audiences** | Build and edit shared audiences from commerce and contact conditions; preview "customers of this store" against "across all your stores"; match **all** or **any** condition |
| **Templates** | Write, submit, duplicate and retire WhatsApp templates; see Meta's approval state, rejection reason and quality score; choose which WhatsApp number a template belongs to |
| **Campaigns** | Create, edit, schedule, preview, send, **stop** and delete; choose the channel it sends from; read per-recipient results and the skip breakdown; see what is left of the period's allowance |
| **Consent** | The store's own decision ledger beside what Lynomia actually holds, the checkout opt-in switch, and a reconcile-now control |
| **Storefront checkout** | The opt-in itself: never pre-ticked, naming the store, saying how to stop, in Arabic and English |

All five are Arabic/RTL complete and verified at phone width.

## 3. What is reused rather than rebuilt

Lynomia owns every engine. Store Syria owns the merchant's screens and the commercial
decisions about its own product. The wire between them carries nothing else:

| Lynomia owns | Store Syria owns |
|---|---|
| audience resolution, the consent store, the send loop, pacing, the recipient snapshot, analytics, template mirroring with Meta | which plan includes what, which add-ons a merchant holds, the checkout opt-in, the merchant UI |

No campaign, audience, template or recipient row exists on the Store Syria side. A "status"
column here would be a second account of whether a merchant's customers were messaged, and
the two would disagree the first time a run was interrupted — which is exactly when somebody
looks.

## 4. Security and isolation

Every new merchant surface carries the same five layers, asserted **from the booted router**
rather than read off the routes file: `web/auth/xss/setlocate/verified`,
`PlanModuleCheck:LynomiaChat`, a per-person permission, `EnsureStoreOwnsLink:existing`, and no
route parameter naming a tenant.

Three new permissions, each a decision a merchant can reasonably make separately:
`Manage Lynomia Audiences` (who gets messaged), `Manage Lynomia Templates` (the words Meta
approves), `Manage Lynomia Campaigns` (the act of sending, which cannot be taken back). Each
is tested as a refusal for staff who hold the others. The Phase 4 permission tripwire caught
all three additions and was updated, never relaxed.

The send boundary has ten ordered refusals, every one re-read live per recipient: kill switch,
campaign cancelled, installation revoked, contact blocked, native suppression, native
withdrawal, partner suppression, partner withdrawal, missing promotional grant, provider
rules. The commercial ceiling is taken separately, by the sender, on the line before the
message goes (§5.7).

## 5. Defects found and fixed

Thirty-six, all reproduced before being fixed: twenty-two during the build (§5.1–§5.4) and
fourteen in the final verification pass (§5.8). **Eleven of the first twenty-two were in code
written earlier in this same phase**, and seven of those were found only by the adversarial
review at the end of it — which is the argument for running one. All fourteen of the second
set were in code written in this phase, and eleven of them were in code written to fix the
first set — which is the argument for running a second review of the first review's fixes.

### 5.1 The four that made a shipped feature a no-op

| # | Defect | What it meant |
|---|---|---|
| 1 | **Store Syria sent the bare store id where Lynomia holds `{installation_ref}:{id}`** — in the consent dispatcher, the consent projector, the reconciler and both audience-scope resolvers | Every **checkout opt-in was rejected 404** by Lynomia. Every inbound consent event was dropped as belonging to another installation. Reconciliation repaired nothing. Every store-scoped count silently answered account-wide. |
| 2 | `CampaignPolicy` had no `cancel?`, and the controller's `check_authorization` derives the query from the action name | **The entire P5D cancel feature 500'd for every caller.** Only a model-level spec covered it. |
| 3 | `commerce_store_id` was read by three things and written by none — upstream's `campaign_params` does not permit it | The preview was store-scoped and the send was not; the send boundary never consulted partner consent at all. |
| 4 | `audience_contacts` required a **native** promotional grant for every campaign | A shopper who agreed at a Store Syria checkout could never be a recipient. `Partners::Eligibility.filter`, written for this, had **zero callers**. |

Each of the four was green on both sides: the fakes used the same wrong shape as the code.

### 5.2 Correctness and lifecycle

| # | Defect | What it meant |
|---|---|---|
| 5 | The send guard ran **before** the throttle's sleep | A withdrawal or kill switch landing during the wait was read and ignored — and the wait is where a long campaign spends most of its time |
| 6 | `account.settings`, `store.status`, `contact.blocked` read off objects loaded before the run | The kill switch, a revoked installation and an agent blocking a shopper mid-run all did nothing until the next job |
| 7 | `campaign.completed!` overwrote `cancelled` when the sender's loop finished | The sends stopped; the record said the campaign had finished sending |
| 8 | Campaign analytics looked the campaign up by **primary key**, and the API only ever hands out `display_id` | The results page showed **another campaign's** delivery results, in Lynomia's own dashboard as well as Store Syria's |
| 9 | Native and partner consent shared one row ordered by incommensurable revisions | A partner grant overwrote a native withdrawal on the merchant's screen |
| 10 | The native consent `revision` was the row's **primary key**, and the row is updated in place | Every native consent change **after the first** was permanently dropped by Store Syria, and reconciliation fed the same stale value back |
| 11 | A **suppressed** customer link still counted as this store's customer in three of four readers | A store an agent had told us was not this shopper's could record decisions about them, was told their consent state, and was sent their opt-outs |
| 12 | `external_customer_id` selected through a SQL join returned ciphertext | Reconciliation would have matched nothing, silently |
| 13 | Commerce aggregates summed across every store | A store-scoped campaign's preview counted another store's customers |

### 5.3 Concurrency and data integrity

| # | Defect | What it meant |
|---|---|---|
| 14 | `Billing::MeteredUsage.consume` took `account.lock!` with **no enclosing transaction** | Measured: four workers released together at a ceiling of **one** all got through, and the ledger recorded four. The "hard" quota was decorative under exactly the load it exists for |
| 15 | TOCTOU read-then-write in the throttle counter | Concurrent workers overshoot the ceiling by their number |
| 16 | `partner_consents` foreign keys were **restricting** | Deleting a contact who had opted in raised `InvalidForeignKey` — an erasure request included — and so did releasing a store and deleting an account |
| 17 | The send allowance re-anchored to the calendar month during a **renewal gap** | A merchant at their ceiling got a second allowance in the gap and a third when the renewal landed |

### 5.4 Merchant-facing surfaces that read keys the server never sends

| # | Defect | What it meant |
|---|---|---|
| 18 | The templates page read a top-level `waba_contexts`; upstream sends `meta.whatsapp_business_accounts` | The WhatsApp-number picker was always empty, so **no template could be created** |
| 19 | The campaign page read a `data-inbox` attribute nothing sets, defaulting to `inbox_id: 1` | Every campaign went to whichever inbox was first in the workspace, or was refused |
| 20 | The recipient projection read `contact_name` (it is `contact.name`) and four timestamps that do not exist | A column of empty names beside the outcomes they belonged to |
| 21 | `scheduled_at` rendered with `new Date(seconds)` | Every scheduled campaign showed a date in January 1970 |
| 22 | Saving an audience hard-wrote `query_operator: 'and'` | Editing an OR audience built elsewhere silently narrowed it to AND |

Plus: the checkout opt-in rendered as a third column on desktop; the editor blanked "Send at"
and never transmitted it; the audiences page made the same upstream request twice per load;
and a back-office order could mint a shopper's marketing consent without
`Manage Lynomia Consent`.

### 5.5 Two promises from THROTTLE-SAFETY, closed rather than quietly dropped

Campaign cancellation (§3, delivered in P5D) and the commercial quota living outside the
throttle module and failing closed (§1, delivered in P5G).

### 5.6 What the fixes added to the test suites

Each of these is red on the code as it was, which was verified by reverting the fix:

- `campaign_cancel_spec` — 4 request examples; all four returned 500 before
- `campaign_store_scope_spec` — 3
- `campaign_partner_audience_spec` — 8, ending in an agreement example: nine contacts in every
  consent state, decided once by the relation the audience resolves through and once by the
  per-recipient guard the sender asks
- `metered_usage_concurrency_spec` — 2, forcing the window with a barrier rather than racing
  for it; got **4** through a ceiling of 1 before
- `send_quota_per_channel_spec` — 7, one group per sender, because the quota now has three
  call sites and a forgotten one is an unmetered channel
- `partner_consent_lifecycle_spec` — 4
- `suppressed_link_scope_spec` + 2 platform examples
- `audience_fields` contract — a **second** cross-repository artifact, SHA-pinned in both repos

### 5.7 Two things deliberately not "fixed"

- **A crashed send loop strands its campaign in `processing`.** `SendGuard.spend` fails
  closed, so a database it cannot reach aborts the loop, and `mark_processing!` then refuses
  the retry forever. Resetting the status would be worse: the senders address every contact
  the audience resolves without skipping those already recorded as `sent`, so re-running a
  half-sent campaign would message those people twice. Double-sending is worse than stopping.
  Recorded here and in the code.
- **The reconciler loads every projected consent row for a store.** It compares every scope
  against every other, so bounding it would defeat what it is for. The merchant-facing screen
  beside it **was** bounded. The sweep's memory profile at a large customer base is a known
  scale limitation.

### 5.8 The final verification pass, and the re-review that followed it

Phase 5 was asked to finish with a full Lynomia RSpec sweep against the committed branch and
an adversarial **re**-verification of the six defects the earlier review had reported fixed.
Both found more. This section is that pass; it is separated from the rest of §5 because the
earlier sections describe building, and this one describes checking what had been built.

**The sweep itself found one defect, and it was in the quota.** 11,186 examples, one failure:
`metered_usage_concurrency_spec` read a usage ledger of zero for an allowance a worker had
demonstrably taken. It passed in isolation and failed only in a 47-minute run, which is the
signature of order dependence rather than a flake, so it was reproduced deterministically —
two files, 2.4 seconds — before anything was changed.

`BillingUsageCounter.period_start_for` derived `period_start` through `Time.zone`, which is
**thread-local**. `period_start` is the third column of the counter's unique index, so two
callers whose zones straddle midnight key two rows off one subscription: two full allowances
on a ceiling whose whole purpose is to fail closed, and a usage display reading a row the
sender never writes to. Nothing in the application sets `Time.zone` per request or per job
today — the reporting code converts with `in_time_zone` and leaves the thread-local alone —
so no shipped caller diverges; the suite does, because `spec/models/working_hour_spec` sets
`Australia/Sydney` and never puts it back. The key is now derived from the stored column, and
the concurrency example asserts **one counter row** as well as one unit in it, which is the
shape the defect actually produced.

**Two conformance defects were found by reading the code against ADR-001 rather than by any
test.**

- **The campaign's class was the client's to declare, which is a consent bypass.**
  `Campaigns::Eligibility.purpose_for` read `template_params['category']` — a field of the
  campaign's own JSON body, permitted wholesale on create and update. A campaign created with
  a MARKETING template and `"category": "UTILITY"` was transactional on the creator's say-so,
  needed no promotional grant, and went to every contact in the audience who had never agreed
  to marketing. This was not only an API abuse: the dashboard's WhatsApp form sent
  `category: currentTemplate?.category || 'UTILITY'`, so a form that had not resolved its
  template declared every campaign transactional. The category is now read from
  `channel.message_templates` — the snapshot Meta's own sync writes, and the same list the
  sender resolves the template from — matched the same way, and the form no longer sends the
  field at all.
- **Step 8 was half-enforced, so a native grant did not count.** ADR-001 §3.3 step 8 is
  "promotional with no grant from **either** source", under a rule the ADR states twice:
  negatives union, positives do not. Both expressions of it required a grant from the
  *installation* and discounted an account-wide native one. It fails closed, so nobody was
  messaged who should not have been — but a merchant whose customers consented on Lynomia's
  own channels reached none of them, which is the gap §1 of that ADR exists to close,
  reappearing from the other side.

**The adversarial re-review returned PARTIALLY_CORRECTED on all six defects**, with 19
residual holes that survived independent verification. Eleven are fixed here; the remaining
eight are listed in §9 rather than quietly carried. The eleven:

| Severity | Residual | What it meant |
|---|---|---|
| critical | A cancelled **ongoing** campaign kept messaging visitors | `cancel!` does not restrict to `one_off?`, so a Website campaign cancels with a 200 and `campaign_status: "cancelled"`. An ongoing campaign has no send loop and no scheduler, so `Campaigns::SendGuard` is not in that path at all: the visitor's own `campaign_triggered` event reached `CampaignConversationBuilder`, which looked the campaign up by display_id with no status predicate and created the conversation and the message. The widget's list filtered on `enabled` alone, so it was still advertised. |
| critical | A campaign that sent **nothing** spent the whole period allowance | The WhatsApp sender took the unit immediately before `super`, defended as "the only thing left is Meta's own answer". Untrue: upstream's `return if name.blank?` resolves the template from `channel.message_templates`, and the authentication guard reads the same jsonb. Both are offline, and an unapproved or language-mismatched template is a property of the **campaign** — so a 5,000-contact audience on a plan of 1,000 consumed the merchant's entire period, recorded 5,000 skips, made no provider call, and left every later campaign that period refused. |
| critical | A store in `needs_reauth` silently created an **account-wide** campaign | `array_filter` dropped a null `commerce_store_id`, and a campaign without it resolves account-wide upstream: the native consent path instead of the partner one, so the store's checkout opt-ins authorise nobody and, in an account with a second connected store, the campaign addresses that store's customers. 200 OK, no warning. A rejected token flips a store to `needs_reauth` unattended. |
| high | The consent `revision` restarted at 1 | `clear_suppression` **destroys** the row, so the next decision came from `find_or_initialize_by` on a fresh one and published 1 — below everything already stored. A partner holding 80 discarded that decision and the next eighty. The counter is now the table's rather than the row's. |
| high | A contact merge changed consent **invisibly** | `Contacts::ConsentMerge` writes `commerce_messaging_consents` directly and carried neither the revision bump nor the feed publish. A base contact granted at revision 80, merged with a duplicate holding the shopper's STOP, flipped to withdrawn and stayed "subscribed" on the merchant's screen permanently — reconciliation reports the revision the partner already holds. |
| high | A manual grant's **expiry** was read as a billing cycle | `current_period_end` is nil for a comp, the default of `grant_plan`. Re-granting the same plan with an expiry moved the anchor from the calendar month to (expiry − 1 month): a different counter key, so a full fresh allowance in the same calendar month, reissuable by toggling the expiry. |
| high | An empty `200` was read as a broken body | Lynomia answers `head :ok` — zero bytes — for deleting a template, an audience, a campaign, a flow and an automation rule. `LynomiaHttp` threw `KIND_INVALID_RESPONSE`, the BFF answered 503, and the merchant got a red banner over work that had succeeded with the deleted row still in the list. The double emitted `Http::response([], 200)`, which is the two bytes `[]`. |
| high | The dashboard preview asked a different question from the send | `audiencePreview` sent only the audience, so every preview resolved as SMS and as promotional. A WhatsApp campaign on an approved UTILITY template was previewed against a grant requirement its send does not have. |
| high | A cancelled campaign read as **"Scheduled"** in amber | The card knows only completed/processing/blocked; everything else falls through. `mark_processing!` refuses a cancelled campaign, so it waited there forever. |
| medium | Cancelling a **blocked** campaign erased why it was blocked | `blocked_reason` was overwritten with `reason.presence` — nil when the box is left empty — and the `:feature_disabled` Operations signal is resolved only by `unblock!`, which refuses anything no longer `blocked?`. There is no un-cancel, so the feed asserted a capability problem forever. |
| medium | The Results button was hidden for a cancelled campaign | The recipient snapshot is the entire reason a campaign is cancelled rather than deleted. |

Every one of the eleven was proved by reverting the fix and watching the new coverage go red.

**Three fixtures were passing for the wrong reason** and are corrected rather than relaxed:
the throttle example paced three recipients it sent nothing to, because the channel held no
template the campaign named — and its Meta stub answered the same message id every time,
which collides with the unique index on `campaign_recipients.source_id` the moment real sends
happen; the Store Syria campaign test that asserted an account-wide fallback was written
against an installation that had never been registered, so it could not see the case it
described; and the HTTP double sent `[]` where Rails sends nothing.

---

## 6. Tests actually executed

Every number below was produced by a run in this environment against the commits in §8, with
both working trees clean. Nothing is projected and nothing is carried over from an earlier
run.

| Suite | Result |
|---|---|
| Lynomia — **full RSpec sweep**, frozen tree | **11,211 examples, 0 failures, 70 pending** (47m19s); the 70 pending are the single MFA/encryption guard, as in every sweep since Phase 4 |
| Lynomia — quota concurrency, real threads, non-transactional, barrier-forced | 2 examples, 0 failures |
| Store Syria — full Feature suite against the known-outcome gate | **1,884 tests, 12,515 assertions**; 8 declared failures, 17 declared skips, 0 errors, **nothing undeclared** |
| Store Syria — Unit suite | 181 tests, 1,035 assertions, 0 failures |
| Store Syria — `--filter Lynomia` | 445 tests, 5,003 assertions, 0 failures |
| Store Syria — Contract suite (two real producer artifacts, real ingress) | 14 tests, 43 assertions, 0 failures |
| Store Syria — browser, 8 suites | **189 checks, 0 failures** |
| Lynomia — Vitest | 503 files, 5,428 tests, 0 failures |

The Store Syria failures and skips are the pre-existing declared set; the gate asserts the
**set by name**, so a new failure and a fixed-but-still-declared one both break it.

**Both contract artifacts are byte-identical across the two repositories**, verified by
sha256 at the commits in §8:

| Artifact | sha256 | Pinned in |
|---|---|---|
| `consent_changed.v1.json` | `05183533…7bb8373` | `spec/contracts/consent_changed_contract_spec.rb`, `tests/Contract/LynomiaConsentContractTest.php` |
| `audience_fields.v1.json` | `7ca6f190…f0889a57a11` | `spec/contracts/audience_fields_contract_spec.rb`, `tests/Contract/LynomiaAudienceFieldsContractTest.php` |

**Three earlier sweeps were discarded rather than reported.** Editing a file under `custom/`
while RSpec runs makes Zeitwerk reload the constant mid-run, which produces
`uninitialized constant` and `expected X::Error, got #<X::Error>` failures that are artifacts
of the edit and not of the code. One such run reported 86 failures; re-running five of its
files on the frozen tree gave 58 examples and 0 failures. The rule adopted after the third
one — **never edit either repository while a sweep is running** — is why the numbers above
are from a tree that did not move.

## 7. Adversarial review

Two rounds.

**Round one, during the build.** Eight dimensions (tenancy, authorization, consent,
concurrency, data correctness, failure modes, contract drift, UI and i18n), each finding fed
to independent verifiers defaulting to refuted. **52 candidates, 7 survived.** All seven are
fixed in §5.1–§5.4.

**Round two, re-verifying the six defects the first round reported fixed.** 45 agents, each
defect given its own prober and its own panel of verifiers instructed to refute. The verdict
was **PARTIALLY_CORRECTED on all six** — not one came back clean — with **19 residual holes
surviving verification**, four of them critical. Eleven are fixed in §5.8; the other eight
are in §9.1 and §9.2.

Two findings in round two were **right about the problem and wrong about its mechanism**;
the verifiers said so, named the real path, and the findings were kept with the reasoning
corrected rather than discarded on a technicality. That is the same pattern as round one,
and it is the argument against treating a refuted mechanism as a refuted defect.

**What this says about the earlier "fixed" claims.** A defect reported corrected in §5 was,
in every one of the six cases, corrected along the path the original report described and
left open along at least one other. The sweep found a seventh the review did not: a quota
key derived from thread-local state. The lesson the owner should take from this section is
not that the fixes were wrong, but that a fix verified only against the path that produced
the bug report is a fix of unknown extent.

## 8. Commits

The numbers in §6 were produced against these two heads, with both working trees clean.

**Lynomia** (`claude/store-syria-lynomia-integration-1oa1tt`), head **`1d797ed9`**

Phase 5 build and first-round fixes:
`5fde4281`, `3331d245`, `6054da6d`, `241f623f`, `8ee0ce70`, `597b8ce4`, `3874fa4b`,
`fd5ee925`, `5b1f269e`, `e11d941c`, `2a47bbae`, `b1f4d7b7`

Final verification pass (§5.8):

| Commit | What |
|---|---|
| `756e055c` | the campaign's class comes from the provider's synced template list, step 8 is satisfied by a grant from either store, and the usage period is keyed by data rather than by the reader's time zone |
| `21aa5029` | the eleven critical and high residuals the re-review confirmed |
| `1d797ed9` | the dashboard preview carries the channel and the template, so it asks the send's question |

**Store Syria** (same branch), head **`8cd1e866`**

Phase 5 build and first-round fixes:
`b9b49501`, `ed426f45`, `9fd26f27`, `6f4b026e`, `44885342`, `628d5003`, `559ab910`,
`24cc4297`, `344fcd2d`, `7188a0d6`

Final verification pass (§5.8):

| Commit | What |
|---|---|
| `8cd1e866` | a campaign for a registered-but-unavailable installation is refused rather than silently widened to the account, and an empty `200` is read as success rather than as a broken body |

Both branches are pushed. **Nothing was merged and nothing was deployed.**

## 9. What is still open

**A1 is unchanged and is still the condition on this phase.**

| Id | What | Who can close it |
|---|---|---|
| **A1** | No run against a deployed Lynomia has ever happened | the owner, with a non-production deployment of both sides and a peer-to-peer test |

Everything in both repositories is switched **off** as deployed —
`LYNOMIA_INTEGRATION_ENABLED`, `STORESYRIA_ENABLED`, `whatsapp_campaign`, `lynomia_commerce`
and every payment gateway. No Lynomia-facing path has executed in this environment, and no
production credential was created, rotated or activated. Nothing in this phase was merged or
deployed, and `.env`, `APP_KEY` and every production credential are untouched.

**A1 is the right blocker and this phase is the reason it matters.** Four features passed
every test on both sides and would have failed on first contact with a real deployment,
because the two sides' fakes agreed with their own authors. Two cross-repository artifacts
now close part of that gap; the rest of the surface is not covered, and a staging run is the
only thing that finds the next one.

### 9.1 Residuals the re-review confirmed and this pass did not close

Each survived independent verification, each has a traced path from a real caller, and none
is fixed. They are listed so that "Phase 5 complete" is not read as "nothing is owed". The
ids are the register's: `PRODUCTION-READINESS-BLOCKERS.md` §B2 carries the same items with
what would close each one.

| Id | Severity | What | Where |
|---|---|---|---|
| **B2.1** | medium | A **transactional** store-scoped campaign reaches every contact in the account the labels select, including people who have never been that store's customer. `Partners::Eligibility.subject_of?` checks only `contact.account_id == store.account_id` and never a `Commerce::CustomerLink`; `filter` has no subject check at all. For a transactional purpose neither side requires a grant, so nothing else narrows it. Two docblocks state the opposite, and `recipient_snapshot` labels `:partner_scope_mismatch` "Not a customer of this store". | `custom/app/services/partners/eligibility.rb:50` |
| **B2.2** | medium | The qualified store identifier is recomputed from a mutable URL on every call rather than persisted. `installation_ref` falls back to the host of `APP_URL`; Lynomia **persists** the resulting string in `commerce_stores.external_store_id`. A domain change, or one deploy target without `LYNOMIA_INSTALLATION_REF`, moves every answer at once: outbound decisions 404 and are retired, reconciliation 404s, and inbound events are rejected as another installation's. The integration then fails closed and silently on all three paths. | `packages/workdo/LynomiaChat/src/config/lynomia.php:219` |
| **B2.3** | medium | A first-time shopper's checkout opt-in can be lost on its first attempt. Lynomia answers 404 when no `Commerce::CustomerLink` exists for the subject yet — the ordinary race when the opt-in is dispatched before the order event that creates the link — and `LynomiaApiException` classes 404 as non-transient, so `ConsentDecisionDispatcher` retires it as `rejected` with no retry. The reconciler's drift count then skips the scope, so the operator-facing number stays 0. | `.../Services/ConsentDecisionDispatcher.php:130` |
| **B2.4** | medium | Cancelling a campaign stops its sends but does not stop its **job**. `SendGuard` refuses each remaining recipient, and the sender loops over the whole remaining audience: a 100k-recipient WhatsApp campaign cancelled a minute in writes ~99k skip rows and issues ~200k queries before `completed!` is reached and refused. No early break. | `custom/app/services/custom/whatsapp/oneoff_campaign_service.rb` |
| **B2.5** | medium | A merchant whose WhatsApp inbox uses the `default` provider (360dialog) has no `business_account_id` in `provider_config`, so the WABA picker on the templates page is empty — the headline symptom of defect #18, for a whole provider class — and the page offers no explanation: the New button is never disabled and saving yields only "Choose which WhatsApp number this template belongs to." | `.../resources/views/merchant/templates.blade.php:354` |
| **B2.6** | low | There is no cancel affordance in Lynomia's own dashboard: `dashboard/api/campaigns.js` defines no `cancel`, and nothing under the campaigns routes references one. Cancelling is reachable only from the Store Syria page or the raw API. | `app/javascript/dashboard/api/campaigns.js` |

### 9.2 Functional gaps found while verifying, which are feature work rather than defects

| Id | What | Why it is not fixed here |
|---|---|---|
| **B2.7** | **A WhatsApp campaign created from Store Syria sends to nobody.** The BFF accepts and forwards `template_params`, but the merchant campaign modal never sends it — it posts title, message, audience, schedule and inbox only. `Whatsapp::OneoffCampaignService#process_contact` returns early for every recipient when `template_params` is blank, so the campaign completes having sent nothing. The merchant's own template manager page exists; the campaign form has no picker wired to it. | A picker is not the whole of it: a WhatsApp template has variables, so the form also needs to collect this campaign's `processed_params`. That is a UI design question, not a correction. |
| **B2.8** | `Campaigns::Eligibility.excluded_counts` — the "400 of 1,000, and here is why" breakdown — has **no production caller**. It is written, tested and unreachable. | Either wire it to the preview surface or delete it; both are decisions about what the merchant should see. |
| **B2.9** | Four copies of "find this campaign's template in the channel's synced list" now exist: `Whatsapp::TemplateProcessorService#find_template`, `Whatsapp::AuthenticationTemplateGuard#template`, `Campaigns::RenderedTemplateText#template` and `Campaigns::Eligibility#category_for`. They differ deliberately — the first requires `approved`, the others do not — but four is a drift hazard. | Consolidating means editing two upstream OSS files; the enterprise guidance prefers an extension point, which is a design decision. |

Full register: `PRODUCTION-READINESS-BLOCKERS.md`.
