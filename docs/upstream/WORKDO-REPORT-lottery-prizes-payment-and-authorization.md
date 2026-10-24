# UPSTREAM REPORT — PREPARED, NOT SUBMITTED

**Status:** drafted for owner review. **Not sent.** No vendor file has been modified.
**Internal references:** issues #67 (item 1), #68 (item 2), #70 (items 3 and 4); tasks
`P1-INV-20`, `P1-INV-21`, `P1-INV-22`.

> **Owner approval is required before this leaves the building.** It names file paths, line
> numbers and four defects in a commercial vendor's code. It contains no credentials, no customer
> data, no store names and no schema beyond the column list of one table — but it is still an
> outward-facing disclosure and the decision to send it is the owner's.

This is the **second** such report. The first,
`docs/upstream/WORKDO-REPORT-email-template-tenancy.md`, covers the cross-tenant email-template
resolve shared by six modules (#69, and #67 item 3). Nothing here repeats it.

---

## Reporting against

| | |
|---|---|
| Platform | eCommerceGo SaaS addons, Laravel 11.47.0, PHP 8.3 |
| Module | `workdo/lottery-prizes`, `module.json` `"version": 1.1`, `"package_name": "lottery-prizes"` |
| Version pinning | `module.json` declares `1.1`; the module's `composer.json` declares no `version` key, so each defect below is pinned **additionally** by file path, line number and the quoted line, which is reproducible against any checkout |
| Checkout pinned at | `3c39c160001965b7e11602eed73c2c6e4f03f52f` (2026-08-27), the last commit touching `packages/workdo/LotteryPrizes/` |

Line numbers were read with `grep -n` against the installed tree while writing this. Each quoted
line is given in full so the report survives a shift.

---

## Why these four are reported rather than fixed

This installation does not modify `packages/workdo/`: an in-place edit is reverted by the next
upstream update and leaves no patch record, and a CI guard refuses any diff that touches the
directory. Where a defect has a seam in the host application — a policy, a FormRequest, a query
scope, or middleware appended to the `web` group — it is closed there, and several in this module
already have been (ticket-write, refund-ownership, draw-ownership and lottery-setting product
ownership guards are all live host-side).

**These four have no such seam.** Each is a decision taken inside a vendored controller or service
with nothing in front of it that a host mechanism can intercept, and two of them need a database
column that does not exist. They are therefore yours.

---

## Defect 1 — a lottery ticket is recorded with no evidence that the payment settled

**Severity: high. Money-bearing.**

### The lines

`src/app/Http/Controllers/LotteryPrizesController.php`, `StoreLotteryPayment()`:

```php
:567        $requests_data     = (Session::get('lottery_request_data'));
:568        if ($requests_data == null) {
:569            $requests_data = $request->all();
:570        }
:571        $ticket_amount     = $requests_data['ticket_price'];
...
:575        } elseif ($requests_data['payment_methods'] == 'Midtrans') {
:580        } elseif ($requests_data['payment_methods'] == 'Khalti') {
...
:614        $lottery_data->amount       = $ticket_amount;
:617        $lottery_data->save();
```

Eight routes reach this one action, and all eight are in the same `['web']`-only group
(`src/routes/web.php:41-50`): `store-lottery-payment`, `get-ticket-payment-paytm`,
`get-ticket-payment-aamarpay`, `get-ticket-payment-midtrans`, `store-ticket-payment-khalti`,
`store-ticket-payment-authorized`, `ticket-telegram` and `ticket-whatsapp`.

### What happens

`payment_methods` is caller-supplied and is **the sole selector of whether anything is verified**.
Only two arms verify a payment at all — Midtrans at `:575` and Khalti at `:580`. Every other value
falls through to `save()`. The route (`src/routes/web.php`, the `['web']`-only group) carries no
authentication and no entitlement check, and `store.lottery.payment` is wired as `return_url`,
`cancel_url` **and** `callback_url` throughout `LotteryPaymentService` (`:335`, `:438-439`, `:528`,
`:655-656`, `:713-714`, `:779-780`, `:899`), so a caller who submits the lottery form legitimately
— that route carries no authentication either — can then skip the gateway entirely and request the
return URL directly.

### Why no host-side mechanism closes it

Middleware can ask who the caller is and what they sent. It cannot ask whether money moved. The
answer has to come from the gateway's own signature or a transaction lookup, and both have to
happen inside the handler that writes the row. Requiring an authenticated customer would not help
and would break a real flow: guest ticket purchase is supported and the form route is anonymous by
design.

### Suggested shape of a fix

Verify inside `StoreLotteryPayment()` before `save()`, for every arm rather than two: the
gateway's signed callback, or a server-to-server transaction lookup keyed on the gateway's own
reference. Defect 2 is the prerequisite — there is currently nowhere to put the answer.

---

## Defect 2 — `purchase_tickets` has no column in which a payment could be recorded

**Severity: high. The prerequisite for Defect 1.**

### The table

`src/database/migrations/2024_10_24_063457_create_purchase_tickets_table.php`, the complete column
list as shipped:

```
id, lottery_id, lottery_code, customer_id, firstname, lastname, email, number,
address, country_id, state_id, city_id, postcode, product_id, amount,
payment_type, store_id, timestamps
```

### What is missing

There is **no payment-status column, no transaction-id column and no gateway-reference column**.
`payment_type` is the caller-supplied `payment_methods` value, not a result. So:

- there is nothing for any verification to write to, which is why Defect 1 cannot be closed by a
  check alone;
- a ticket row cannot be distinguished from a paid ticket row by any query, at any later date,
  including by the merchant's own reporting;
- a reconciliation against the gateway's records is not possible, because nothing in the row names
  the gateway transaction.

`amount` is a `string` column, which is a separate and smaller problem: it stores money as text,
so arithmetic over it depends on the database's implicit conversion rules.

### Suggested shape of a fix

Three nullable columns — `payment_status`, `transaction_id`, `gateway_reference` — written by the
verification in Defect 1, with existing rows left NULL and read as "unknown" rather than
backfilled to "paid".

---

## Defect 3 — two authorization checks lock out the platform operator

**Severity: medium. Fails closed, so this is a usability and administrability defect, not an
exposure — reported because the mirror-image defect is just as real.**

### The lines

`src/app/Http/Controllers/LotteryPrizesController.php`:

```php
:92     if (auth()->user()->isAbleTo('Edit Lottery Setting')) {
```

`src/app/Http/Controllers/LotteryOrderController.php`:

```php
:102    if (auth()->user() && auth()->user()->isAbleTo('Show Order')) {
```

### What happens

The platform's permission layer (Laratrust, with `permissions_as_gates => false`) has **no
super-admin bypass**: operator status is a value in `users.type`, and `isAbleTo()` consults roles
only. The platform operator holds no role carrying either permission, so both checks refuse them
permanently. An operator cannot open a store's lottery settings or view a lottery order even to
diagnose a support request.

`:92` has a second consequence worth stating plainly: `auth()->user()` is dereferenced with no null
check, so an unauthenticated request to that action is a fatal rather than a redirect.

### Suggested shape of a fix

Test operator status **before** the permission, which is the ordering the rest of the platform
uses:

```php
if ($user && ($user->type === 'super admin' || $user->isAbleTo('Edit Lottery Setting'))) {
```

and give `:92` the same `auth()->user() &&` null limb that `:102` already has.

---

## Defect 4 — a permission check is commented out

**Severity: medium.**

### The lines

`src/app/Http/Controllers/LotteryOrderController.php`, `index()`:

```php
:28     if (!auth()->user()) {
:29         return redirect()->route('login');
:30     }
:31     // if (auth()->user() && auth()->user()->isAbleTo('Manage Lottery Order')) {
:32     $store = Store::find(getCurrentStore());
:33     $orders = Order::where('store_id', $store->id)->where('product_order_status','lottery_order')->get();
```

### What happens

The lottery order list is gated on **authentication only**. Any signed-in user of the store — any
staff member, whatever their role — sees every lottery order in it. `Manage Lottery Order` is
seeded by the module's own `PermissionTableSeeder` and is checked nowhere, so granting or
withholding it has no effect on this screen.

The containment is real but narrow: `getCurrentStore()` scopes the query, so this is a
**within-tenant** permission bypass, not a cross-tenant read.

### Suggested shape of a fix

Uncomment `:31` with the operator limb from Defect 3 placed first, and close the brace.

---

## What this installation has done in the meantime

For the record, so the report is not read as a list of things left open:

| | Closed host-side | Mechanism |
|---|---|---|
| anonymous single-request ticket write | yes | `LotteryTicketWriteGuard`, `web` group |
| refund deletes another store's ticket | yes | `LotteryRefundOwnershipGuard` |
| 500 on every successful draw | yes | `LotteryMailTemplatePrerequisite` |
| forged ticket price | yes | `EnforceLotteryTicketPrice`, an `eloquent.creating` listener |
| lottery pointed at another store's product | yes | `LotterySettingProductOwnershipGuard` |
| draw ownership | yes | `LotteryDrawOwnershipGuard` |
| **the four above** | **no** | **no seam exists** |

Two further findings in this module are verified and documented but are **not** in this report
because they are functional rather than security defects, and because a correction for them is
already proposed internally: a casing mismatch between the form's
`ucfirst($payment_data->name_string)` and `LotteryPaymentService::process`'s mixed-case `switch`
makes most payment arms unselectable, and the Midtrans arm is dead in both directions
(`LotteryPrizesController:575` tests `== 'Midtrans'` while `LotteryPaymentService:99` is
`case 'midtrans'`). Those are
`docs/security/P01-LOTTERY-PAYMENT-METHODS-AVAILABLE.md` and
`docs/security/P01-LOTTERY-PAYMENT-SELECTION-PROPOSAL.md`. They would be worth sending with this
report if the owner chooses to send it.
