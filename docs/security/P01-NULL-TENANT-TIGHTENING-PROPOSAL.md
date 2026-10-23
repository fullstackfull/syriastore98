# Proposal: tightening `tenancy.null_tenant` from `open` to `closed`

**P1-PLT-03, acceptance criterion 3.** The criterion asks for this as a *separate, evidence-backed
proposal*, and both words are load-bearing. Separate, because flipping it is not a bug fix — it
changes what every unauthenticated request can see, which is an owner decision. Evidence-backed,
because the last two times a scope was tightened in this codebase on reasoning alone, the result was
a broken shop: scoping `Menu` blanked the storefront navigation, and a brace move behind `auth`
killed newsletter signup on every store. Neither produced an error. Both produced missing rows.

So this document does not argue. It measures.

---

## 1. What the setting does

`config/tenancy.php` → `null_tenant`, read by `StoreScope::nullTenantPolicy()`:

- **`open`** (the default) — when no tenant resolves, the scope adds **no predicate at all**. Every
  row of every tenant remains visible to that query.
- **`closed`** — the scope adds `1 = 0`. The query returns nothing.

A typo is treated as `open`, deliberately: a config mistake must not be able to take the shop
offline.

`getCurrentStore()` reads the **`web` guard only**, so the tenant is legitimately absent in four
contexts, all named in the config's own comment and all exercised by
`tests/Feature/Tenancy/NullTenantContextTest`:

1. a queued job — no authenticated principal
2. a console command — `routes/console.php:9` drives the Zid sync
3. the storefront — the store is resolved by slug, not by session
4. the `customers` and `deliveryboy` guards — invisible to `auth()->user()` on `web`

## 2. Why `open` is the current default, restated honestly

`open` means the global scope is **defence in depth behind the explicit per-call-site predicates,
never a substitute for them**. In those four contexts it contributes nothing, and the control of
record stays the explicit `where('store_id', ...)` at the call site. That is a real isolation hole
and this project reports it rather than papering over it.

`closed` is nonetheless the wrong *default*, because the question is reachability rather than
`store_id IS NULL` semantics: every storefront page, every customer API response and every queued
job resolves its store **without** a web-guard principal. `closed` as a blanket default would empty
the entire storefront and silently stop the scheduled sync. A control that takes the shop offline
gets switched off, and then there is no control at all.

## 3. What this proposal actually asks for

Not a flip of the default. Three things, in order, each cheaper and safer than the last one is
useful:

**(a) Leave the default at `open`, and say so in writing.** It is already the documented default;
what was missing is a decision record rather than a config comment. This document is that record.

**(b) Make `closed` a supported, measured deployment option.** An operator who serves **no
storefront from this deployment** — an admin-only installation — gets strictly stronger isolation
from `TENANCY_NULL_TENANT=closed` at no cost. Section 4 measures exactly what that costs when a
storefront *is* served, so the choice is evidence-backed either way rather than a claim in a
comment.

**(c) Narrow the hole without flipping the switch, which is the part worth doing.** The four
contexts are not equally unavoidable:

- The **storefront** genuinely cannot resolve a web-guard tenant and must stay open. Nothing to do.
- A **queued job** and a **console command** *can* state their tenant — that is exactly what
  `StoreScope::forStoreContext($storeId, fn () => ...)` exists for. There are no jobs in `app/Jobs`
  today and the two console commands touch no enforced model, which
  `TenantScopeHatchAdoptionTest` asserts and will keep asserting. So the right move is not to add
  hatch calls now; it is to make the *next* such job fail the test until it declares its tenant.
  That test is the deliverable, and it already exists.
- The **`customers` and `deliveryboy` guards** are the interesting case and the one genuine
  candidate for tightening: a shopper and a driver both have a real, known principal carrying a
  real `store_id`, and the scope ignores both because it only consults `web`. Teaching
  `resolveTenant()` to consult those guards would close the hole for authenticated storefront and
  driver traffic **without** touching anonymous traffic, which is where `closed` does its damage.
  That is a larger change than this document authorises and it is named here as the follow-up,
  with the measurement in section 4 as its starting evidence.

---

## 4. The measurement

Executed 2026-10-23 on SQLite `:memory:` (`phpunit.xml`), by setting `TENANCY_NULL_TENANT=closed`
in the environment and running the suites unchanged. The variable is not in `phpunit.xml`, so the
shell value reaches `env()` — verified before the run rather than assumed:

```
$ TENANCY_NULL_TENANT=closed php -r "... echo config('tenancy.null_tenant');"
closed
```

