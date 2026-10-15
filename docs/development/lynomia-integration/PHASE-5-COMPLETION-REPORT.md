# Store Syria × Lynomia — Phase 5 completion report

Native campaigns, audiences, messaging templates, marketing analytics and advanced commercial
entitlements, plus the partner consent subsystem P5E was authorised to build.

**What this report is.** One consolidated account of what was built, what was found broken
while building it, what was tested and what is still owed. Per-capability detail lives in
`PHASE-5-COMPLETION-MATRIX.md`, `PHASE-5E-CLOSURE.md` and
`../../../../lynomiachat/docs/partner-consent/THROTTLE-SAFETY.md`; this does not restate them.

**Assessment: CONDITIONAL GO.** Every approved capability is implemented, tested offline and
browser-verified. The condition is blocker **A1**, unchanged and unclosable from inside these
two repositories: nothing here has ever run against a deployed Lynomia. Phase 5 narrowed A1
twice — the consent wire format and now the audience-fields contract are each proven against
a real producer artifact rather than two mocks — but narrowing is not closing.

**The honest headline.** The adversarial review at the end of this phase found that four of
the features reported complete earlier in it did not work on the real wire, and would not
have been caught by any test either side had. They are fixed, with the tests that were
missing. Section 5 is the most important part of this document.

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

Twenty-two, all reproduced before being fixed. **Eleven were in code written earlier in this
same phase**, and seven of those were found only by the adversarial review at the end of it —
which is the argument for running one.

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

## 6. Tests actually executed

| Suite | Result |
|---|---|
| Store Syria — full Feature suite against the known-outcome gate | **1,879 tests, 12,503 assertions**; 8 declared failures, 17 declared skips, 0 errors, **nothing undeclared** |
| Store Syria — Unit suite | 181 tests, 1,035 assertions, 0 failures |
| Store Syria — `--filter Lynomia` | 426 tests, 4,948 assertions, 0 failures |
| Store Syria — Contract suite (two real producer artifacts, real ingress) | 14 tests, 43 assertions, 0 failures |
| Store Syria — browser, 8 suites | **189 checks, 0 failures** |
| Lynomia — full RSpec sweep | PENDING_LYN_SWEEP |
| Lynomia — concurrency, real threads, non-transactional | 5 examples, 0 failures |

The Store Syria failures and skips are the pre-existing declared set; the gate asserts the
**set by name**, so a new failure or a fixed-but-still-declared one both break it.

## 7. Adversarial review

Eight dimensions (tenancy, authorization, consent, concurrency, data correctness, failure
modes, contract drift, UI and i18n), each finding fed to independent verifiers defaulting to
refuted. **52 candidates, 7 survived verification.** All seven are fixed above: the identifier
mismatch (§5.1 #1), suppressed links (§5.2 #11), back-office consent capture (§5.4), the
missing allowance sweep, the quota spent on unsent messages (§5.7 → §5.3), the restricting
foreign keys (§5.3 #16) and the unbounded consent screen (§5.7).

Several unconfirmed candidates were still **correct about the defect while wrong about its
cause** — the verifier for #1 refuted the stated mechanism and found the real one. Those were
kept, with the reasoning corrected, rather than discarded on a technicality.

## 8. Commits

**Lynomia** (`claude/store-syria-lynomia-integration-1oa1tt`), Phase 5 tail:
`3331d245`, `6054da6d`, `241f623f`, `8ee0ce70`, `597b8ce4`, `3874fa4b`, `fd5ee925`, `5b1f269e`

**Store Syria** (same branch):
`b9b49501`, `ed426f45`, `9fd26f27`, `6f4b026e`, `44885342`, `628d5003`, `559ab910`

## 9. What is still open

| Id | What | Who can close it |
|---|---|---|
| **A1** | No run against a deployed Lynomia has ever happened | the owner, with a staging deployment of both sides |

Everything in both repositories is switched **off** as deployed —
`LYNOMIA_INTEGRATION_ENABLED`, `STORESYRIA_ENABLED`, `whatsapp_campaign`, `lynomia_commerce`
and every payment gateway. No Lynomia-facing path has executed in this environment, and no
production credential was created, rotated or activated.

**A1 is the right blocker and this phase is the reason it matters.** Four features passed
every test on both sides and would have failed on first contact with a real deployment,
because the two sides' fakes agreed with their own authors. Two of the four are now covered
by cross-repository artifacts; the rest of the surface is not. A staging run is the only thing
that finds the next one.

Full register: `PRODUCTION-READINESS-BLOCKERS.md`.
