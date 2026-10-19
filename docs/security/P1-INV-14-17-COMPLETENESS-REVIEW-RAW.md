# P1-INV-14 / P1-INV-17 — completeness review (raw)

**Status: UNTRIAGED INPUT, not findings.** This is the verbatim output of a completeness-critic
agent run after both module guards were committed. It is recorded here so nothing is lost while
each row is verified, because the two guards' own censuses provably could not see some of it.

Every row below needs reading before it is believed. One row has already been verified and raised
(the `POST lotteryresult` host-order write). Where a row contradicts a claim in
`P1-INV-14-MODULE-ORDER-MUTATION-VERIFICATION.md` or
`P1-INV-17-MODULE-RESOURCE-OWNERSHIP-VERIFICATION.md`, the contradiction is the row's own claim
and not yet established fact.

---

## COMPLETENESS REVIEW — P1-INV-14 / P1-INV-17: what nobody has looked at

### Route census (the thing both guards claim to have done)

**`AuctionProduct/src/routes/web.php`** — group `:19` = `['auth','web','PlanModuleCheck:AuctionProduct']`; group `:24` = `['web']` only.

| line | route | handler | store predicate? |
|---|---|---|---|
| :20 | resource `auctionproduct` (index/create/**store**/show/edit/update/destroy) | `AuctionProductController` | index/create/edit yes; **store() no** (713 lines, unaudited); show() `view()` stub; update/destroy now guarded |
| :21 | resource `auctionsetting` | `AuctionProductSettingController` | **edit():94, update():117, destroy():142, updateStatus():156 all bare `find()`** |
| :22 | resource `auctionorder` | `AuctionOrdersController` | **destroy():107 bare `find()`** |
| :25 | GET `{storeSlug}/place-bid/{product_slug}` | `bidingForm` | **NO** — `:1566` `Product::where('slug',…)->first()`, `$store` fetched and unused |
| :26 | POST `{storeSlug}/product-bid` | `addProductbidamount` | **NO** — `:1603` `store_id` from request; `:1602` `customer_id` from request |
| :27 | GET `{storeSlug}/auction-product/{product_slug}` | `product_detail` | yes (`:1625-1632`) |
| :28–:33 | the six guarded admin mutations | — | guard supplies it |
| :29,:34,:35 | `auction-order-view/{id}`, `auction-order-receipt/{id}`, `auction/shippinglabel/pdf/{id}` | — | covered by `OrderDocumentAccessGuard:189,195,196` — **not a gap, verified** |
| :30 | `{storeSlug}/auction-order/{id}` | `orderdetails` | yes (`:151`) |
| :36 | `{storeSlug}/auction-order-list` | `order_list` | yes (`:330-337`) |

**`LotteryPrizes/src/routes/web.php`** — group `:19` = `['auth','web','PlanModuleCheck:LotteryPrizes']`; group `:32` = `['web']` only.

| line | route | handler | store predicate? |
|---|---|---|---|
| :20 | resource `lotteryproduct` | `LotteryPrizesController` | store():107-124 yes; update/destroy are `//` |
| :21 | resource `lotteryresult` | `LotteryResultController` | **store():66-193 NO** — see F1 |
| :22 | resource `lotteryorder` | `LotteryOrderController` | index():33 yes but **permission check commented out at :31,:36-38**; rest `//` |
| :25,:27 | `lottery_email_template_lang`, `lottery_email_lang/{id}/{lang?}` | `LotteryemailTemplate`, `manageLotteryEmailLang` | **NO** — `:381` `EmailTemplate::where('id',$id)` |
| **:28** | **PUT `lottery_email_lang/{id}/`** | `updateLotteryEmailSettings` | **NO** — see F5 |
| :33,:34 | lottery product / form pages | | yes |
| :35 | POST `{storeSlug}/lottery-form-data` | `lottery_form_data` | `:535` `Product::find($request->product_id)` **unscoped**; setting re-anchored at :537 so a foreign id → `$lottery_setting->ticket_price` on null → `Error` escapes `catch (\Exception)` at :560 → anonymous 500 |
| :37,:39 | refund form / POST `refund-lottery-form-data` | `refund_lottery`, `refund_lottery_form_data` | `:862`, `:870` customer-keyed, **no store_id**; both deref `auth('customers')->user()->id` on an anonymous route → 500 |
| :41–:50 | `store-lottery-payment` + 5×`Route::any` ticket-payment + 2×`Route::any` telegram/whatsapp | `StoreLotteryPayment` | store predicate yes — but see F4 |
| :48 | POST `{storeSlug}/ticket-get-massage` | `getWhatsappUrl` | yes |
| :54,:57,:58 | view/shippinglabel/receipt | — | covered by `OrderDocumentAccessGuard:188,197,198` |

