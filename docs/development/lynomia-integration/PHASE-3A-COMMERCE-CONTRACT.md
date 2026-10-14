# Phase 3A — Store Syria as a Lynomia commerce provider: the contract

## What already exists, and is therefore not being rebuilt

Verified against `lynomiachat` at `79f3cc3b`:

- `Commerce::Providers::REGISTRY` (`custom/app/services/commerce/providers.rb:3`) — a frozen hash
  of four providers. A fifth is one entry plus a `Commerce::Providers::Base` subclass.
- A second, independent list — `Commerce::Store::PROVIDERS`
  (`custom/app/models/commerce/store.rb:30`) — which model validation uses. **Nothing reconciles
  the two.** A provider added only to the registry is constructible but fails validation on
  `Commerce::Store`, `Commerce::Cart` and `Commerce::ActionRun`.
- Canonical value objects every provider maps into: `Commerce::Customer`, `Commerce::Order`,
  `Commerce::AbandonedCart`, `Commerce::ActionSnapshot` / `ActionResult`. There is **no**
  `commerce_orders` table — orders live in memory and in a Redis cache, summarised durably only
  in `commerce_contact_metrics`.
- SSRF protection, body caps, redirect refusal, timeouts and store-health recording come free
  **only** by going through `Commerce::HttpClient`. Hand-rolling HTTP in an adapter bypasses all
  of it.
- Store ownership: `commerce_stores` has a **global** unique index on
  `(provider, external_store_id)` — one store, one Lynomia account, installation-wide — arbitrated
  by `Commerce::StoreConnection#claim`. Channel exclusivity for commerce is therefore already
  solved; Phase 3 inherits it rather than reimplementing it.
- Eight commerce automation triggers already exist and are fully wired. Phase 3 extends them; it
  does not build a second engine.

## The blocker that shapes the Store Syria side

**The existing admin API cannot be used by the adapter.**

`routes/api.php` exposes `admin/orderlist`, `admin/vieworder`, `admin/customerlist` and friends
behind `custom.auth` (`app/Http/Middleware/CustomAuth.php`). That middleware resolves a Sanctum
token to its owner and logs them into the `web` guard. Every one of those handlers then tenants
itself with `getCurrentStore()`, which reads **`users.current_store`** — a persisted, mutable
column on the users table (`2014_10_12_000000_create_users_table.php:25`), written by the store
switcher, by registration and by the Flutter admin app.

So the tenant of an admin-API call is "whichever store this human last clicked", shared across
every session and device for that user. For an integration that must hold a stable, provable
binding to exactly one store, that is not a tenancy mechanism — it is a race.

### The rule this forces

> **The credential names the store. Nothing else does.**

Not a request parameter, not a session, not the token owner's current selection. This also
satisfies the Phase 3 brief's "never rely on an arbitrary self-declared store ID as proof of
ownership" — there is no store id in the request to declare.

## The contract

A dedicated, versioned surface, separate from both the shopper API and the admin API:

```
POST /api/lynomia/commerce/v1/<resource>
Authorization: Bearer <store-bound commerce token>
```

| Endpoint | Purpose |
|---|---|
| `GET  …/v1/store` | identity + health; the store this credential is bound to |
| `POST …/v1/customers/lookup` | by verified email **or** E.164 phone, never by name |
| `GET  …/v1/customers/{id}/orders` | newest first, bounded |
| `GET  …/v1/orders/{id}` | one order, full detail |
| `GET  …/v1/carts/abandoned` | eligible carts only, per the Phase 3 criteria |

Normalisation happens on the **Lynomia** side, in the provider's `Normalizer`, exactly as the
other four providers do — the store returns its own vocabulary and the adapter maps it.

### Status vocabulary — measured, never invented

Store Syria has no enum and no check constraint. `orders.delivered_status` is a plain integer and
`orders.payment_status` is free text with at least ten spellings across ~52 gateway controllers.
The mapping is therefore pinned from the source:

| `delivered_status` | meaning | canonical `Commerce::Order#status` |
|---|---|---|
| 0 | Pending | `pending` |
| 1 | Delivered | `delivered` |
| 2 | Cancel | `cancelled` |
| 3 | Return (+ `return_status` 1/2/3) | `refunded` / `other` |
| 4 | Confirmed | `processing` |
| 5 | Picked Up | `processing` |
| 6 | Shipped | `shipped` |
| 7 | Partially Paid | `pending` |
| 8 | Pre Order | `pending` |

Payment status is canonicalised **by Store Syria**, reusing the one normaliser that already
exists — `OrderFinancialService::normalizePaymentStatus()` — so the adapter never has to know
that `success`, `succeeded`, `Succeeded` and `Paid` are the same thing.

There is **no separate shipping status column**: fulfilment is folded into `delivered_status`,
with `picked_date` / `shipped_date` / `delivery_date` as timestamps. The contract reports
shipping state derived from those, and never claims a tracking number the store did not give.

### What is deliberately not exposed

- No line items beyond name/quantity/total. `orders.product_json` is an unvalidated blob.
- No addresses. `order_billing_details` holds them and **has no `store_id` column**, so every read
  of it must be joined through `orders` — the table that an unscoped raw read of was removed from
  this branch in the census remeasurement.
- No payment instrument data of any kind.

## Open, and deliberately not started here

Consent, opt-out and suppression (Phase 3E). Lynomia has **no** consent model today — the only
per-contact gates on an automated send are `contact.blocked?` and "has a channel identity". That
foundation must exist, with transactional and promotional as **distinct eligibility classes**,
before any recovery or marketing path is wired.
