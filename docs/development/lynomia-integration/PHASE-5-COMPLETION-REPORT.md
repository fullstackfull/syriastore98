# Store Syria × Lynomia — Phase 5 completion report

Native campaigns, audiences, messaging templates, marketing analytics and advanced commercial
entitlements, plus the partner consent subsystem P5E was authorised to build.

**What this report is.** One consolidated account of what was built, what was found broken
while building it, what was tested and what is still owed. Per-capability detail lives in
`PHASE-5-COMPLETION-MATRIX.md`, `PHASE-5E-CLOSURE.md` and
`../../../../lynomiachat/docs/partner-consent/THROTTLE-SAFETY.md`; this does not restate them.

**Assessment: CLOSED, CONDITIONAL ON A1.** Every approved capability is implemented and
tested offline, the merchant surfaces are browser-verified in English and Arabic, and every
residual the three adversarial rounds confirmed — B2.1 through B2.9, the capability gap
among them — is fixed. §10 is the closure assessment and is the part of this document to
read first. The condition is blocker **A1**, unchanged and unclosable from inside these two
repositories: nothing here has ever run against a deployed Lynomia. Phase 5 narrowed A1
twice — the consent wire format and the audience-fields contract are each proven against a
real producer artifact rather than against two mocks — but narrowing is not closing.

**The honest headline, in three parts.**

The adversarial review at the end of the build found that four features reported complete
earlier in the phase did not work on the real wire, and would not have been caught by any
test either side had. §5.1–§5.4 are those, and they are fixed.

Then the **final verification pass** — the full RSpec sweep and an adversarial
**re**-verification of the six defects that review had reported fixed — found more. The
re-review returned **PARTIALLY_CORRECTED on all six**: every one had been corrected along
the path its bug report described and left open along at least one other. Nineteen residual
holes survived independent verification, four of them critical. The sweep itself found a
seventh defect the review did not — a quota key derived from thread-local state. §5.8 is
that pass.

Then a **third round**, run against the second round's fixes on the owner's instruction to
close the residuals before opening the next phase. It confirmed **15 more findings, every
one of them in code written during this verification pass**, 13 in the two commits
immediately preceding it. All 15 are fixed. §10 carries that round and the closure
assessment; §9 now lists **A1 alone**.

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
- `campaign_partner_audience_spec` — 8 here, and 15 in all once the closure pass added the
  customer-linked members (§10.1), ending in an agreement example: eleven contacts in every
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
| Lynomia — **full RSpec sweep**, frozen tree, at the final head `c0c1a06a` | **11,244 examples, 0 failures, 70 pending** (37m58s) |
| Lynomia — full RSpec sweep, frozen tree, at `3402f299` | 11,242 examples, 0 failures, 70 pending (44m47s) |
| Lynomia — full RSpec sweep, frozen tree, at `2eaca095` | 11,242 examples, 0 failures, 70 pending (45m51s) |
| Lynomia — quota concurrency, real threads, non-transactional, barrier-forced | 2 examples, 0 failures |
| Store Syria — full Feature suite against the known-outcome gate | **1,896 tests, 12,545 assertions**; 8 declared failures, 17 declared skips, 0 errors, **nothing undeclared** |
| Store Syria — Unit suite | 181 tests, 1,035 assertions, 0 failures |
| Store Syria — `--filter Lynomia` | **457 tests, 5,033 assertions**, 0 failures |
| Store Syria — Contract suite (two real producer artifacts, real ingress) | 14 tests, 43 assertions, 0 failures |
| Store Syria — browser, 8 suites | **203 checks, 0 failures** |
| Lynomia — Vitest | **504 files, 5,434 tests, 0 failures** |

Three full sweeps are listed because the tree moved twice. The middle two report the same
example count because `3402f299` changes one Vue component and no Ruby, so it adds no RSpec
example — and it added no Vitest example either, which the audit in §10.4 caught and
`c0c1a06a` fixed: the two behaviours it changed now have examples, and both go red without
it. The final sweep's +2 are the Twilio cancellation examples from the same commit. The 70 pending in every Lynomia sweep are the single
MFA/encryption guard, as they have been since Phase 4. The example count rose from 11,211 to
11,242 across this pass because the closure work added regression coverage; no example was
deleted, skipped or marked pending to reach a green sweep.

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

Three rounds. The first two are below. The third — run against the second round's fixes,
which is where it found all fifteen of its confirmed findings — is in §10.2.

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

The numbers in §6 were produced against these two heads, with both working trees clean. The
commit that adds this section and §10 is a documentation-only commit made after those runs.

