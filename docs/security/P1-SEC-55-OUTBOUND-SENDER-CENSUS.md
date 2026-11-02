# Does any other outbound sender bypass the same protection?

Owner direction of 2026-11-02, Decision 1, final verification clause: *"Verify that no other
outbound sender bypasses the same protection."*

**Answer: one does, and it is the same two-part mistake. Everything else is either already pinned or
has no caller-controlled destination.** One further exposure was found, confirmed to be **already
documented**, and is reported here only with what this census adds to it.

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

## 4. What this census does not cover

- **`packages/workdo/` beyond the `webhook.site` grep.** 70 vendored modules were not read for
  outbound senders. The OTP `api_secret` census did cover `packages/`, so the *credential* question
  is answered there; the broader destination question is not.
- **`file_get_contents()` / `curl_*` with a dynamic argument** were included in the 138-site count
  but only the `Http::` facade sites had their URL expressions read individually.
- **Whether the Benefit provider is enabled in production.** `DEPLOYED` is NO, and
  `'benefit'` is absent from `config/payments.php`'s `plan_activation` map, so the fail-closed
  posture does not cover it either way. That is a fact about the configuration, not a mitigation
  anyone has asserted.
