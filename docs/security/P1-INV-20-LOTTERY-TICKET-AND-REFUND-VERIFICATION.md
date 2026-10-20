# P1-INV-20 — an anonymous cross-tenant ticket writer, an unscoped refund delete, and a 500 on every successful draw

**Issue:** #67 ·
**Guards:** `app/Http/Middleware/LotteryTicketWriteGuard.php`,
`app/Http/Middleware/LotteryRefundOwnershipGuard.php`,
`app/Http/Middleware/LotteryMailTemplatePrerequisite.php` ·
**Shared predicate:** `app/Http/Middleware/Concerns/ComparesRowOwnership.php` ·
**Suite:** `tests/Feature/Security/LotteryTicketWriterTest.php` (19 tests, 104 assertions) ·
**Script:** `tests/Support/Security/bin/verify-lottery-refund-collation-on-mysql.php`

Four items, one module, **three different mechanisms** — which is why this is three middleware and
not one. They are grouped by where they were found, not by what they are.

---

## Item 1 — `StoreLotteryPayment` writes a ticket into any store, anonymously

`packages/workdo/LotteryPrizes/src/routes/web.php:41-52` declares `store-lottery-payment` and seven
more routes inside a **`['web']`-only group** — no `auth`, no `PlanModuleCheck`, no permission. The
same shape as P1-INV-14's six anonymous admin mutations.

The defect is the fallback at `LotteryPrizesController:568-570`:

```php
$requests_data = (Session::get('lottery_request_data'));
if ($requests_data == null) {
    $requests_data = $request->all();          // the caller's whole payload
}
```

and then:

```php
:594   $store = getStore($slug);                        // the CALLER'S url slug
:613   $lottery_data->lottery_id  = $lottery_setting->id;
:614   $lottery_data->customer_id = auth('customers')->user()->id ?? 0;
:617   $lottery_data->amount      = $ticket_amount;     // from $requests_data['ticket_price']
:618   $lottery_data->save();
```

### Reproduced by execution

```
GET /victim-store/store-lottery-payment?ticket_price=1&payment_methods=telegram&product_id=N
    no bearer token, no session, no guard, no payment

STATUS  200
TICKETS before=0 after=1
ROW  store_id=1  lottery_id=1  customer_id=0  amount=1
```

`amount=1` is the attacker's own query-string value landing in a money-bearing column.

A premise test asserts the route is registered and carries no `auth` before any of this, because
"no row was written" is vacuously true of a 404 — the failure mode `CLAUDE.md` names.

### ⚠ ITEM 1 IS NOT CLOSED. The guard costs the attacker one request

This is the correction that matters most in this document, and it came from adversarial review
**after** the guard was written, tested, green, and described here as a fix. The first version of
this section, of the guard's docblock and of `bootstrap/app.php`'s comment all said requiring the
session "refuses the attacker". **Measured, it does not.**

```
POST /victim-store/lottery-form-data        (no auth on that route either)
     product_id=<the victim's lottery product>, payment_methods=telegram, ticket_price=1,
     and the nine lottery_info[*] fields the validator at :513-527 requires
-> 200,  session key present after POST: YES

GET  /victim-store/store-lottery-payment    (no query string needed)
-> 200
TICKETS before=0 after=1
ROW  store_id=1  customer_id=0  amount=1
```

`processTelegram` (`LotteryPaymentService:1135-1140`) is literally
`Session::put('lottery_request_data', $request); return view(...)`, so step 1 hands the caller the
session key — containing **their own** `ticket_price`. The outcome is identical to the one-request
defect, including the forged amount.

**Why no middleware can close it.** The two requests are the shape of a legitimate *guest* purchase
minus the payment — and for `telegram` and `whatsapp` there is no payment step at all: the module
creates the row and settles out of band by design. The only discriminator is whether money moved,
which means the gateway's own signature or a transaction lookup **inside the handler**. Requiring
`auth('customers')` would close it by breaking guest purchase, which
`test_a_guest_inside_the_real_flow_still_writes_the_ticket` pins as a real flow.

