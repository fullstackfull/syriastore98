# P1-INV-18 — `POST lotteryresult` draws another tenant's ticket codes

**Issue:** #64 · **Guard:** `app/Http/Middleware/LotteryDrawOwnershipGuard.php` ·
**Shared predicate:** `app/Http/Middleware/Concerns/ComparesRowOwnership.php` ·
**Suite:** `tests/Feature/Security/LotteryResultOwnershipTest.php` (14 tests) ·
**Script:** `tests/Support/Security/bin/verify-lottery-code-collation-on-mysql.php`

## What was found

`packages/workdo/LotteryPrizes/src/app/Http/Controllers/LotteryResultController.php` `store():66-193`,
reachable as `POST lotteryresult` from that module's `Route::resource('lotteryresult', …)`
(`routes/web.php:21`) inside `['auth','web','PlanModuleCheck:LotteryPrizes']`. **No permission
check** — only `index():32` checks `Manage Lottery Winner`.

```php
$selectedCodes = explode(',', $request->input('selected_codes'));          // :78
$purchased_tickets = PurchaseTicket::with(...)
    ->whereIn('lottery_code', $selectedCodes)->get();                      // :86  NO STORE PREDICATE
$store = Store::find($user->current_store);                                // :91  the CALLER'S store
```

Then per ticket:

- `:125-142` a **host `App\Models\Order`** with `product_id` = the *victim's* product,
  `final_price` = the victim's `ticket_price`, `payment_status => 'Paid'`, `store_id =>` the
  *attacker's* store. `payment_status` is exactly what `OrderObserver::updated` reacts to.
- `:148` `LotteryCustomer::insert()` with `'store_id' => $ticket->store_id` — rows written **into
  the victim's tenant**, and `::insert()` bypasses model events and every global scope.

That second one is the unusual direction: not reading another tenant's data but **planting rows in
it**, where the victim's own `where('store_id', …)` listings show them as their own.

### Reproduced by execution

A merchant in store 1, holding `Manage Lottery Winner` and the plan entitlement, drew store 2's
code and produced:

```
orders:            store_id 1, payment_status "Paid", final_price 777,
                   product_json embedded store_id 2   ← the victim's product, in the attacker's order
lottery_customers: store_id 2                         ← planted inside the victim's tenant
```

### Two reasons my first two reproduction attempts reported GREEN

Both worth recording, because each is a way a security test passes for the wrong reason.

1. **Both writes sit inside `if ($UserEmailTemplate)` (`:103`).** With no such row the loop runs —
   the `sleep(1)` per ticket was visible in the test duration — and writes nothing. The fixture
   needs the *caller's own* `email_templates` row (named `Lottery Win`, `created_by` = caller) and a
   `user_email_templates` row. Those are the attacker's own notification setup, not a barrier.
2. **`sendOwnerEmailTemplate:739` does `$content->from = $template->from` on a null lookup.**
   Without an `email_template_langs` row that is an uncaught `Error` — HTTP 500 before any write.
   A fixture gap, not a shipped protection: a real install has those rows, and a legitimate
   own-store draw hits the same crash without them. `content` is left empty on purpose so `:740`'s
   `if (!empty($content->content))` skips the actual mail send.

## The fix, and why it is a third guard

The predicate is a **set**, which is why this is not an entry in either sibling. Both of those
answer *"which row does this request name?"* — from a body key (`ModuleOrderMutationGuard`) or from
a route parameter (`ModuleResourceOwnershipGuard`). This request names no row: it carries a
comma-separated list of ticket **codes**.

**The whole call is refused rather than the list filtered.** The handler re-reads `selected_codes`
itself, so a guard that narrowed the list would authorise a subset while the handler acted on the
whole of it — the same divergence as authorising one row while the handler mutates another, which
`ModuleOrderMutationGuard` shipped once and had to fix.

### The first version of that predicate asked the wrong question and was bypassable on MySQL

This is the part worth reading. It originally ran

```php
whereIn($column, $values)->where('store_id', $currentStore)->pluck($column)
```

