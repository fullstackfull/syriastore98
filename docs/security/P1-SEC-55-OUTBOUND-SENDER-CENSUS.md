# Does any other outbound sender bypass the same protection? And is every writer gated?

Owner direction of 2026-11-02, Decision 1: *"Verify that no other outbound sender bypasses the same
protection."* And Decision 2: *"Apply authorization to the relevant controller **actions** and
routes."*

**Two censuses, two findings, and the second one is in my own first pass.**

| | answer |
|---|---|
| **§1-2 — outbound senders** | **One other bypasses it**, with the same two-part mistake: `WoocomCategoryController:114` is judged and not pinned (**P1-SEC-57**). Everything else is literal or config-level, so no caller-controlled destination. |
| **§3 — the `webhook.site` bins** | **Already documented** at `E14-GATEWAY-MATRIX:109`. Reported here only with what this census adds: three more vendored sites, and `webhook_backup()` proven dead. |
| **§4 — writers of the OTP row** | **The first pass gated the two ROUTED actions and called that the action question.** A third writer existed: `save1()`, unrouted, ungated, a full duplicate. **Now gated**, with three mutation-checked tests. A broken `index()` route was found alongside it and pinned rather than tidied. |

Every figure below carries the command that produced it. Measured 2026-11-02 at
`claude/otp-ssrf-and-permissions`.

---

## 1. The census

```bash
grep -rnE 'Http::(get|post|put|patch|delete|asMultipart|asForm|withHeaders|withToken|timeout)|curl_exec|file_get_contents\(\s*\$|->request\(' \
  app/ --include='*.php' | grep -vE 'app/Services/(SafeWebhook|SafeRemoteFetch|OtpTransport)\.php' | wc -l
# 138 call sites, in 13 files
```

138 sites is not 138 risks. **SSRF needs a destination the caller or the tenant controls**; a
hardcoded provider domain is not one, however much it is called. So the census was reduced by
reading each file's URL expression, and that is the only question it asks:

| file | destination | caller/tenant-controlled? |
|---|---|---|
| `Http/Controllers/WoocomCategoryController.php:114` | `$woocommerce_store_url . '/wp-json/wc/v3/products/categories'` | **YES — §2** |
| `Http/Controllers/OtpIntegrationController.php` | via `OtpTransport` | yes, and **now pinned** |
| `Http/Controllers/Customer/Auth/CustomerLoginController.php` | via `OtpTransport` | yes, and **now pinned** |
| `Http/Controllers/SettingController.php:65` | `'https://deliverysyria.com/api/secret/cheack'` | no — literal |
| `Http/Controllers/AccountProfileController.php:268` | `'https://deliverysyria.com/api/secret/state'` | no — literal |
| `Http/Controllers/Auth/PasswordResetLinkController.php:48` | `'https://www.google.com/recaptcha/api/siteverify'` | no — literal |
| `Http/Controllers/ZidOAuthController.php`, `Console/Commands/ZidRefreshTokens.php` | `'https://oauth.zid.sa/oauth/token'` | no — literal |
| `Http/Controllers/IyziPayController.php` | `'https://ipinfo.io/...'` | no — literal |
| `Libraries/PhonePe/PhonePe.php` | `$this->urls[...]`, from `getUrls($env)` | no — a fixed env→host map |
| `Services/PaymentService.php` | `config('services.cashfree.url')` and literal gateway hosts | no |
| `Http/Controllers/PaymentController.php`, `Services/WebuzoDomainService.php` | literal / platform-level panel host | no |

**The two OTP files are fully migrated.** The only remaining `Http::` occurrences in them are
inside explanatory comments:

```bash
grep -nE 'Http::' app/Http/Controllers/OtpIntegrationController.php \
  app/Http/Controllers/Customer/Auth/CustomerLoginController.php
# 2 hits, both inside a /* */ block describing the defect that was removed
```

