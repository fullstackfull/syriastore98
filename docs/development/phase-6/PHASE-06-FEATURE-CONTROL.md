# Phase 06 §06-H — `CTRL-001` … `CTRL-004`, measured against the code and then built

**Created 2026-11-26.** Everything in §1 was executed or read from the code at the symbol cited.
Nothing here is taken from `MASTER-PLAN-REQUIREMENT-TRACEABILITY.md`, from
`MASTER-PLAN-GAP-REGISTER.md` or from a summary — the point of this page is that the trackers had
never looked at this family.

The approved text, verbatim, from `docs/development/master-plan/DECISION-ID-REGISTER.md:249-252`:

| id | approved text | English label |
|---|---|---|
| `CTRL-001` | تفعيل الميزات حسب الباقات والمتاجر والمجموعات التجريبية. | Feature enablement by **plan**, by **store**, by **pilot group** |
| `CTRL-002` | إطلاق تدريجي وإيقاف آمن. | **Gradual rollout** and **safe disable** |
| `CTRL-003` | سياسات عامة وإعلانات صيانة وسجل تغييرات. | Public policies, maintenance announcements, a **change log** |
| `CTRL-004` | عدم حذف بيانات ميزة بمجرد إيقاف إتاحتها. | Do **not** delete a feature's data merely because its availability was disabled |

**Tracker state before this work. Measured here, with the counting rule stated, because the same
facts can be reported three ways:**

| id | hits in `MASTER-PLAN-REQUIREMENT-TRACEABILITY.md` | hits in `MASTER-PLAN-GAP-REGISTER.md` |
|---|---|---|
| `CTRL-001` | **0** | 2 |
| `CTRL-002` | **0** | **0** |
| `CTRL-003` | **0** | **0** |
| `CTRL-004` | **0** | 1 |

**NOT ONE OF THE FOUR APPEARS IN THE TRACEABILITY MATRIX AT ALL** — that column is the finding, and
it is the same for all four.

The gap-register column needs its rule said out loud, or it reads as though two decisions were
tracked and two were not. The register's only scope row for this family is `GAP-199`, whose subject
cell is the **range** `CTRL-001 … CTRL-004` and whose whole entry is *"Not located. No feature-flag,
pilot-group or gradual-rollout mechanism. Phase 06 scope, not started. Expected absence."* So the
register does cover all four — the counts above differ only because a literal-string search finds
the two ids the range spells out and not the two it elides. Reported this way rather than as "two
appear and two do not", which would be true of the greps and false of the register.

**And that row is correct about the absence and wrong about one word.** `CTRL-004` was not "not
located": it **HELD**, on every availability path, and nothing asserted it. §4 is that distinction,
and it is the reason the family splits across four of the five buckets rather than sitting in one.

---

## 1. What existed, measured

### 1.1 `CTRL-001` — the three axes, one at a time

`CTRL-001` names three axes, and they must be measured separately: it is not satisfied by any one
of them.

| axis the decision names | mechanism in the code | keyed on | verdict |
|---|---|---|---|
| — (the whole platform) | `add_on_managers.is_enable`, read by `Module::allEnabled()` / `getByStatus()` and by `App\Support\Modules\ModuleAvailability::isDisabled()` | the module name, **one global row** | exists; not one of the three |
| **by plan** | `plans.modules` (a comma-joined list, `2024_04_10_031608_add_modules_column_in_plans_table`), expanded into `user_active_modules` by `User::assignPlan()` | the plan | **EXISTS**, in a different shape |
| by merchant | `user_active_modules.user_id` + `users.active_module`, read by `ActivatedModule()` / `activated_modules_for_owner()` | the **owner's** user id | exists; coarser than asked |
| **by store** | nothing | — | **ABSENT** |
| **by pilot group** | nothing | — | **ABSENT** |

**The store axis is absent, and "absent" is a measurement rather than a reading.** No migration in
this repository creates a table carrying both a `store_id` and a module or feature column. The
nearest thing is the merchant axis, and it cannot stand in: `user_active_modules` is keyed on the
tenant OWNER, so for a merchant who owns several stores it cannot enable a feature for one and not
its sibling. `FeatureControlResolutionTest::test_a_feature_can_be_enabled_for_one_store_and_not_
its_sibling` is that case, driven.

**The pilot-group axis has no spelling at all.** Measured over `app/`, `config/`,
`database/migrations/`, `routes/` and `resources/views/` at this branch's own base commit
(`e1c85aca5`), so no figure here can be inflated by this change's own files:

| term | hits | what they are |
|---|---|---|
| `pilot_group`, `pilot group` | **0** | — |
| `canary` | **1** | `Atlantic/Canary`, in `config/timezones.php` |
| `rollout` | **4** | 2 in `TokenInventoryReport` (a token-expiry rollout), 2 in `StoreScope` (a "blanket rollout" of a trait). **None is a feature rollout.** |
| `feature_flag` / `featureFlag` | **9** | all nine in `AppSettingController`, all the same five checkboxes — see below |

Those five are real and they are per-store: `AppSettingController::PANEL_OWNED_FEATURE_FLAGS` =
`cart_quantity_control_enable`, `enable_product_compare`, `enable_product_enquiry`,
`enable_product_barcode`, `enable_spin_to_win`, written as `settings` rows by `ThemeSettings()`. But
they are the **merchant's own** switches on their **own** store, with no plan dimension, no pilot
dimension, no percentage, and no operator control. They are not the capability `CTRL-001` asks the
platform operator for, and saying "feature flags exist" on the strength of them would be the kind of
half-true this family's tracker rows already contain.

### 1.2 `CTRL-002` — gradual rollout and safe disable

**Gradual rollout: absent entirely.** See the table above. A boolean per merchant is not a gradual
rollout; nothing in the tree reaches a subset of stores and then widens.

