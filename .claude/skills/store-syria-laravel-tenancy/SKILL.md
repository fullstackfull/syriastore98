---
name: store-syria-laravel-tenancy
description: Use when writing or reviewing any Laravel code in Store Syria that touches authorization, tenancy, a controller, a policy, an Eloquent query, a route's middleware, an order, a payment, or any handler that reads an identifier from request input. Holds the counter-intuitive facts of this codebase that have each produced a real defect, and the test disciplines that catch them. Read before adding a controller, policy, middleware or query scope.
---

# Store Syria — Laravel, tenancy and authorization

First-party and committed, so it travels with the repository. It exists because `CLAUDE.md` used to
delegate this to an account-level `laravel-best-practices` skill that **does not exist in every
environment** — three of the four skills that file named were absent when the project moved
machines, and the two that survived were the two committed here.

This is not generic Laravel idiom. Generic idiom is not what breaks this codebase; the local facts
below are, and each one has produced a measured defect.

## 1. Authorization

**Laratrust, not spatie, and `permissions_as_gates => false`.** So `@can('Some Permission')` is
**always false**. Use `$user->isAbleTo('Permission Name')`. In Blade, Laratrust's `@permission`
works — it resolves through `hasPermission()` and never touches Laravel's Gate — so `@permission`
and `@can` are *not* interchangeable here.

**There is no super-admin bypass.** Operator status is a string comparison on `users.type`.
`isAbleTo()` alone **refuses the platform operator**, so the operator limb comes **first**:

```php
if ($this->isPlatformOperator($user)) { return $next($request); }   // FIRST
if ($permissionIsEnforceable && ! $user->isAbleTo($permission)) { return $this->deny(...); }
```

Reversing those two is a lockout, not a tightening — `App\Http\Middleware\FinancePermission`
records it happening once already for six unseeded finance names.

**A permission that no row exists for can be held by no role**, so gating on it is a permanent 403.
Check the row exists before enforcing. Each vendored module seeds its own permissions from its own
`PermissionTableSeeder`, which the enable path runs — `Utility::addNewData()` is the only **host**
mechanism, not the only one. (Assuming otherwise shipped a real within-tenant gap.)

## 2. Tenancy

Shared database, `store_id` discriminator, with an allowlist in `config/tenancy.php`'s
`scoped_models`. **Absence from that allowlist is what decides** — `StoreScope::isActiveFor()`
short-circuits for a model that is not in it, even if the model carries `BelongsToStore`.

`Product`, `Order`, `Category`, `Coupon` and every `Workdo\*` model add **no predicate for any
principal**. `null_tenant => 'open'`, so when no tenant resolves the scope adds nothing at all.

**Never let the scope be the only tenant check.** And a predicate built from caller-supplied input
(`$request->store_id`) is not a tenant check — it compares the caller's own two values.

## 3. The pattern that has produced the most defects

> Authenticate a principal, then act entirely on request input.

A **presence** test is not an **identity** test. `if (! auth('customers')->user()) { … }` proves
*some* customer of *some* store is signed in, and nothing more. Derive the target from the
authenticated principal; never from request input.

`CustomAuth` logs a token's owner into whichever guard matches its tokenable class, so a merchant's
or driver's token passes `custom.auth` while `auth('customers')` stays null — and a handler shaped
`if (! auth('customers')->user()) { $id = $request->some_id; }` takes the attacker-controlled
branch. **A customer-token probe cannot test this**: a `Customer` token takes the safe branch, so a
test that drives only one proves nothing. Drive a merchant and a driver too.

## 4. Which request key does the handler read?

A guard that authorises one row while the handler mutates another is not a guard. Read the
handler's own source line:

- `Request::__get` reads `all()` **before** route parameters, so `$request->id` resolves to the
  **posted body** when both are present.
- A handler taking `$id` as a **method argument** (`update(Request $request, $id)`) reads the
  **route parameter**, and a body key cannot change it.

Those are opposite answers to the same question. Get it from the code, not from the route
signature.

**Parse identifiers strictly.** `is_numeric` is not enough: it accepts `'5.9'` and rejects
`'5abc'`, while MySQL coerces `'5abc'` to row 5 in a `WHERE id = ?` comparison. Use
`filter_var($v, FILTER_VALIDATE_INT)`.

**Ask about the rows, not the values.** For a set predicate, run the handler's own query *without*
a store filter and refuse if any matched row is foreign. Verifying that the requested *values* are
owned is a different question, and MySQL's case- and accent-insensitive default collation separates
them.