`OtpOutboundHardeningTest::test_no_first_party_sender_of_the_otp_secret_bypasses_the_pinned_transport`
ratchets that, and a companion test proves the census detector is not vacuous by pointing it at a
synthetic unguarded send.

---

## 2. P1-SEC-57 — `WoocomCategoryController`: judged, not pinned

```php
// app/Http/Controllers/WoocomCategoryController.php:108-115
$woocommerce_store_url = \App\Services\ImportStoreUrl::woocommerceBase(
    Utility::GetValueByName('woocommerce_store_url', getCurrentStore())
);

if ($woocommerce_store_url === null) {
    return redirect()->back()->with('error', __('The WooCommerce store URL is not a valid public address.'));
}

$response = \Http::get($woocommerce_store_url . '/wp-json/wc/v3/products/categories');
```

**`ImportStoreUrl::woocommerceBase()` is verdict-only.** Read from the source
(`app/Services/ImportStoreUrl.php:87-111`): it normalises the string, calls `self::check($url)`
inside a `try`, returns `null` on refusal — and on success **returns the original URL**, not a
pinned one. So:

- the name is resolved **a second time** by the HTTP client, which is DNS rebinding;
- `\Http::get()` sets no redirect options, so Guzzle follows up to five, and a merchant's public
  WooCommerce host can `302` the request to `169.254.169.254` or a private address.

**This is exactly the shape of the P01-SEC-45 mistake** that `OtpTransport` was written to fix:
`allows()` and `woocommerceBase()` both judge the destination and then hand the caller an
unpinned string. The pre-check is real and worth having — it is why the *first* hop cannot be
internal — but it is half of a two-part control.

**Severity is lower than the OTP case, for two measured reasons.**

1. **No credential is sent.** The request carries no `Authorization` header, no consumer key and
   no secret — compare the OTP sends, which carried the store's `api_secret` in the body.
2. **It is a BLIND SSRF.** `store()` assigns `$jsonData` and then returns nothing, so no response
   body reaches the caller. The code says so itself, in a comment left by the P1-SEC-06 audit:
   *"this handler assigns `$jsonData` and returns NOTHING, so it is a BLIND SSRF, not one that
   serves the response as the task row states."*

So what an attacker gets is a POST-authenticated, merchant-triggered GET to an address of their
choosing, with the response invisible. Timing and error differences remain as a side channel.

### Not fixed here, and why

The owner's Decision 1 asked for the four OTP sends to be fixed and for this question to be
**verified**. This is the verification. PR #128 is deliberately one coherent change about OTP, and
this is a different feature (WooCommerce product import) with no test coverage I have measured, so
a behavioural change to a merchant import belongs in its own slice with its own test.

**The fix is one line**, and it is recorded here so it is trivially actionable:

```diff
-$response = \Http::get($woocommerce_store_url . '/wp-json/wc/v3/products/categories');
-$jsonData = $response->json();
+// Judged AND pinned AND redirect-refusing, like every OTP send since P1-SEC-55.
+$body = \App\Services\SafeWebhook::send(
+    $woocommerce_store_url . '/wp-json/wc/v3/products/categories', [], 'GET'
+);
+$jsonData = is_string($body) ? json_decode($body, true) : null;
```

Before taking it, one measured caveat: **`SafeWebhook::send()` sets `CURLOPT_POSTFIELDS`
unconditionally** (`app/Services/SafeWebhook.php:53`), so a `GET` carries whatever payload is
passed. `METHODS = ['GET', 'POST']` (:24) does allow `GET`, and `CURLOPT_CUSTOMREQUEST` is set to
it (:86), but an empty-array payload may still make cURL emit a `Content-Length: 0` or a multipart
body on a request that should have none. Check against a real WooCommerce endpoint, or guard the
assignment on a non-empty payload, before relying on it. Then add the positive/redirect/rebinding
trio this round added for OTP. The
`$jsonData` variable is currently unused, so the import feature may be unfinished — establish that
first, because if nothing consumes the response the honest fix may be to remove the request.

