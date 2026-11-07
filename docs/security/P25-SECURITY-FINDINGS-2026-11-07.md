# Security findings, 2026-11-07 — owner instruction item 3

Four priorities were named. Each is reproduced by a test that **failed first**. Two are fixed, two
are proposed rather than applied, and the reason for each choice is stated.

| # | priority | state |
|---|---|---|
| 1 | **Issue #84** — operator creates a customer in a merchant store with no selection | **FIXED**, 5 tests, 2 bites |
| 2 | **Customer e-mail existence disclosed before authorization** | **FIXED**, 5 tests, 2 bites |
| 3 | **`XSS.php` corrupting valid customer e-mail addresses** | **REPRODUCED, proposed not applied** — Phase 01 guard |
| 4 | **Cross-tenant consequences of the archive / re-registration repairs** | **ANSWERED: none.** Census below; one same-tenant dead end found and proposed |

---

## 1. Issue #84 — FIXED

`tests/Feature/Security/OperatorCustomerStoreSelectionTest.php` — 5 tests, **2 bites**.

Three lines, each correct alone:

1. `CustomerController::store():50` sets `$isOperator`; the gate at `:52` admits the operator —
   correctly, because Laratrust has no super-admin bypass.
2. `getCurrentStore()` returns the integer literal **`1`** for a `super admin` whose
   `current_store` is empty — `app/Helper/helper.php:774-775`, *"Default store for super admin"*.
3. `if (empty($store))` — the guard meant to catch "no store selected". **`1` is not empty.**

So the operator created a customer in **whoever owns store 1**, with a password the operator chose
and `email_verified_at` already set — an account able to sign in to that merchant's storefront at
once. The merchant is not told and the response never names the store.

**Not an escalation** — the operator may act in any store. The defect is that **no store was
chosen**, and a silent default is the wrong answer when the answer decides who owns a new
credential.

**The fix is in the handler, not the helper.** That literal serves a large number of call sites;
changing it is a platform-wide behaviour change needing its own decision and evidence. `store()`
now refuses an operator whose `current_store` is empty, using `!empty()` so all three spellings the
helper treats as unset — `null`, `0`, `''` — are refused rather than one slipping through.