## 5. Vendored modules

**Never edit `packages/workdo/`.** An in-place edit is reverted by the next upstream update and
leaves no patch record. `LynomiaChat` is the one exemption. Enforced by a `PreToolUse` hook locally
and by `guard-no-workdo-changes.sh` in CI, which is the authority.

A fix that seems to need a module file belongs in the host app — a policy, FormRequest, query
scope, or **middleware appended to the `web` group**:

```php
$middleware->appendToGroup('web', \App\Http\Middleware\YourGuard::class);
```

Not a route re-declaration. Module route files load from `app->booted()` — *after* `routes/web.php`
— and `RouteCollection` keys on method+domain+uri, so the module's declaration wins the slot and
your middleware silently disappears. The group registration also survives `route:cache`, because
the cache stores declared middleware *names* unexpanded.

Row existence in `add_on_managers` is what loads a module. `loadDynamicAddons()` plucks modules with
**no `is_enable` predicate**, so **"disabled" is not containment** — a disabled row still registers
every route.

**A module's own listeners CAN be pre-empted, and that is the one clean way to fix a vendored
listener.** Module providers are registered from `app->booted()`, so anything registered earlier —
a host provider's `boot()`, or event discovery — runs **first**, and a Laravel event object is
passed by handle, so a first-party listener can rewrite `$event->…` before the module's listener
reads it. Measured: a probe registered from `app->booted()` observed an array a discovered listener
had already normalised. Used by `App\Listeners\NormaliseRewardRedemptionFlag` to contain
`AddRewardClubPointLis:38`. Keep such a listener a **shape normalisation**, never a second copy of
a decision the host already makes elsewhere, or the two drift.

**EVENT DISCOVERY IS ON, AND THE FILE THAT LOOKS LIKE IT TURNS IT OFF DOES NOT.**
`App\Providers\EventServiceProvider::shouldDiscoverEvents()` returns `false` — and discovery still
runs, because `Application::configure()` calls `->withEvents()` unconditionally
(`Illuminate/Foundation/Application.php:244`) and registers the **framework's** own
`EventServiceProvider`, whose `protected static $shouldDiscoverEvents` is `true` and whose default
discovery path is `app/Listeners`. The app's override applies only to the app's own instance.
So **every class in `app/Listeners` with a typed `handle()` is auto-registered**, under the
`Class@handle` key rather than `Class`. Measured consequences:

- All five listeners there are live, and `VerifyReCaptchaTokenLis` is registered **twice** — once
  in `EventServiceProvider::$listen` and once by discovery — so it runs twice per dispatch.
- `EnforceLotteryTicketPrice` is discovered against `Illuminate\Database\Eloquent\Model`, because
  that is its `handle()` parameter type. Inert, but it is in the listener table.
- A test asserting `assertContains(Foo::class, $registrations)` **misses a discovered listener**,
  whose entry is `Foo@handle`. That cost a real false reading: a mutation deleting an explicit
  `Event::listen` reddened only the registration test while the behavioural test stayed green,
  because discovery was still registering the listener. Match with `str_starts_with`.

**A module route behind `auth` and a permission is not protected, and the census does not look at it.** `RouteCensusGateTest` classifies only
module routes with NO authentication, so the seven Helpdesk ticket routes in the module's own `['auth','web','PlanModuleCheck:…']` group were never read:
each looked a ticket up by its identifier alone, the permissions are granted to the global `admin` role every merchant holds, and the number in the URL was
`time()` under an APP_KEY that is public (P1-SEC-68). The manifest takes ownership rules for authenticated routes too (`owner:helpdesk-ticket-row`,
`store-row:<table>:<param>`); `tests/Support/Security/bin/map-module-id-lookups.py` lists the authenticated handlers that still have the shape (**leads, not
findings** -- P1-SEC-76). Two corollaries: judge the ROW an encrypted id names, never the encryption; and request bytes never reach `unserialize()` -- the container's
encrypter refuses anything that is not plain data -- an object, an enum (`ObjectFreeEncrypter`, P1-SEC-77) -- and a guard that decodes a segment itself does it WITHOUT unserialize and refuses what is not exactly
the shape it expects, **for the operator as well** (the operator is admitted before an ownership rule is asked, so a shape check must come first).

## 6. Tests that actually bite

**After writing a regression test, revert the fix and confirm the test fails.** This has caught
vacuous tests repeatedly. Seventeen ways they pass for the wrong reason, all measured in this project:

1. **The endpoint 500s for everyone**, so "the row didn't change" holds either way. And a handler
   that `catch (\Exception)`es its own failure returns **200**, so even `assertNotSame(500, …)` is
   not a liveness check. Assert the **refusal** — a 403 the guard alone produces.
2. **The route does not exist.** A row assertion is satisfied by a 404. Assert
   `assertNotSame(404, …)` too.
3. **A precondition was never met**, so the handler was never reached: a missing plan entitlement
   (`PlanModuleCheck`), a missing `add_on_managers` row, a validator refusing first, a fixture row
   the write path is gated on. Assert the premise in the fixture.
4. **The engine hides it.** SQLite's affinity and BINARY collation hide real MySQL defects — a
   string in an INTEGER column, NULL into NOT NULL under `strict => false`, and case/accent-
   insensitive `whereIn`. Evidence that cannot live in the suite goes in
   `tests/Support/Security/bin/` as a script stating its own preconditions. **A NAME compared in PHP is not the engine's comparison**: `utf8mb4_unicode_ci` also
   folds accents, zero-width and no-break spaces and full-width letters, and the 255-character column CUTS a longer name (MySQL 8.0 also at an invalid byte), so a
   write-side rule keyed on a setting's name (`/_sign_in_image$/`) never saw a row that the handler's `where name = ...` finds (P1-SEC-70/78, executed on MariaDB: of
   197,624 candidate names the engine found 2,331 and the old rule let 1,696 through; S46 on MySQL 8.0.46). **Judge the name as the COLUMN stores it and put a name that is
   not plain printable ASCII to the engine -- `HostClaims`, `StoredPathPolicy::isSignInName` -- do NOT refuse it:** the first form of P1-SEC-78 refused every such name and
   broke 23 `HostClaimsTest` cases that pin a review's decision ("an accented name that is not a host name is still saved"); running the tests that name the neighbouring
   rules, before committing, is what found it. A stand-in `lower()` for SQLite lives as long as the PDO, so take it out after the test.
5. **`actingAs` tightened the scope the test was meant to measure.**
   `actingAs($customer, 'customers')` also calls `shouldUse('customers')`, so `auth()->user()` —
   which `StoreScope::resolveTenant()` calls, web-guard-only by design — starts returning the
   **Customer**. It cannot tell, falls through `getCurrentStore()` to
   `Store::where('created_by', $user->id)`, and resolves a tenant for a shopper. Measured:
   `OrderRefund::where('id', $id)` compiled with `and "order_refunds"."store_id" = ?` attached
   where a real request (default guard `web`, no principal → `MODE_UNRESOLVED` → `open`) attaches
   nothing. So a **direct-call** test on the `customers` guard runs under a *tighter* scope than
   production, and the global scope silently substitutes for the handler's own predicate —
   removing that predicate then changes nothing the test can see. In a direct-call test, put the
   default guard back: `$this->app['auth']->shouldUse('web')`, then assert `auth()->user()` is
   null. Over HTTP the kernel sets the guard itself, so this applies to direct calls only.
6. **`strip_tags()` ate the thing you were asserting about.** The merchant layout inlines scripts
   containing bare `<` comparisons — `for (let i = 0; i < node.classList.length; i++)`,
   `if (window.innerWidth < …)` — and `strip_tags()` reads each as a tag opening and discards
   everything up to the next `>`. Measured on `/finance`: **80,007 bytes became 18,679**, taking a
   whole rendered block with them. It silently inverts an ABSENCE assertion, so
   `assertStringNotContainsString($needle, strip_tags($html))` passes whether or not the thing is
   on the page. Assert against the raw body with whitespace collapsed
   (`preg_replace('/\s+/', ' ', $html)`) instead, and drive the positive case too so you know the
   assertion can see anything at all.
7. **The mutation never reached the file.** Not a defect in the test — a defect in the *harness*,
   and it reports the most dangerous possible result: "survived". Two measured in one sitting.
   A Python heredoc replacement containing `\$` is an **invalid escape**, so the pattern never
   matched and six mutations silently did nothing, every one reported as surviving. Replacing the
   applied-check with `git diff --quiet` then reported the opposite error, because the file under
   mutation was **untracked** and `git diff` is always empty for an untracked file. Verify a
   mutation applied by **checksum before and after**, and make the harness *fail loudly* when the
   search string does not occur exactly once. The same shape has also produced a `gate exit: 0`
   from a pipeline whose `$?` came from `tail`.