---

## 3. The `webhook.site` destination — ALREADY DOCUMENTED, not a discovery

The census found six sites posting to public `webhook.site` inspection bins. **This is a known
finding**, recorded at `docs/development/phase-2/E14-GATEWAY-MATRIX-2026-10-31.md:109`, which
already states the mechanism and the consequence and says it *"should be raised on its own"*. It is
repeated here only because a census that finds it and says nothing would read as a clean result.

What this census **adds** to that record:

| | the matrix says | measured now |
|---|---|---|
| first-party sites | `PaymentService.php:1684`, `BenefitPaymentController.php:107` | **`PaymentService.php:1739`, `BenefitPaymentController.php:129`** — line drift only |
| the second bin | `PaymentService.php:3504` | **`:3559`, inside `webhook_backup()`** |
| vendored sites | not mentioned | **three more**: `packages/workdo/Campaigns/src/app/Services/CampaignsPaymentService.php:940`, `.../Donation/...:968`, `.../LotteryPrizes/...:965` — the same bin UUID |
| reachability of the second bin | not stated | **`webhook_backup()` has ZERO callers.** `grep -rn 'webhook_backup' app/ packages/ routes/ resources/ tests/` returns only its own declaration. It posts the whole `$order` model and the raw request, so it is one call site away from being serious — but it is dead code today |

So the live exposure is the `post.url` on the Tap/Benefit charge creation, at **five** sites rather
than two: the gateway's server-to-server notification for every plan and order charge — customer
name, email, amount, currency and the `tap_id` — is directed to a public bin whose URL is in this
repository and in its git history.

**Three of the five are vendored** (`packages/workdo/`), which `CLAUDE.md` forbids editing as a
shortcut, and all five change a **payment** callback destination, which the owner's standing
instruction reserves for escalation. So this is escalated and not touched. Benefit is also already
recorded as a HIGH-risk unguarded gateway return in the same matrix, and the `tap_id` harvesting
this bin enables is the input that defect accepts as proof of payment — the two compound, which the
matrix also says.

---

## 4. The WRITER census — Decision 2's other half, which the first pass missed

Decision 2 says *"Apply authorization to the relevant controller **actions** and routes."* The
first pass gated the two **routed** actions and stopped. Finishing the census — every writer of the
row, not every route to it — found a third action and one broken route.

### 4.1 `save1()` — a complete second writer of the destination and the secret, ungated

```php
// app/Http/Controllers/OtpIntegrationController.php:139 (before this change)
public function save1(Request $request) {
    $storeId = getCurrentStore();
    $request->validate([...]);
    $integration = OtpIntegration::firstOrNew(['store_id' => $storeId]);
    $integration->base_url = rtrim($request->base_url, '/');
    if ($request->filled('api_secret')) { $integration->api_secret = $request->api_secret; }
    ...
    $integration->save();
}
```

A near-duplicate of `save()`. It writes `base_url`, keeps the stored `api_secret` unless a new one
is supplied, and saves the same row — with **no authorization of any kind**.

**It is unrouted, so it is not a live defect.** Measured:

```bash
grep -rn 'save1' app/ routes/ resources/ packages/ tests/
# one hit: the declaration itself
grep -rn 'OtpIntegrationController' routes/
# index (:349), save (:352), test (:346). Nothing else.
grep -nE '<form|url:' resources/views/setting/otp_setting.blade.php
# :27  action="{{ route('otp.integration.save') }}"
# :388 url: "{{ route('otp.integration.test') }}"
```

**Gated anyway, and the reason is not caution.** The gate belongs to the **action**, not to the
route list. `save` and `save1` differ by one character, so a later registration is the easiest
mistake available here — and `CLAUDE.md` records that a later `Route::` declaration for the same
method+URI **silently replaces** the earlier one and drops its middleware with it. Gating costs
nothing today and removes that whole class of regression.

**Deleting it would be better still, and is deliberately not done**: removing a method is a scope
decision for the owner, and this change is the authorization one.