**Lynomia** (`claude/store-syria-lynomia-integration-1oa1tt`), head **`c0c1a06a`**

Phase 5 build and first-round fixes — **26 commits, `88630724..b1f4d7b7`**. The last twelve:
`5fde4281`, `3331d245`, `6054da6d`, `241f623f`, `8ee0ce70`, `597b8ce4`, `3874fa4b`,
`fd5ee925`, `5b1f269e`, `e11d941c`, `2a47bbae`, `b1f4d7b7`. The fourteen before them are the
P5B–P5G build and the partner-consent subsystem, the producer half of the contract artifact
§6 pins among them; they are cited per capability in `PHASE-5-COMPLETION-MATRIX.md`. This
list named twelve of twenty-six until the audit in §10.4 counted them.

Final verification pass (§5.8):

| Commit | What |
|---|---|
| `756e055c` | the campaign's class comes from the provider's synced template list, step 8 is satisfied by a grant from either store, and the usage period is keyed by data rather than by the reader's time zone |
| `21aa5029` | eight of the eleven residuals the re-review confirmed, critical through medium. The other three are Store Syria's and `1d797ed9`'s, below |
| `1d797ed9` | the dashboard preview carries the channel and the template, so it asks the send's question |

Closure pass — the owner's instruction to close B2.1–B2.9 before opening Phase 6, and the
third adversarial round it produced (§10):

| Commit | What |
|---|---|
| `8b7fa347` | B2.1, B2.3, B2.4: the installation scope enforced in **both** expressions, the subject race made retryable, and a cancelled campaign's senders stopped rather than drained. B2.2 is Store Syria's and is in `e6b87274` |
| `f6934c44` | B2.6, B2.8, B2.9, and the consent and quota holes the third round found — the automation template action, the flow Send-template node, and the two cycle cases `manual?` could not tell apart |
| `2d1e8730` | the remaining third-round findings: the capability signal on destroy, the second stop control, and the change feed publishing after commit |
| `2eaca095` | a consent merge republishes the decision that governs the subject, not only the one it changed |
| `3402f299` | the campaign preview runs on a form seeded from a draft, and a stale in-flight answer cannot overwrite a newer one |

Correction pass — the fourth round, which audited the claims above rather than the code (§10.4):

| Commit | What |
|---|---|
| `c0c1a06a` | the three gaps that round found in Lynomia: B2.4's uncovered third sender, a B2.9 agreement example that ran no lookup at all, and the two Vitest specs this report had already cited |

**Store Syria** (same branch), head **`a090237a`**

Phase 5 build and first-round fixes — **16 commits, `c9476304..7188a0d6`**, oldest first:
`c9476304`, `ab610fa2`, `c984d268`, `79dcdd68`, `02a427bf`, `559ab910`, `628d5003`,
`44885342`, `6f4b026e`, `9fd26f27`, `ed426f45`, `b9b49501`, `6f86127a`, `24cc4297`,
`344fcd2d`, `7188a0d6`. This list named ten of sixteen until the audit in §10.4 counted
them; the six it omitted include `c984d268`, the consumer half of the contract §6 pins.

Final verification pass (§5.8):

| Commit | What |
|---|---|
| `8cd1e866` | a campaign for a registered-but-unavailable installation is refused rather than silently widened to the account, and an empty `200` is read as success rather than as a broken body |

Closure pass:

| Commit | What |
|---|---|
| `f7f5d971` | the Phase 5 final verification report, with the numbers the runs produced |
| `c2f0a19b` | the warning that the obvious fix for B2.1 opens a worse hole than the one it closes |
| `e6b87274` | B2.2 and B2.3: the store identity persisted on `lynomia_store_links` rather than recomputed from a mutable URL, and `subject_not_linked` classed transient |
| `02fd9d25` | **B2.7, the capability gap**: a WhatsApp campaign carries an approved template and this campaign's placeholder values, and a number that cannot hold a template says so |
| `f8a3949b` | the installation is proved by what Lynomia returned, not by a local setting that is set before registration and cleared while Lynomia still holds the install |
| `305e8e99` | editing a campaign is guarded as creating one is, and a raw `FEATURE_DISABLED` no longer reaches a merchant |

Correction pass (§10.4):

| Commit | What |
|---|---|
| `b1d8af1c` | this report's §10, the register's §C and the Phase 6 proposal |
| `a090237a` | **B2.7's scoping**: a campaign may only name a template the number it sends from can actually send, and the nineteen documentation claims the fourth round confirmed wrong |