**Two positive controls**, because a fix that refused the operator outright would have removed a
capability rather than disambiguated one: an operator who **has** selected a store still creates
customers in it, and the merchant path is untouched (a merchant's `current_store` is set, so the
helper's first branch returns it and this limb is never reached).

---

## 2. E-mail existence disclosed before authorization — FIXED

`tests/Feature/Security/AddCustomerEmailDisclosureTest.php` — 5 tests, **2 bites**.

`Api\DashboardController::addCustomer()`, in order:

```
$storeId = $request->query('store_id');            <- CALLER-SUPPLIED
Validator::make([...'exists:stores,id'])
$request->validate([... Rule::unique('customers','email')->where('store_id', $storeId)])
$store = $this->resolveOwnedStore($storeId);       <- OWNERSHIP, finally
if (!$store) { return 403 }
```

The uniqueness rule is correctly scoped to `$storeId` — but nothing had yet checked the caller owns
that store. **Measured before the fix:** for a store the caller does not own, a **known** address
answered **422** and an unknown one **403**. One bit per request; a list of addresses enumerates
another merchant's customer base.

`resolveOwnedStore()` was already correct (`:4543-4563`) — it compares `created_by` against
`creatorId()` and exempts the operator. It was simply **called too late to protect anything**.

**The fix is pure ordering**: ownership resolution moved above field validation, and the now-
redundant later copy removed. No rule changed. Nothing changes for a caller who owns the store —
same 422, same reason, two tests pin it. Only a non-owner's answer changes, 422 → **403**, which is
the point. **403 and not 401**, so the shipped Flutter app's force-logout-on-401 rule is not
engaged.

A premise test asserts the bearer token really authenticates, so a 403 is a tenancy refusal and not
a 401 in disguise.

---

## 3. `XSS.php` corrupts valid e-mail addresses — REPRODUCED, NOT FIXED

`tests/Feature/Security/XssInputCorruptsIdentifiersTest.php` — **5 tests, all passing, and that
is deliberate.** The three that document the defect assert the **wrong values they measured**, not
the right ones.

A test that failed on purpose would be an **undeclared failure**, and
`.github/known-test-outcomes.json` asserts the outcome set in **both directions** — so it would
break that gate for every later change on this branch. Declaring it in the manifest instead would
expand the accepted-failure set, which is the thing the manifest exists to keep small. Each pinning
test says in its own message that it is what should be rewritten when the fix lands; when `XSS.php`
is repaired those three fail, and that failure is the notification. (I caught this after writing
them the other way round, which would have broken the gate.)

`app/Http/Middleware/XSS.php:53-59` runs over **every string input on the whole `web` group**,
before validation and before the insert:

```php
$value = htmlspecialchars_decode($value);
$value = str_replace(['&lt;', '&gt;', 'javascript', 'alert'], '', $value);
```

A bare **substring** strip. Measured:

| typed | what happens |
|---|---|
| `alerts@company.com` | stored as **`s@company.com`** |
| `Alberto-alert` (a name) | stored as **`Alberto-`** |
| `javascript@x.example` | **refused** — "The email must be a valid email address" |

That third row is sharper than the first two, and my first draft of its test predicted the wrong
thing: I expected a corrupted row and the measurement showed **no row at all**. When the stripped
substring is the whole local part, `javascript@x.example` becomes `@x.example`, which then fails the
handler's own `email` rule. So the merchant is not given a wrong row — they are **refused, and told
the address they typed is not a valid e-mail address, when it is.** Nothing in the message, the form
or the logs connects that refusal to a middleware that edited the value before validation saw it.

`alerts@` is not contrived — it is the shape of a monitoring mailbox. The address is the key every
recovery flow uses, and **the merchant is never told**: validation only ever sees the rewritten
value, so `email` passes and the row is written wrong.

**And the cost is paid for protection that is not real.** `CLAUDE.md` already records why: the
`htmlspecialchars_decode()` turns an encoded `&lt;` into a raw `<` **before** the strip looks for
the encoded form, and a raw `<` was never a target — so `<img src=x onerror=…>` passes through
whole as long as it avoids two words.

### Why it is not fixed here

It is a **Phase 01 control on every form in the application**, and the owner's instruction in the
same message is *"Do not weaken existing Phase 01 guards."* So the two shapes that are worth
something are **pinned by passing tests** — `javascript:` and `alert(` must still be neutralised —
and the fix is proposed rather than taken:

**Proposal:** match the dangerous *shapes* instead of the bare words — `javascript:` (the actual
URL scheme) and `alert(` (a call) rather than `javascript` and `alert`. On every attack shape the
old rule caught, the new one still neutralises; on `alerts@company.com` it leaves the value alone.
It is strictly a gain in precision rather than a reduction in coverage — but it is a change to a
security control on every form, so it needs the owner's word and its own
`tenant-authz-reviewer` pass.

**Deliberately not pinned:** that `&lt;`/`&gt;` are stripped. Per the decode above that pair is
already ineffective, and pinning it would pin a no-op as though it were a control.

---

## 4. Cross-tenant consequences of the archive repairs — NONE, and here is the census

`tests/Feature/Security/StorefrontArchivedCustomerDeadEndTest.php` — 3 tests, passing.

Raw `DB::table('customers')` reads are the class the F2 finding belonged to, because they bypass
`Customer`'s `SoftDeletingScope`. There are **four** in first-party code:

| site | shape | verdict |
|---|---|---|
| `ModuleStorefrontInputGuard:187` | by e-mail, `orderBy('id')` ascending | **the F2 site — already fixed** with `whereNull('deleted_at')`; 5 tests, 3 bites, one through the real module route |
| `ModuleStorefrontInputGuard:344` | by `id`, compares `store_id` | unaffected — a trashed row's `store_id` is still its own store |
| `CustomerLoginController:85` | storefront **login** existence check | store-scoped; soft-delete-blind |
| `CustomerLoginController:934` | storefront **registration** existence check | store-scoped; soft-delete-blind |

**All four carry a store predicate, so none can cross a tenant boundary.** Asserted directly: an
archived row in store A leaves store B's existence check answering `false`, with a positive control
proving the row exists and is found in its own store.

### The same-tenant dead end that census found

`:934` fails registration with *"This Email already exist, please login."* for an **archived** row —
and the shopper cannot log in, because the query that actually authenticates (`:723`) is **Eloquent**
and therefore soft-delete-scoped, so it returns null.

**So a shopper whose account a merchant archived can neither sign in nor register again, and is
told to do the one thing that cannot work.** That is the same dead end `P2.5-PKG-E` (E-1) removed
from the admin form, still present on the storefront, for the same reason.

`:85` (login) is the milder half: the existence check passes for an archived row, but the
authenticating query does not — so the outcome is a misleading message, **not an authentication
bypass**. Checked rather than assumed.

**Not fixed in this slice.** D-40 authorised the admin form by name; this is a storefront
authentication and registration path, and allowing re-registration at an archived address there is a
behaviour change on a surface the owner has not named. Proposed in
`P25-CUSTOMER-EMAIL-UNIQUENESS-PROPOSAL.md` §4 and reproduced here so the decision has a
measurement rather than a description.

---

## 5. Further findings from the three review rounds — recorded, not fixed

These came out of the `tenant-authz-reviewer` passes over the slice above. **None is fixed here**,
and each says what principal can reach it today, because "a defect exists" and "a defect is
reachable" are different claims and conflating them is how an audit ceiling gets inflated.

### 5.1 `Api\DashboardController::store()` — a second, UNROUTED copy of the e-mail oracle

§2 above fixed the ordering in `addCustomer()`. **The same ordering exists, untouched, in
`Api\DashboardController::store()` (~line 6257):** `$storeId = $request->query('store_id')`, then
`$request->validate([... Rule::unique('customers','email')->where('store_id', $storeId)])`, and only
then `$this->resolveOwnedStore($storeId)` with its 403.

**It is not reachable.** `routes/api.php` declares 115 `DashboardController` routes and **none** maps
to `store`; no other route file reaches it either. So it is a trap for whoever routes it, not a live
hole — and **my commit message for §2 said the oracle was "fixed", which was true of `addCustomer()`
and silent about this copy.** Recorded so the next reader is not misled by that sentence.

It also contains a second thing worth knowing: its `Customer::create()` falls back to
`Hash::make(Str::random(64))` when no password is supplied, which is the repaired form. An earlier
version used a committed literal.

### 5.2 `AccountProfileController` is STRICTER than the approved policy

`:127` and `:183` scope uniqueness per store but are **archived-insensitive**, so a live shopper
cannot take an address an archived account in their store still holds. That is the same dead end
PKG-E removed from the admin form — but it fails in the **safe** direction, and loosening it would
let a live shopper take an archived account's address, which a future restore would turn into two
live rows for one address: the state the owner's clause 2 forbids. **There is no restore route for
`Customer` anywhere**, so this is latent rather than live. Left alone deliberately; it is a
storefront authentication path and needs its own decision.

### 5.3 `Api\AuthController:76` — a GLOBAL uniqueness rule on a routed, public endpoint

`unique:customers,email`, no store predicate, on `POST api/{slug}/register`
(`routes/api.php:82`, middleware `APILog` only — **public**). This is the one site where the approved
policy's clause 1 is contradicted on a live, reachable surface: a shopper cannot register in store B
with an address already live in store A.

**Not changed, because it decides who may create a login credential** and the owner's instruction in
the same message is *"No silent payment behavior or authentication policy changes."* Presented for a
separate decision in `P25-CUSTOMER-EMAIL-UNIQUENESS-PROPOSAL.md` §6 Step 2.

Worth stating precisely, because an earlier note of mine got it wrong: the **create-only** property
of that handler comes from **control flow**, not from the rule's scope — `$user = new Customer()` at
`:142`, with the account-adoption branch inside `if ($request->register_type != 'email')` at `:144`.
The e-mail path performs no lookup at all. So scoping the rule per store would **not** turn
registration into an adoption path. The reason to escalate it is the policy question, not a
takeover risk.

### 5.4 `POST api/admin/AddCustomer` HAS NO PERMISSION CHECK — and the web form that does the same thing has one

**The sharpest finding of the four review rounds, and it is pre-existing.** No line of the
uniqueness change caused it or widened it; it is recorded because it is in the handler that change
edited, on the same `validate()` call, and because the slice compared the two surfaces on uniqueness
while never comparing *who may call them at all*.

| surface | gate |
|---|---|
| `CustomerController::store()` — the merchant web form | operator first, then `isAbleTo('Manage Customer')`, else refused |
| `Api\DashboardController::addCustomer()` — the shipped admin app | `if (!$adminUser)` → 401, `resolveOwnedStore()` → 403. **No `isAbleTo`. No operator limb.** |

`resolveOwnedStore()` scopes on `created_by = $user->creatorId()`, and `User::creatorId()` returns
`created_by` for any type that is not `admin`/`super admin` — so **a staff member resolves their
owning merchant's store and passes the 403.** `Api\DashboardController::login` (routed at
`routes/api.php:83` and `:170`) refuses only inactive or login-disabled accounts and places no
restriction on `type`, so staff can obtain the token.