### 4.2 `index()` — a registered route that cannot render

```
view()->exists('otp.integration')   =>  FALSE
find . -name 'integration.blade.php' =>  only packages/workdo/LynomiaChat/src/resources/views/superadmin/
```

`OtpIntegrationController::index()` returns `view('otp.integration')`, which does not exist, so the
registered `GET /otp-integration` (`routes/web.php:349`) **throws on arrival**. The live settings UI
is `SettingController`'s `otp_setting` tab rendering `resources/views/setting/otp_setting.blade.php`
— which is also where `save()` redirects back to (`'setting_tab' => 'otp_setting'`).

**Not a security finding, and not fixed here.** It is a correctness one, and the two honest options
(point `index()` at the real tab, or drop the route and the method) are both outside this change.
It is *pinned* instead: `test_the_index_route_is_still_the_known_broken_one` asserts
`view()->exists('otp.integration')` is **false**, and its failure message tells whoever fixes it to
come and delete the test and this section. A known-broken route that nothing checks is folklore.

### 4.2a Its twin, found two days later: `GET /currency-rates`

Added 2026-11-06. **Not part of the OTP census** — found by P2.5-WAVE-29's phone-table census,
which drove 73 merchant routes signed in as a merchant and recorded each status. Five answered
500; three were SQLite standing in for MySQL (`regexp`, `MONTH`, `SUBSTRING_INDEX` — environment,
not defects, and they work in production). Two were the same shape as §4.2, and only one of the
two was on record.

```
view()->exists('currency_rates.index')   =>  FALSE
GET /currency-rates  (signed-in merchant) =>  500  "View [currency_rates.index] not found"
```

`CurrencyRateController::index()` (`app/Http/Controllers/CurrencyRateController.php:17`) returns
`view('currency_rates.index', compact('currencies'))`, and no such view exists. The route is
registered and `auth`-protected (`routes/web.php:198-201`).

**Nothing links to it.** The live UI is the Settings screen's currency tab,
`resources/views/setting/currency_setting.blade.php`, whose form posts to
`route('currency-rates.update', $currency->id)` at `:232` — the POST twin of the same controller,
which works and is permission-gated (`StoreSettingsAccess::permits`). So the exposure is a deep
link or a bookmark, not a screen a merchant navigates to. That is a smaller claim than "a merchant
screen is broken", and it is the accurate one.

**Not fixed here, for the reason §4.2 was not.** Both honest repairs — point `index()` at the
Settings tab, or drop the route and the method — change what a registered route does, and the
recorded decision on the twin is that removing a method is a scope decision for the owner.
Re-deciding that because a second instance turned up would be the quiet scope widening the
decision exists to prevent.

Pinned by `tests/Feature/Security/KnownUnrenderableRoutesTest`, which asserts
`view()->exists('currency_rates.index')` is **false** *and* that the route still exists and still
carries `auth` — so it cannot pass by pinning nothing. Whoever fixes it is told by the failure
message to delete the test and this section.

### 4.3 What the census found gated, and what it proves

| writer | state |
|---|---|
| `OtpIntegrationController::save()` | routed, **gated** |
| `OtpIntegrationController::test()` | routed, **gated** — this is the one that transmits the stored `api_secret` |
| `OtpIntegrationController::save1()` | unrouted, **now gated** (§4.1) |
| `SettingController`'s `otp_setting` branch (:2143) | **read-only** — loads the row and renders the tab; writes nothing |
| anything else in `app/` or `packages/` | **none** |

Three tests ratchet it, and each was mutation-checked:

| test | mutation | result |
|---|---|---|
| `test_every_writer_of_the_integration_is_gated_including_the_unrouted_one` — registers its own route to `save1` and drives a staff user through it | remove the `save1` gate | **fails** |
| `test_no_fourth_writer_of_the_otp_integration_exists` — pins the public action list, requires `mayManageOtpIntegration()` in each writer's body, and sweeps `app/` + `packages/` for external writes | remove the gate / add a fourth public action / add an external `OtpIntegration::updateOrCreate` | **fails on each** |
| `test_the_index_route_is_still_the_known_broken_one` | create the missing view | **fails**, naming the document section to delete |