**`api.php` (both modules)** — `Route::middleware('auth:api')->get('/auctionproduct' | '/lotteryprizes')`, registered at `api/…` by each `RouteServiceProvider::mapApiRoutes():61-67`. There is no `api` guard in this application, so both are a guaranteed `InvalidArgumentException` 500. Latent (nothing calls them). Also both `web.php` files are registered **twice** — `RouteServiceProvider::map()` (confirmed reachable: `vendor/laravel/framework/.../Providers/RouteServiceProvider.php:167-168`) and the module provider's `loadRoutesFrom()`; harmless for the guards because the in-file groups are identical, but the docblock's single-registration model of load order is incomplete.

---

### F1 — `POST lotteryresult` writes host `orders` cross-tenant. In neither TARGETS, invisible to both ratchets. **LIVE.**

`packages/workdo/LotteryPrizes/src/app/Http/Controllers/LotteryResultController.php:66-193`, reachable as `POST lotteryresult` from the resource at `routes/web.php:21`. **No permission check** (only `index():32` checks `Manage Lottery Winner`), so any authenticated principal whose plan carries LotteryPrizes reaches it.

- `:86-88` `PurchaseTicket::with(…)->whereIn('lottery_code', $selectedCodes)->get()` — `$selectedCodes` is `explode(',', $request->input('selected_codes'))`. **No store predicate.** The caller names another tenant's ticket codes.
- `:125-142` for each such ticket it inserts a **host `App\Models\Order`** with `product_id` = the victim's product, `final_price` = the victim's `ticket_price`, `payment_status => 'Paid'`, `product_order_status => 'lottery_order'`, and `store_id => $store->id` — *the attacker's* store. A forged paid order in the host money table, product id from another tenant.
- `:148` `LotteryCustomer::insert($lotteryCustomers)` with `'store_id' => $ticket->store_id` — rows written **into the victim's tenant**, via `::insert()`, which bypasses model events and every global scope.
- `:79` `LotteryCustomer::whereIn('lottery_code', …)` unscoped — the replay guard is global, so one tenant's draw can block another's codes.

Why nobody saw it: `P1-INV-17-…md:10-11` states the scope as "reading every `update()` and `destroy()` body behind the two modules' six `Route::resource` declarations" — `store()` was never in scope, and `:237-238` "What is NOT closed" names only `show()`. `ModuleOrderMutationGuardTest.php:962` exempts `lotteryresult/{lotteryresult}` with "stub: update():221 and destroy():231 are both `//`" — accurate about those two methods and **falsely reassuring about the resource**, since the dangerous method on it is `store()`. The resource ratchet (`ModuleResourceOwnershipTest.php:683-687`) hard-codes six *AuctionProduct* actions and `:703` restricts to PUT/PATCH/DELETE, so neither census can ever produce this route.

One prior look at this file exists and settled a different question: `docs/STABILIZATION-REGISTER.md:459` examined `:136`'s `'payment_status' => 'Paid'` literal, ruled it NOT APPLICABLE, and recorded "Nothing is outstanding." That conclusion is about the payment-status vocabulary only; it does not survive the unscoped `whereIn` above.