**And the write is a credential**: the create sets `password` from the request and
`email_verified_at => now()`, so the resulting account signs in to that merchant's storefront
immediately. That is the exact property that made the same gap on the web form an escalation worth
closing — `CustomerController.php:21-26`, *"Any authenticated user could create a customer… A HIDDEN
BUTTON IS NOT A FIX"*. Closed there, open here.

**MEASURED, NOT ARGUED.** `tests/Feature/Security/AdminApiCustomerCreatePermissionTest.php` — 7
tests. A staff member holding **no** permissions posts to the endpoint and gets **201**; the row
lands in the merchant's store; `Hash::check` confirms the password is the one that caller chose; and
`email_verified_at` is set. The same principal posting the equivalent web form creates **nothing**.
`updateCustomerInfo()` is **not** affected and that was checked rather than assumed — `:9123`
restricts `type` to `admin`/`super admin`, so staff get 401 there; a test asserts it.

**NOT FIXED, AND ESCALATED.** Adding the gate would make a **shipped Flutter endpoint start refusing
a principal it accepts today**. Any merchant whose staff add customers through the admin app would
see that stop working at the next release, and nothing in this repository records whether that is
current practice. The owner's instruction in the authorising message is *"No silent payment behavior
or authentication policy changes"*, and who may create a login credential is squarely that.

