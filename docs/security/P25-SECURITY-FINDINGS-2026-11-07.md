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

## What none of this does

No Phase 01 guard is weakened or removed. No audit ceiling is raised and no advisory suppressed. No
CI check is changed. No migration is added. No business policy is changed — the e-mail uniqueness
question is a written proposal awaiting the owner, and the current behaviour stays pinned by a test
until then.