and compared that against the requested values with `array_diff` — asking *"does every value I was
given resolve to a row I own?"*. The handler asks *"which rows does `whereIn` match?"*, with no
store filter. **MySQL's default collation separates those two questions.** Measured on MySQL
8.0.46, database collation `utf8mb4_0900_ai_ci`:

| | |
|---|---|
| rows | `(1, 'ABC-1', store 1)` · `(2, 'abc-1', store 2)` |
| old guard query | `… IN ('ABC-1') AND store_id = 1` → row 1 only → **ALLOW** |
| handler query | `… IN ('ABC-1')` → rows 1 **and 2** → draws store 2's ticket |

`ai_ci` is accent- *and* case-insensitive, so `CAFE-1` reaches a victim's `CAFÉ-1` the same way —
both measured. `config/database.php` defaults `DB_COLLATION` to `utf8mb4_unicode_ci`, also `_ci`,
so production is the case-insensitive shape.

**SQLite's BINARY default hides it completely**, so no test in the Feature suite could find it and
none can prove it fixed. The same engine-hiding class as P01-SEC-13 and as the `'5abc'` coercion.
**It was found by thinking the predicate through, not by a test** — the suite was green at 14/14
and the removal checks had already run.

The predicate now asks the engine's own question: run the handler's `whereIn` **without** a store
filter and refuse if any row it matches lies outside the caller's store (a NULL `store_id` counts
as outside). Whatever the collation does, guard and handler see the same rows. The value check is
kept as a second, stricter limb — every requested value must also be accounted for by an owned row
— which closes the unmatched-code 500 and, where PHP's case-sensitive `array_diff` disagrees with
the database, disagrees in the direction of refusing.

## What this closes beyond the headline, and what it does not

**Closed**

- A code matching **no** ticket is refused here, so `:151`'s use of `$lottery_setting` after the
  `:95` loop — an undefined variable, HTTP 500 — is no longer reachable from this route.
- The **cross-tenant half of the replay guard**. `:79` resolves
  `LotteryCustomer::whereIn('lottery_code', …)` with no store predicate and returns an error
  **naming** the codes, which is an existence oracle over every tenant's code space. It is now
  reachable only with codes the caller owns, and a test asserts that a drawn foreign code and a
  code that never existed answer identically.

**Not closed, and recorded rather than papered over**

- If two tenants own the **same code string** and one has drawn it, the other is still refused — by
  the handler's own global replay guard, not by this guard. The guard allows (the caller does own
  the code) and the handler refuses. A cross-tenant denial of service, closable only inside
  vendored code. `test_KNOWN_LIMITATION_an_identical_code_string_in_another_tenant_still_blocks_the_draw`
  pins the current behaviour so an upstream fix shows up as a failure prompting this to be re-read.
- `:144`/`:185`'s `sleep(1)` per ticket. The trait caps the list the guard will consider at 500
  values, which bounds what the *guard* costs; the handler's sleep is vendored.

## Why neither sibling's census could have found this — my own mistake

P1-INV-17's stated scope was *"every `update()` and `destroy()` body behind the two modules' six
`Route::resource` declarations"* — `store()` was never in it, and that write-up's "What is NOT
closed" named only `show()`. Worse, `ModuleOrderMutationGuardTest`'s census carried