Secondary, same handler: `:151-152` uses `$lottery_setting` after the `:95` loop, so `POST lotteryresult` with a code matching no ticket 500s on an undefined variable; `:144`/`:185` `sleep(1)` per ticket makes request duration linear in attacker-supplied code count.

### F2 — the lottery order UI posts its return to the **auction** route. Wrong table, wrong tenant. **LIVE.**

`packages/workdo/LotteryPrizes/src/resources/views/admin/lottery-order/view.blade.php:349` is `url: '{{ route('auction.order.return') }}'`, posting `order_id` taken from the **host `orders`** row the lottery view renders. It lands on `AuctionOrdersController::order_return:251` → `AuctionProductOrder::product_return` → `AuctionProductOrder::find($order_id)` — a host order id looked up in `auction_product_orders`.

Pre-guard: whichever tenant owns the auction order with that numeric id gets its `product_json` rewritten and its products restocked. Post-guard the guard reads `auction_product_orders` (TARGETS `:195-198`) so cross-tenant is refused — but the residue is worse than "closed":

- a merchant whose own auction order happens to share the numeric id passes the guard and **silently mutates their own auction order instead of their lottery order** (within-tenant, no cross-tenant predicate can see it);
- otherwise the shipped lottery-return button now returns **403 to a legitimate merchant** — a user-visible regression introduced by this guard, on a path no test drives;
- `lottery-order-return` (`routes/web.php:59`), which the guard's TARGETS `:174-178` and `P1-INV-14-…md:17` treat as one of "the two lottery routes [that] are the worst", has **no caller anywhere** in either repository (grep: route declaration, guard, and tests only). The guard pins a dead route for the lottery module while the live lottery-return traffic is authorised against the wrong table.

### F3 — `auction_product_orders` has none of the four columns the return path writes. Two guarded routes cannot complete for anyone, and no allow-path test is possible. **LIVE (as a 500), and it falsifies INV-14's latency analysis.**

`packages/workdo/AuctionProduct/src/database/migrations/2024_09_18_070205_create_auction_product_orders_table.php` creates no `return_price`, `final_price`, `return_status` or `return_date`. No other migration touches the table (grep across `database/` and `packages/`; `_gate_f_pending/` holds none of them). Yet:

- `AuctionProductOrder.php:257` `$order->return_date = now()` and `:259` `$order->return_status = 1`, then `:260 save()` — so the **`return` verb of `auction-order-status-change`** 500s on `Unknown column`. `P1-INV-14-…md:18` lists that route among the five that are "live"; one of its six verbs is dead.
- `AuctionProductOrder.php:352` `$Order->return_price = …` and `:354` `$Order->final_price = …`, then `:355 save()` — `auction-order-return` 500s **regardless of `product_json`'s shape**.

`P1-INV-14-…md:33-42` explains `auction-order-return`'s latency solely by the `product_json`-is-an-object / `ErrorException` path and stops at the first obstacle. Two consequences it gets wrong: (a) fixing #61's `product_json` shape would **not** revive the route or produce the "live cross-tenant stock drain" the guard docblock `:121-130` anticipates — the handler still dies on four missing columns; (b) `:350` reads `$Order->final_price` on a column that does not exist, so the "no replay guard… recomputes `final_price` from the already-reduced total" claim in guard docblock `:128-129` describes arithmetic on a nonexistent attribute.

The testing consequence is the one that matters here, and it is the CLAUDE.md "a test must bite" failure in its exact documented form: there is **no allow-path test for either auction return surface** (`ModuleOrderMutationGuardTest` has `test_an_anonymous_caller_cannot_return_an_auction_order:386` and nothing on the other side), and none can be written, because the handler 500s for every principal. Every "the row did not change" assertion on `auction-order-return` is satisfied by the 500. This is a second vacuous-test class, distinct from the product-update one already found.

Note the restock at `AuctionProductOrder.php:309`/`:315` and the `save()` at `:322` **commit before** the `:355` failure, so the unscoped `Product` write does happen and then the response is a 500 — not the success JSON the docblock's residual description implies.