Both branches are pushed. **Nothing was merged and nothing was deployed.**

## 9. What is still open

**A1 is unchanged and is still the condition on this phase.** B2.1 through B2.9 were closed
in the closure pass; §10.1 says what closing each one involved, and the register records
them under `PRODUCTION-READINESS-BLOCKERS.md` §C.

**Two new items were opened by the audit in §10.4, and neither is a Phase 5 regression** —
both are pre-existing and were found by looking rather than by a failure. They are
`B3.1` (the agent composer sends an approved MARKETING template with no consent check) and
`B3.2` (a consent decision waiting on a subject link is retried without a ceiling and
nothing prunes it). The register carries both with a traced path and a proposed fix.

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

### 9.1 The residuals, and what closed each one

All nine are closed. Each fix was proved by reverting it and watching the new coverage go
red before putting it back — though a fourth round, auditing these claims rather than the
code, found two of them resting on coverage that did not exist and one not closed as far as
it says (§10.4). Those are fixed and the rows below are the corrected state. §10.1 carries
the reasoning; this is the index.

| Id | Was | Closed by | Regression coverage |
|---|---|---|---|
| **B2.1** | a transactional store-scoped campaign reached contacts who were never that installation's customers | the subject check added to **both** `refusal` and `filter`, excluding a contact linked to another of the account's stores and not to this one | `spec/models/campaign_partner_audience_spec.rb`, population extended with the two customer-linked members that tell the two expressions apart |
| **B2.2** | the qualified store identifier recomputed from a mutable URL on every call | `lynomia_store_links.commerce_external_store_id`, written on first use with exactly the value the old code produced | `tests/Feature/Lynomia/LynomiaStoreIdentityTest.php` |
| **B2.3** | a first-time shopper's opt-in retired as `rejected` on the ordinary subject race | Lynomia sends `error_code: subject_not_linked`; Store Syria classes that one 404 transient | consent dispatcher specs, both sides |
| **B2.4** | a cancelled campaign's sender drained the whole remaining audience | the senders stop — `break` in **all three** SMS and WhatsApp loops — and one `UPDATE` records the remainder | `spec/services/campaigns/cancellation_stops_the_run_spec.rb`, covering all three senders since §10.4 |
| **B2.5** | a 360dialog number offered template creation that could not work | the page says so, once, and New is not offered | browser suite `p5c-templates` |
| **B2.6** | no cancel affordance in Lynomia's own dashboard | a cancel control behind a confirm dialog that says what cancelling is **not** | `specs/ConfirmCancelCampaignDialog.spec.js`, added in §10.4 — the row claimed Vitest before one existed |
| **B2.7** | **the capability gap** — a WhatsApp campaign from Store Syria sent to nobody | the campaign modal asks for a template approved **on the number the campaign sends from**, renders one input per BODY placeholder, refuses to save without them, and sends `{name, language, processed_params}` | `--filter Lynomia`, browser suite `p5d-campaigns` |
| **B2.8** | `excluded_counts` written, tested and unreachable | removed | n/a — deletion |
| **B2.9** | four copies of "find this campaign's template in the channel's list" | the two in `custom/` are one; a spec holds the remaining three to the same row for the same inputs | `spec/services/whatsapp/channel_templates_spec.rb` |

### 9.2 What is deliberately not done

| What | Why |
|---|---|
| A1 closed | it cannot be closed from inside these repositories. It needs a non-production deployment of both sides and a peer-to-peer run, which is the owner's to schedule |
| Phase 6 started | the owner's instruction is to submit a scope for review first. Nothing of Phase 6 is built |
| Anything merged, deployed, or switched on | the standing restrictions in `PRODUCTION-READINESS-BLOCKERS.md` §D. `.env`, `APP_KEY` and every production credential are untouched |

Full register: `PRODUCTION-READINESS-BLOCKERS.md`.

---

## 10. Phase 5 closure

**Assessment: CLOSED, CONDITIONAL ON A1.** Every approved capability is implemented and
tested offline, the merchant surfaces are browser-verified in English and Arabic, and every
residual the four adversarial rounds confirmed is fixed. The condition is unchanged and
unclosable from inside these repositories: nothing here has ever run against a deployed
Lynomia.

**Two items are open that were not Phase 5's**, both found by the fourth round reading code
rather than by any failure: B3.1, the agent composer asking nothing about consent, and
B3.2, a held consent decision retried without a ceiling. Both are pre-existing, both are in
the register with a traced path and a proposed fix, and B3.1 is deliberately not fixed here
because the safe fix is a policy decision rather than a correction (§10.4).