```
'lotteryresult/{lotteryresult}' => 'stub: LotteryResultController::update():221 and destroy():231 are both `//`',
```

— true of those two methods and **falsely reassuring about the resource**.

That is the **fourth** distinct way a census in this repository has failed to cover what it claimed:
keyed on uri words (order documents), on `Route::post` lines (module mutations), on the uri prefix
(resource mutations), and now **an exemption scoped to the methods somebody happened to look at.**
All three lottery entries now state which methods were read *and which were not*, and this suite
carries `test_every_module_resource_store_method_is_guarded_or_reasoned` — a census over `store()`
itself, with a reachability assertion so a rename cannot make it pass vacuously.

## Evidence

### Removal checks — 10 of 12 isolate

| # | control removed | result |
|---|---|---|
| R1 | the `bootstrap/app.php` registration | **8 of 14 fail** |
| R2 | the ALL rule (filter instead of refuse) | 1 fail |
| R3 | the store predicate in the set query | 5 fail |
| R4 | the empty-set refusal | 1 fail |
| R5 | the permission limb | 1 fail |
| R6 | operator-first ordering | 1 fail |
| R8 | method+uri keying | 1 fail |
| R9 | the inertness early return | 1 fail |
| R10 | the uniform refusal body | 1 fail — **only after a test was added** |
| R11 | the permission-row existence check | 1 fail |
| R7 | the no-codes refusal | **GREEN — subsumed** |
| R12 | the empty-store refusal | **GREEN — subsumed** |

**R10 needed a new test.** The oracle test compares two denials that happen to share a reason
(`code outside store`), so disclosing `$reason` in the body left the suite green.
`test_a_permission_refusal_and_an_ownership_refusal_are_indistinguishable` compares denials with
*different* reasons, which is what actually pins the uniform body — and it matters, because
"you lack the permission" and "that code is not yours" carry different information.

**Both greens are subsumptions, labelled rather than counted.** Removing R7 leaves
`explode(',', '')` producing `['']`, which the trait filters to the empty set, which R4 refuses.
Removing R12 leaves `->where('store_id', null)`, which matches no row in SQL, so the ALL rule
refuses. Both explicit checks are kept: each names its case in the log instead of mislabelling it.

## What the adversarial review found, and the two claims measurement refuted

It ran after the suite was green at 14/14 with removal checks done. It found **no** request that
makes the guard allow a set it should not, and it confirmed all five of my executed claims. It
found three defects in the surrounding work, and two of its own proposals did not survive
measurement — which is worth as much as the ones that did.

### Confirmed and fixed

**The `store()` census overclaimed in its name.** It was called
`test_every_module_resource_store_method_is_guarded_or_reasoned` while globbing only AuctionProduct
and LotteryPrizes — six resources out of 60+ across ~45 module route files. A census whose *name*
overclaims is the same defect as an exemption that overclaims, which is what it was written to stop.
Renamed to `test_every_resource_store_in_the_two_guarded_modules_is_reasoned`, with its two further
limits stated: it matches `Route::resource(` only, and a widened version would need the sibling's
**two** globs because at least one module declares resources in a capital-`Routes` directory a
lowercase glob skips on Linux.

**One reasoned entry was imprecise in exactly the way #64's was.** `'lotteryproduct' => '…IS
store-scoped'` is true of `store_id` and silent about the identifier that matters:
`lottery_products` (`:117`, `:119`) is a **host `Product` id taken from request input with no
ownership check**, and `Product` carries no effective scope — so a merchant can point their own
setting at another tenant's product, which rides into `product_json` on the draw. The entry now
says which identifier was checked and which was not.

**The sibling census entry went stale.** `ModuleOrderMutationGuardTest`'s `lotteryresult` exemption
still read *"OPEN at #64 … it is NOT protected"* after this guard landed, and the test passed
because only the key is asserted. A stale exemption reason is the same defect class the surrounding
comment block exists to prevent.

**Two test hardenings.** The two headline tests asserted only row counts, so they would have been
satisfied by the route simply **not existing** — the module-prefix version of the 404 failure mode.
Each now asserts `assertNotSame(404, …)`. And
`test_a_permission_refusal_and_an_ownership_refusal_are_indistinguishable` grants the permission
*between* its two requests; if that grant ever failed to take (stale `Cachable` `User`, Laratrust
cache) both denials would carry reason `permission`, the bodies would match trivially, and R10
would silently re-vacuate. It now asserts the grant took.

### Independently found what I had already fixed

The review's F1 described the guard proving *"every named code has a row in my store"* while the
handler acts on *"every row in any store whose code matches"* — and its recommended fix, *"refuse if
any named code matches a row whose `store_id` is not the caller's, including NULL"*, is **exactly the
inversion already shipped**. Two routes to the same defect, which is the best evidence the shape is
right. Its observation that the write half had **no test** was correct, and
`test_an_identical_code_string_in_another_tenant_is_not_drawn_through_the_callers_own` now covers it
on SQLite with identical code strings; the collation-equal case stays the MySQL script's job.

### Refuted by measurement — mine first, then the review's sharpening of it

I claimed trimming in the predicate *"can only ever be STRICTER"* than the handler. The review
inverted that and proposed refusing any untrimmed value, reasoning that the guard would accept
`"\nCODE"` as `CODE` while the handler matched nothing and 500'd on an undefined
`$lottery_setting`.

**Both readings are wrong.** `TrimStrings` is in the **global** middleware stack, so the field value
is already trimmed before either the guard or the handler reads it. Driven through the real stack,
`"\nWHITESPACE-1"` and `"WHITESPACE-1"` behave **identically** — both reach the handler and both
500 for an unrelated reason. So there was no guard/handler divergence to close. I had implemented
the review's rule before measuring it; **it was removed again**, because keeping a control on a
refuted justification is worse than not having it — and it had a real cost: it turned an empty
selection into a 403. `test_whitespace_in_the_posted_value_behaves_the_same_as_without` is the
measurement, kept so the question is not re-argued.

(`TrimStrings` normalises the *outer* value only, so an inner element can still carry whitespace.
That divergence runs in the **safe** direction — the guard considers a value the handler ignores —
and the dangerous case is covered by the first limb regardless, which asks about the rows.)

### One UX regression I introduced, and the review was right about it

An empty `selected_codes` answered **403** on the web path. The draw screen posts a plain form whose
hidden field is initialised empty with no client-side guard, so a mis-click used to come back as a
302 with a toast and now cost the merchant the screen. `test_an_empty_code_list_denies` could not
catch it because it drove `postJson` for every payload, and the JSON branch correctly still answers
403.

Fixed by softening **only** that reason and **only** for a non-JSON request — "you sent nothing"
discloses nothing about any tenant's rows, so the uniform-refusal property the two oracle tests pin
is untouched. The usable-set check also moved **into the guard**: `' , , '` survives the blank check
(`TrimStrings` leaves `', ,'`) and explodes to nothing usable, which the predicate was reporting as
`code outside store` — a 403 for the same mis-click.

### And a 500 on the SUCCESS path, found because my own positive test tolerated it

`LotteryResultController:156-157` does `UserEmailTemplate::where('template_id', $FailTemplate['id'])`
on a null `EmailTemplate` lookup, so **every successful draw 500s after writing its rows** unless
the caller also has a `Lottery Fail` template. My positive test asserted only
`assertNotSame(403, …)` plus the row — and `500 !== 403`, so it passed while every draw was
crashing. That is the trap CLAUDE.md names, on the positive side where it is easiest to miss. The
test now also asserts the status is not a 500, and the fixture seeds the second template so a
legitimate draw completes — which is what makes the assertion meaningful rather than decorative.
The underlying null dereference is pre-existing vendored code and is raised as **#67**, along with
three more the review found: an anonymous ticket writer into any store (which amplifies this
guard's one documented residual), an unscoped refund delete, and a cross-tenant email-template
resolve.

### Removal checks on the three new controls

| # | control removed | result |
|---|---|---|
| R13 | limb 1, the foreign-row test | 1 fail — the shared-code write path |
| R14 | the softened `no codes` redirect | 1 fail |
| R15 | the usable-set check in the guard | 1 fail |

Suite now **17 tests, 120 assertions** (16 controls + 1 behaviour pin).

### Gates

Feature suite with the known-outcome gate; the two raw-query inventories were updated because the
set predicate is **two** queries by design — limb 1 the collation-safe core, limb 2 the value check
— and collapsing them would be collapsing two different questions. Owned-path sites 11 → 13;
platform-wide `app` 113 → 115, total 158 → 160, both variable-table so the per-table breakdown is
unchanged.