### F4 — anonymous, unverified ticket mint with the price taken from the request. The docblock's "public by design" dismissal does not cover the write. **LIVE.**

`LotteryPrizesController::StoreLotteryPayment:565-617`, reachable at `routes/web.php:41-46` and `:49-50` — `GET {storeSlug}/store-lottery-payment` plus five `Route::any` ticket-payment callbacks and two `Route::any` telegram/whatsapp routes, all in the `['web']`-only group.

`:567-570` `$requests_data = Session::get('lottery_request_data'); if (null) $requests_data = $request->all();` then `:571 $ticket_amount = $requests_data['ticket_price']`. With no session the amount is **whatever the caller posted**, and for every method except `paytr`/`Midtrans`/`Khalti` there is **no verification branch at all** — control falls straight to `:600-617`, which inserts a `PurchaseTicket` with `amount = $ticket_amount` (`:614`) and `customer_id = auth('customers')->user()->id ?? 0` (`:613`, so anonymous is fine). Those ticket rows are the input to F1's host-`orders` prize awards.

`ModuleOrderMutationGuard.php:35-37` and `P1-INV-14-…md:30-31` dismiss these as "shopper-facing and public by design". That is true of the page and false of the write: these are the **payment-completion** endpoints, and the census never distinguished a public read from a public money write. `ModuleOrderMutationGuardTest.php:922-925` restricts the scan to eight method names (`order_status_change`, `order_return`, `order_assign`, `updateStatus`, `order_payment_status`, and three camelCase variants), so `StoreLotteryPayment`, `lottery_form_data`, `refund_lottery_form_data`, `addProductbidamount` and `getWhatsappUrl` are not produced by the ratchet at all — not guarded, not exempted, not recorded. The docblock's claim at `:22-24` that "**every POST** in each module's route file was listed" is not supported by any control, and the ratchet is method-name-allowlisted, not merely uri-prefix-anchored.

### F5 — `PUT lottery_email_lang/{id}` rewrites any tenant's email templates. **LIVE.**

`routes/web.php:28` → `LotteryPrizesController::updateLotteryEmailSettings:333-376`, in the `auth` + `PlanModuleCheck:LotteryPrizes` group, **no permission check** (the sibling `store():92` checks `Edit Lottery Setting`).

`:350` `EmailTemplate::where('id', $id)->first()` then `:351-352` writes `from`; `:354-366` writes `subject` and `content` on `EmailTemplateLang::where('parent_id', $id)`. `$id` is the raw route parameter. `EmailTemplate` and `EmailTemplateLang` are plain `Model`s, absent from `config/tenancy.php`'s `scoped_models`, and `email_template_langs` has **no `store_id` column at all** (tenant separation on `email_templates` is `created_by`, and this handler ignores it). So any merchant with the module in plan rewrites another tenant's transactional email bodies, with `content` validated only `required`. `manageLotteryEmailLang:381` is the matching cross-tenant read.

Honest framing: `app/Http/Controllers/EmailTemplateController.php:59-96` has the **identical** hole, so this is a platform-wide defect the module duplicates, not a module-specific bypass — but it is a *second, unaudited copy on a route both censuses are structurally unable to see* (a `PUT`, and a method name not in the allowlist), and it is not in `docs/security/P01-SECURITY-WORK-QUEUE.md`.

### F6 — the auction order view drives **host** order routes with an **auction** order id. **LIVE, within-tenant corruption.**

`AuctionProduct/src/resources/views/auctionorder/view.blade.php:437-445` posts `order_id: "{{ $order['id'] }}"` (an `auction_product_orders` id) to `route('order.payment.status')` — the host `routes/web.php:1180` → `OrderController::order_payment_status:1087`, which does `Order::findOrFail($request->input('order_id'))` on the **host `orders`** table and `$this->authorize('updatePaymentStatus', …)`. Same shape at `auctionorder/index.blade.php:29-37` → `route('order.return.request')` (host `routes/web.php:1266`).