**Safe disable: partly present, and the present part is recent.** Two pieces exist, both from
earlier Phase 06 work on this branch, and both are about *modules* rather than about features:

- `App\Http\Middleware\DisabledModuleRouteGuard` + `App\Support\Modules\ModuleAvailability` —
  switching an application off now actually removes its routes.
  (`PHASE-06-DISABLED-APPLICATION-BOUNDARY.md`.)
- `App\Support\Modules\RemovableModule` — the uninstall door, which had no validation of any kind.
  (`PHASE-06-MODULE-REMOVAL-FINDINGS.md`, whose own opening paragraph names `CTRL-002`.)

Neither is a *feature*-level safe disable, and neither carries the properties §3.2 sets out.

### 1.3 `CTRL-003` — three limbs, and they are in three different states

| limb | state, measured |
|---|---|
| **public policies** | **the mechanism EXISTS.** `App\Support\Platform\PlatformContent::LEGAL_DOCUMENTS` carries five documents — terms of service, privacy notice, acceptable use, subscription/billing/refunds, handling customers' data — each a `markdown` field marked `owed => true`. `PlatformContentStore` stores them, `PlatformContentWriteGuard` restricts writing them to the operator, `platform/legal` publishes them. **The TEXT is the owner's and is deliberately not supplied.** |
| **maintenance announcements** | **ABSENT.** Laravel's own `PreventRequestsDuringMaintenance` is `php artisan down` — an outage, not an announcement, and not reachable from any operator screen. There is no announcement table, model, screen or banner. |
| **a change log** | **ABSENT, and named as outstanding by the code itself.** `PlatformContentStore`'s docblock says of the official-site editor: *"the editor records nothing about who changed what, and an audit trail is named as outstanding in the evidence document rather than implied here."* |

`audit_logs` exists and has a `record()` helper, but it is `IAM-004`'s — *"سجل تدقيق مركزي
واستجابة للحوادث"*, a central audit log for incident response, per-tenant and per-actor, carrying
`store_id`, `ip_address` and `user_agent`. **Measured here rather than quoted:** `AuditLog::record`
is called from **27** sites in `app/` and **59** across `app/` plus `packages/workdo/`. (The model's
own docblock says "18 existing call sites"; that figure is stale and is not relied on above —
it is cited here only so nobody reconciles the two by trusting the comment.) `CTRL-003`'s change
log sits in §06-H beside the feature flags and its subject is the platform's own configuration.
§3.3 says why they are not collapsed.

### 1.4 `CTRL-004` — the negative constraint

**The guarantee HELD on every availability path, and nothing asserted it.** Each path read, and
`truncate()` measured at **zero** occurrences across the whole of `app/` and `packages/workdo/`:

| path | what it writes | deletes? |
|---|---|---|
| `Module::disable()` (via `POST /modules-enable`) | `add_on_managers.is_enable` 1 → 0 | no |
| `User::assignPlan()` | `user_active_modules` rows; `users.is_active`, `stores.is_active` | availability only; over-limit staff and stores are **deactivated**, not deleted |
| `PlanController::update()` with fewer modules | `user_active_modules` for every holder of that plan | availability only |
| `AppSettingController::ThemeSettings()` | `settings` rows set to `'off'` | no |
| `AccountSuspension` | flags on `users`, `customers` | no |
| `ModuleController::remove()` | permissions, the add-on row, the package directory | **yes — and it is an UNINSTALL, not a disable** |

That last row is the one to keep straight. `CTRL-004` is about *إيقاف إتاحتها* — availability being
switched off. Deleting the application is a separate, deliberate operator act with its own guard,
and driving it as a `CTRL-004` violation would be asserting something the decision does not say.
`DisablingAvailabilityNeverDeletesDataTest`'s docblock records the exclusion so a later reader does
not "complete" the file by adding it.

**There is no scheduled pruning of anything.** `routes/console.php` schedules exactly four commands
— `zid:process-events`, `analytics:send-scheduled-reports`, `analytics:check-metric-thresholds`,
`marketing:detect-recovery-candidates` — and none of them deletes a feature's data.

---

## 2. The five-bucket classification

