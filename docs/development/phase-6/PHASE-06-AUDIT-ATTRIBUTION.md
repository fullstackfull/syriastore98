# P06-AUDIT — every operator action was filed under store 1, whoever it was taken against

**Date:** 2026-11-24
**Owner instruction:** item 2 of the 2026-11-24 triage directive.
**Severity:** audit-trail integrity. **Not** a cross-tenant disclosure today, and that distinction
is measured rather than assumed.

---

## 1. The defect

`AuditLog::record()` took `store_id` from `getCurrentStore()`, and that helper **fabricates a
tenant** for the platform operator — `app/Helper/helper.php:888-889`:

```php
if ($user->type == 'super admin') {
    return 1; // Default store for super admin
}
```

So every audit row written by the operator was filed under **store 1**, regardless of whose data
changed. Two consequences:

- Auditing merchant Y's history **misses every operator action against Y** — precisely the actions
  a tenant cannot take themselves, and therefore the ones a trail exists for.
- Store 1 is a real store. Its audit history accumulated rows describing actions against **other
  merchants'** data.

**Why this is integrity and not disclosure.** Measured: `AuditLog` appears in three controllers and
**every reference is a write** (`BankTransferController`, `PlanController`, `UserController`), plus
one direct `create()` in `ThemeVersionController`. There is no merchant-facing audit reader, so
nobody can read the wrong part of the trail. It becomes a disclosure the day one is added — which
is what the ratchet in §4 exists for.

### Phase 03 already found this, and routed around it

`ThemeVersionController`'s own docblock, written in Phase 03 M4:

> *"IT DOES NOT USE `AuditLog::record()`, and that is not a style choice. That helper sets
> `'store_id' => getCurrentStore()` … the literal-store-1 fallback M4-1 and M4-2 spent two slices
> removing — so a refusal by an operator who named no store would be audited against a merchant's
> store."*

An earlier phase reached the same conclusion independently and worked around it **locally** rather
than fixing the helper. That is corroboration, not duplication. The bypass **stays**: it passes the
store the *gate* resolved for that specific request, which is more precise than anything `record()`
can infer. Its docblock is updated to say so, since its claim about `AuditLog.php:36` is now stale.

---

## 2. The fix

`App\Support\Audit\AuditAttribution::storeFor($subject, $actor)`, in the owner's own order of
precedence:

1. **The subject's own `store_id` wins.** The owner's rule verbatim: *"Do not infer the affected
   tenant solely from an operator's current UI selection when the modified resource identifies a
   different store."* `PlanOrder` carries a nullable `store_id`, so the three plan-order actions
   now file against the order's store rather than the actor's session.
2. **An operator with no store-bearing subject gets `null`** — platform-wide, stated as such rather
   than invented. `audit_logs.store_id` is `->nullable()` (migration line 87), which is what makes
   that representable.
3. **A merchant or staff member keeps the attribution they already had** — `current_store`, else
   their single owned store.
4. **No authenticated `web` user → `null`.** Console, queue, and a storefront request with no panel
   session. `auth('web')` is read explicitly because `CustomAuth` can leave the default guard as
   `customers` or `deliveryboy`, and a `Customer` is not a `User`.

**`getCurrentStore()` is NOT changed**, and that is a scope decision with a number behind it: it has
**1,024 call sites in `app/`**, and the operator's `1` is load-bearing for an unknown number of
them. The fix belongs at the audit door, which is the one place that needs a *truthful* answer
rather than a usable one.

**The acting user is untouched** — still `auth()->id()`.

**No existing row is rewritten.** Whether the historical rows filed under store 1 should be
re-attributed is an owner decision about live data, and in most cases the information needed to do
it correctly is no longer recoverable from the row itself.

---

## 3. Evidence

| | |
|---|---|
| tests | **8 passed**, covering two stores, the operator with and without a selection, a background context, and a shopper on another guard |
| mutations | **5 applied, 5 caught** — two only after the harness exposed a test that could not discriminate |
| docs corrected | the audit migration's docblock (it described the defect as current behaviour) and `ThemeVersionController`'s claim about `AuditLog.php:36` |

### The two mutations that survived first

1. **"the operator is treated as a tenant again" SURVIVED.** `makePlatformAdmin()` leaves
   `current_store` empty and owns nothing, so with the operator check deleted the *merchant* branch
   answered `null` too — identical outcomes, nothing pinned. The operator now **owns a store** in
   the fixture, so the branches differ. (`getCurrentStore()` still answers 1, because its
   `super admin` branch fires before it looks for an owned store, so the premise holds.)
2. **"a merchant stops being attributed at all" SURVIVED.** With `current_store` equal to the
   merchant's only store, deleting that branch changed nothing — the
   `Store::where('created_by', ...)` fallback returned the same id. The merchant now owns **two**
   stores and has selected the second, so the branch is load-bearing.

A third thing the harness caught before any mutation ran: `test_the_subjects_own_store_beats_the_actors_selection`
originally created store B **first**, so B's id was 1 — the very id the operator fabricates. The
test could not tell the right answer from the wrong one. A decoy store now takes id 1.

---

## 4. The reader ratchet

The owner's instruction: *"Ensure any future audit reader enforces proper authorization."* There is
nothing to gate today, so the honest way to honour it is a ratchet rather than a reader nobody
asked for. `test_no_audit_reader_exists_without_an_authorization_gate` sweeps `app/` and fails if an
`AuditLog` **read** appears in a file with no `isAbleTo(` / `super admin` / `Gate::` / `authorize(`
beside it.

Two things it needed before it was worth anything:

- **A write list.** The first draft treated anything but `record` as a read and flagged
  `ThemeVersionController::create()` — an Eloquent *insert*. Writes are now enumerated.
- **A premise.** A test that asserts a list is **empty** is satisfied perfectly by a broken regex,
  so it also asserts that the four known writes **are found**. Mutation-proved: blinding the regex
  now fails the test instead of passing it.