The controller was restored byte-identically after every mutation (md5 `d92da4f0`).

### 4.4 WHAT THE `tenant-authz-reviewer` FOUND IN MY OWN FIX, before it was committed

CLAUDE.md requires that reviewer before committing any authorization change, and records that
*"three of the last four packages had a defect found only by this review, two of them in the fix
itself."* This was one of those. Four findings; all four were verified against the cited source
before being acted on.

#### F1 — the gate named the WRONG SCREEN's permission. LIVE, and the repository had already
recorded this exact correction.

My first draft ended `return $user->isAbleTo('Manage Store Setting');`. But:

| | |
|---|---|
| the OTP tab is rendered by | `SettingController`'s `otp_setting` branch (`:2143`) — the **`setting.index`** screen |
| whose permission is | **`Manage Setting`** (`CompanyMenuListener:964`) |
| `Manage Store Setting` belongs to | the separate **"Store Setting"** entry, route `app-setting.index` (`CompanyMenuListener:88`) |

`StoreSettingsAccess`'s own docblock says it in as many words, verbatim at `:30-32`:

> `isAbleTo('Manage Setting')` … **NOT `Manage Store Setting`: that belongs to the separate "Store
> Setting" entry (route app-setting.index), and a role holding only it must not be handed the SMTP
> password.**

And `api_secret` is a **restricted name** under that same service — `GatewaySecretRedactor:24` is
`/secret|private|salt|…/i` — so a staff member holding only `Manage Store Setting` is refused the
store's SMTP password by `StoreSettingsAccess` and would have been handed its OTP secret by my
draft. The concrete path: a merchant grants a staff role `Manage Store Setting` (routine — it is
the Store Setting screen's own permission), and that staff member points `/otp-integration/test` at
a host they control and reads back the `api_secret` the settings view deliberately never renders.

**The same mistake is in this repository's own queue, one row up.**
`P01-SECURITY-WORK-QUEUE.md`'s F5 row reads: *"**fixed**: `StoreSettingsAccess` (operator → owner →
`Manage Setting`); 403, never 401. **First draft used the wrong permission (`Manage Store Setting`
is the Store Setting screen)**"*. So this was the second time, and the answer is to stop
hand-writing the gate.

**Framing, because it matters:** this was a **residual, not a regression**. Before the change the
audience was *any* authenticated staff member holding zero permissions. The draft was a strict
improvement that stopped one notch short of the house rule.

#### F2 — `getCurrentStore()` defaults the operator to STORE 1 by a hardcoded literal. Pre-existing.

`app/Helper/helper.php:774-775` returns the literal `1` for `users.type == 'super admin'` with an
empty `current_store`. So an operator in that state would read and overwrite **store 1's**
`base_url` and `api_secret`, and `test()` would transmit store 1's secret to a caller-named host.
Wrong-tenant exposure and an unintended credential write — not escalation, since the operator is
entitled to everything.

**My docblock asserted the opposite**, claiming `getCurrentStore()` *"derives from the caller's own
store and takes no request input, so this gate decides WHETHER, never WHOSE"*. The request-input
half is true and was independently re-verified (one definition repo-wide, reads
`users.current_store`, no query string / header / caller-set session value on any path). The second
half was false for one of the three principals.

Fixed with `resolvedStoreId()`, copied from the house pattern — `CouponController:171-177`,
`StoreFinanceController:332-335` (F157), `TaxMethodController:107-108` and
`FlashSaleController:66-69` all refuse exactly this default and say why.

#### F3 — the tenant-owner limb re-implemented a string comparison. LATENT.

