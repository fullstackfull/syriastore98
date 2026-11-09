# P2.5-PKG-K — the abandoned-basket WhatsApp nudge

The owner gave a limited authorisation: *"Reproduce the reported missing-return defect through the
actual abandoned-cart and abandoned-wishlist message workflows. Determine whether messages are
truly sent while the UI falsely reports failure, or whether delivery fails. Use mocks/fakes to
avoid sending real messages. You may repair the merchant-visible reporting behavior in these
specific flows after suitable tests and security review. Do not modify payment workflows or
globally change the shared SendMsgs contract without separate owner approval."*

## The answer to the question that was asked

**Delivery fails — always — and the reporting would be wrong even if it did not.**

Reproduced through both real endpoints (`POST /abandon-carts-messagesend`,
`POST /abandon-wishlist-message-send`) against a store configured with a WhatsApp phone number id,
an access token and an active `Abandon Cart` template. Three independent faults, each sufficient on
its own:

### 1. The recipient was never a phone number

Both handlers passed `$cart->UserData` — the `hasOne` **relation**, an Eloquent model — where
`Utility::SendMsgs()` expects `$mobile_no`. It does not throw:
`Illuminate\Database\Eloquent\Model::__toString()` is `toJson()`, so the WhatsApp `to` field became

```
+{"first_name":"Nour","last_name":"Saleh","email":"...","mobile":"963900000001","demo_field":"demo_field","name":"Nour Saleh","address":"","postcode":""}
```

WhatsApp cannot deliver to that. On a store with real credentials it also **POSTs the shopper's
name, e-mail, mobile, address and postcode to graph.facebook.com in the recipient field**.
`password`, `remember_token` and `password_otp` are in `Customer::$hidden` (P1-SEC-16), so this is
personal data, not credentials — stated precisely because the difference is the difference between
a bug and an incident.

### 2. `Utility::SendMsgs()` has no return statement

It returns `null` on every path that does not throw, whether the curl ran or the whole block was
skipped. Both handlers then asked `if ($resp == false)`, and `null == false` is true in PHP — so
the failure branch was taken **100% of the time**. A genuinely delivered message would have been
reported as a failure.

### 3. The reason was a hardcoded string

Both answered `"Invalid Auth access token - Cannot parse access token"` for every outcome. Measured
in the test harness: the response is **byte-identical** for a store with no WhatsApp configuration
at all and for one fully configured with an active template. The merchant was told their token was
broken when it may have been fine, and given nothing that would let them fix the real cause.

## The repair, confined to these two flows

* `$cart->UserData?->mobile` — the column, not the model. Fail-closed: a missing or soft-deleted
  shopper yields `null` and is refused with a reason.
* `App\Services\AbandonedMessageDispatch` derives the outcome from the **state** `SendMsgs()` reads,
  before calling it, and names the one that fails: no mobile number, WhatsApp not configured,
  template missing, template switched off.
* On success the merchant is told the message was **queued for WhatsApp delivery**, not delivered.
  `SendMsgs()` discards the gateway's response, so "sent successfully" would be exactly as untrue
  as the old failure message. The test asserts both halves of that wording.

**`Utility::SendMsgs()` is unchanged.** It is called from `PaymentService`, `PaymentController`,
`ApiController`, `PayUPaymentController` and `AuthorizeNetPaymnetController` on the order and stock
paths, and the owner reserved the shared contract.

## A behaviour change worth stating

**The nudge becomes deliverable for the first time.** WhatsApp rejected a JSON `to` field, so on a
configured store this button previously sent nothing to anybody. A merchant or staff member holding
`Abandon Cart` can now really message their own shopper, and the endpoint has no rate limit of its
own. The recipient is a customer of the caller's own store — the unchanged P2.5-PKG-C tenant
predicate is what keeps it there.

## Nothing was sent

`tests/Feature/Merchant/AbandonedMessageReportingTest.php` — 15 cases, 42 assertions. Every request
that can reach the send leg runs behind `withoutSending()`, which replaces `handOver()` with a
recorder; `blockedReason()` is left as the real class computes it, so the preconditions under test
are production's. The configured case asserts the **double** received the message exactly once.

**This was wrong in the first version and an independent review caught it.** The two cases that
configure WhatsApp drove the real `Utility::SendMsgs()` into `curl_exec` against
graph.facebook.com — four outbound POSTs per Feature run, with no `CURLOPT_TIMEOUT` and no
`CURLOPT_CONNECTTIMEOUT`, carrying `Authorization: Bearer FAKE-TOKEN-NEVER-REAL`. Their assertions
passed either way, because `SendMsgs()` discards the response, so the result looked identical and
only reading the path showed it. Those four requests were made before the fix landed; Facebook
rejects an invalid token, but the owner's instruction was to use fakes and that version did not.

## Reported, not changed

* **The wishlist handler gates on the permission `Abandon Cart`** and uses the template name
  `Abandon Cart`, on a wishlist action. Correcting either changes who may do what, or which
  template the action reads — a role holding `Abandon Cart` but not `Abandon Wishlist` can send
  these today. Permission changes are reserved; the test asserts the names actually used.
* **`$storeId` must equal `getCurrentStore()`.** `SendMsgs()` re-resolves the store itself and
  ignores the caller's, so a future caller passing another store would check one and send with the
  other. Both callers today pass `getCurrentStore()` directly; the contract is recorded on the
  method.
* **`GetValueByName()`'s fallback ignores its own `$store_id`** when a user is authenticated
  (`Utility::Setting()` substitutes `getCurrentStore()`). Harmless under the contract above.
* **A staff member holding `Abandon Cart` but not `Manage Setting` now learns whether their own
  store has WhatsApp credentials and whether the template is on** — a boolean about their own
  tenant, never a value, and observable from success-vs-failure in any case.
* **`resources/views/layouts/includes/alerts.blade.php:43`** declares a SweetAlert mixin that is
  never fired, carrying a debug string: `"23sfdcvdcgvThis action can not be undone…"`. Dead code.
* **`routes/web.php:1128` and `:1146`** both declare `POST abandon-wish-emailsend`. The later wins;
  both are in the same middleware group, so nothing is silently dropped.

## Files

```
app/Services/AbandonedMessageDispatch.php                     the state, and why it is not SendMsgs
app/Http/Controllers/CartController.php                       abandon_carts_messsend()
app/Http/Controllers/WishlistController.php                   abandonWishlistMsgSend()
tests/Feature/Merchant/AbandonedMessageReportingTest.php      15 cases, nothing leaves the machine
resources/lang/{ar,en}.json                                   the four merchant-visible reasons
```
