# Phase 3 — Store Syria × Lynomia Chat commerce integration: completion report

**Assessment: CONDITIONAL GO.** The engineering is complete, gated and pushed. What stands
between here and go-live is UAT, one product decision, and two owner decisions on defects this
phase found but did not fix. Nothing is merged, deployed, activated or connected to a live
provider, and no production credential exists.

---

## Commits

Branch `claude/store-syria-lynomia-integration-1oa1tt` in both repositories.

### `fullstackfull/syriastore98`

| Commit | Increment |
|---|---|
| `a7319706` | P3A — the commerce contract, recorded before any of it was built |
| `9c72e3e2` | P3A — store-bound commerce credential and the `/store` endpoint |
| `3759cfbe` | P3B — customers, orders and abandoned carts |
| `76d6008b` | P3C — the conversation's orders in the merchant inbox |
| `81f9f5df` | P3D — the transactional outbox |
| `1186bd30` | P3F — Super Admin commerce health, two review fixes, the security regression suite |

### `fullstackfull/lynomiachat`

| Commit | Increment |
|---|---|
| `6ed5194b` | P3A — Store Syria as the fifth commerce provider |
| `56926feb` | P3B — reading customers, orders and carts |
| `8b2eb6f8` | P3C — the conversation panel, with recovery held back |
| `967c7077` | P3D — receiving commerce events |
| `80056d69` | P3E — consent, opt-out and suppression |
| `22be2556` | P3F — the provider's Super Admin switches and its webhook throttle |

---

## What was built, and the one decision behind each

**P3A — the credential names the store, and nothing else does.** Store Syria's existing admin
API tenants every handler through `getCurrentStore()`, which reads `users.current_store` — a
persisted, mutable column written by the store switcher, by registration and by the Flutter
app, shared across every session and device of that person. An integration bound to it changes
tenant when a merchant clicks a different store in another tab. That is not a tenancy boundary,
it is a race, and no care on the Lynomia side could fix it. So Phase 3 built a separate surface
whose credential is bound to one store, and sends no store identifier of any kind: there is no
store id in any path, query or body, which is also why there is nothing for a caller to tamper
with. The credential is never stored — it is recomputed and compared in constant time — and
revocation is a version bump that invalidates it immediately, without a deploy.

**P3B — five reads, every one tenanted by that credential.** `CommerceReader` takes a store id
in its constructor and has no request, no session and no authenticated user, so the
`getCurrentStore()` mistake is not available to make inside it. `order_billing_details` has no
`store_id` column at all, so every read of it is joined through `orders`. Every test seeds the
same shape in two stores, because a single-tenant fixture cannot fail a tenancy test.

**P3C — the orders an agent sees, decided in one place.** Both the Lynomia panel and the Store
Syria merchant inbox show the conversation's orders, and both get them from Lynomia's matcher,
which links only on identifiers a channel verified. The merchant inbox proxies rather than
reading its own database, which looks like the long way round and is not: answering "which
customer is this conversation" twice means two sets of rules that disagree the first time
either changes.

**P3D — an outbox, because the alternatives both lose events.** A job dispatched from a model
observer fires even when the transaction rolls back; one dispatched after commit is lost by a
crash in between. A row written in the same transaction as the order change can do neither.
Delivery is a scheduled drain — not inline, which is the defect the retired Chatwoot
integration shipped, and not a queued job, because this module dispatches none and an
installation may have no worker.

**P3E — two classes with opposite defaults.** Transactional messages are about an order the
shopper placed and go out unless refused; promotional messages do not go out unless granted,
and the absence of a row is not a grant. Treating them the same fails in one direction or the
other. An opt-out is matched on the inbound message itself, in English and Arabic, because an
opt-out that needs an agent to notice it is not an opt-out.

**P3F — the controls, and what the review found.** See below.

---

## Executed test results

Every number here was produced by running the suite, not estimated.

### `syriastore98`

| Suite | Result |
|---|---|
| Unit | **181 tests, 1035 assertions, 0 failures** |
| `tests/Feature/Lynomia` | **203 tests, 3191 assertions, 0 failures** |
| `tests/Feature/Tenancy` (census) | **436 tests, 1502 assertions, 0 failures, 6 skipped** |
| Feature (whole) | 1623 tests, 10741 assertions, 8 failures, 17 skips |
| **Official known-outcome gate** | **EXIT 0** — matches the manifest exactly, nothing undeclared |
| **Merge-base regression gate** vs `0d2ca628` | **EXIT 0** — 0 new failures, 0 resolved, 8 inherited and all accepted, 0 unaccepted drift |

The base run for the regression gate was a real run at the merge base (1398 tests, 8 failures)
in a worktree with a **hard-linked** vendor directory and the autoload self-check passing —
a symlink would make the base run execute HEAD's `app/`, which is how a real regression gets
classified as inherited.

The 8 Feature failures are the pre-existing `owner_menu_settings` family in Auth and Profile,
unchanged in count and identity since before this branch. **The suite is not green, and this
report does not claim it is.**

### `lynomiachat`