| id | bucket | why |
|---|---|---|
| `CTRL-001` | **MISSING FUNCTIONALITY** (two of three axes) + **MISSING VERIFICATION** (the plan axis existed and nothing asserted it as a *feature* control) | built — §3.1 |
| `CTRL-002` | **MISSING FUNCTIONALITY** | built — §3.2 |
| `CTRL-003` | **split three ways**: policies = **OWNER DECISION** (mechanism measured present, text is the owner's); maintenance announcements = **MISSING FUNCTIONALITY**; change log = **MISSING FUNCTIONALITY** | §3.3, §3.5 and §5 |
| `CTRL-004` | **MISSING VERIFICATION** | asserted — §4 |

**WHAT IS AND IS NOT CLOSED, counted rather than summarised** — because "built" is one of §4.1's
four states and not the others:

| | |
|---|---|
| decisions with engineering delivered | **4 of 4** (`CTRL-001`, `002`, `003`, `004`) |
| decisions fully closed by this change | **2** — `CTRL-002` and `CTRL-004` |
| decisions with an **OWNER DECISION** still open | **2** — `CTRL-001` (one question) and `CTRL-003` (three) |
| items needing **HUMAN ACCEPTANCE** | **3** — a pixel contrast reading, the RTL rendering, the banner's placement |
| items **EXTERNALLY BLOCKED** | **1** — applying the three migrations |

All of them are named individually in §5, so nothing here is a rounded figure. **No decision in
this family is accepted, and none is release-ready:** `CLAUDE.md` §4.1's four states are engineering
complete, original-requirement coverage, human acceptance and production readiness, and this change
moves the first two only.

---

## 3. What was built

### 3.1 `CTRL-001` — the two missing axes, without disturbing the three that exist

`2026_11_26_000100_create_platform_feature_control_tables` adds three tables:
`platform_feature_flags`, `platform_feature_flag_targets`, `platform_pilot_group_members`.
`App\Support\Control\FeatureControl` resolves them.

**Nothing that worked before behaves differently.** `module_is_active()`, `ActivatedModule()`,
`add_on_managers` and `user_active_modules` are untouched, and a key with no flag row answers
`FeatureDecision::NotControlled`, so every existing gate is byte-identical. That is not a stated
intention — it is asserted by a census
(`FeatureControlResolutionTest::test_the_control_plane_is_not_wired_into_the_existing_module_gate`,
which reads `helper.php` with comments stripped, because the two helpers this change adds at the
foot of that file name `FeatureControl` repeatedly in their docblocks).

**The answer is three-valued and that is the design.** A boolean would force every caller to pick a
meaning for "no flag row", and both available meanings are wrong here: `false` makes a retrofitted
flag hide an existing surface on every database where nobody inserted a row (and CLAUDE.md records
what that looks like — a dashboard redirect at HTTP 200, which a status assertion photographs as the
screen), while `true` makes a mistyped key enable a feature. `NotControlled` is what makes the plane
safe to adopt one surface at a time.

**The plan axis is resolved through the store's owner**: `stores.created_by` → `users.plan_id`. A
merchant holding no plan matches no plan target, including one keyed `0` — see §6's survivor note,
because that half was untested until a mutation said so.

### 3.2 `CTRL-002` — the properties, not just the knobs

**Gradual rollout.** `bucket(key, store) = hexdec(substr(md5("<key>:<store>"), 0, 6)) % 100`,
included iff `bucket < percentage`. Three properties, each its own test, each the thing a naive
version gets wrong:

- **sticky** — `rand()` would flip a store between calls, so one rendered page could come half from
  the new code and half from the old;
- **monotonic** — widening 10% → 50% only ADDS stores, asserted as a subset relation at every step
  from 0 to 100. A rollout that takes a feature away as it widens is one an operator cannot
  continue;
- **decorrelated across flags** — `store_id % 100` would put the same unlucky merchants in the
  first 10% of every rollout for ever.

`md5` and not `crc32`, for a measured reason: on a 32-bit build `crc32()` returns a NEGATIVE int and
`% 100` keeps the sign, so a negative bucket is `<` every percentage — a 1% rollout reaching the
whole platform, on one architecture only, invisible to a 64-bit suite. Six hex digits of md5 is at
most `0xFFFFFF`, a safe integer everywhere. It is not used as a security hash.

The function is **pinned by value**, because changing it reshuffles every live rollout: a store
inside a 10% rollout today could be outside the new 10% tomorrow and LOSE the feature. That is
monotonicity broken across a deploy rather than across a percentage, and the distribution tests
cannot see it — any sane hash gives roughly the right proportion.

**Safe disable, as four separate claims** rather than one word:

1. **total** — `off` is read BEFORE any target, any pilot membership and any percentage, so nothing
   left configured can partially undo it;
2. **immediate** — the memo is cleared before the change is even recorded. CLAUDE.md records the
   live version of this hazard: `activated_modules_for_owner` kept letting an owner whose plan had
   lapsed through a gate until the queue worker restarted;
3. **reversible without reconstruction** — disable, then resume, and six differently-reached stores
   are all exactly where they were. An operator who must rebuild a pilot group by hand after an
   incident will not use the switch *during* one;
4. **it deletes nothing** — which is `CTRL-004` applied to the control plane itself, and is why that
   test counts rows as well as comparing decisions.

### 3.3 `CTRL-003`'s third limb — the change log

`2026_11_26_000200_create_platform_control_changes_table` and
`App\Support\Control\PlatformChangeLog`. Every state transition, every target change and every pilot
membership change records one entry carrying `before`, `after`, the actor's id and a **snapshot** of
their name.

- **Append-only, enforced at the model boundary.** `App\Models\PlatformControlChange` throws on an
  Eloquent update or delete. **Said plainly rather than left to be discovered: nothing in the
  database refuses an `UPDATE` or a `DELETE`** — a raw `DB::table(...)->delete()` works, and a test
  asserts that, so the docblock cannot quietly become a claim of immutability.
- **Not `audit_logs`**, for the reason in §1.3: different decision, different section of the plan,
  different subject. Collapsing them means either flooding an incident-response log with routine
  configuration edits or scoping a platform-wide record with a tenant's `store_id`.
- **A non-`User` principal is recorded without an id.** CLAUDE.md records a measured defect of
  exactly this shape — `customers` has a `type` column with `'type'` in `Customer::$fillable`, and a
  `Customer` typed `super admin` reached a gate that read `->type` without asking what class it was.
  Writing a Customer's primary key into `actor_id` would make the log name a `User` who had nothing
  to do with it.
- **The recorder never throws.** A change log that can refuse a change is a change log that can stop
  the platform being controlled, so the flag is written first and recorded second. **The cost is
  stated: a change can therefore be made and not recorded.** The alternative — a database hiccup
  leaving a feature switched on that an operator is trying to switch off — is worse for a kill
  switch. Making it strict is a one-line change and an owner decision, not an engineering default.

### 3.4 The operator's screen

`platform/feature-control`, one read and three writes, `PlatformFeatureControlController`,
`resources/views/platform/feature_control.blade.php`, linked from the operator dashboard.

A control plane nobody can reach is not delivered: `OperatorDashboardPlatformLinksTest`'s own
docblock records three platform screens that shipped reachable only by typing their URLs.

- **The gate is identity, not a permission** — `PlatformOperatorOnly`, and the handler repeats the
  test. A merchant creates their own roles through the product, so "holds permission X" is
  substantially self-assignable, while `users.type` is not; and CLAUDE.md records that `isAbleTo()`
  alone would ALSO refuse the platform operator, so a permission gate would be wrong in both
  directions at once.
- **The handler's own check is driven with the route gate removed**, because CLAUDE.md records twice
  that a handler-level check behind an `abort()`ing middleware is invisible to an HTTP test — once
  with a `Customer` typed `super admin` reaching a handler and disabling a plan at HTTP 200.
- **The three writes are POSTs with no route parameter.** A GET would be thrown by a crawler or a
  link prefetch; a `{key}` segment would be bound by `SubstituteBindings` — `web`-group middleware,
  so it runs BEFORE the route's own gate — and a missing model would answer 404 ahead of the
  operator check, which is how a refusal test comes to be satisfied by a route that never ran the
  guard.
- **The three writes drop `xss`, and the first draft of this change got that backwards** — see
  §6.1, which is the one defect this workstream found in its own work rather than in the
  platform's.
- **The screen collects no free-text note.** `FeatureControl`'s writers all accept one and the
  change log stores it; this screen passes none. A note is a sentence an operator writes under
  pressure during an incident, and the right input for one is a textarea on a form that also shows
  them what they are about to switch off — which this slice does not have. The log records who,
  what, before and after, which is the audit content. Adding the field is a follow-on.
- **Bilingual by construction.** Every string is `__()`, direction comes from the layout's own `dir`,
  and all 84 keys are declared in BOTH `en.json` and `ar.json` with real Arabic —
  `FeatureControlMessagesAreDeclaredTest` fails on either half, because `__()` returns its own key
  when nothing is declared, so an undeclared sentence reads correctly in English by accident and
  reads IN ENGLISH on the Arabic page.

### 3.5 `CTRL-003`'s second limb — the maintenance announcements

`2026_11_26_000300_create_platform_maintenance_announcements_table`,
`App\Support\Control\MaintenanceAnnouncements`, `platform/maintenance` for the operator, and
`<x-ss.maintenance-notice />` for the merchant, included from `layouts/app.blade.php`.

**PLATFORM-WIDE, WITH NO AUDIENCE TARGETING, AND THAT IS A SCOPE DECISION.** The sibling tables in
this change carry three targeting axes because `CTRL-001` names three. `CTRL-003` names none:
maintenance of a shared platform reaches every tenant on it. The obvious thing to invent —
"announce to the pilot group first" — is a thing an operator might well want and is **not what the
decision asks for**, so it is §5's open question rather than code.

**THE FOUR SITUATIONS ARE THE WHOLE DIFFICULTY.** An unpublished draft, a published announcement
whose window has not opened, and a published one whose window has closed **all show a merchant
nothing** — so a test suite that only drives "published and in window" against "not published"
passes against an implementation that reads `is_published` and ignores the dates. Each of the four
is driven on the merchant's side, each of the four is asserted on the operator's (`draft`,
`scheduled`, `live`, `ended` — the screen prints the word, because an operator asking *why can't
they see it* needs to be told which), and the live case is driven in both directions so that "not
shown" can never pass because the banner never renders at all.

**THE BANNER RENDERS NOTHING WHEN THERE IS NOTHING LIVE** — not a wrapper, not a margin. It is
included from the layout, so it is on all 334 merchant screens: any markup at all with nothing to
say would be a spacing change to every one of them, to be re-measured in two themes and two
directions, in exchange for nothing. The absence is asserted by a marker the component emits, in
both directions, because "the banner is not there" and "the banner is there and empty" are the same
HTTP 200.

**BILINGUAL IN THE SCHEMA, WITH A FALLBACK, AND THE FALLBACK IS THE POINT.** `title_en`/`title_ar`/
`body_en`/`body_ar` in one row, because these are words the operator writes at 2am and cannot go
through `resources/lang/*.json`, which needs a deploy. A reader whose language is empty is shown the
other one: an Arabic merchant seeing **nothing** about tonight's outage is worse than seeing it in
English. Asserted in **both** directions, because a test that only drove the Arabic-missing case
would pass against a resolver that always returned English.

**URGENT BEFORE QUIET, AND THE ALPHABET IS THE WRONG WAY ROUND.** `severity` is a string and
`notice` sorts *before* `urgent`, so an implementation leaning on the column's natural order puts
the quiet announcement on top — the one a merchant can afford to miss. The fixture writes the quiet
one **second**, so newest-first would also get it wrong, and only a real severity ordering passes.

**WITHDRAWING IS NOT DELETING, AND THERE IS NO DELETE AT ALL.** `withdraw()` unpublishes and keeps
the wording and the dates, so it can be published again — and because what the platform told its
merchants, and when, is the record somebody asks for after an incident. That is `CTRL-004`'s shape
applied to this surface. The screen offers no delete, the controller contains no `->delete()`, and
both absences are asserted rather than left to be noticed.

**THE WRITE ROUTE DROPS `xss`, AND HERE IT IS THE STRONGEST INSTANCE OF §6.1's CASE.** The route's
entire purpose is to carry prose about tonight's maintenance. *"We are fixing the javascript error
on the checkout page"* would have published, to every merchant on the platform, as *"We are fixing
the  error on the checkout page"*, with nothing anywhere to say it had happened. Both halves are
tested: the prose arrives as typed, **and** a hostile title driven through the real route to the
real merchant banner is escaped — with a premise asserting the title really arrived carrying its
angle brackets, so the escaping assertion cannot be satisfied by the sweep whose absence it is
testing.

---

## 4. `CTRL-004` — the guarantee held, so the deliverable is the assertion

`tests/Feature/Control/DisablingAvailabilityNeverDeletesDataTest`. Three availability paths driven
through the real route or the real method, and the invariant asserted as **"no table holds fewer
rows afterwards"**, except three named availability tables.

**The direction is inverted on purpose.** CLAUDE.md: *"WHEN A CENSUS HUNTS FOR SOMETHING DANGEROUS,
ENUMERATE THE SAFE SET INSTEAD. A list of dangerous spellings is always short by the one nobody
thought of."* A test naming the tables a disable must not touch is exactly such a list and is blind
to the next table; snapshotting all of them and permitting three named exceptions is not.

Growth is not a violation, so the test needs no exclusion list for sessions, caches or logs — only a
*decrease* is what the decision forbids.

**The premise is asserted first, every time**, because "no table shrank" passes perfectly when the
handler did nothing: a 403, a gate's redirect, a `Module::find()` memo that never saw the fixture
row. And **the instrument is tested too** — a case deletes two products in the open and proves the
comparator reports it, because an invariant that was already true has no red run to start from and a
blind comparator would make all nine tests theatre.

---

## 5. What is NOT built, and which bucket each belongs in

**OWNER DECISION — the text of the five public policies.** `PlatformContent`'s five
`LEGAL_DOCUMENTS` are marked `owed => true` and carry no built-in copy. Terms of service, a privacy
notice, an acceptable-use policy, billing-and-refunds terms and a customers'-data statement bind the
business; drafting them would be settling the owner's decision in code. The editor exists, the
public page exists, the operator-only write guard exists. **The words do not, and must not be
invented.**

**OWNER DECISION — may a maintenance announcement be aimed at a subset of merchants?**
`CTRL-003` names no audience, so none is built: every published announcement inside its window
reaches every merchant. The thing an operator will ask for first is "announce it to the pilot group
before everybody", and the primitives for that already exist two tables away — which is exactly why
it is written down here instead of added. Aiming an announcement is a decision about how the
platform talks to its tenants, not an engineering default.

**NOT BUILT — the announcement reaches the merchant PANEL and not the STOREFRONT.** `CTRL-003`'s
announcements are the platform talking to its tenants, which is the reading §06-H supports. Whether
a shopper should also be told that the shop they are buying from is about to go down for twenty
minutes is a different question with a different audience, and CLAUDE.md records that a
storefront layout change must be made in **four** places. Stated, not assumed.

**OWNER DECISION — is `CTRL-003`'s change log public?** *"سجل تغييرات"* is read here as the
operator's record of platform-control changes, which is what §06-H's subject matter supports (it
sits beside the feature flags and the rollout). A second reading is a **public** change log — release
notes merchants can read. Both are defensible from the sentence; they are different products. The
operator's record is built; a merchant-facing one is not, and which was meant is the owner's to say.

**OWNER DECISION — may a merchant see which pilots they are in?** A merchant who is given a feature
before everybody else may reasonably want to know, and `platform_pilot_group_members` is the only
place that says so. Showing it is a product decision about how the platform talks about unreleased
work; nothing merchant-facing was built.

**HUMAN ACCEPTANCE — a pixel contrast reading of the three new surfaces.** All three views declare
**no colour at all** — asserted: no inline style, no hex, no `rgb()`/`hsl()`, and none of the host
colour classes — so every colour on every one of them comes from a `store-syria-ds.css` token and
re-themes with the admin. The one CSS rule this change adds (`.ss-maintenance-notice__body`) sets a
single property, `white-space`, and no colour. That is the thing that *makes* the contrast correct,
and it is a **census rather than a measurement**. A pixel reading needs a running server and the
project's live harness, with `APP_DEBUG` **off** and `.phpdebugbar` excluded **by ancestor** —
CLAUDE.md records that the badge count varies per surface, so a harness subtracting a constant is
wrong on the next one, and that a quick colour probe reported FAIL twice where the real harness read
clean. **Not run here. It is a human-acceptance item, not a claim.**

**HUMAN ACCEPTANCE — the RTL rendering, looked at.** All three surfaces are asserted to render in
Arabic with Arabic strings, and to pin no side of their own: a regular expression refuses every
`{m|p}{s|e|l|r}-{0..5|auto}` utility, with a premise proving it matches `class="ml-2"` and does not
match `class="mb-0"` — because the first draft of that check used a leading space, missed
`class="ml-2"` entirely, and its mutation **survived**. Nobody has *looked* at any of them.

**HUMAN ACCEPTANCE — the merchant banner's placement.** It sits above `@yield('content')` on every
panel screen, which is a judgement about where a merchant's eye is rather than a measurement. It is
one line in the layout and renders nothing when there is nothing live, so the cost of moving it is
one line — but somebody should see it on a real dashboard, a real order list and a real product form
before it is accepted.

**EXTERNALLY BLOCKED — applying the three migrations.**
`2026_11_26_000100` (the three feature-control tables), `000200` (the change log) and `000300`
(the announcements). All three are purely additive, all three are no-op-on-existing-table by
construction, all three call `ThemeSchemaGuard::assertCompatible()` rather than trusting
`hasTable()`, and all three have a documented no-op `down()` — CLAUDE.md records two GATE F drafts
that dropped a column *because* it existed and a rollback that destroyed 45 `cost_price` values.

CLAUDE.md reserves a persistent migration to the owner, so they are **approved for development and
isolated database testing only**. Until they are applied, every read answers as though nothing were
controlled and nothing were announced, and every write throws naming its migration — the asymmetry
is deliberate and is tested in both directions. **Nothing in this change requires them to be
applied to keep working**, which is the property that makes them safe to leave pending: a read has
to survive a database without them, because the merchant banner is on every panel page.

---

## 6. Evidence

Measured from the run, not estimated — `php artisan test tests/Unit/Control tests/Feature/Control`:

```
tests/Feature/Control/DisablingAvailabilityNeverDeletesDataTest     9 passed
tests/Feature/Control/FeatureControlResolutionTest                 22 passed
tests/Feature/Control/FeatureControlSafeDisableTest                 7 passed
tests/Feature/Control/FeatureControlOverviewTest                   13 passed
tests/Feature/Control/PlatformChangeLogTest                        15 passed
tests/Feature/Control/PlatformFeatureControlScreenTest             33 passed
tests/Feature/Control/MaintenanceAnnouncementTest                  30 passed
tests/Unit/Control/GradualRolloutBucketTest                         8 passed
tests/Unit/Control/FeatureControlMessagesAreDeclaredTest            4 passed
                                                                  ---
                                                     141 passed (3,958 assertions)
```

And the neighbouring suites, run against the frozen source after every ratchet was re-pointed:

```
tests/Unit/Control + tests/Feature/Control + tests/Feature/Tenancy
                                                       609 passed  (5,940 assertions)
tests/Unit/Ui + tests/Unit/Saas + tests/Unit/Localisation
  + tests/Feature/Saas + tests/Feature/Modules
  + SecurityLogInventoryTest + PlatformOperatorOnlyPrincipalTest
  + PlatformAdminSurfaceDefenceInDepthTest           1,761 passed (20,093 assertions)
```

**Mutations: 112, in four harnesses under `tests/Support/bin/`, all 112 CAUGHT:**

```
mutate-ctrl004.py                  12   the CTRL-004 invariant, and the instrument itself
mutate-ctrl001-002-003.py          38   the resolution order, the three axes, the rollout, the log
mutate-ctrl-operator-screen.py     31   the gate, the validation, the screen, the bilingual census
mutate-ctrl003-announcements.py    31   the four situations, the fallback, the banner, the record
                                  ---
                                  112
```

Every harness applies its change, **asserts the anchor occurs exactly once before writing**, runs
one named test METHOD, restores the file and restores its mtime. All three of those guards earn
their place and CLAUDE.md records each failing here before: an anchor that matched nothing reported
"survived" against unmutated code; a filter keyed on a Pest display name rather than a method name
selected no test and PHPUnit exited 0; and a restored file with a copy's mtime left a stale compiled
Blade serving the mutation afterwards, reversing the result in both directions.

**One anchor really did miss, and the harness said so rather than reporting a survivor** — the
banner's host-colour mutation was written with eight leading spaces against a line that has four,
and the run printed `anchor x0` and counted it as a survivor by construction. Corrected and re-run.
That is the first of the three guards doing exactly its job.

### 6.1 The one defect this change had in itself, found by self-review and not by a mutation

`App\Http\Middleware\XSS` deletes `['&lt;', '&gt;', 'javascript', 'alert']` from **every string in
every request it handles**, case-sensitively, exempting credential-shaped keys only. The first draft
of this change kept `xss` on the three write routes and its route comment asserted that nothing
there could be affected — *"these three carry identifiers and a number, none of which can contain
those substrings in a form the middleware would touch"*.

That is false, and executing one line shows it:

```
str_replace(XSS::STRIPPED_SUBSTRINGS, '', 'stock-alerts')   ===   'stock-s'
```

`alert` is not an exotic word in a feature key. `stock-alerts`, `price-alert`, `alert-banner` are
names somebody really types, and a pilot group or a target could carry one too. The operator would
have declared a flag, been told it worked, seen a perfectly readable key on the screen — and the
code asking for the key they typed would have answered `NotControlled` for ever. Silently.

Fixed by `->withoutMiddleware('xss')` on the three writes, following `platform/site-content`'s
precedent four routes above in the same file, with the reasoning written out there. **Nothing is
given up:** `key`, `value` and `group` are validated against `^[A-Za-z0-9._-]+$`, which cannot
express markup; `axis`, `effect` and `action` against `in:` lists; `store_id` and `percentage` as
integers. The only prose is `label` and `description`, printed in exactly one place through Blade
`{{ }}` — and `test_markup_in_a_label_is_escaped_rather_than_rendered` **proves** that escaping,
with a premise asserting the label really arrived carrying its angle brackets, so the assertion
cannot be satisfied by the sweep it is testing the absence of. CSRF is in the `web` group, not in
`xss`, and the installer redirect still fires on the GET route.

Two things worth taking from it. First, **the claim in the first draft was of exactly the kind this
project keeps having to withdraw**: a sentence about what a middleware "would touch", written
without running it. Second, **no mutation would have found this**: the mutation list was written by
the same hand, against the same belief, so it contained no mutant for a trap its author did not
know existed. CLAUDE.md records that failure mode — *"a mutation group written by the hand that
wrote a containment shares its blind spots"* — and it is the argument for the independent review
this workstream could not run (§6.3).

### 6.2 Four mutations survived first, and each one found a real weakness

Recorded because this is what the mutation run is for, and because three of the four were weaknesses
in the TESTS rather than in the code.

1. **`$ownerId = $storeId`** — a plainly wrong plan lookup — survived
   `test_a_feature_can_be_enabled_for_every_store_on_a_plan`. `RefreshDatabase` restarts every
   auto-increment, and the fixture made merchant 1 own store 1 and merchant 2 own store 2, so
   reading the wrong column gave the right answer. The id sequences are now pushed apart before
   every test in that file and the gap is **asserted**, not assumed. CLAUDE.md records the same
   class of artefact twice; the rule it states is the one applied — *a fixture must not be able to
   cause the thing the test hunts for*.
2. **Weakening the plan guard to `if ($planId !== null)`** survived, because the real default IS
   null, so the `'0'` and `''` limbs were unreachable by any test and the guard could not be
   distinguished from its absence. CLAUDE.md's standing rule is to delete such code; it is **kept**
   here for one measured reason — `users.plan_id` carries the type drift
   `MigrationHygieneTest::test_plan_id_type_drift_remains_the_only_root_instance` already tracks,
   and on the `varchar` branch `'0'` means "no plan" while one target keyed `0` would reach every
   merchant holding it — and then given its own test with a raw write.
3. **Blanking the feature's visible key in the declared-features table** survived an assertion over
   the whole screen, because the change log at the foot of the SAME page also prints that key in a
   `.ss-code`. The assertion was being satisfied by a different section — and, before that, by the
   `value` of a dozen hidden inputs. The view now emits a section marker and the test slices to it.
   CLAUDE.md, verbatim: *"Scope the search to the block too."*
4. **Replacing `"Feature control"`'s Arabic with the English string** survived, because the census
   inherited its sibling's escape hatch — *an Arabic value identical to the English one is allowed,
   since some keys legitimately are*. That hatch is the hole: an Arabic operator would have seen an
   English heading with nothing red. It is replaced by an explicit, **measured empty** allowlist, so
   "this one needs no translation" is a claim somebody has to write down.

### 6.3 What the evidence does not cover, said plainly

- **THE INDEPENDENT AUTHORIZATION REVIEW WAS NOT RUN.** `CLAUDE.md` §6 names
  `.claude/agents/tenant-authz-reviewer.md` as the thing to use before committing any change to
  authorization, a route's middleware, or a handler that reads an identifier from request input —
  and records that *"three of the last four packages had a defect found only by this review, two of
  them in the fix itself"*. This change is all three of those categories. It was **not** reviewed by
  that agent, because this workstream runs as a subagent with no agent-spawning tool. What was done
  instead: a deliberate adversarial pass against the project skill's own eighteen-item list of ways
  a test passes for the wrong reason, which is what found §6.1 and three of the four mutation
  survivors. **That is not a substitute, and the review is outstanding.** The surface to put in
  front of it: `PlatformFeatureControlController`'s four actions, the `withoutMiddleware('xss')` on
  three of them, and `FeatureControl`'s write methods.

- **No full suite run.** This workstream ran the files it touched and their neighbours
  (`tests/Unit/Control`, `tests/Feature/Control`, `tests/Unit/Saas`, `tests/Feature/Saas`,
  `tests/Unit/Ci`, `tests/Unit/Database`, `tests/Unit/Tenancy`, `tests/Unit/Ui`,
  `tests/Feature/Security`). The consolidated gates are the integrator's.
- **SQLite, not MySQL.** The suite runs SQLite `:memory:`; production is MySQL. Two places in this
  change are MySQL-sensitive and are handled by construction rather than by measurement on a real
  server: the pilot-group slug is normalised on both sides because MySQL's default collation is
  case-insensitive and SQLite's `=` is not, and `state`/`target_type`/`effect` are read defensively
  because production's non-strict `sql_mode` truncates rather than refuses. **Neither has been
  verified against a real MySQL**, which is the pattern
  `tests/Support/Security/bin/verify-sec13-migration-on-mysql.php` exists for.
### 6.4 Two ratchets owned by other workstreams went red, and both were right to

Recorded because the point of a ratchet is that the change which trips it did not have to remember
it existed — and because one of the two found a real defect in this work rather than a stale number.

**`PlatformOperatorOnlyPrincipalTest` — a number, correctly maintained.** The blast radius of that
gate moved from **37 routes to 43**: the four `platform/feature-control` routes and the two
`platform/maintenance` ones. Four of the six are WRITES, which makes them the first routes on that
gate that CHANGE platform configuration rather than read it — so the `instanceof User` clause that
file exists to protect now stands between a non-`User` principal and both a feature switch and a
notice published to every merchant. The const, the method name and the docblock tables in BOTH files
are updated together, exactly as that test's own failure message instructs.

**`RequiredFieldMarkerTest` — a real defect, not a number.** Three required controls on the
feature-control screen (a feature key, a pilot group, a store number) shipped with **no required
marker**, which is a design-system contract this project already enforces across 165 fields. The
markers were added and the count ratcheted to **167**. The ratchet is the only reason this was
caught: nothing about the rendered page, and none of this workstream's own tests, could see it.

**167 AND NOT 168, AND THE ARITHMETIC IS THE INSTRUMENT RATHER THAN A MISSING MARKER.** 168 was
tried first and the census read 167. `blocks()` splits on an opening `<div>` whose class names
`form-group` or a `col-*` and then judges each block as a WHOLE — `isRequired($block)` is a boolean.
The feature key sits in one `col-12` card while the pilot group and the store number sit together in
another, so the second pair is ONE block and is counted once. The figure is therefore "blocks
containing at least one required control", which that method's own name slightly misdescribes; both
markers are present, and marking is judged per block too. Written into that test rather than quietly
accepted, because the next person to add two required fields to one column will see +1 and
reasonably wonder which one the census lost.

One detail worth keeping: the comment beside those markers deliberately does **not** spell the
marker's class name. That census is a text search over the view, so a docblock quoting the string it
greps for would satisfy it with prose while the span was missing — CLAUDE.md records that happening
eight times, four of them on one day.

**And three MORE in `tests/Feature/Tenancy/`, which is where the interesting one is.** All three are
count ratchets on the raw-query and model censuses, and `FeatureControl::subject()`'s three
`DB::table()` reads plus five new models moved them:

| ratchet | moved | |
|---|---|---|
| `RawQueryTenancyInventoryTest` per-directory | `app` 232 → 235 | three reads: the store's owner, that owner's plan, the store's pilot groups |
| `RawQueryTenancyInventoryTest` grand total | 277 → 280 | the same three |
| `RawQueryTenancyInventoryTest` per-table | `platform_pilot_group_members` NEW at `[1, 0]`; `stores` `[26,25]` → `[27,26]`; `users` `[6,6]` → `[7,7]` | one scoped, **two unscoped** |
| `BuilderRawQueryVisibilityTest` premise | `app` 232 → 235 | the second instrument, agreeing |
| `TenantModelClassificationTest` total | 106 → 111 | five new models |
| `TenantModelClassificationTest` tenant-discriminated | 76 → **77** | `platform_pilot_group_members` carries `store_id` |
| `TenantModelClassificationTest` platform-global | 28 → **32** | the other four carry none |

**The two unscoped reads are the ones a reader should stop at, so they are written out at the call
site's own inventory row rather than absorbed.** `stores.created_by` and `users.plan_id` are read
by primary key, and **neither table has a `store_id` to predicate on** — `stores` IS the store (a
predicate would compare the column with itself, which is the reasoning already standing against
that table's other 25 unscoped sites) and `users` carries none and is not in `scoped_models`. What
leaves the method is a plan id used as a STRING compared against a stored `target_key`, never to
load a row; and the store id that starts the chain is a parameter the caller supplies from the route
or from `getCurrentStore()` — `platform_feature_decision()`'s docblock refuses to resolve it itself
for exactly that reason.

**And the model classification is not the bookkeeping it looks like.**
`platform_pilot_group_members` carries `store_id`, so the census counts it as tenant-discriminated —
and the honest answer to "is it a tenant's row" is still **no**: it is the operator's record that a
given store is in a given pilot. The column is a REFERENCE, not a discriminator of ownership, the
same distinction that file already records for `zid_stores`. It is deliberately **not**
scope-enforced, because the operator's own screen reads every store's memberships and a global scope
would blank it for the one principal it exists for. All of that is written into the ratchet's own
comment, where the count that includes it is asserted — which is what the ratchet is for.

**One thing deliberately NOT changed:** those two method names still read
`..._are_the_measured_fifty_eight` and `..._twenty_eight`, against assertions of 77 and 32. They
were already stale before this change (76 and 28), and the sibling file records that such a name is
"an identity the CI gate matches on" while "the assertions carry the real figures" — and
`BuilderRawQueryVisibilityTest` cites one of those names in a failure message. Renaming them is
somebody's housekeeping, not a change to smuggle into this one.

---

## 7. Recorded, not fixed

- **The control plane has one consumer: its own operator screen.** `platform_feature_enabled()` is
  available to first-party code and nothing in `app/` calls it yet. That is deliberate — the three
  existing module axes are untouched and a key with no row answers `NotControlled`, so adoption is a
  per-surface decision rather than a flag day. It is stated here so nobody reads "feature control
  exists" as "features are controlled".
- **A flag's key cannot be renamed.** There is no rename path: an operator who mistypes a key
  declares a new flag. The screen refuses a transition on an undeclared key, so the mistake is
  visible rather than silent, but the dead flag stays. A rename would have to move the change log's
  `subject_key` too, and rewriting a change log's rows is what §3.3 forbids.
- **The rollout bucket is pinned by value and that pin will one day be in the way.** Changing the
  bucketing function is a legitimate thing to want (a better distribution, a different hash) and it
  withdraws the feature from live stores. The test says so in its failure message and asks for the
  change to be deliberate. It must not be made green by re-measuring the numbers.
- **`platform_control_changes` has no retention policy.** It grows for ever. How long the platform
  keeps its own change history is an owner decision, and a `prune` command written without one would
  be a `CTRL-003` violation dressed as housekeeping. The same is true of
  `platform_maintenance_announcements`, which nothing deletes by design.
- **A maintenance announcement is not pushed anywhere, and a merchant who only uses the Flutter app
  will not see one.** Measured in the second repository rather than assumed:
  `/home/user/StoreSyria_app98/lib` has **no** announcement or maintenance surface of any kind (the
  three files matching the word carry an unrelated `banner`), so there was nothing to extend. This
  change adds **no API route at all**, which is the deliberate half: `api/admin` is the shipped
  admin app's contract, and CLAUDE.md's standing principle is to preserve it. So the reach of
  `CTRL-003`'s announcements today is *the merchant web panel, on the next page they open* — no
  e-mail, no notification row, no app. For a window announced hours ahead that is adequate; for an
  outage already under way it is not. Telling them *now* needs a notification channel and a change
  in a second repository, which is a bigger decision than `CTRL-003`'s sentence contains.
- **`MaintenanceAnnouncements::live()` runs one indexed query on every merchant panel page** and is
  not memoised. Measured as acceptable rather than assumed: it is one query against `pma_live`,
  which is `(is_published, starts_at, ends_at)` with `is_published` first because almost every row
  in a mature table is a past announcement that is still published. It is deliberately NOT cached
  for the reason its model's docblock gives — withdrawing an announcement has to take effect at
  once, and a banner that keeps telling every merchant the platform is going down until a cache
  expires is worse than no banner. If the query ever shows up in a profile, the fix is a
  request-scoped memo like `FeatureControl`'s, not a TTL.
- **ONE PRE-EXISTING DRIFT WAS CORRECTED IN PASSING, and it is somebody else's.**
  `docs/security/P01-OBSERVABILITY-INVENTORY.md:246` described
  `ApiController.php:9395` as *"payment_sheet refused: basket names a product from another store"*
  while the source has said *"...a product this store does not sell"* since before this branch's
  base commit. `SecurityLogInventoryTest` could not see it — it compares by `file:line` key, and
  the key matched — but `resync-observability-inventory.py` compares the MESSAGE and refuses to
  write while any row disagrees, so the inventory could not be updated at all until the row was
  corrected. Fixed to match the source. **Reported rather than owned:** the drift came from the
  change that reworded the message, and the lesson is that the ratchet and the tool disagree about
  what a row is.
- **One of this change's own log messages was reworded to suit that tool.**
  `FeatureControl`'s targets-read failure said *"...a feature flag's targets"*. Both the test and
  the resync tool extract the literal with a regular expression that stops at the first quote, so
  the escaped `\'` truncated the message they read and the row could never match. Reworded to
  *"could not read the targets of a feature flag"*, with the reason in a comment beside it — by
  wording rather than by widening somebody else's census, which is the cheaper of the two and does
  not weaken a check this workstream does not own.