8. **A neighbouring test pins the failure you just removed.** `PreTransactionStockGiveBackTest`
   asserted, by name, that a null plan **throws** — a premise-pinning case written to correct an
   earlier write-up — so the fix that made the writer stop throwing turned it red. It was found by
   running `Commerce`, `Security` and `Tenancy` *together*, and would have been missed by running
   the new test file alone, which is what had just passed. When a fix removes a crash, grep the
   tests for the crash's **message** (`Attempt to read property`, `Undefined variable`) and for the
   method's old behaviour before the suite — **and before rewriting it, search the Decision
   Register (`docs/development/phase-2/PHASE-02-DECISION-REGISTER.md`) for a ruling on that
   behaviour.** This test was pinning a crash the owner had *deliberately kept* (D-13/D-18, "the
   `$plan` fix stays suspended": landing it on a handler that decrements stock before the payment is
   proven would turn a crash into an unverified decrement). The red test was the ruling talking and
   the fix was the error: the guard was reverted on five handlers and the test restored byte-for-byte.
   Where no ruling exists, rewrite the case to pin the *new* contract with the same premise
   assertions — a case that only asserts "no longer throws" can pass by returning early, so also
   assert the order was written once and the stock taken once.
9. **The test cannot tell the two readings apart.** A placeholder test posted `0` for every
   jurisdiction level, and a mutant that read `0` as a *selection* survived — because with every
   level `0`, "zero is a value" and "zero is unset" give the same answer. The cases that bite are
   the ones where the readings **differ**: a `0` level whose parent is *absent*. When a mutation
   survives, do not weaken the mutation; ask what input would make the two behaviours disagree.
   The top-five tie-break did it a second time: all-lower-case labels cannot tell a bytewise
   comparison from a case-folded one, and the SQL charts beside it obey a case-insensitive
   collation. Mixed case (`a, B, c, D, e, F, g`) separates them, and belongs in the MySQL evidence
   script as well as the unit test, because PHP's `<=>` and the database's collation are different
   comparators.
10. **A correction can be wrong in the way the thing it corrects was.** A finding said an
    inclusive-tax change moved only a recorded figure and not the charge, and the staged comment
    said so — true for the guest cart, false for the signed-in cart, which adds the same figure with
    no inclusive exemption. Reading the one caller the finding named was not enough. Before writing
    a claim about what a change does, list **every** call site (`grep -rn 'functionName('`) and read
    how each uses the result.
11. **"The form cannot produce that" is a claim about JavaScript, and nobody had read it.** A
    comment and a test header both said the Country → State → City cascade made a city-with-no-state
    unbuildable by clicking. Reading the script showed the country handler rebuilt the state select
    and left the city select holding its old choice, so changing the country posted exactly that
    row. Read the handlers before writing "the UI prevents X". When the same script is pasted into
    several views (here `create` and `edit`), pin the fix with a source-level census over **each
    copy** and run the mutants on **all copies identically** — a mutation applied to one view is
    killed by the equality test between the copies and tells you nothing about the census.