`strtolower(rtrim($type, ' ')) === 'admin'` admits `'Admin'` and `'admin '`, which
`User::creatorId()` (a `==` on the exact value) calls **staff**. `StoreSettingsAccess:24-29` warns
against precisely this. Not tenant-reachable — `RolePolicy::isReservedName()` normalises the same
way and refuses those names at both writers of `users.type` — but it fails in the **widening**
direction. Moot now: `permits()` uses `creatorId() === id`.

#### F4 — the refusal log wrote a URL whose refusal reason may be *that it contains credentials*.
LIVE, low.

`$baseUrl` is caller-supplied and one refused shape is userinfo-in-URL — the suite drives
`https://user:pass@sms.example.test` — so the exact input reaching the refusal log is the one that
writes a credential into `storage/logs/`, with incident #120 still open. Fixed by
`loggableHost()`: scheme, host and port only.

#### The fix for F1 and F3 together is SMALLER than what it replaces

```php
private function mayManageOtpIntegration(): bool
{
    return StoreSettingsAccess::permits(auth()->user());
}
```

`permits()` **is** operator → tenant owner (`creatorId() === id`) → `isAbleTo('Manage Setting')`,
in that order and for the reason CLAUDE.md gives: `permissions_as_gates => false`, so `isAbleTo()`
alone refuses the platform operator and the operator limb must come first. It is already covered by
`tests/Feature/Security/SettingsRestrictedWriteTest`, so this **removes** a gate that needed its own
tests rather than adding one. No permission name is invented, per the owner's instruction:
`Manage Setting` is the Settings menu's own permission and is not held by the staff role.

#### Three defects in MY OWN TESTS, two of them found by the mutation run rather than by reading

1. **The F4 fix had no test at all.** Reverting `loggableHost()` to `$baseUrl` left all 25 other
   tests green — the definition of an unasserted fix. Now
   `test_the_refusal_log_does_not_carry_credentials_from_the_url`, with a non-vacuity premise that
   fails if the host is redacted away too.
2. **`test_the_gate_itself_refuses_a_null_principal` proved the wrong thing.** I wrote it because
   the reviewer showed `test_an_unauthenticated_caller_cannot_reach_either_endpoint` passes with the
   gate removed (the `auth` middleware stops it first) — and my replacement had the same defect one
   layer down: with the gate stubbed `return true` it still passed, because `resolvedStoreId()`
   refuses when `getCurrentStore()` returns null for a null user. It now asserts the gate's own
   decision by reflection, and keeps the behavioural assertion with a docblock that no longer
   overclaims.
3. **The source-text ratchet was satisfiable by a comment.**
   `assertStringContainsString('mayManageOtpIntegration()', $body)` matches the identifier in a
   docblock, and this file's docblocks discuss the gate at length; `methodBody('save')` also
   over-spanned into `save1`'s 28-line docblock. Comments are now stripped with
   `token_get_all()` before matching. The external-writer sweep was also widened beyond the static
   constructors — the reviewer noted a future `DB::table('otp_integrations')->update()` or
   `OtpIntegration::where(...)->update()` would have passed it.

#### Mutations, after the corrections

| mutation | result |
|---|---|
| gate → `permitsStoreScreen()` (i.e. back to accepting `Manage Store Setting`) | **fails** `the_other_screens_permission_is_not_enough` |
| gate → `return true` | **fails 5**, including the null-principal reflection |
| remove the F2 operator guard | **fails** `an_operator_with_no_current_store_does_not_fall_through_to_store_one` |
| log `$baseUrl` whole again | **fails** the F4 test (it passed silently before that test existed) |
| redact the host as well as the credential | **fails** the F4 non-vacuity premise |
| remove the `save1` gate / add a 4th public action / add an external writer / create the missing view | **fails** its own assertion, each |

Controller restored byte-identically after every one (md5 `d5bc4487`).

#### What the reviewer verified clean

