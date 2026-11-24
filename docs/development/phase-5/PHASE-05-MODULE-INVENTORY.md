# Phase 05 — the module inventory, and what the suite can actually reach

**Measured 2026-11-24** on `feature/phase-05-marketing-analytics` at `5a270928`. Every figure here was
produced by executing a census over the working tree; none is carried over from an earlier document.
`P5-R10`.

This exists because Phase 05 is marketing, loyalty and analytics, and most of that functionality is
**not in `app/`**. It is in vendored WorkDo add-on modules under `packages/workdo/`, which
`guard-no-workdo-changes.sh` forbids editing. So "Phase 05 is implemented" is a claim about code this
repository does not own and cannot change, and "Phase 05 is tested" is a claim that needs checking
against a loader most tests never trigger.

## 1. Why a module can be invisible to 10,000 passing tests

`CLAUDE.md` states it and this inventory confirms it by execution: **no WorkDo module loads in a test
run unless that test creates the module's `add_on_managers` row.** A module's routes, controllers,
policies and listeners simply are not registered, so a test that drives its URL gets a 404 and a test
that never mentions it proves nothing about it.

Reaching module code takes **three** things, not one, and `MarketingContactOwnershipTest` documents
all three at `:46-93`:

1. the `add_on_managers` row, which is what the production loader reads;
2. the module's own tables — `RefreshDatabase` runs `database/migrations` only, so the module's
   migrations have to be run by the test itself;
3. a `user_active_modules` row for the **owner**, or `PlanModuleCheck` refuses the request before the
   handler runs — and that lookup is memoised in a container `scoped()` binding, so the row must
   exist *before* the request and the binding must be forgotten (`forgetInstance`) if it does not.

Miss (3) and every assertion still passes, having never reached the handler. That is the shape this
project calls a test that passes for the wrong reason.

## 2. The census, and three wrong numbers before the right one

The enablement census is worth describing because it was wrong twice, in opposite directions.

| attempt | what it looked for | answer | why it was wrong |
|---|---|---|---|
| 1 | quoted capitalised tokens near `add_on_managers` | 14 modules, incl. `POST`, `SUMMARY`, `Base`, `Landing` | matched prose and unrelated strings in the same statement |
| 2 | `DB::table('add_on_managers')->insert(` only | **2** of 70 | assumed ONE call shape |
| 3 | all four shapes, keyed on `'module' => '...'` | **15** of 70 | — |

Attempt 2 is the instructive one. `AddOnManager::create` appears **32** times in the suite and
`DB::table('add_on_managers')->insert` only **2**, so the Eloquent path is how modules are actually
enabled and the raw-query path is the exception. A census built on the exception under-reported
coverage by a factor of seven and would have put six falsely-uncovered modules into this document.
The shapes that count: `AddOnManager::{create,updateOrCreate,firstOrCreate}` and
`DB::table('add_on_managers')->{insert,updateOrInsert}`.

**15 of the 70 modules** are enabled by at least one test: `AuctionProduct`, `AutomaticOrderPrinting`,
`BulkDelete`, `Campaigns`, `CheckoutAttachment`, `DigitalProduct`, `Donation`, `LandingPage`,
`LotteryPrizes`, `LynomiaChat`, `Marketing`, `PartialPayments`, `ProductReview`, `RewardClubPoint`,
`SidebarCustomization`. The other **55 never load in any test run.**

## 3. The twelve modules Phase 05 depends on

Selected by keyword over module source (`marketing|analytic|loyalt|campaign|newsletter|coupon|reward|
referral`), then ordered by whether the suite reaches them. Route counts are
`Route::{get,post,put,patch,delete,resource,match,any}` occurrences under each module's `routes/`.

| module | routes | controllers | views | row created by a test | HTTP calls in those tests |
|---|---:|---:|---:|---|---:|
| `LynomiaChat` *(first-party)* | 99 | 20 | 12 | yes — `InteractsWithLynomia` trait, 21 files | — (trait, counted elsewhere) |
| `Marketing` | 31 | 3 | 35 | yes — 2 ownership tests | 12 |
| `LotteryPrizes` | 26 | 3 | 27 | yes — 7 tests | 130 |
| `Campaigns` | 22 | 3 | 29 | yes — 1 ownership test | 11 |
| `Donation` | 18 | 3 | 22 | yes — 1 serialization test | 1 |
| `RewardClubPoint` | 4 | 1 | 11 | yes — 1 ownership test | 5 |
| `SpinToWinReward` | 8 | 2 | 11 | **NO** | 0 |
| `ProductAffiliate` | 7 | 1 | 7 | **NO** | 0 |
| `PDFVouchers` | 6 | 3 | 9 | **NO** | 0 |
| `CouponEmail` | 4 | 1 | 5 | **NO** | 0 |
| `CouponList` | 3 | 1 | 4 | **NO** | 0 |
| `Subscribe` | 1 | 1 | 3 | **NO** | 0 |

**Six of the twelve are never loaded in any test run, and they carry 29 routes between them.** No
test in this repository can reach a single one of those 29 handlers, whatever it asserts.

**Two claims, not one, and they are different.** A created row means the module *loads*; it does not
mean its handlers are exercised. `Donation` is the example: its row exists only because
`StoreSerializationSecretsTest` needs the module present, and that test makes **one** HTTP call,
which is not donation coverage. `LotteryPrizes` (130), `Marketing` (12), `Campaigns` (11) and
`RewardClubPoint` (5) are genuinely driven. Read the last column, not the one before it.

## 4. This predicts where the defects were actually found

Every Phase 05 module security finding to date came from **reading** the code, not from a failing test:

| finding | module | found by |
|---|---|---|
| `P1-SEC-84` contact rows editable and deletable across tenants by id | `Marketing` | review |
| `P1-SEC-86` unscoped delete, and an update that reassigns `created_by` | `Campaigns` | review |
| `P1-SEC-88` `get_appointment_data` reads its id from the body | `Marketing` | review |
| `SEC-MOD` cross-tenant reads/writes, missing operator checks | `Campaigns`, `Marketing` | review |
| `SEC-MOD-2` two handlers write arbitrary settings with no permission check | vendored modules | review |

The regression tests that now pin those five exist **because** the fix work created the loader
fixture for them. Before that work, Marketing and Campaigns had 53 routes and no test that could
reach any of them. The six unreached modules are in that same state today.

## 5. What this means for the Phase 05 report, stated as limits

- **Green CI says nothing about the six unreached modules.** Any Phase 05 completion figure must say
  so rather than let 10,000 passing tests imply coverage they do not have.
- **The fix is per-module and not free.** Reaching one costs the three-part fixture in §1 plus its
  own migrations. It is ordinary work, needs no owner decision, and is the natural next slice for any
  of the six — `SpinToWinReward` (8 routes) and `ProductAffiliate` (7) are the largest.
- **It cannot be fixed by editing the modules.** They are vendored and `guard-no-workdo-changes.sh`
  blocks it. A defect found in one is remediated first-party (a guard, a policy, a route middleware)
  or reported to the owner as a vendor issue — which is how `P1-SEC-74`'s vendored twin is handled.

`PhaseFiveModuleCoverageTest` re-measures §3's last-but-one column against the working tree, in both
directions, so neither a new fixture nor a lost one can leave this table stale and unnoticed.
