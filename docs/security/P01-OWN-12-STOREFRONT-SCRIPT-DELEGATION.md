# P01 — who may put script and style on every storefront page

**Issue:** #108 · **Task:** `P1-OWN-12` · **Status:** DECIDED by **D10**, and the decision is
**preserve**, which is the opposite of what this project had recommended. Nothing is narrowed. This
document is the other half of D10: *"document the delegation risk."*

> **D10, verbatim:** *"**JS/CSS**: **preserve** the existing delegation — any holder of
> `Manage Store Setting` may edit permitted store JS/CSS and analytics; **do not narrow it to the
> owner or invent a new mandatory permission**; but enforce tenant ownership and authentication,
> keep CSRF, prevent crossing into another merchant's or the operator's origin, no access to
> server-side secrets, and **document the delegation risk**."*

The project had put three options to the owner — (a) leave it, (b) require `Manage Setting` for
the four script-bearing names, (c) keep it and sanitise. **The answer is (a)**, with five
conditions. This document records what the delegation is, checks each condition against the code,
and states the residual plainly rather than softening it.

---

## 1. What is delegated

Four setting names, written from the Store Setting screen, are rendered **unescaped**:

| name | what it is |
|---|---|
| `storejs` | arbitrary JavaScript |
| `storecss` | arbitrary CSS |
| `google_analytic` | an analytics id, rendered into a script context |
| `fbpixel_code` | a pixel snippet |

Rendered at:

```
resources/views/front_end/layouts/app.blade.php:49     {!! $storecss ?? '' !!}
resources/views/front_end/jQueryRoute.blade.php:136-138 @if(isset($storejs)) {!! $storejs !!}
resources/views/layouts/app.blade.php:134              {!! isset($setting['storecss']) ... !!}
resources/views/layouts/app.blade.php:178              {!! isset($setting['storejs']) ... !!}
```

`{!! !!}` is Blade's *unescaped* echo. These are not sanitised and are not meant to be — a store
script that was escaped would not run, which is the point of the field. The `xss` middleware strips
a handful of literal substrings and **is not a control**: it was never designed as one and must not
be cited as one.

**The storefront rendering includes the checkout page.** That is the whole of the risk and it is
stated first so it is not buried: a holder of `Manage Store Setting` can run JavaScript in the page
where a shopper types a card number.

---

## 2. The five conditions D10 attaches, each checked against the code

### 2.1 "enforce tenant ownership and authentication" — **holds**