### 10.1 What closing B2.1–B2.9 actually involved

The owner approved closing the eight confirmed residuals and the capability gap before
opening the next phase. All nine are closed, and each fix was proved by reverting it and
watching the new coverage go red. Three of the rows below are the **corrected** state: a
fourth round audited these claims against the code and found B2.7 not closed as far as it
said, and B2.4 and B2.6 resting on coverage that did not exist (§10.4).

| Id | Closed by |
|---|---|
| B2.1 | The installation scope is enforced in BOTH expressions — `refusal` and `filter` — excluding a contact linked to another of the account's stores and not to this one. NOT "has a link to this store": a chat-only contact has no link anywhere, and a Store Syria merchant can create nothing but store-scoped campaigns, so that reading would put their own contacts out of reach. The agreement population gained the two members that can tell the two expressions apart; as written, every contact in it belonged to the campaign's account, so the subject check was true for all of them |
| B2.2 | `lynomia_store_links.commerce_external_store_id` holds the identifier, written on first use with exactly the value the old code produced. A deliberate change now requires updating Lynomia's own row, which is the conversation the column forces instead of a silent outage |
| B2.3 | Lynomia sends `error_code: subject_not_linked` on that 404 and only that one; Store Syria classes it transient, so the opt-in is **held rather than retired** instead of being thrown away on its first attempt. It lands when the shopper becomes a known contact — auto-matched on their first WhatsApp or SMS conversation, or linked by an agent. **No order event creates that link**, which this row claimed until §10.4 checked it, and nothing caps the retries in the meantime: see B3.2 in the register |
| B2.4 | The senders stop — `break` in the two SMS loops, `throw`/`catch` past upstream's loop for WhatsApp — and one UPDATE records the remainder, which a row left `queued` would otherwise report as a run still in progress |
| B2.5 | A workspace with no WhatsApp Cloud number is told so, once, and New is not offered when it cannot work |
| B2.6 | Cancel exists in Lynomia's own dashboard, behind a confirm dialog that says what cancelling is not |
| B2.7 | **The capability gap is closed.** The campaign modal asks for a template approved **on the number the campaign sends from**, renders one input per placeholder in its BODY, refuses to save without them, and sends `{name, language, processed_params}` — but no category, because the server reads that from the channel's synced list. The scoping is not a detail: filtering on Meta's status alone offered every approved template in the workspace, and the sender resolves one only in the chosen inbox's own list, so a two-number workspace could pick a name the send cannot find and complete having sent nothing — B2.7 again, one level down. §10.4 found that; it is fixed and red-proved |
| B2.8 | `excluded_counts` removed rather than left unreachable |
| B2.9 | The two lookups in `custom/` are one, so three remain and a spec holds them to the same row for the same inputs. It claimed four until §10.4 pointed out that two of them are now the same function, and that its authentication-guard example passed a phone number to a guard that returns at its first line for anything but a business id — so that example ran no lookup at all. Both are fixed |

**The B2.1 warning was real.** Changing `subject_of?` alone would have made the audience
WIDER than the send boundary — a campaign reporting it reached people it refused, which is
worse than the over-inclusion B2.1 described. Both expressions changed together.

### 10.2 The third adversarial round

Run against the fixes of the second, because the second had found every fix of the first to
be partial. 67 agents, 42 verdicts, **15 findings survived verification** — and all 15 were
in code written during this verification pass, 13 of them in the two commits immediately
before it.

All 15 are fixed. The ones that matter most:

- **Two consent bypasses the campaign fix had never been applied to** — two of the
  automated ones. A third surface, the agent composer, asks nothing either; §10.4 found it
  and the register carries it as B3.1, because closing it is a policy decision rather than a
  correction. The automation
  template action took the message class from the RULE'S TRIGGER, so an approved MARKETING
  template on an order trigger was transactional and went to a shopper who had withdrawn
  marketing — a pairing an admin can make in two clicks, since `send_whatsapp_template` is
  offered on every rule and the picker offers every approved non-AUTHENTICATION template.
  The flow Send-template node asked nothing at all.
- **Two quota holes in this pass's own fix.** `manual?` tests who wrote the subscription
  row, not whether a cycle is running: `extend_trial` writes `source: 'stripe'` and
  `status: 'trialing'` while leaving `current_period_end` alone, so one button reissued a
  comp's whole allowance. The mirror case is a `canceled` subscription whose dead period
  pinned an account to one allowance forever.
