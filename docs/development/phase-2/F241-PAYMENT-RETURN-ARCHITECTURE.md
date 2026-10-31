# F241 / F230 — a permanent, secure payment-return architecture

**Status: DESIGN. Nothing in it is implemented, and no route is exposed by it.** The owner's
instruction is explicit that provider authenticity, intent binding, replay resistance and safe
behaviour without a browser session must be **proven before** any route exposure is considered.
This document establishes what is measured, what the architecture is, and what it cannot prove yet.

---

## 1. The measurement, first — because the diagnosis turned out narrower than "payment returns are broken"

Every plan-return route sits in `routes/web.php` inside `Route::middleware(['web'])` (`:390`) →
`Route::middleware(['auth', 'xss', 'setlocate', 'verified'])` (`:411`). None of the four is in
`bootstrap/app.php`'s CSRF exception list — each of the 28 patterns was run through `fnmatch()`
with `inExceptArray()`'s own `trim($except, '/')` semantics and **all four miss**.

But they do not behave the same, and the reason is the HTTP verb:

| route | verb | cookie sent cross-site? | CSRF checked? | reachable by the gateway? |
|---|---|---|---|---|
| `plan-paytm-payment/{plan_id}` | **POST** only | **no** — SameSite=Lax blocks cross-site POST | yes → fails | **NO. 419.** |
| `plan-mollie-payment/{plan}` | GET | yes — Lax permits top-level GET navigation | no — `isReading()` | yes |
| `plan-Benefit-payment` | `any` (gateway uses GET) | yes | no — `isReading()` | yes |
| `cashfree/payments/success` | `any` (gateway uses GET) | yes | no — `isReading()` | yes |

**So F241 is Paytm-specific, and it is a total outage of that purchase path rather than a
weakness.** `SameSite=Lax` — the Chrome default, and `config/session.php:221` leaves `same_site`
unset — sends the session cookie on a top-level **GET** navigation, which is what a payment
redirect is, and withholds it on a cross-site **POST**, which is what Paytm sends. The cookie is
absent, so `auth` would redirect; and the token is absent, so CSRF raises first. Either way the
handler is never reached.

**The three that work do so for a reason that is not a control.** They are reachable *because*
`VerifyCsrfToken::isReading()` skips GET — which means a forged cross-site GET reaches them just as
easily as a real redirect does. CSRF was never protecting them, and `auth` only establishes that
*somebody is logged in*, not that a payment happened. That is the real finding behind F230's
operator half: **session authentication is the wrong control for a provider callback**, and the
three "working" routes demonstrate it as clearly as the broken one.

---

## 2. What the architecture must be

One sentence: **the browser return must stop being the thing that grants entitlement.**

```
  initiation            provider                 callback                 browser return
  (authenticated)       (money)                  (authenticated by        (authenticated by
                                                  PROVIDER evidence)       SESSION)
  ┌──────────────┐     ┌──────────┐     ┌──────────────────────┐     ┌──────────────────┐
  │ mint intent  │────▶│  charge  │────▶│ verify + activate    │     │ READ the outcome │
  │ server-owned │     │          │     │ idempotent, no       │     │ write nothing    │
  │ reference    │     │          │     │ session required     │     │                  │
  └──────────────┘     └──────────┘     └──────────────────────┘     └──────────────────┘
         │                                         ▲                          │
         └── plan, amount, currency, coupon,       │                          │
             purchaser bound here                  └── provider-attested ──────┘
                                                       evidence only
```

### 2.1 Initiation stays exactly as it is — authenticated, session-based, CSRF-checked

It is a merchant acting in their own browser. `PlanPurchaseIntent::create()` already mints the
server-owned record binding purchaser, plan, coupon, expected amount and currency, and — for
Cashfree and Mollie — uses the provider's own reference. Nothing here changes.

### 2.2 The callback authenticates by provider evidence, and therefore needs no session

This is the piece that does not exist. Its contract:

| requirement | how it is met | why not otherwise |
|---|---|---|
| **provider authenticity** | a signed webhook verified against a server-held secret, **or** a server-to-server verification call over TLS this server validates itself, keyed on a reference the server minted | a session proves who is browsing, not that money moved. A bare callback URL proves nothing at all. |
| **intent binding** | the reference is the intent's; the plan, amount and currency come from the intent, never from the request | `APP_KEY` is in git history, so a `Crypt` route parameter is an encoding and not a control |
| **replay resistance** | the intent's single conditional `UPDATE … WHERE status = 'pending'`, already built and raced on MariaDB | a UNIQUE index alone cannot express "once per purchase" when the reference is per-call |
| **no browser session** | the route is **outside** the `auth` group and establishes the principal from the intent's `user_id` | that is the whole point: a provider has no session, and giving it one would mean exempting a route from auth, which the owner forbids |

**A route outside `auth` is only safe because the provider evidence is the authentication.** That
is the inversion: today's routes are inside `auth` and prove nothing about the payment; the
proposed one is outside `auth` and proves everything about the payment. It is not a relaxation.

### 2.3 The browser return becomes a read

A session-authenticated page that looks up what the callback recorded and renders it. It writes
nothing, so a forged GET to it achieves nothing, and `plan-paytm-payment`'s 419 stops being an
outage because the entitlement no longer depends on that request arriving.

---

## 3. What this does NOT do

- **No blanket CSRF exemption.** The callback route needs no exemption, because it is not in the
  `web` group at all — it is an API-style route with no session and no cookie. A CSRF exemption on
  a session-authenticated route would be the opposite: it would keep the useless control's shape
  while removing its only effect.
- **No removal of authentication.** Initiation and the browser return keep `auth` + `verified`.
  The callback does not *lose* authentication; it authenticates by a different and stronger means.
- **No new refund, settlement or stock behaviour.** Recovery stays what it is: an unreconciled
  intent a human acts on.
- **No route is exposed by this document.**

---

## 4. Why it cannot be implemented yet — the dependency, stated precisely

The callback needs **one** of these per provider, and this container has neither for any provider:

1. **A verified webhook signature contract** — the header name, the algorithm, and what the
   signature is computed over; or
2. **A verified server-to-server verification contract** — the response fields, the status
   enumeration, and the amount's units.

For Paytm the second is built (`PaytmTransactionVerifier`, over a TLS channel this server validates
itself, with the status response's own checksum checked). For Cashfree and Mollie the plan binding
is built but the amount contract is not. For **Benefit and Flutterwave neither exists**, which is
what keeps them BLOCKED.

**And the evidence could not be obtained in this session.** Both official documentation hosts fail
DNS from this container:

```
developers.tap.company      getaddrinfo ENOTFOUND
developer.flutterwave.com   getaddrinfo ENOTFOUND
```

That is the environment's network policy, not a missing page. The owner's instruction authorises
obtaining this evidence "through authorized sandbox testing or provider documentation" — the
documentation route is available the moment those two hosts are allowed in the environment's
network settings. **No contract has been guessed in the meantime, and none of the four providers'
checks has been written against an assumed field name.**

---

## 5. The one genuinely new owner decision

Everything above follows from decisions already recorded (D-15, D-31, requirement 8). One thing
does not, and it is a product decision rather than a security one:

> **When a provider callback arrives and the purchase is activated, but the buyer's browser never
> returns — or returns to a 419 — what should the buyer see?**

Today the browser return *is* the activation, so the question cannot arise. Once the callback owns
activation, a buyer can be charged and entitled while their browser shows nothing. The options are
a plan-page banner, an email, or silence-plus-the-next-login — and that is a choice about how the
product communicates, not about how it is secured. It needs no answer before the callback is
built; it needs one before the browser return is changed.

**Not asked, because it is already settled:** whether to expose any route (no — not until §2.2's
four proofs are demonstrated per provider), and whether to guess a provider contract (no —
requirement 8).

---

## 6. Order of work, once unblocked

1. Allow the two documentation hosts; establish Benefit's and Flutterwave's contracts.
2. Complete Cashfree's and Mollie's **amount** verification against their established contracts.
3. Build the callback route for **one** provider — Paytm, because its verification contract is the
   only one fully built — behind the four proofs, with no route exposed until each is demonstrated.
4. Only then change the browser return, which needs §5 answered.

`plan-paytm-payment` stays broken until step 3, and that is the correct state: a purchase path that
cannot complete is strictly better than one that completes without proof.