So **#67 item 1 stays open**, and
`test_KNOWN_LIMITATION_two_anonymous_requests_still_write_a_ticket_into_any_store` asserts the
remaining defect in the suite — so the limit is visible where the work is, and so whoever closes it
properly sees that test fail and deletes it rather than this file over-claiming.

### What the guard does close, and why it is kept

The **single-request, validator-free** variant: the `$request->all()` fallback at `:568-570`,
through which a caller supplies every field by hand, including fields the lottery form never
collects. That is the only path to the five gateway-callback routes, which have no form step of
their own. Two facts make refusing it non-regressive, and both were counted rather than assumed:

1. **`LotteryPaymentService` populates that key on every path** before handing off to a gateway —
   **34** `Session::put('lottery_request_data', …)` sites, counted with `grep -c`. An earlier
   version of this document, of the guard's docblock and of the test's comment all said **15**,
   which was a partial count of a 1700-line file. The conclusion is unaffected; the number is
   load-bearing by this project's own standard, so it is corrected rather than quietly dropped.

2. **The session-less path cannot serve a real gateway callback anyway.** These routes are wired as
   `return_url`, `cancel_url` and `callback_url` (`LotteryPaymentService:335`, `:438-439`, `:528`,
   `:655-656`, `:713-714`, `:779-780`, `:899`). A cross-site POST callback loses the session cookie
   — `config/session.php:202` leaves `same_site` at `env(…, null)`, which browsers now treat as Lax
   — and when it does, `:571` reads `$requests_data['ticket_price']` from the *gateway's* POST body,
   which has no such key: an undefined-key warning, promoted to `ErrorException`, HTTP 500. The
   branch is already broken for the legitimate caller and useful only to someone sending our field
   names by hand. Refusing it replaces a 500 with a message.

### The guard asks the handler's question, not a tidier one

The handler's test is `$requests_data == null` — loose equality, so also true of `''` and `[]`. A
guard using `session()->has()` would pass an empty array through and the handler would take the
fallback anyway: present and useless. `test_a_session_value_the_handler_treats_as_empty_is_also_refused`
drives `[]`, `''` and `0`.

### Keyed on the controller action, not the uri

**Eight** routes reach this one method, two with an *optional* `{storeSlug?}` segment. Confirmed
against the router rather than assumed:

```
GET|HEAD                         {storeSlug}/store-lottery-payment
GET|HEAD|POST|PUT|PATCH|DELETE   {storeSlug}/get-ticket-payment-paytm
GET|HEAD|POST|PUT|PATCH|DELETE   {storeSlug}/get-ticket-payment-aamarpay
GET|HEAD|POST|PUT|PATCH|DELETE   {storeSlug}/get-ticket-payment-midtrans
GET|HEAD|POST|PUT|PATCH|DELETE   {storeSlug}/store-ticket-payment-khalti
GET|HEAD|POST|PUT|PATCH|DELETE   {storeSlug}/store-ticket-payment-authorized
GET|HEAD|POST|PUT|PATCH|DELETE   {storeSlug?}/ticket-telegram
GET|HEAD|POST|PUT|PATCH|DELETE   {storeSlug?}/ticket-whatsapp
```

P1-INV-17's census missed live routes **twice** by keying on uri shape — once on uri words, once on
a uri prefix. `test_every_route_reaching_the_handler_is_guarded` derives the list from the router
and asserts its size, so the array in the test cannot drift from reality.

### And a guest must still be able to buy a ticket

`lottery-form-data` carries no `auth` either, and `:614` writes `customer_id = 0` for a guest. A
guard requiring a signed-in customer would have closed the defect **by breaking guest checkout** —
which is why this guard tests the *flow* and not the principal.
`test_a_guest_inside_the_real_flow_still_writes_the_ticket` pins that.

---

## Item 2 — the refund deletes a ticket with no store predicate

`LotteryPrizesController:870-871`:

```php
$lottery_setting = PurchaseTicket::where('lottery_code', $request['ticket_code'])
    ->where('customer_id', auth('customers')->user()->id)->first();
$lottery_setting->delete();
```

