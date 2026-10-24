# Tenant-scope adoption: the escape hatches, and the model inventory

**P1-PLT-03**, acceptance criteria 1 and 2. Criterion 3 lives in
[`P01-NULL-TENANT-TIGHTENING-PROPOSAL.md`](./P01-NULL-TENANT-TIGHTENING-PROPOSAL.md), which is
where the row asks for it — *separate*.

---

## 1. Why `forStoreContext()` and `allowCrossTenant()` have zero call sites

The row's title reads as a complaint, and the obvious reading is that the hatches were built and
then forgotten — that somewhere in `app/` there are queries silently crossing tenants which ought
to be doing it explicitly. **That reading is wrong, and the reason is worth more than a count.**

`config/tenancy.php` names four contexts where no tenant resolves. Here is what each one actually
gets today:

| context | what resolves | needs a hatch? |
|---|---|---|
| the platform operator | `MODE_SUPER_ADMIN`, `store_ids => []`, **no predicate emitted** (`StoreScope:536-548`) | **No** — already unscoped |
| a merchant with several stores | `admissibleStoreIds()` = current store ∪ owned stores | **No** — already crosses, by design |
| the storefront, the `customers`/`deliveryboy` guards, a queued job, a console command | no `web` principal → `null_tenant`, which is `open` → **no predicate** | **No** — nothing to escape from |

So there is no production query that needs either hatch, because every context that would is
already covered by a built-in branch. **Adding hatch calls to make the count non-zero would be
making the code say something that is not true.**

The hatches are for the world *after* `null_tenant` is tightened — which is exactly why criterion 3
is a proposal rather than a change, and why the two criteria belong in one task.

### The property this task leaves behind instead

`tests/Feature/Tenancy/TenantScopeHatchAdoptionTest` turns "zero call sites" from an unexplained
count into a **maintained property**. It asserts:

1. both hatches still exist and are public static — so "absent from production code" cannot be
   confused with "renamed or deleted";
2. that the census is reachable — the enforced-model list is non-empty and at least one scanned
   directory exists, because four censuses in this project have passed vacuously on a spelling that
   had changed;
3. **that no code in `app/Console/Commands`, `app/Jobs`, `app/Observers` or `app/Listeners` queries
   an enforced model without opening a hatch.** Today that set is empty: there are no jobs and the
   two Zid console commands touch no enforced model. The first one that does will fail this test
   and be told which hatch to use — because that is the first context in which the absence of a
   call site stops being correct and starts being a bug;
4. that the operator bypass still emits no predicate. If it were ever narrowed, every cross-store
   operator query *would* need `allowCrossTenant()`, and this is the tripwire.

The scan strips comment lines before matching, because a docblock naming a model would otherwise
satisfy it — the trap the project skill records for source-grep ratchets.

---

## 2. The model inventory — and why it is a test, not this document

Criterion 2 asks for "a documented inventory of which models are scoped and which are deliberately
not". **It already exists, and it is better than a document:**
`tests/Feature/Tenancy/TenantModelClassificationTest`, whose own docblock states the reason — *"the
brief's item 1, turned into a durable test instead of a document that drifts"*.

Duplicating it here would create exactly the second, rotting copy it was built to avoid. So this
section records **where it is, what it covers, and what was verified against it** rather than
restating it.

### What it covers, read from the live migrated schema at runtime

Not from `$fillable` — several models declare `store_id` for a table that does not have the column
— and not from a grep. `Schema::hasColumn()`, against `migrate:fresh`:

| partition | count |
|---|---|
| table exists **and** has `store_id` → tenant-discriminated | 56 |
| table exists, **no** `store_id` → platform-global | 25 |
| **no table at all** → GATE F | 14 |
| total, asserted exhaustive | **93** |

And the distinction that matters: **tenant-discriminated is not the same as scopable.** 56 models
carry the column; **10** are enforced. The test holds three separate reasoned exclusion lists:

- `publicStorefrontModels()` — 28 models whose rows an anonymous visitor renders. Scoping these
  blanks the catalogue, the category menu, the blog and the checkout options for a signed-in
  merchant browsing any other store — silently, because an empty result set is not an exception —
  and buys no confidentiality, because the anonymous visitor those pages exist for was never
  filtered. This has happened twice here already: scoping `Menu` blanked the storefront navigation.
- `doNotScopeDespiteHavingTheColumn()` — models with the column that must still never be scoped,
  each with its reason. `Role` is the instructive one: `roles.store_id` is **nullable**, so
  platform-level roles carry `store_id IS NULL`, and `store_id = ?` matches no NULL in SQL —
  scoping `Role` would hide every platform role and take the permission system down.
- the GATE F tableless set, which includes `StoreWallet`, `StoreWalletTransaction` and
  `StoreWithdrawal`. Three money-bearing models carrying the trait and not enforced looks alarming
  until you see why: **there is no table to scope.**

### The one hole, and it is a real one

A census of trait-carriers against the allowlist gives **17 carriers − 10 enforced = 7** models
with the scope registered and no predicate. Six are accounted for above —
`Product`, `Category`, `Shipping` are public storefront content; the three wallets are GATE F.

**`DeliveryBoy` is the seventh, and it appears in none of the three reasoned lists.** It is named in
`BelongsToStore`'s docblock as one of the seven, and nowhere is it said *why*. An unexplained
exclusion is a hole in an inventory, so it was investigated rather than left:

| question | measured answer |
|---|---|
| does `delivery_boys.store_id` exist? | yes, `nullable()->index()` (`2024_..._create_delivery_boys_table.php:23`) |
| is it the `Role` nullable trap — can a driver exist with `store_id IS NULL`? | **not from this codebase.** Both writers set it: `DeliveryBoyApiController:160` (`'store_id' => $store_id`) and `DeliveryBoyController:71` (`= getCurrentStore()`). Legacy rows are the one precondition an operator must check — a single count |
| would enforcing it break driver login? | **no.** A driver authenticates on the `deliveryboy` guard, which `auth()->user()` on `web` cannot see, so the lookup resolves `MODE_UNRESOLVED` and `null_tenant => 'open'` adds no predicate |
| would it add protection? | **yes.** Of 26 `DeliveryBoy::` query sites in `app/`, only 4 carry a store filter |

So `DeliveryBoy` is **not a deliberate exclusion — it is an unclassified enforcement candidate**,
and that is now recorded in `TenantModelClassificationTest` so the hole cannot quietly reopen.

**Enforcing it is NOT done here, deliberately.** It changes the visibility of an *authentication
provider*, which is the class of change that has twice broken this codebase, and the evidence it
needs — a full-suite run with the model allowlisted — is a package of its own, not a line in a
documentation task. It is raised as its own row.

---

## 3. What this task did not do, stated plainly

- **No hatch calls were added.** Section 1 says why; adding them would be dishonest code.
- **No model was moved onto or off the allowlist.** `DeliveryBoy` is recorded as a candidate, not
  enforced.
- **`null_tenant` was not changed.** The proposal measures the flip and recommends against it as a
  default.