The fix, when authorised, is the operator-first shape this codebase already uses — Laratrust has no
super-admin bypass, so testing the permission alone would refuse the platform operator:

```php
$isOperator = strtolower(trim((string) $adminUser->type)) === 'super admin';
if (! $isOperator && ! $adminUser->isAbleTo('Manage Customer')) { /* 403 */ }
```

**403 and not 401**, because the shipped Flutter app forces a logout on any 401.

### 5.5 Vendored `SignInWithSlack` resolves a customer with NO store predicate, then logs them in

`packages/workdo/SignInWithSlack/src/app/Http/Controllers/SignInWithSlackController.php:102-106`:
`Customer::where('email', $user->email)->first()` with no `store_id`, followed by
`Auth::guard('customers')->login($slackuser)`. On an anonymous storefront request no tenant
resolves and `null_tenant => 'open'` adds no predicate, so the lookup really is platform-wide: a
Slack sign-in on store B can log the caller into store A's row.

**It is not an escalation and it is not a consequence of this change.** `->first()` returns the
*older* row, so a merchant who plants a duplicate loses to the victim's existing row, and the
direction that does fire hands a store-A shopper a store-B row they gain nothing from. It is
**vendored**, so CLAUDE.md forbids editing it as a shortcut, and it is module-gated — reachability
needs the module enabled, which was not driven. Recorded for the owner, not repaired.