`PurchaseTicket` is a `Workdo\*` model, so it is absent from `config/tenancy.php`'s `scoped_models`
and `StoreScope::isActiveFor()` short-circuits: **no predicate for anyone**. The two keys above are
the whole authorisation.

### Why a customer has a row in another store at all

This is the step that makes it reachable, and it is not exotic: **`customers` is one session guard,
not one per store.** A shopper signed in at store A who walks into store B's storefront is still a
populated `customers` principal there, so `:613` stamps their store-A customer id onto a store-B
ticket row. The refund form on *either* storefront then reaches *both* rows, and `->first()` decides
by primary key which is destroyed. Store B's merchant loses a sold ticket without store B's refund
rules ever running.

### Reproduced by execution

```
POST /store-a/refund-lottery-form-data  {ticket_code: <store B's code>}
ITEM2 STATUS  302                       ("Refund sucessfully")
ITEM2 store-B ticket still present: NO -- DELETED

POST /store-a/refund-lottery-form-data  {ticket_code: 'NOSUCHCODE'}
ITEM2b STATUS  500                      (->delete() on null)
```

### What closes it

`LotteryRefundOwnershipGuard`, in four checks:

| check | closes |
|---|---|
| a signed-in `customers` principal is required | the anonymous 500 — `auth('customers')->user()->id` is dereferenced unguarded on a route with no `auth` |
| ~~the slug-resolved store must equal the customer's own `store_id`~~ | **REMOVED — this was a regression I introduced.** See below |
| `noMatchedRowOutsideStore()` — the shared set predicate | the code reaching any row outside that store, collation included |
| `$ownedByCaller` — store **and** customer | another shopper's ticket in the *same* store, so the guard is not weaker than the handler it fronts |

The set predicate's second internal limb removes the unmatched-code 500 for free: it requires every
requested code to be accounted for by a row inside the store, so a code matching nothing is refused
rather than reaching `->delete()` on null.

### The limb I had to remove, because it refused a refund the module offers

Adversarial review found it, and reading `refund_lottery():862` settled it:

```php
$tickets = PurchaseTicket::where('store_id', $store->id)                 // the SLUG's store
    ->where('customer_id', auth('customers')->user()->id)                // the caller
    ->where('product_id', $product->id)->where('lottery_id', $lottery_setting->id)->get();
```

So a shopper registered at store A who bought a ticket on store B's storefront sees that ticket
**listed as a radio input** on B's refund form (`theme/refund-lottery-form.blade.php:166`) and
submits it to B's slug. My `resolveStore()` required the slug's store to equal the *customer's own*
`store_id` — and refused them, permanently, with the deliberately uniform refusal body, so neither
they nor support could tell it from the attack refusal.

That mismatch is not an anomaly. **It is the premise of the defect this guard fixes**: `customers`
is one session guard, so a shopper's id lands on rows in whichever store they browse. I had turned
the premise into a denial — closing the attack by also closing the flow.

### The slug still authorises nothing

`CLAUDE.md` records **four separate defects** produced by treating a predicate built from request
input as a tenant check. The slug is request input, and it still is not the check: it only *names*
the store that the two remaining limbs are applied to. A caller naming a store can reach only their
own tickets in that store, which is exactly the handler's intent.

Dropping the limb reopened nothing, and that was verified both ways rather than argued: every
negative test still fails on reversion, including
`test_a_slug_naming_a_different_store_is_refused`, and
`test_a_foreign_code_posted_to_the_callers_own_slug_is_still_refused` was added for the mirror
case. `test_a_shopper_can_refund_a_ticket_they_bought_on_another_stores_storefront` — with a premise
assertion that `refund_lottery()`'s own listing predicate really matches the fixture — is what stops
the limb coming back.

### One thing this guard cannot do

It cannot make the handler delete the **right** row. The handler's query has no store filter, so it
still runs unscoped — the guard's contribution is that no foreign row can *match* it, which makes
the unscoped `->first()` safe by elimination rather than by correction. The same limitation is
recorded on #64's guard, and the consequence is identical: a caller whose own code collides, under
the database's collation, with a foreign one is **refused rather than served**. Refusing the owner
is the safe direction, and it is asserted rather than left to be discovered.