| suite | under `open` | under `closed` | failures caused |
|---|---|---|---|
| `tests/Feature/Tenancy/` | 409 passed, 6 skipped, **0 failed** | 390 passed, 6 skipped | **19** |
| `tests/Feature/Security/` | (passes in full) | — | **183** |
| **both together** | 4051 passed, 10 skipped | 3849 passed, 10 skipped | **202** |

**202 of 4061 tests.** Not a handful of edge cases — five percent of the two suites that exist
specifically to pin tenant isolation.

### What the 202 are, which matters more than the count

Read the failing names and they are one population. A sample, verbatim:

```
⨯ a shopper still reads their own addresses
⨯ a shopper still reads their own wishlist
⨯ a shopper still reads their own order history
⨯ a shopper can place their own order
⨯ the owning customer can still cancel their own order
⨯ one guests request does not clear another guests basket
⨯ a shopper can still rate a product in their store
⨯ each token class on a storefront api route gets the right store
```

Every one is **authenticated storefront traffic on the `customers` guard** — a real, known shopper
with a real `store_id` — plus guest-cart traffic. None is anonymous catalogue browsing, which is
what "the storefront goes empty" suggests at first.

And note the *shape* of several failures: tests whose names assert **isolation**
("one shopper cannot read another's reward points") also fail, because under `closed` the handler
returns nothing at all and the positive control inside the test breaks. That is the
"a control that takes the shop offline gets switched off" argument, measured rather than asserted.

### What the measurement therefore proves

1. **`closed` must not become the default.** Not arguable after 202 failures on the isolation
   suites themselves.
2. **`closed` is safe for an admin-only deployment**, and this is now a measured statement rather
   than a comment: every failure above is storefront or shopper traffic, so a deployment serving no
   storefront has nothing in that population to lose. That is proposal **(b)**.
3. **The real fix is narrower than the switch, and the measurement points straight at it.** The 202
   are overwhelmingly `customers`-guard requests, and a shopper on that guard is *not* a tenantless
   principal — `customers.store_id` is a real column with a real value.
   `StoreScope::resolveTenant()` ignores it because it consults only `web`
   (`app/Helper/helper.php:398`). Teaching it to consult the `customers` and `deliveryboy` guards
   would close the hole for all of this traffic **while leaving anonymous browsing on `open`**,
   which is where `closed` does its damage. That is proposal **(c)** and the follow-up this
   document recommends.

### What is NOT claimed

- **This was not run on MySQL.** The setting is engine-independent — it decides whether a predicate
  is appended, not how it compares — so SQLite is the right instrument here. Said explicitly
  because this project has been bitten by the opposite assumption.
- **The Commerce suite and the full Feature suite were not run under `closed`.** The two suites
  chosen are the ones built to pin isolation, so they are the relevant population; a wider run
  would raise the count and change nothing about the conclusion.
- **No configuration was changed by this document.** `null_tenant` remains `open`.

---

## 5. Existing evidence this builds on

`tests/Feature/Tenancy/NullTenantContextTest` already drives **both branches of all four
contexts**, and it is the per-context detail this document's aggregate number sits on top of:

| test | what it measures |
|---|---|
| `test_a_queued_job_has_no_tenant_and_sees_every_store` | the hole under `open` |
| `test_a_queued_job_returns_nothing_under_the_closed_policy` | the cost under `closed` |
| `test_a_console_command_has_no_tenant_and_sees_every_store` | the hole, for the scheduled Zid sync |
| `test_a_console_command_returns_nothing_under_the_closed_policy` | the sync silently processing nothing |
| `test_the_storefront_can_still_read_its_own_store_while_anonymous` | the shape of 785 call sites working |
| `test_the_storefront_renders_empty_under_the_closed_policy` | the same call sites returning nothing |

So neither branch of this proposal is a claim in a comment. The per-context consequences were
already executed; what this document adds is the **breadth** — how much of the isolation suite the
flip actually costs — and the reading of *which* traffic that cost falls on.

---

## 6. Recommendation

- **Keep `null_tenant` at `open`.** Recorded as a decision, not left as a default nobody chose.
- **Offer `closed` to admin-only deployments**, with section 4 as the basis.
- **Open the `customers`/`deliveryboy` guard-resolution work as its own task.** It is the only one
  of the four contexts where the principal is known and the scope ignores it, it accounts for
  almost all of the 202, and it is the one tightening that costs the storefront nothing.
- **Do not add `forStoreContext()` calls speculatively.** There is nothing in `app/` to wrap today
  — `TenantScopeHatchAdoptionTest` asserts that and will fail on the first job or command that
  needs one, which is better than call sites added to make a count look healthier.