- **The registration marker was wrong in both directions.**
  `commerce_credential_issued_at` means "an operator minted a token" — set BEFORE
  registration is possible, and cleared on revoke while Lynomia still holds the
  installation. It refused merchants mid-onboarding and widened campaigns after a revoke.
  It now reads what Lynomia actually returned.
- **Three more `head :ok` stubs sent a body Rails does not**, one of which means connecting
  a flow to a channel answered 503 in production on every attempt while the suite was green.
- The merge published one of two consent tables, and nothing at all on the path where the
  base already held the stronger decision — although the customer links have moved by then,
  so the decision governing that subject at a partner had changed.
- `destroy` stranded the capability signal forever (`unblock!` has no caller anywhere), the
  two signal writers raced, the builder honoured `cancelled?` but not `enabled`, `update()`
  was the way round the create guard, a raw `FEATURE_DISABLED` reached merchants, and the
  corrected preview never ran on a seeded form.

### 10.3 What three rounds say

Round one found 7 of 52 candidates real. Round two found all six of round one's fixes
PARTIALLY_CORRECTED, with 19 residuals. Round three found 15 more, every one of them in
round two's fixes. Round four, in §10.4, audited this report instead and found 19 of its
claims wrong — three of them because the code was not as closed as the claim.

The pattern is not that the fixes were wrong. Each was correct along the path its bug report
described. The pattern is that **a fix verified only against the path that produced the
report is a fix of unknown extent** — and that this codebase's two sides agree with each
other offline in ways that say nothing about a deployment. That is the argument for A1,
four rounds deep, and it is why this report does not claim more than offline verification.

### 10.4 The fourth round, which audited this report rather than the code

Everything above is a claim about code. The fourth round checked the claims: nine auditors,
one per cluster, each required to quote the code that contradicts a sentence, and every
objection then sent to a verifier instructed to refute it and to default to "the auditor
misread". **36 alleged discrepancies, 19 survived.**

It is the cheapest round that has been run and it found the most embarrassing class of
problem, because this is the first round pointed at the report:

- **Three were code or coverage gaps, not wording.** B2.7 was not closed as far as it said —
  the modal filtered on Meta's status alone while the sender resolves a template only in the
  chosen inbox's own list, so a workspace with two WhatsApp numbers could pick a name the
  send cannot find. B2.4's fix changed three send loops and the suite covered two, so
  reverting the Twilio `break` left every example green. B2.6's row claimed Vitest coverage
  that did not exist, and neither did any for the preview fix in `3402f299`. All three are
  fixed, red-proved, and the rows above say so.
- **One was a causal story that is simply untrue.** B2.3's row said a deferred opt-in "lands
  when the order event creates the link". No order event creates a customer link anywhere in
  Lynomia; the only two writers are the conversation auto-match and an agent's manual link.
  The fix is still right — the opt-in is held rather than thrown away — but what it waits for
  is not what the report said, and nothing caps the wait. Now B3.2.
- **One was a surface nobody had counted.** "Consent is asked on every outbound surface" was
  false: the agent composer sends an approved MARKETING template with no consent check on the
  path. Now B3.1, and not fixed here deliberately — see below.
- **The rest were counting and attribution.** The commit lists named 12 of 26 and 10 of 16;
  one commit was credited with eleven residuals when it holds eight; a range label said
  B2.1–B2.4 for a commit that closes B2.1, B2.3 and B2.4; a spec was described as having nine
  contacts when it has eleven; the Phase 6 proposal said Lynomia has eight Super Admin
  controllers when it has 22, and that none concerns this integration when one administers
  the `PlatformApp` the integration authenticates as.

**Why B3.1 is recorded rather than fixed.** The composer is upstream OSS used by every
account on this fork, not only commerce ones, and `MessagingEligibility` refuses a
promotional send unless a grant exists — so gating the composer on `:promotional` would stop
every marketing template in every account with no consent rows, which is weakening working
functionality, not closing a hole. The narrow version (refuse only on an explicit withdrawal
or suppression) is safe and is what the register proposes, but it needs an agent-facing
error, its copy and its translations, and a decision about whether an agent may override.
That is the owner's call, so it is named rather than taken.

**What four rounds say.** Round one found 7 of 52 candidates real. Round two found all six
of round one's fixes partial. Round three found 15 more, all in round two's fixes. Round
four found that the report describing those fixes overstated three of them. The lesson
compounds rather than repeating: a fix is of unknown extent until something adversarial
looks at it, and **so is a claim that a fix is finished**. The cheapest guard against the
second is to audit the report against the code before anyone relies on it, which is now a
step rather than an afterthought.