---

## Item 3 — the cross-tenant email template. NOT FIXED, and why

`LotteryPrizesController:715`:

```php
$template = EmailTemplate::where('name', 'LIKE', $emailTemplate)->first();   // no created_by
...
:722   $slug = Store::where('created_by', $template->created_by)->select('slug')->first()->slug;
```

On a populated multi-tenant install the mail body **and the store slug inside it** can come from
another tenant's template.

**There is no host-side seam.** `sendOwnerEmailTemplate` is a `public static` method called from
inside vendored controllers: no middleware, policy, FormRequest or query scope sits between caller
and callee. The only host-side lever is a global scope on the shared `App\Models\EmailTemplate`,
and that would reach **every module's** template reads and the platform operator's own management
screens — a blast radius out of all proportion to the finding, in a remediation project on a live
business.

So this criterion is **left unmet and stated**, the way #63's module-sweep criterion was. It is
recorded on #67, in `bootstrap/app.php`'s registration comment, and in
`RawQueryTenancyInventoryTest`'s `email_templates` row, so the next reader meets it from three
directions rather than rediscovering it.

Worth keeping straight: this does **not** weaken #64's reproduction. The `if ($UserEmailTemplate)`
gate at `LotteryResultController:99-103` *is* keyed on `created_by = $user->id`. Only the mail
content can come from elsewhere.

---

## Item 4 — every successful draw 500s after writing

`LotteryResultController:156-157`:

```php
$FailTemplate = EmailTemplate::where('name', 'Lottery Fail')->where('created_by', $user->id)->first();
$FailEmailTemplate = UserEmailTemplate::where('user_id', $user->id)
    ->where('template_id', $FailTemplate['id'])->first();
```

`$FailTemplate['id']` is an array offset on **null** — a PHP warning, promoted to `ErrorException`
by Laravel's error handler. Measured at HTTP **500** on a draw that was otherwise entirely valid:
the merchant's own store, own ticket, own permission. The writes at `:139` and `:148` have already
committed, so the merchant gets an error page and no way to tell whether the draw took.

### Why an ordinary merchant has no such template — sharper than #67 recorded it

`LotteryPrizesController::EmailTemplateDefaultData()` is the only code that creates `Lottery Win`,
`Lottery Fail` and `Lottery Remainder`, and it is called from **exactly one place**:
`LotteryemailTemplate():315` — the lottery mail-settings *screen*, behind
`Manage Lottery Mail Setting`.

Sibling modules wire the identical call into a **ViewComposer**, so it runs on every render:

```
packages/workdo/SpinToWinReward/src/app/Providers/ViewComposer.php:42
    SpinToWinRewardController::EmailTemplateDefaultData();
```

`PartialPayments` likewise. **LotteryPrizes has no such wiring.** A merchant who enables the module,
configures a lottery, sells tickets and draws a winner without ever opening that one screen has no
template at all. Ordinary, not an edge case.

### What closes it, and the line it does not cross

`LotteryMailTemplatePrerequisite` calls the module's own idempotent creator for the authenticated
merchant before the draw, creating the **`email_templates` rows only**.

It deliberately does **not** call `userTemplateDefaultData()`, the companion that creates the
`user_email_templates` join rows — even though `LotteryemailTemplate()` calls both. Those join rows
are what `if ($FailEmailTemplate)` tests to decide whether to **send mail**. Creating them here
would start sending lottery-loser email from a merchant who never configured a template: an
outbound behaviour change. Removing a crash must not become one. Without them `$FailEmailTemplate`
stays null, the `if` is skipped exactly as today, and the only thing that changes is that the
request does not 500. `test_the_prerequisite_creates_the_template_but_does_not_enable_sending`
asserts both halves, and a ratchet assertion in `OwnedPathRawQueryInventoryTest` pins the absence of
the write.

### A middleware that writes a row is unusual, so the reasoning is on the record