Two smaller vendored siblings of the same shape, neither pursued:
`packages/workdo/Subscribe/.../SubscribeController.php:56` (a newsletter `customer_id` can be
another store's) and `packages/workdo/ProductCatelog/.../ProductCatelogController.php:67`.

### 5.6 `updateCustomerInfo()` rewrites an e-mail without revoking sessions

`:9201` writes `email` with no `$customer->tokens()->delete()` and no `remember_token` rotation,
which by this project's own rule is a takeover primitive. **Pre-existing**, and the uniqueness change
widens the writable set only *within the caller's own tenant* — addresses held solely by an archived
row in their own store. A merchant could already set almost any other address in their own tenant,
so this is a within-tenant widening of something already true, not a new hole. Recorded because the
change touches that exact rule.

### 5.7 Five defects the review found in my own tests and comments, all fixed in this change

Listed because the pattern matters more than any one of them: **three of the five were claims that
could not fail, or could not detect the condition they named.**

| # | defect | fix |
|---|---|---|
| E1 | `test_the_policy_is_validation_only_and_therefore_racy` was `assertTrue(true, '…')` — **an assertion that cannot fail**, the exact shape CLAUDE.md names | deleted; the paragraph moved into the no-unique-index premise's failure message, which really can fail |
| E2 | the parity test read `session()->has('errors')` **after** an intervening API request, and its other disjunct was dead (both outcomes of `store()` are redirects). Safe only because the `api` group has no `StartSession` — a property of another file | the web outcome is captured from its own response before the second request is issued |
| E3 | that parity test archived **both** fixture rows, and the pre-change web rule was already live-only — so it passed whether or not the per-store predicate existed, i.e. it did not test the axis its own name claims | a second parity case with a **live** row in a store the caller does not own |
| E4 | the cross-store test never asserted its fixture had landed in the other store, relying on `BelongsToStore` not auto-populating | one assertion, stated as a premise |
| E6 | the no-unique-index premise filtered index **names** for "email", so `sqlite_autoindex_customers_2` or any hand-named index escaped it | asks `pragma_index_info` which **columns** each index covers |

And one of my own, found by running the new parity case rather than by review: it asked both
surfaces about the **same** store, so the web create landed first and the API's refusal was clause 2
working correctly on the row the web form had just made — a real answer to the wrong question.

### 5.8 AN ADVERSARIAL MULTI-LENS REVIEW OF THE FIX ITSELF — 60 findings, and the serious ones were mine

Run after the change was pushed, over the final tree: six independent lenses (production code, test
quality, documentation accuracy, scope discipline, rule semantics, test bite), each followed by
three refuters instructed to default to "refuted" under uncertainty. **CLAUDE.md records that three
of the last four packages had a defect found only by this review, two of them in the fix itself.**
That held again.

**And I misreported it first.** My own journal parser read the key `value` where the runtime writes
`result`, so I told the owner that three of seven lenses had "died" and re-ran two of them on that
basis. **No lens died.** The probe was wrong, not the agents — the same shape of error as a test
that measures the wrong thing, recorded here rather than quietly dropped.

**What survived, all of it introduced by this change set, all fixed in the follow-up commit:**

| # | defect | why it mattered |
|---|---|---|
| R1 | `webAccepted()` returned `! session()->has('errors')` | `store()` has **four** refusal paths and only validation flashes `errors`; the other three flash `error` (singular). So **both parity tests could pass while the web form created nothing.** Now asks the table for the row |
| R2 | the ARCHIVED parity assertion was `assertSame($api, $web)` | satisfied by **both surfaces refusing** — agreement about the wrong answer. Now asserts the direction clause 3 requires |
| R3 | `test_the_store_comes_from_the_authenticated_principal_not_from_request_input` could not detect its own condition | where a row LANDS is decided by `'store_id' => $store` in the create, not by the rule's predicate; an input-derived **rule** would pass it. A second test now makes the two stores give opposite answers, and it was **proved** to bite by temporarily making the rule read `$request->store_id` — only the new test failed |
| R4 | the ledger certified an owner condition *"in the suite, not only in a document"* by naming `test_the_policy_is_validation_only_and_therefore_racy` | **a test this same commit deleted** for being an assertion that could not fail. The row cited something that no longer existed |
| R5 | *"the script asserts the line count is unchanged"* | **no such assertion exists in this repository.** It ran in an uncommitted scratch script, so the sentence described something a reader could not find |
| R6 | `updateCustomerInfo`'s `$storeId = $store->id` cited at `:9156` | it is at **`:9179`** — my own 23-line insertion above it moved it, and I then cited the pre-shift number **in the comment explaining the shift** |
| R7 | `PaymentController:2070` / `HomeController:1218` cited as the lines carrying the store predicate | those are the **docblocks**; the predicates are at `:2096` and `:1230` |
| R8 | *"ten `PaymentController` rows"* re-measured in the observability inventory | **thirteen** were |
| R9 | two figures for "the customer and security subset" — 135/407 and 161/502 | two different file sets wearing one label. Now **one** figure, 141/448, with the fourteen files listed beside it |
| R10 | repo B: `goldenSkipReason()` was **dead code** | `testWidgets`'s `skip` is typed `bool?`, not `dynamic`, so a String reason cannot be passed to it. Eight cases skipped **in silence** inside a gate the repository calls blocking and 100%. Now `markTestSkipped(goldenSkipReason(...))` at the top of the body — proved by hiding the baselines and watching the full reason print for all eight |
| R11 | repo B: the same file declared the count `1165` stale and then stated `1165` as current | every mention is now explicitly historical |

**The pattern is worth more than any single row: seven of the eleven are claims in prose or in a
test name that the code did not support.** Two were assertions that could not fail, two were
citations my own edit had invalidated in the same commit, and one certified compliance with an
owner instruction by naming a deleted test.

**What the review did NOT overturn.** The three production edits themselves survived verification —
one refuter reported *"I tried to break it on four fronts and each attempt confirmed it instead."*
Every per-edit revert still bites, re-measured after these repairs.

**One finding is a product contradiction rather than a defect in the change, and it sharpens §5.3:**
the merchant form will now re-create an address held only by an archived row, while shopper
self-registration still refuses it (`CustomerLoginController:934`, a raw query with no
`whereNull('deleted_at')`). Before this change both refused; now the two surfaces **actively
disagree**. That is a consequence of applying the approved policy to three of the four write paths,
and the fourth is a storefront authentication path the owner's instruction holds back. It is
recorded for the owner's decision, not resolved here.

### 5.9 A second, NARROWER review pass — and the one finding with real product risk

The first pass asked each lens to go and find the change in 10,000-line controllers. A second pass
embedded the **entire production diff (3.3 KB) in the prompt** and bounded each lens to one question
and two files. 53 agents, 7 findings surviving adversarial verification.

**THE ONE THAT MATTERS, and nothing in the suite covered it:**

`Rule::unique('customers','email')->ignore($request->customer_id)->where('store_id', $storeId)
->whereNull('deleted_at')` — **`ignore()` is what lets the shipped Flutter app re-post a customer's
CURRENT address while editing some other field.** That is the ordinary edit, on every customer, on
every release.

**Neither update test touched it.** One moves onto a *different live* address (the held row matches
with or without `ignore()`, so 422 either way); one moves onto an address only an *archived* row
holds (no live candidate at all, so 200 either way). **Break or drop `ignore()` and both stay green
while every ordinary customer edit from Flutter starts answering 422 on `email`.**
`test_re_posting_a_customers_OWN_address_while_editing_another_field_is_allowed` now pins it, and
was **proved to bite** by temporarily dropping `ignore()`: exactly that one test failed.

**Two more weaknesses in my own tests, both fixed:**

| | defect | fix |
|---|---|---|
| R-a | `test_the_web_form_refuses_a_staff_member_with_no_permission` asserted only *redirected + 0 rows* — satisfied by **any** refusal. `store()` returns exactly that for the permission gate, the empty-store tenancy limb (**same flash string**) and ordinary validation, and the web rule set differs from the API payload so a payload drift would look identical | asserts the flash, so a validation refusal cannot masquerade as a permission refusal |
| R-b | `test_that_create_does_not_touch_the_other_stores_account` had **no precondition that the create happened**. Every assertion is about the *other* store's row, so all four are trivially true when nothing was created | asserts `assertSessionHasNoErrors()` and that the caller's own row exists |

**Four residual disclosures, all pre-existing, none caused by this change, none fixed here:**

1. **`updateCustomerInfo` discriminates 422 from 404** (`:9182`). `'customer_id' => '...|exists:customers,id'` is platform-wide and runs **before** the store-scoped lookup, so a nonexistent id answers differently from one belonging to another store: **one bit per request over the global customer id space**. The *write* is correctly gated by the hard store predicate, so this is existence disclosure only — the same shape as the 422-vs-403 leak fixed one method away, and the fix is the same: resolve through the owned store first, then validate.
2. **An array `customer_id` is an uncaught 500.** The rules array is evaluated **before** any rule runs, so `->ignore($request->customer_id)` receives the raw value and the sibling `integer` rule cannot sanitise it. `Unique::__toString()` calls `addslashes()` on it, which **throws `TypeError` on PHP 8.3** — and `TypeError` is an `Error`, not an `\Exception`, so it passes through **both** catch blocks. A 422 is the correct answer. **No uniqueness bypass**: for string values Laravel's quoting and `str_getcsv` parsing keep an injected comma inside the field, which the reviewer checked specifically.
3. **`addCustomer` discloses store existence before ownership** (`:8601`): a nonexistent store id answers differently from one owned by someone else. Strictly weaker than the leak the ordering fix closed — store existence, not another merchant's customer addresses — and `exists:stores,id` is what makes `integer` meaningful before the lookup.
4. **A source-scan premise inspects only the API handler** while its name claims a comparison with the web form, and restates behavioural assertions made ten lines below it. Fails safe; the weaker half of a duplicated check.

---

## What none of this does

No Phase 01 guard is weakened or removed. No audit ceiling is raised and no advisory suppressed. No
CI check is changed. **No migration is added, and none is authorised.**

**The business policy HAS now changed, by owner decision of 2026-11-07, and only at the application
level.** The owner approved it in principle and conditioned implementation on the
tenant-authorization review and on the payment and storefront lookups being store-scoped; both were
completed first. The three merchant write paths now enforce `(email, store_id)` over live rows, the
pinning test has been rewritten into its inverse as its own message said it should be, and the
database constraint that would make the rule race-free is a **plan awaiting a separate decision**,
not a change. `CustomerEmailUniquenessPolicyTest` carries the policy, the Flutter-parity assertion
and an explicit test stating the race that remains open.