12. **A merge into the request does not protect a reader, and a test that "sees" a 500 and shrugs is hiding a finding.**
    `Request::all()` is `array_replace_recursive($this->input(), $this->allFiles())`, so a multipart FILE part
    named like a field the intake just merged (here `customer_id`) **replaces the merge** for every reader of
    `$request->field`. D6 stage 1 first read the principal's balance at the COD writer as a consistency fix; the
    discount was then granted, the post-commit `OrderNote` insert (a foreign key on the file's temp path) threw
    **after the commit and before the event that debits the points** — final price 50.00, balance still 500, no
    ledger row, HTTP 500, repeatable. My own test had measured exactly that, written it up as "not an exploit"
    because the request 500'd, and trimmed the assertion to the order total. An independent review caught it. Rules:
    (a) a 500 *after* a commit is a half-written state, not a refusal — assert the balance, the ledger and the
    stock, not just the status; (b) when a request must agree with the principal, **compare the bag with the
    principal at the intake and refuse** (`PaymentController::customerIdTheIntakeCannotTrust()`) rather
    than patching one consumer; (c) a fix that moves a read from "the bag" to "the principal" can remove an
    *accidental* safety (a file part priced from nobody) — mutate the fix and run the hostile request before
    calling it consistent; (d) a check that can never disagree with the one before it (the COD writer's second
    `forOrder()` in the quote's own request) is a trap, not depth: it cannot fail a test.

13. **The premise request consumed what the later requests need.** The guest-checkout door test of P1-SEC-73 placed one order to
    prove the receipt reaches the uploader, then reused its basket and cookie for four hostile receipts. The order had consumed the
    basket, so every later request was refused `Cart is empty.` before the code under test, and `assertSame([], stored())` held
    whatever the policy did — a mutant that turned the policy off survived it. The corrected case gives **every request its own
    fixture** and follows **every refusal with a control** that proves the fixture was usable; it also found that one of the four
    names (`shell.php.png`) is *stored* there, by the receipt's own naming, which the old assertion's message denied.
14. **A refusal asserted without its reason.** "Flag 0 and nothing stored" is also what an earlier rule, a crash, or the filesystem
    layer produces: Flysystem rejects any `\p{C}` character in a path by itself, so a NUL or a right-to-left mark in a stored name was
    "refused" with the policy's own check deleted. Give each hostile case the **reason** it is a case for and assert it (the
    platform-upload policy logs one per refusal, and the test reads it), and when a mutant changes *which* rule refuses rather than
    whether, a test that asks only "whether" cannot see it.
15. **A shopper signed in with `actingAs($customer, 'customers')` is not a production shopper.** It also calls `shouldUse('customers')`, so
    `auth()->user()` -- the DEFAULT guard, which `getCurrentStore()` reads -- is the customer, and `getCurrentStore()` answered with
    whichever store had a `created_by` equal to the customer's id: by an accident of the fixture's ids the ticket edit and reply paths
    appeared to store in the store's own folder, and the cross-store collision measured on the real code did not reproduce. In
    production the default guard is `web` with nobody in it, and the answer is null (`PlatformUploaderDoorsTest::asAShopper()` signs a
    customer in and puts the default guard back; the folder and the collision then reproduce). Any storefront test whose handler calls
    `getCurrentStore()`, `auth()->user()` or `Auth::user()` needs it. **The same test hid a second half** (the review's tests lens): the
    store under test was store 1, owned by user 1, so "the route's store", "store 1", "its owner's user id" and `getCurrentStore() ?? 1`
    were ONE number and four wrong-folder mutants survived. Build the store under test so that its id is neither 1 nor its owner's
    (`PlatformUploaderDoorsTest::aStoreWhoseIdIsNobodyElses()`); and when the claim is about a shopper who also holds a panel login, hold
    that session too with the default guard on `web` and assert as a premise that `getCurrentStore()` answers for the OTHER tenant.
16. **The principal never reaches the rule.** The first version of "a user rooted at the operator is nobody's colleague" PASSED against the unguarded
    Helpdesk routes: such a user has `creatorId() = 1`, `ActivatedModule()` reads the OPERATOR's `user_active_modules` (none), and `PlanModuleCheck` refused
    them before the ownership rule was ever asked. Assert the premise (`assertContains('Helpdesk', ActivatedModule($user->id))`) for every principal a negative
    case depends on. **Same family:** assert on the field the screen PRINTS (the reply screen prints the description, not the subject); and give every
    negative leg a reason only the control produces (`HelpdeskTicketOwnershipTest::refusedByTheGuard()` asserts the 302 to the dashboard AND the flash
    `Permission denied`, which differs from the plan gate's `Permission denied ` and the handlers' `Permission Denied !!`).
17. **A run with `LARAVEL_STORAGE_PATH` on a private directory is a different run.** The census-based tests print nothing and fail (the census subprocess inherits
    the variable), and `RegisteredUserController::__construct` calls `die()` when `storage/installed` is missing -- `tests/bootstrap.php` creates it only in the
    repository's own storage -- so the whole run can END SILENTLY with exit 0 and no summary line, having executed part of the suite. A `--bootstrap` override
    skips `tests/bootstrap.php` altogether. Create `installed` and the `framework/{cache/data,sessions,views,testing}`, `logs` and `app` folders in a private storage,
    read the summary line (not the exit code), and leave the census tests to the default storage.

18. **A mutation group written by the hand that wrote a containment shares its blind spots.** P1-SEC-70's first form: tests green, 24 of 24 mutants killed, required CI
    green -- and four independent lenses and five skeptics that EXECUTED what they claimed found three bypasses: a row another tenant planted that names the SAVER'S OWN
    file, a `..` that leaves the application and comes back in by its own directory name, and nothing outside `uploads/` judged at all. A guard whose job is to refuse
    must judge the ROW and the PLACE the operating system resolves (`realpath`, not a lexical collapse), and be default-deny; and a second pair of hands attacks it
    before its group counts as evidence. (The first run of that group had also printed a perfect score and meant nothing: read the failure COUNTS, not the summary.)

**Assert through `DB::table()`.** `User`, `Product` and `Order` are `Cachable`; an Eloquent read can
serve a mutated in-memory instance. That produced a false *reproduction* once — a model read
reported a hijacked row while the raw row was untouched.

**Strip comment lines from any source-grep ratchet.** A docblock quoting the string a ratchet greps
for makes the ratchet satisfiable by prose. That has happened here.

**AND THE CONVERSE, WHICH IS WORSE BECAUSE IT LOOKS SAFE: "comment-only" DOES NOT MEAN "cannot
break CI".** Several censuses here are text searches over `app/` that do **not** strip comments, so
your prose is indistinguishable from code to them. Measured, twice in one session:

- A docblock sentence containing `event(new AddRewardClubPoint(` pushed a dispatch-site census from
  nine to ten — a census *I* wrote, in the same change.
- A docblock sentence containing `storedCheckoutState(` put `app/Listeners/` into
  `Feature\Commerce\PendingPaymentGatewayCensusTest`'s set of **payment order writers** and turned
  the required `Tests` job red. That census derives "files that read a stored checkout state" from
  a plain substring search; its own docblock says it is a text census.

Two consequences. First, **do not skip the Feature gate on the grounds that a change is
comment-only** — that reasoning was used here and CI caught what it missed. If you must justify
skipping, the test is not "did behaviour change" but "could any text census see this", and the
second is much harder to answer. Second, when a census you do not own flags your file, **reword
your comment; do not add your file to its declared-exception list.** Those lists are security
assertions — adding a flag normaliser to a list of gateway handlers that cannot settle a pending
balance is a false statement in a payments control, which is worse than the red check.

Mention such an identifier without its call parens, and say in the comment why.

## 6b. Four inventories are keyed on `file:line`, and ANY edit above one breaks them

Not a defect — they exist so that a moved raw query, truncate or log line gets looked at — but they
are the most common way an unrelated change turns CI red here. Measured twice in one session: a
24-block replacement in two controllers shifted 14 rows of one inventory, and a 25-line comment
added to `app/Models/Utility.php` shifted **three** inventories at once.

| inventory | where |
|---|---|
| security log lines | `docs/security/P01-OBSERVABILITY-INVENTORY.md`, ratcheted by `SecurityLogInventoryTest` |
| raw-query entry points | `ResidualTenancyExposureTest` |
| `truncate()` sites | `EloquentScopeBypassInventoryTest` |
| filesystem removals (by file and COUNT, not line) | `StoredFilePathSafetyTest` |

After any edit to a file they name, re-point them before running the gate:

```bash
python3 docs/development/phase-1/tools/resync-observability-inventory.py --write   # the doc
php artisan test tests/Feature/Tenancy/ResidualTenancyExposureTest.php             # read actual vs expected
php artisan test tests/Feature/Tenancy/EloquentScopeBypassInventoryTest.php
```

The `(Measured anchors:` block in `PaymentController`'s docblock is the fifth: edit its digits **in place, at the same width** — adding a sentence moves every row beneath it, which is the cascade this section exists to avoid — and take the shift from `git diff -U0 <file>` (the sum of `new − old` over the hunks above each anchor), then confirm each cited line still holds its token.

The two test-file arrays are re-pointed by hand from the failure's own diff. **Apply the highest
line number first**: a renumbering where 3230→3255 and 3255→3280 rewrites the same entry twice if
you go in ascending order. Never silence one by deleting its entry — a removed row means a finding
was addressed and its note goes with it.

## 7. Censuses fail in a specific way

Four times now, a completeness claim missed what it claimed to cover — each time because it was
keyed on *spelling* rather than on the handler:

1. keyed on uri **words** → missed four document routes
2. keyed on `Route::post` **lines** → missed `Route::resource`
3. keyed on the uri **prefix** → would miss a prefixed re-declaration
4. an **exemption scoped to the methods somebody happened to look at** → said "update and destroy
   are stubs", true, while the live defect was in `store()`

Key on `$route->getActionName()`. State what was read **and what was not**. Add a reachability
assertion, so a rename cannot make the scan pass vacuously.