The null is inside vendored code, which this project does not edit, and no Policy, FormRequest or
query scope sits between `:156` and `:157`. The row created is the merchant's **own** notification
template, with the same name, owner and default content the module would create the moment they
opened its settings screen — so this moves the creation earlier, it does not invent data. It is
guarded by an existence check, so a merchant who already has the template pays one `count` query,
and `created_by` comes from `auth()->user()->id`, never from request input.

Failing **open** is correct here and is the opposite of what the ownership guards do. Those decide
whether a request may proceed, so an error in them must refuse. This one decides nothing: if the
write fails the request is no worse off than today — it reaches the same null dereference. Refusing
would turn a valid draw into a denial on the strength of a mail-template row.

---

## Evidence

### The four reproductions, before and after

| item | before the fix | after |
|---|---|---|
| 1 anonymous ticket write | **HTTP 200**, `purchase_tickets` 0 → 1 in the victim's store, `amount` = the attacker's value | 302, no row |
| 2 cross-store refund delete | **HTTP 302** "Refund sucessfully", store B's row **deleted** | 302, store B's row intact |
| 2b unmatched code | **HTTP 500** | 302 with a message |
| 4 legitimate draw, no Fail template | **HTTP 500** after the order and `lottery_customers` rows committed | 302, both rows written |

### Removal checks — all 12 isolate

Each control deleted **alone**, the suite re-run, the file restored byte-for-byte
(`tests/Support/Security/bin/` pattern; the script is in the session scratchpad, not committed).

```
R1  the ticket-write registration .............. 3 fail
R2  the refund registration ....................  5 fail
R3  the prerequisite registration ..............  2 fail
R4  the handler-equality session test ..........  1 fail
R5  the refund store predicate .................  1 fail   ← GREEN at first; see below
R6  the owned-by-caller check ..................  1 fail
R7  the slug/customer store comparison .........  1 fail
R8  the signed-in-customer requirement .........  1 fail   ← GREEN at first; see below
R9  the uniform refusal body ...................  1 fail
R10 the refund action keying ...................  4 fail
R11 the prerequisite's ensure call .............  2 fail
R12 "does not enable sending" ..................  2 fail
```

**Two were green on the first run, and both were acted on rather than labelled "subsumed".** That
distinction is the whole value of the exercise:

- **R5 exposed a case the suite did not cover at all.** `$ownedByCaller` asks "does a row exist
  that is mine, in my store" — which can be **true while the handler still destroys a different
  row**, because the handler's query has no store filter and resolves with `->first()`, i.e.
  primary key order. A row with the same code *and* the same customer id in another store, inserted
  **earlier**, is the one returned. Reachable for exactly the reason item 2 is reachable, and
  nothing stops two rows sharing a code: `generateRandomCode` is 8 symbols per store and
  `purchase_tickets.lottery_code` has no unique index. Now
  `test_an_earlier_foreign_row_with_the_same_code_and_customer_is_not_the_one_deleted`.

- **R8 was genuinely subsumed, but by an implicit path**, so the check was kept and its *message*
  asserted instead. Without the explicit check the anonymous case is still refused — but only
  because `$customer->store_id ?? null` on a null `$customer` evaluates to null under
  null-coalescing, `resolveStore()` returns null, and the generic ownership refusal fires. That is
  fragile (it depends on `??` suppressing a property read on null) and it tells a signed-out
  shopper their ticket "could not be refunded" rather than that they need to sign in.
  `test_the_anonymous_refusal_asks_the_shopper_to_sign_in` is what keeps the explicit check from
  being deleted as redundant.

### MySQL 8.0.46 — `utf8mb4_0900_ai_ci`, 12 assertions, exit 0

`tests/Support/Security/bin/verify-lottery-refund-collation-on-mysql.php`. SQLite's default TEXT
collation is BINARY, so the Feature suite cannot ask this question in either direction.