Request-input tenancy predicates (none in this controller); the `custom.auth` guard-class trap (not
applicable — web session guard, no `auth('customers')` fallback); reliance on the global scope
(none — `OtpIntegration` is deliberately unscoped and every query carries an explicit `store_id`);
`@can` with a permission name (none); `redirect()->back()` steering (possible in principle via
`Referer`, but the victim is the requester and a cross-origin POST is refused by `VerifyCsrfToken`,
and it is the pre-existing idiom on `save()`'s own success path); `test()`'s HTTP-200 refusal shape
(no oracle — the gate precedes both validation and the row read, so an unauthorized caller learns
nothing about whether the integration exists); and the `$fillable`-column trap on `save1`'s
`sms_enabled`/`whatsapp_enabled` writes, which it chased to the migration and the production dump
and ruled out.

### 4.5 The honest reading of this

**The first pass answered the route question and called it the action question.** The owner's
wording said actions *and* routes, and the difference was a whole second writer. That is the same
class of error as P1-SEC-56 one layer down: a control applied to the wrong set.

**And the pass after it picked the wrong permission for the right action.** F1 is the same shape
again — the gate was real, correctly ordered, and asked about the wrong thing — on a question the
repository had already answered in writing, twice: once in `StoreSettingsAccess`'s docblock and once
in the queue row recording the identical first-draft error.

So this round produced the pattern four times over, at four levels:

| level | what was applied to the wrong set |
|---|---|
| the CI gate (P1-SEC-56) | the right question, asked about the wrong **files** |
| the OTP gate (§4.1) | the right check, applied to the wrong **actions** |
| the permission (F1) | the right gate, naming the wrong **screen's permission** |
| my own tests (F4, the null limb, the comment-satisfiable ratchet) | the right assertions, measuring the wrong **thing** |

The reviewer caught the third and fourth; the mutation run caught two of the fourth that reading
had not. **Neither would have been caught by the suite being green**, which is the whole argument
for both disciplines.

---

## 5. THE HTTP-STATUS REGRESSION the owner caught, and how it was repaired

> Owner direction, 2026-11-03: *"The previous implementation required both a successful HTTP
> response and provider status 200. The new OtpTransport reads the provider's JSON status but loses
> the actual HTTP status through `SafeWebhook::send()`. Preserve the original dual-success condition
> without weakening DNS pinning, redirect refusal or authorization. Prefer a narrow,
> backward-compatible transport result or an opt-in HTTP metadata API. Do not silently change the
> semantics of other `SafeWebhook` callers."*

### What was wrong

The original condition at all three call sites was

```php
$response->successful() && ($result['status'] ?? null) == 200
```

— Laravel's HTTP-level 2xx **and** the provider's own `status` field. `SafeWebhook::send()` answers
the body alone and the HTTP code died with the cURL handle, so the move kept the second test and
dropped the first. **One case flipped from failure to success: a provider answering an HTTP error
while its body claims `status: 200`.**

That is not a hypothesis about a hostile provider; it is what a broken or half-deployed gateway
looks like. The merchant would have been told the test OTP was sent, and the *customer-facing* flow
would have told a shopper to wait for a code that never left.

I disclosed this difference in a code comment at the time and left it in place, reasoning that the
provider's documented `status` field was the real contract. **That was the wrong call** — the
instruction had been to use the existing implementation, not to re-decide a success condition, and a
behaviour change recorded in a comment is still a behaviour change.

### The repair, and why it is shaped this way

**`SafeWebhook::sendWithStatus()`** is new and opt-in:

```php
/** @return array{body: string|false, status: int|null}|null */
public static function sendWithStatus(string $url, $payload, string $method): ?array
```

- **`send()` is unchanged in contract.** It is now a one-line projection of `sendWithStatus()`, so
  the two cannot drift, and it still returns the body, `false`, or `null`. Its only other caller in
  the application is `Utility::WebhookCall` (`Utility.php:2119`) — measured, the whole census is in
  §1 — and `WebhookController` uses `allows()` only. Neither sees any difference.
- **One `plan()`, so one set of protections.** The same `SafeRemoteFetch::check` judgement, the same
  `CURLOPT_RESOLVE` pin, the same `CURLOPT_FOLLOWLOCATION => false`, the same protocol and timeout
  bounds. The methods differ only in how much of the answer they hand back, which is what stops
  this becoming a second, laxer path.
- **`CURLINFO_RESPONSE_CODE` of `0` is reported as `null`, not as a status.** No HTTP response is
  not a status code, and a caller comparing against a 2xx range must not read `0` as one.
- **The test seam gained an additive shape**: `['body' => ..., 'status' => ...]`, recognised by the
  presence of a `body` **key** rather than by being an array, so a seam returning an array body
  cannot be misread as metadata. Every pre-existing seam returns a string or `false` — measured
  across `WebhookSafetyTest`, `GateFActivatedSurfacesTest` and `OtpOutboundHardeningTest` — so none
  changed behaviour.

**`OtpTransport::succeeded()`** is the restored condition, in **one** place rather than copied into
each branch — the previous version had it written out twice in two controllers, which is how one of
them would eventually have been updated alone:

```php
outcome === ANSWERED  &&  http_status is 2xx  &&  provider status === 200
```

`successful()` is the whole 2xx range, not `== 200`, so 201/202/204 still succeed. An unknown HTTP
status **fails closed**: the real transport always has one, so the only way it is absent is a seam
that did not supply it, and a lenient fallback there would make every positive test pass without
exercising the restored condition at all.

### Evidence

| assertion | cases |
|---|---|
| the regression itself — HTTP 500 + body claiming `status: 200` | 1, by name |
| no non-2xx is a send | 400, 401, 403, 404, 429, 500, 502, 504, **302** (a redirect is never followed, so it arrives as itself) |
| a legitimate 2xx still is | 200, 201, 202, 204, 299 |
| HTTP 2xx + provider failure is not — and the provider's message still reaches the authenticated merchant | 1 |
| unknown HTTP status fails closed | 1 |
| `send()`'s contract: body / `false` / `null`, and nothing sent on refusal | 1 |
| an array without a `body` key is a body, not metadata | 1 |
| `succeeded()` directly, over the whole decision table | 14 rows |

**Mutations**, each reverted byte-identically (`OtpTransport` md5 `4328dff7`, `SafeWebhook`
`2aaf5614`):

| mutation | result |
|---|---|
| drop the HTTP half again (the original regression) | **18 fail** |
| narrow 2xx to exactly 200 (the over-correction) | **4 fail** |
| drop the provider half (the mirror regression) | **1 fails** |
| treat `0` as a successful status | **1 fails**, precisely that data set |
| `SafeWebhook` reports `0` as a status rather than `null` | **passed silently** — see below |

### One branch this suite does not cover, stated rather than implied

The `0 → null` mapping lives on the **real cURL path**, which the suite never executes: every test
goes through the transport seam. I mutated it to pass `0` through unchanged and the whole suite
stayed green. What *is* asserted is the property that makes the mapping safe either way —
`succeeded()` refuses both `0` and `null`, pinned by the decision table — so the mapping is
defence-in-depth for the log and for any future range comparison, not the thing the success decision
rests on. Saying so is better than letting the green suite imply coverage it does not have.

---

## 6. What this census does not cover

- **`packages/workdo/` beyond the `webhook.site` grep.** 70 vendored modules were not read for
  outbound senders. The OTP `api_secret` census did cover `packages/`, so the *credential* question
  is answered there; the broader destination question is not.
- **`file_get_contents()` / `curl_*` with a dynamic argument** were included in the 138-site count
  but only the `Http::` facade sites had their URL expressions read individually.
- **Whether the Benefit provider is enabled in production.** `DEPLOYED` is NO, and
  `'benefit'` is absent from `config/payments.php`'s `plan_activation` map, so the fail-closed
  posture does not cover it either way. That is a fact about the configuration, not a mitigation
  anyone has asserted.