`P1-SEC-27` made the three Store Setting writers require `Manage Store Setting`, which is what the
screen itself asks. A user holding **neither** `Manage Store Setting` nor `Manage Setting` can no
longer reach them at all. The writers key their rows by store, and `P1-PLT-07` (#110) closed the
re-stamping and shadowing of `created_by` on those writes.

### 2.2 "keep CSRF" — **holds, and it was nearly broken by a sibling control**

Every browser form posts `_token`. `GatewaySecretRedactor`'s pattern contains `token`, and the
baseline for a missing name is empty — so for one round the CSRF token itself counted as *"a change
to a secret"* and the **whole request was refused**. CSRF is off under the tests, so no earlier test
posted one and nothing caught it. `SettingsRestrictedWriteTest::test_an_honest_browser_post_with_a_csrf_token_still_saves_for_staff_holding_manage_store_setting`
exists because of that, and it is why this condition is listed as checked rather than assumed.

### 2.3 "prevent crossing into another merchant's or the operator's origin" — **holds, by two
different mechanisms, and the second one is not obvious**

The storefront renders the **visited store's** rows, so a merchant's script runs on their own
storefront only.

The **admin** layout is the one worth reading, because it renders the same two names unescaped and
chooses its source from `users.type` (`resources/views/layouts/app.blade.php:1-8`):

```php
if (auth()->user() && auth()->user()->type == 'admin') {
    $setting = getAdminAllSetting();        // the merchant's own rows
} else {
    $setting = getSuperAdminAllSetting();   // the OPERATOR's rows
}
```

- A **merchant** (`type === 'admin'`) sees their own script in their own admin. Their own origin.
- A **staff member** (`type` is the role name, not `'admin'`) and the **platform operator**
  (`type === 'super admin'`) both take the `else` branch and render the **operator's** rows.

So a merchant's script never reaches the operator's admin — **provided** `getSuperAdminAllSetting()`
cannot be made to return a merchant's row. It cannot: it is doubly scoped, to
`created_by = <the super admin's id>` **and** `store_id = <that user's current_store>`
(`app/Helper/helper.php`). `P1-SEC-11` and `P1-PLT-07` are what make that predicate trustworthy —
before them, a store-less read fell back to `created_by = 1` and a writer could re-stamp the row.

**A consequence of this that is not a defect but should be known:** a merchant's staff member does
**not** see their own merchant's `storejs` in the admin panel — they see the operator's. The admin
panel's script field is therefore effectively the operator's, not the merchant's, for every
principal except the merchant themselves.

### 2.4 "no access to server-side secrets" — **holds for the write path**

The script runs in the **browser**. It has whatever the page has, which is the shopper's session
cookie for that storefront and anything the page already prints — not the server's configuration.
`withoutPageExposedCredentials()` (P1-SEC-22 (c)) and the restricted-name rule keep gateway secrets
and SMTP credentials out of the names a `Manage Store Setting` holder may write; a staff member
holding only that permission cannot write `MAIL_PASSWORD`, `stripe_secret_key` or `domains`.

### 2.5 "document the delegation risk" — **this document**

---

## 3. The residual, stated without softening

A holder of `Manage Store Setting` — a store-manager role, not the merchant — can place JavaScript
on every page of that store's storefront, **including the checkout page**. The outcomes available to
such a script are the ordinary ones for script on a payment page:

- read what a shopper types into the checkout form, card fields included, before it is submitted;
- post it anywhere;
- rewrite the form's destination;
- read the shopper's session cookie for that storefront, unless it is `HttpOnly` (session cookies
  are; anything the page writes in JavaScript is not);
- persist across every page view until the setting is changed back.

**This is the "redirect a merchant's card payments" outcome by another route**, and preserving the
delegation does not reduce it. D10 accepts that risk deliberately in exchange for not breaking a
delegation merchants rely on; this document exists so the acceptance is on the record and is not
later mistaken for an oversight.

**What reduces it, and none of it is in this phase:** an allow-list or CSP on the storefront
response, a review step before a script takes effect, or an audit record of who changed these four
names and when. Each is a product change, not a tightening of a permission, and D10's instruction
not to "invent a new mandatory permission" rules out the cheap version.

---

## 4. The test that pins today's behaviour

`tests/Feature/Security/SettingsRestrictedWriteTest::test_script_bearing_names_remain_open_to_manage_store_setting_holders_pending_the_owner_decision`

It posts `storejs` and `storecss` as a `Manage Store Setting` holder and asserts both are stored.
**Its name is now wrong in one word** — nothing is "pending" any more, D10 decided it — and the
test's docblock is updated to cite D10 rather than an open question. The assertion itself is
unchanged, because the behaviour it pins is the behaviour D10 preserves.

It fails the day someone narrows the delegation, which is exactly when a reader should be sent back
to D10.

---

## 5. What was NOT done, and why

- **Not narrowed to `Manage Setting`.** D10 forbids it in terms.
- **Not sanitised or allow-listed.** D10 lists option (c) among what it preserves against; and a
  sanitiser that let real analytics snippets through while stopping a hostile one is not a
  thing this phase can build or verify.
- **No new permission invented.** D10 forbids it, and this codebase has independent reason to
  agree: a permission with no seeded row can be held by no role, so gating on one is a permanent
  403 for every principal including the operator — the lockout `App\Http\Middleware\FinancePermission`
  records having been caused once already.