```
GUARD-B on 'abc-1'   -> OWNED
handler matches      -> [{id:1,'abc-1',store 2}, {id:2,'ABC-1',store 1}]
handler ->first()    -> row 1, store 2            ← a FOREIGN row
GUARD-A on 'abc-1'   -> REFUSE
same for 'café-1'    (ai_ci is accent-insensitive too)
NULL store_id row    -> REFUSE
'MINE-1' legitimate  -> ALLOW + OWNED             ← the half a security script forgets
SQLite on 'abc-1'    -> only the exact-case row
```

**STEP 2b exists because the first draft of this script could not fail.** It had steps 1, 2 and 4
only, and when the trait's foreign-row limb was deleted as a removal check the script still reported
"every assertion held": the trait's *other* limb was doing the refusing —
`array_diff(['abc-1'], ['ABC-1'])` is non-empty in PHP — while the label claimed the first limb had.
Step 2b isolates it by asking for a code the caller owns **exactly**, which satisfies the value
limb, while a foreign row shares it under the collation. Verified both ways: deleting that limb now
fails the script.

### The MySQL runner itself was broken, and is fixed here

`run-mysql-verifications.sh` reported **"passed: 9"** while one of its scripts was dying at step 4
of 6. Found by reading the log of a script the runner had just called PASS.

Every one of these scripts calls `$kernel->bootstrap()`, which installs Laravel's `HandleExceptions`
and with it a `set_exception_handler`. An uncaught throwable is then **rendered** by that handler
rather than killing the process with PHP's usual status 255 — so the script ends normally, **exit
0**, having skipped every assertion after the crash.

`run_one` now requires all three: exit 0, no `[FAIL]` line, and no rendered throwable. Re-verified
by reintroducing the crash (FAIL, with the right reason) and an assertion failure (FAIL), then
confirming all 9 scripts pass under the stricter check — **so no earlier evidence claim was
hollow**, which was the natural worry and is worth stating rather than leaving open.

It lives in the runner rather than in the twelve scripts because the runner is the authority: one
place, and it covers scripts not yet written.

### Gates

```
Unit                282 passed (1586 assertions)
Feature             2 failed, 17 skipped, 2952 passed (19155 assertions)
known-outcome gate  exit 0 -- 2 declared failures, 17 declared skips, 0 errors, nothing undeclared
Contract            26 passed (83 assertions)
tenancy + security   10 skipped, 1718 passed (9968 assertions)
MySQL runner        passed: 9  failed: 0
guard-no-workdo-changes / guard-test-isolation / guard-no-env-files / scan-secrets   all exit 0
```

The two Feature failures are the manifest's declared ones (`new users can register`,
`profile page is displayed`), unchanged by this work.

### Ratchets updated, with each new site classified rather than waved through

| ratchet | change |
|---|---|
| `OwnedPathRawQueryInventoryTest` | 13 → 16 sites. `LotteryRefundOwnershipGuard` → `'comparison'`, `LotteryMailTemplatePrerequisite` → `'platform'`, each with its controls asserted |
| `RawQueryTenancyInventoryTest` | app 115 → 118, total 160 → 163, literal sites 133 → 135, unscoped 65 → 67, `stores` 18 → 19, **`email_templates` new** |

Two corrections were needed along the way and both are recorded in the files themselves, because
each was a claim that did not survive contact with the ratchet:

- The owned-path inventory classifies per **file**, not per site. The first draft of its entry gave
  one read `'tenant'` and the other `'platform'` — a granularity the inventory cannot express. It
  now states the file's weakest claim and describes both reads under it.
- `assertStringNotContainsString('userTemplateDefaultData', …)` failed on the guard's own
  **docblock**, which names that method in order to explain why it is deliberately not called. A
  ratchet that reddens on the comment explaining the control is worse than no ratchet; it now pins
  the call shape `table('user_email_templates')`.

---

## What the adversarial review found

Run after the three guards were written, their 19 tests green, 12 removal checks biting and a MySQL
script passing — i.e. at the point where the work looked finished. It found **two defects in my own
fix**, one wrong verdict, and three test or ratchet weaknesses. Every claim below was re-verified by
execution or by reading before it was acted on.

### The verdict it overturned