Because the host side is now correctly store-authorised, the outcome is: if the merchant owns a host order with that numeric id, **their own unrelated host order's `payment_status` is silently overwritten** (and `OrderObserver` → `OrderFinancialService::syncFromOrder` fires on it); otherwise 403/404. Either way the auction order's own `payment_status` can never be changed from its own UI. Neither guard's TARGETS contains these uris and neither census produces them — `order_payment_status` *is* in `ModuleOrderMutationGuardTest.php:924`'s method list, but the scan only reads module route files, and this route is declared in the host.

### F7 — a second IDOR on a route this guard already authorises. **LIVE.**

`AuctionProductController.php:1118` and `:1208`, inside `update()` (a `PUT auctionproduct/{id}` target): `ProductAttributeOption::where('terms', $fValue)->first()->toArray()` — `$fValue` is request-supplied, **no store predicate**, **no null guard**. The guard authorises the `products` row; it does not authorise this lookup, so another tenant's attribute-option id is resolved and written into the caller's variant, and an unmatched term is a 500. `ModuleResourceOwnershipGuard`'s docblock states what it cannot close only for `show()` and for permissions; this residual on a guarded write is unstated in both the docblock and `P1-INV-17-…md:235-244`.

### F8 — bid writer: the forged field nobody named is `customer_id`. **LIVE.**

`AuctionProductController::addProductbidamount:1586-1604`. The already-reported unscoped `store_id` is `:1603`. The sharper half is `:1600-1602`: `product_id`, `bid_amount` and **`customer_id`** all come straight from the request under a validator that marks `product_id` and `customer_id` `nullable`. A signed-in shopper can therefore write a bid **attributed to another customer**, for another store's product, at any amount — and `Console/Auction.php:73-74` then takes `AuctionProductBid::where('product_id',…)->max('bid_amount')` with **no store predicate** and turns `$maxBidRecord->customer_id` into the `AuctionProductOrder`'s customer at `:95`. That is the path from the bid writer to a money row against a third party; the cron is the consumer that makes the writer exploitable rather than merely untidy. `bidingForm:1566` (`Product::where('slug', …)` with no store predicate, anonymous) completes the pair as a cross-tenant catalogue read.

### F9 — scheduled commands. No observers exist; the crossing is in the cron's own state. **LIVE.**

Neither module has `src/app/Observers/`. `AuctionProductServiceProvider::boot():24-31` and `LotteryPrizesServiceProvider::boot():22-29` only load routes/views/migrations/translations and register the commands; `AuctionProductOrderProvider::boot():23-41` is the one view composer, registered through `packages/workdo/AuctionProduct/composer.json:22` (so it *is* live), and its two queries at `:30` and `:32` both carry `where('store_id', $store->id)` — clean on tenancy, but `auth('customers')->user()->id` is dereferenced with no null check, so rendering `front_end.sections.pages.my_account` without a customer session is fatal.

The commands themselves:

- `AuctionProduct/src/app/Console/Auction.php:47` declares `$auctionProductIds = []` **outside** the per-setting loop and `:66` `array_merge`s into it. The product query at `:67-70` is store-anchored, so it is not a tenant leak — but the array **accumulates across settings**, so a second setting firing in the same minute re-selects the first setting's products and creates a **duplicate `AuctionProductOrder`** (`:92-109`) for an already-ordered auction. Money-bearing duplicate, latent on two same-minute settings in one store.
- Same file, no `try`/`catch` anywhere in the per-setting loop: `:78` `Store::find($item->store_id)` → `:85 $store->id`, and `:114` `User::find($store->created_by)` → `:115 $owner->email`, are unguarded. One orphaned row aborts `auction:order` and **every later tenant's auctions are never closed**.
- `LotteryPrizes/src/app/Console/Lottery.php:50` `LotterySetting::get()` is all tenants (fine, it iterates), `:67-69` is store-anchored (fine), but `:71` `$lottery_products->created_by` has no null guard and `lottery_settings.lottery_products` is a `string` column read with `where('id', …)` — a deleted product, or a comma-list value, yields null and kills the run for every remaining tenant.