| Suite | Result |
|---|---|
| Commerce services, models, listeners, commerce controllers, webhook receivers, automation, custom overlays | **1113 examples, 0 failures** |
| `spec/services/commerce/providers/storesyria_spec.rb` | 30 examples, 0 failures |
| `spec/controllers/webhooks/storesyria_controller_spec.rb` | 11 examples, 0 failures |
| `spec/controllers/.../commerce/storesyria_panel_spec.rb` | 7 examples, 0 failures |
| `spec/services/commerce/messaging_eligibility_spec.rb` | 16 examples, 0 failures |
| `spec/listeners/commerce/consent_listener_spec.rb` | 8 examples, 0 failures |
| Commerce settings frontend (vitest) | 29 tests, 0 failures |
| RuboCop / ESLint | clean on every file touched |

---

## The adversarial review

Four findings, all in code this branch added, all fixed in `1186bd30` / `22be2556`:

1. **A switch nobody could set.** `STORESYRIA_ENABLED` and `STORESYRIA_RECOVERY_ENABLED` were
   read by the code but absent from `installation_config.yml`, so Super Admin never offered
   them. The provider shipped off with no way to turn it on.
2. **A webhook path with no throttle.** `/webhooks/storesyria` carries no store id, so it
   matched neither commerce throttle. Adding it to the per-IP list alone would have reproduced
   the exact "one bucket per planet" defect A1 fixed, since Store Syria sends every store's
   events from one address. It is now keyed per store as well, with the per-IP ceiling bounding
   a caller who invents store names.
3. **A memo that outlived its own comment.** `CommerceEventObserver` cached each store's link
   check on the observer instance. Laravel keeps an observer for the life of the process, so in
   a queue worker a store revoked an hour ago would keep recording events until restart — the
   same shape as a `static` memo keyed on nothing, which this codebase has shipped once before.
4. **An N+1 in the cart reader.** A fifty-cart answer was five hundred queries.

Two further corrections were made mid-phase and are recorded in the contract document:
the payment-status normaliser cannot be reused (see Blocker 2), and Store Syria records no ISO
country for a store, so phone region is an installation setting.

`LynomiaPhase3SecurityTest` (8 tests, 179 assertions) pins Phase 3's security properties as
properties rather than behaviours, so they keep holding over code nobody has written yet. The
two load-bearing ones were mutation-checked: pointing the reader at `getCurrentStore()` and
adding a `{storeId}` route segment each turn the suite red.

---

## Remaining blockers

**1. APP_KEY exposure — accepted, unresolved, and designed around.** Unchanged from Phase 1.
Phase 3 stores no credential in the database at all: the commerce credential is derived and
compared per request, and the event signing key is derived from it. Nothing in this phase
depends on `encrypted` columns meaning anything.

**2. `OrderFinancialService::normalizePaymentStatus()` misclassifies paid orders — reported,
not fixed.** Its match arms name `paid`, `success` and `completed`. `succeeded` is not among
them, and `succeeded` is the single most common spelling in this codebase: thirteen write sites
across Stripe, Mercado Pago, Skrill, Midtrans, PayTabs, PayTR, Paytm, Flutterwave, CoinGate and
NMI, against four for `success`. It falls through to `default => 'unpaid'`. So
`store_order_financials` records `payment_status` `unpaid`, `paid_amount` `0`,
`remaining_amount` = the full total and `paid_at` `null` for every order paid through any of
those ten gateways. **This is pre-existing and outside Phase 3's scope; correcting it changes
money-bearing rows and is an owner decision.** The commerce API does not inherit the
misclassification — it has its own measured map — so Phase 3 is correct either way.

**3. No live end-to-end run between the two systems.** Every cross-system path is tested from
both sides against a fake of the other, with the contract pinned on both, but no deployed
Lynomia has been pointed at a deployed Store Syria. This is the UAT that has not happened.

**4. Promotional messaging cannot be activated for anyone yet, by design.** The consent
foundation has a writer, a keyword path and enforcement, but no screen for recording "this
shopper opted in at checkout". Until one exists, the only promotional grant is a shopper
typing START. This fails safe and is the correct state to stop in, but it is a gap a later
increment must close before any marketing or cart recovery is switched on.

**5. Store Syria is PRE_UAT for recovery.** `Commerce::Switches::PRE_UAT[:recovery]` includes
`storesyria`, so abandoned-cart recovery stays off even if an operator sets the switch. A
provider leaves PRE_UAT through a reviewed code change, not a setting.

**6. `LYNOMIA_COMMERCE_PHONE_REGION` is an installation default (`SY`).** Store Syria records
no country code per store — the only country it has is a free-text app setting, and
`countries` carries a name and nothing else. A store whose shoppers are elsewhere will match
only numbers entered in international form. The failure is a customer not found, never the
wrong customer found.

**7. The 8 pre-existing Feature failures remain.** Out of scope, manifest-accepted, unchanged.

---

## What was deliberately not done

- No merge, no deployment, no production credential, no live provider connection.
- `.env`, `APP_KEY` and production credentials untouched.
- No second messaging or automation engine. The eight existing commerce triggers were extended,
  not replaced; `CUSTOMER_MESSAGE_ACTIONS` is unchanged; `send_whatsapp_template` remains the
  one customer-facing automated action and is now **narrower** than it was.
- No unrelated Store Syria remediation.
- Phase 4 not started.

---

## Conditions for GO

1. A UAT run between a deployed Store Syria and a deployed Lynomia, with a real issued
   credential, covering: connect, customer match, order display, an order status change
   arriving through the outbox, and a revocation stopping both reads and deliveries.
2. An owner decision on Blocker 2 (the financial-record misclassification).
3. A product decision on how an at-checkout marketing opt-in is recorded, before any
   promotional path is switched on.
4. The existing release and UAT gates, unchanged.