**Item 1 is not closed.** Measured above. I had conceded a version of this in the guard's docblock —
"may then call the return URL directly instead of paying" — but framed it as a *legitimate* caller's
payment bypass. The review's sharpening is the part that matters: **the form the attacker submits can
be the victim's**, so the finding is unchanged in kind, only in cost. One request became two.

### Two defects in the fix itself

**The prerequisite re-inserted three rows on every draw after the first.** `App\Models\EmailTemplate`
is `Cachable`; the Eloquent builder's `pluck()` caches even an **empty** result, because the stored
value is a collision wrapper and therefore never null, so the usual "rememberForever does not store
null" escape does not apply; and `DB::table()->insert()` flushes nothing. An Eloquent read behind a
raw write served the pre-write empty set.

```
F2 email_templates after draw 1: 3
F2 email_templates after draw 2: 6
   rows: Lottery Win, Lottery Fail, Lottery Remainder, Lottery Win, Lottery Fail, Lottery Remainder
```

Unbounded, on the table `sendOwnerEmailTemplate:715` resolves with an unscoped `first()`. This is
**CLAUDE.md's named trap, in my own fix**, and the one-statement remedy is to read through the same
builder that writes. `EmailTemplate::create()` would flush, but on a non-taggable store
`flushCache()` flushes the entire application cache — a far worse trade for a template row.

`test_the_prerequisite_does_not_duplicate_an_existing_template` could not see it: it seeds the row
**before** the request, which is the one case the cache cannot affect, and every other test is a
single request against a fresh `array` store, so the check always read cold.
`test_a_second_draw_does_not_insert_the_templates_again` is the test that was missing.

**The refund guard refused a refund the module offers.** Covered above. A regression I introduced,
not one I inherited.

### Three things that could not fail

| | |
|---|---|
| `assertStringContainsString('noMatchedRowOutsideStore(', $refund)` | satisfied by the guard's own **docblock** at `:47`, which names the method to explain what it calls. Deleting the real call left it green. **The second time in the same test method** — the `userTemplateDefaultData` assertion twelve lines below already records being bitten the same way. Now pinned on the full call expression |
| `test_another_shoppers_ticket_in_the_same_store_is_not_refundable` | with all three guards removed the row **also** survives: the handler keys on `customer_id` too, so its lookup is null and `->delete()` on null is a 500. The row surviving proved nothing. Now also asserts the status is not 500 |
| `test_the_guards_are_inert_on_a_non_target_route` | asserted `assertNotSame(403, …)`, which an over-broad `LotteryTicketWriteGuard` would have **survived** — its web refusal is a 302 redirect, not a 403. Now asserts a real 200 on a host page, plus `session('error')` being null |

### A count that was simply wrong

`15` → **34** `Session::put('lottery_request_data', …)` sites. My grep had read the first screenful
of a 1700-line file. The conclusion stands, and the number is load-bearing by this project's own
standard, so it is corrected in all six places it had been written: the guard docblock,
`bootstrap/app.php`, the test comment, this document, the ledger and #67.

### Two claims the review made that I kept, and one I hardened

- **`auth()->user()` in the prerequisite reads the mutable default guard**, against the sibling
  guard's own stated rule. The review also supplied the reason it is nonetheless *right* here: the
  handler reads the same resolver (`LotteryResultController:90`), so matching it guarantees the
  created row carries the id `:156` looks up, whereas `auth('web')` could diverge and the fix would
  silently not take. Hardened with `instanceof User` rather than changed. A removal check on that
  line comes back **GREEN** and the reason is recorded rather than dressed up: it is not reachable
  today — `config/auth.php` defaults to `web`, the route's `auth` gates on `web`, nothing in `app/`
  calls `Auth::shouldUse()`, and `LotteryDrawOwnershipGuard`'s explicit `auth('web')` null-limb
  would deny first. A structural assertion pins it so nobody deletes it as dead code on seeing that
  green.