### F10 — `GET lotteryorder`'s permission check is commented out. **LIVE, within-tenant.**

`LotteryOrderController.php:31` and `:36-38`: `isAbleTo('Manage Lottery Order')` is commented out around the body. The query at `:33` and `LotteryOrderData:117-120` are store-scoped, so this is not cross-tenant — but any staff member on a role that deliberately lacks the lottery permission reads the full lottery order list. `ModuleResourceOwnershipGuard` covers `PUT/PATCH/DELETE auctionorder` only; no control covers this.

### F11 — claims in the guards/docs that the code does not support

1. `ModuleOrderMutationGuard.php:22-24` — "Every POST in each module's route file was listed." It is not: `product-bid` (`:26`), `refund-lottery-form-data` (`:39`), `ticket-get-massage` (`:48`) and the six resource `store()` limbs are named nowhere, and `PUT lottery_email_lang/{id}` is outside the claim's own POST framing. The backing ratchet is method-name-allowlisted (`ModuleOrderMutationGuardTest.php:922-925`), so nothing enforces the claim.
2. `ModuleOrderMutationGuardTest.php:958-960` — the three auction-resource exemptions still read "**OPEN** … tracked at #63". `ModuleResourceOwnershipGuard` closed them; the exemption text is stale and now describes as open what its sibling guards.
3. `ModuleOrderMutationGuardTest.php:961-963` — the three lottery-resource exemptions justify themselves entirely on `update()`/`destroy()` being `//` stubs, which is true and which is not the question for `lotteryresult` (F1).
4. `P1-INV-17-…md:15-16` — "Both resource groups carry `auth` + `PlanModuleCheck:**AuctionProduct**` and no store predicate anywhere." The LotteryPrizes group carries `PlanModuleCheck:LotteryPrizes` (`web.php:19`), and `LotteryPrizesController::store():107-124` *does* carry a store predicate. The sentence is wrong in both directions and papers over the one resource method that genuinely lacks one.
5. `P1-INV-14-…md:33-42` — the latency analysis for `auction-order-return` (F3), and `ModuleOrderMutationGuard.php:128-129`'s replay/`final_price` residual, both describe writes to columns that do not exist.
6. `ModuleOrderMutationGuard.php:121-137` ("WHAT THIS GUARD CANNOT CLOSE") lists only `AuctionProductOrder::product_return`'s `Product` restock. `P1-INV-14-…md:318-322` additionally lists `AuctionOrdersController.php:230`'s host-`orders` webhook read (#62). The docblock is the artefact a maintainer reads; it omits a filed residual on one of its own guarded routes.
7. `docs/STABILIZATION-REGISTER.md:459` — "Nothing is outstanding" for `LotteryResultController`. True for the payment-status literal it was examining; false for the file (F1).

### F12 — module `api.php`. **LATENT.**

`AuctionProduct/src/routes/api.php:16` and `LotteryPrizes/src/routes/api.php:16` declare `Route::middleware('auth:api')`, registered at `api/auctionproduct` and `api/lotteryprizes` by each `RouteServiceProvider::mapApiRoutes():61-67`. This application has no `api` guard (three session guards only), so either route is an unconditional `InvalidArgumentException` → 500. Nothing calls them, so latent; worth an entry because `ModuleResourceOwnershipTest.php:675` already cites this second route file as the prefix hazard its census was widened for, without noting the file's own content is broken.

### Answer to the direct question: host tables written outside `products`/`orders`

Yes, in both modules — `product_images` and `product_variants` (`AuctionProductController.php:1310,1451,1532,1533`), `product_variants.stock` (`AuctionProductOrder.php:315`, the known residual), `notify_users` (`:1475`, scoped model), **`email_templates` and `email_template_langs`** (F5, unscoped and with no `store_id` column), and `order_notes` is read but not written. `ProductAttributeOption` is read unscoped (F7). Neither guard's TARGETS, and neither doc, mentions `email_templates`.
