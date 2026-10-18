# P01 — OTP length compatibility: can the storefront recovery code go from 4 digits to 6?

**Decision: NO, not yet — and the blocker is one specific, nameable thing.** The default stays at
4. The change is now a one-line environment value rather than a code change, the server is proven
to work at 6, and the exact check the owner must make is stated below.

---

## The security case for 6, stated first, because it is real

With the per-account send cap and attempt limit in `App\Services\CustomerPasswordResetService`:

| | 4 digits | 6 digits |
|---|---|---|
| space | 10,000 | 1,000,000 |
| guesses per account per hour (5 sends × 5 attempts) | 25 | 25 |
| chance per hour | 0.25% | 0.0025% |
| chance over a sustained week at the maximum rate | **≈34%** | **≈0.4%** |

A one-in-three chance of taking over a targeted account over a week is not comfortable. This is a
change worth making.

## Why it is blocked anyway

**The client that consumes this code is in neither repository in this session.**

The three endpoints — `POST api/{slug}/forgot-password-send-otp`, `-verify-otp`, `-save` — are the
**storefront customer** API. Evidence that no inspectable client calls them:

- They are **absent** from `tests/Support/ApiContract/manifests/flutter-endpoints.json`
  (0 matches for `forgot-password`), the inventory of what the shipped admin app calls.
- The shipped admin Flutter app's base URL is `https://storesyria.com/api/admin`
  (`/home/user/StoreSyria_app98/lib/utils/base_api.dart:3`), so it cannot reach `api/{slug}/...`
  paths at all.
- Nothing under `resources/views/`, `public/` or any JS asset posts to these endpoints — grep for
  all three paths across the repository returns only documentation, manifests and the route
  declarations themselves. So there is no server-rendered web caller either.

And the one contract artefact that **does** exist documents a 4-digit code:

```
packages/workdo/Api/src/documentation/theme.json
  "customer-forgot-password-verify-otp": {
    "request": { "email": "sadsda@example.com", "otp": "1234" }
```

That is an example, not a validation rule — but it is what a client developer building against this
API would have built their input field around.

**The failure mode if we are wrong is worse than the risk we are mitigating.** If that client's OTP
field is 4 characters wide, or validates `length == 4`, a 6-digit code is *un-enterable* and
**every storefront password recovery stops working for every customer**. A 34%-over-a-week
targeted-attack margin is bad; a total loss of password recovery for the whole customer base is
worse, and it would be self-inflicted.

The owner's instruction for exactly this case was: *"If compatibility cannot be verified, do not
make the change; report the specific blocker."*

## What was done instead

### 1. The length is configuration, not a literal

`config/auth.php`:

```php
'customer_password_reset' => [
    'otp_digits' => env('CUSTOMER_OTP_DIGITS', 4),
],
```

`CustomerPasswordResetService::otpDigits()` reads it and **clamps to [4, 8]**, falling back to 4 for
a non-numeric or absent value. So:

- the default behaviour is byte-identical to what is deployed today;
- flipping to 6 is `CUSTOMER_OTP_DIGITS=6` in the environment — no deploy, no code review, and it
  reverses by removing the line;
- a typo (`CUSTOMER_OTP_DIGITS=1`, `=0`, `=-6`, `=six`) **cannot** produce a weaker code. A
  misconfiguration must not be able to shorten a credential, and nine data-driven cases pin that.

### 2. The server is proven length-agnostic

`tests/Feature/Security/CustomerOtpLengthCompatibilityTest` — 20 tests, 188 assertions:

- the **whole flow** (send → verify → save) succeeds end to end at 6 digits, and every field the
  documented response returns is still returned;
- a wrong 6-digit code is refused, and so is a 4-digit **truncation** of a 6-digit code (which is
  what a client stuck at 4 characters would send — so a mismatched client fails safely rather than
  partially matching);
- the attempt limit and the send cap bind identically at 6;
- the email carries the full code, and the template is asserted to contain no length constraint;
- 6-digit codes still come from `random_int`, not a seedable generator;
- `generateNumericOTP` is ratcheted to **exactly one caller**, so the configured length cannot
  silently drag another feature along.

Verified by removal: disabling the clamp fails 5 tests, making the handler ignore the config fails
the end-to-end test, and introducing a second caller fails the ratchet.

### 3. Independent evidence the platform handles 6

The **web** flow already issues 6 digits — `CustomerLoginController:151`,
`random_int(100000, 999999)` — and its view hardcodes `maxlength="6"`, `pattern="[0-9]{6}"` and the
words "6-digit" (`resources/views/front_end/Auth/password-otp.blade.php:19,62,63`). So the
platform's delivery and storage paths already carry 6-digit codes. (That flow cannot complete on
MySQL at all until P01-SEC-13's column widening lands — see that package.)

## The one check that unblocks this

Open the storefront customer app's "enter the code" screen and read its input field. If it accepts
**6 characters** — no `maxLength: 4`, no `length == 4` validator, no 4-box PIN widget — then:

1. set `CUSTOMER_OTP_DIGITS=6`;
2. run `php artisan config:clear` (the key is read through `config()`, so a cached config must be
   rebuilt);
3. complete one recovery on a test account;
4. update `packages/workdo/Api/src/documentation/theme.json` so the documented example matches —
   `test_the_documented_request_shape_is_what_the_default_still_produces` **will fail until it
   does**, deliberately, so the published contract cannot silently drift from the code.

If that client cannot be read, the alternative is to raise it only for newly registered customers,
or to ship a client update first. Neither is worth doing before someone looks at the field.

## What would NOT have worked

- **Accepting either length.** The length that matters is the one *generated*; a 6-digit code a
  client cannot type is broken regardless of what the server accepts.
- **Padding a 4-digit code to 6.** That is 10,000 values dressed as 1,000,000 — no security gain.
- **Changing the constant and hoping.** That is the change the owner's instruction forbids, and the
  blast radius is every customer.