- **A multipart file named `ticket_code` makes the refund 500.** `all()` is
  `array_replace_recursive($this->input(), $this->allFiles())`, so a file wins over a field of the
  same name, while `input()` excludes files. The guard validates the text value, the handler matches
  the uploaded file's pathname, finds nothing, and crashes. The form is already
  `enctype="multipart/form-data"`. **Recorded, not fixed**: it is not an ownership bypass — a
  `/tmp/phpXXXX` pathname cannot equal an 8-character `lottery_code` — and the caller inflicts it on
  themselves. What it does falsify is my "limb 2 removes the 500 **for free**", now reworded.
- **The prerequisite writes before `PlanModuleCheck`.** A merchant whose plan dropped the module
  gets three of their own template rows for a request that is then refused. Own tenant, own
  `created_by`, cosmetic. The review also established from the same ordering that the two halves
  that *would* have mattered are safe: `auth` runs before the prerequisite, so there is no anonymous
  write, and `LotteryDrawOwnershipGuard` runs before it, so a refused draw creates no rows.
  Appending in the chosen order is what makes both true.

### What it checked and found sound

The loose-equality session test (traced through `'0'`, `0`, `''`, `[]`, `[0]`, objects — and in
every case where the guard admits a value the handler cannot use, the handler 500s rather than
taking the fallback, so a type cannot render the guard useless); the action keying across all eight
routes, with a repository-wide grep confirming no other route reaches that method and no live
wildcard shadows the eight storefront uris; `array_filter`'s treatment of a literal `"0"` — safe
because the trait passes an **explicit callback**, so the default falsy-drop never applies, and
because global `TrimStrings` then `ConvertEmptyStringsToNull` make whitespace-only input `null`,
which `is_scalar` rejects with the correct message; and the trait extraction, where `empty()` is
applied to the same expression at the same point in the sequence so `null`, `0`, `'0'` and `''` all
still deny. One nit taken from that last item: `mixed $currentStore` would let a future caller pass
a string and lean on MySQL coercion in limb 1 — both current callers pass `int|null`, so it is left,
with the warning written down.

### Removal checks, re-run after every correction

```
R1  the ticket-write registration .............. 3 fail
R2  the refund registration ....................  9 fail
R3  the prerequisite registration ..............  3 fail
R4  the handler-equality session test ..........  1 fail
R5  the refund store predicate .................  1 fail
R6  the owned-by-caller check ..................  2 fail
R7  the slug resolution ........................  2 fail
R8  the signed-in-customer requirement .........  2 fail
R9  the uniform refusal body ...................  1 fail
R10 the refund action keying ...................  7 fail
R11 the prerequisite's ensure call .............  3 fail
R12 "does not enable sending" ..................  2 fail
R13 the raw existence read (the F2 fix) ........  1 fail
R14 the instanceof User hardening .............. GREEN -- not reachable today; pinned structurally
```

13 of 14 isolate. R14's green is explained above and is the honest label, not a gap being counted
as coverage.

## What this leaves open

| | |
|---|---|
| **item 1 itself** — two anonymous requests still write a ticket into any store | needs payment verification inside the handler. **#67 stays open**, and a `KNOWN_LIMITATION` test asserts it |
| item 3, the cross-tenant email template | no host-side seam — upstream. Recorded on #67 and in two ratchet comments |
| `ticket_price` as a client-posted hidden input landing in `purchase_tickets.amount` | the charge is server-side, the record is not → **#68** |
| a multipart file named `ticket_code` makes the refund 500 | the guard reads `input()`, the handler reads `all()`, and files win in `all()`. Not an ownership bypass; the caller inflicts it on themselves. Recorded in the guard's docblock |
| the prerequisite writes before `PlanModuleCheck` | a merchant whose plan dropped the module gets three of their own template rows for a refused draw. Own tenant, own `created_by`, cosmetic — left alone rather than reordering the stack |

So **two of #67's four criteria are met, one is partially met, and one is not**:

| item | verdict |
|---|---|
| 1 anonymous ticket writer | **NOT closed** — one variant closed, the defect remains |
| 2 unscoped refund delete | **closed** |
| 3 cross-tenant email template | **not closed** — no host-side seam, upstream |
| 4 the 500 on a successful draw | **closed** |

`LotteryTicketWriteGuard`'s docblock leads with that rather than letting its own name imply more
than it covers.
