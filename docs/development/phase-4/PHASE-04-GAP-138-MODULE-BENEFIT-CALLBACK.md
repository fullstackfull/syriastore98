# GAP-138 / P04-P5-04 — the three vendored Benefit charges no longer name a public bin

**Date:** 2026-11-26
**Status:** engineering complete for the *egress* half of the row. The *capture-verification* half
(`P5-FIN-6`) is **NOT** done and is **NOT** started — see §7.
**Authorisation:** the owner decision quoted verbatim at `app/Services/PaymentService.php:1818`,
*"remove webhook.site"*. **Choosing a replacement destination is NOT covered** and was not done; it
remains the open escalation `P1-SEC-55`.

---

## 1. The defect, as measured

Three **vendored** WorkDo payment services build a Tap charge whose `post.url` is a public bin:

| vendored file | line | module's rail | who the payer is |
|---|---|---|---|
| `packages/workdo/Campaigns/src/app/Services/CampaignsPaymentService.php` | 940 | merchant pays the platform for a promoted placement | the **merchant** |
| `packages/workdo/Donation/src/app/Services/DonationPaymentService.php` | 968 | shopper donates at a merchant's storefront | a **member of the public** |
| `packages/workdo/LotteryPrizes/src/app/Services/LotteryPaymentService.php` | 965 | shopper buys a lottery ticket | a **member of the public** |

Each holds, inside the array `json_encode()`d and POSTed to `https://api.tap.company/v2/charges`
with a raw `new GuzzleHttp\Client()`:

```php
"post" => ["url" => "https://webhook.site/fd8b0712-d70a-4280-8d6f-9f14407b3bbd"],
```

`post.url` is Tap's **server-to-server notification destination**. So for every real Benefit payer,
Tap was asked to POST the charge — amount, currency, the payer's name and email, and the charge id
this platform's return paths treat as **proof of payment** — to a page readable by anyone holding
that URL, which is committed in this repository *and in its git history* and therefore is not a
secret.

Two of the five original copies were first-party and were removed under **P4-F046**
(`PaymentService::processBenefit()` ~:1846 and `BenefitPaymentController` :130, both now `''`); a
sixth, the dead `webhook_backup()` helper, was deleted. These three are the remainder.
`GatewayCallbackDestinationTest::VENDORED_COPIES_NOT_ASSERTED` recorded them as a deliberate
non-assertion — *"a ratchet over them could only ever be a standing red light"* — and that is the
state this change ends.

**Note on the exposure on two of the three rails:** on Campaigns the payer is the merchant who
installed the module. On Donation and LotteryPrizes the payer is a **third party** — a donor or a
ticket buyer with no relationship to this platform at all. Their name, email and amount were in the
payload.

---

## 2. Why the fix is host-side, and where the seam is

`CLAUDE.md` forbids editing `packages/workdo/`, and `.github/scripts/guard-no-workdo-changes.sh`
refuses a pull request that does, for the reason that applies exactly here: an in-place edit is
silently reverted by the next upstream module update and leaves no patch record. That is the same
reason `DisabledModuleRouteGuard` and `ModuleAdministrativeRouteGuard` are host middleware.

**The seam, verified rather than assumed.** All three controllers take their payment service by
**constructor injection**, so Laravel resolves it through the container:

| controller | constructor | call site |
|---|---|---|
| `Workdo\Campaigns\...\CampaignsController` | `__construct(CampaignsPaymentService $s)` | `:105` `->process($request)` |
| `Workdo\Donation\...\CustomerDonationController` | `__construct(DonationPaymentService $s)` | `:90` `->process($request->all(), $paymentMethod, $slug, $donation_amount)` |
| `Workdo\LotteryPrizes\...\LotteryPrizesController` | `__construct(LotteryPaymentService $s)` | `:541` `->process($request->all(), $paymentMethod, $slug, $ticket_price)` |

Measured consequences for the design:

* **`process()` is public in all three; `processBenefit()` is private in all three.** A private
  method cannot be overridden — a subclass's same-named method is a *different* method and the
  parent's own `process()` still reaches the parent's. So the override is at `process()`.
* **The three `process()` signatures differ.** Campaigns takes one `Request` and switches on
  `$request->payment_type`; the other two take four arguments and switch on `$paymentMethod` (an
  array `$request->all()`, not a `Request`). Three subclasses, not one.
* **No module provider binds its own service** (`grep` over the three providers: zero `bind(`/
  `singleton(`), so the services are resolved by zero-config autowiring and a host `bind()` wins.
* **The vendored classes are not `final`**, so they can be subclassed.

All four facts are asserted by the premise tests in
`tests/Feature/Payments/ModuleBenefitCallbackDestinationTest`, with failure messages that say *"the
host override does NOT cover this module"* — so if a module update moves to `new`, or makes
`processBenefit()` protected, the build says so instead of the fix quietly covering nothing.

---

## 3. What was added

| file | what it is |
|---|---|
| `app/Support/Modules/ModuleBenefitCallback.php` | the decision and the two declared maps: `DESTINATION = ''`, `OVERRIDES` (vendored service → host override), `VENDORED_SITES` (the census's declared set). Nothing is configurable. |
| `app/Services/Modules/Concerns/SendsBenefitCharge.php` | the POST, identical to the three vendored transports, pulled into one overridable method so a test can capture the bytes without contacting Tap. |
| `app/Services/Modules/CampaignsBenefitCallbackOverride.php` | subclass; overrides `process()`, handles `Benefit`, delegates the rest. |
| `app/Services/Modules/DonationBenefitCallbackOverride.php` | same, four-argument signature. |
| `app/Services/Modules/LotteryBenefitCallbackOverride.php` | same. |
| `app/Providers/AppServiceProvider.php` | **one** registration: a `foreach` over `ModuleBenefitCallback::OVERRIDES` calling `$this->app->bind($vendored, $hostOverride)`, placed immediately after the four existing vendored-class bindings. |
| `tests/Feature/Payments/ModuleBenefitCallbackDestinationTest.php` | 25 tests / 180 assertions: premise, the defect, the fix on the live object, the drift ratchet, the wire bytes, and a positive control. |
| `tests/Unit/Security/ModuleBenefitCallbackCensusTest.php` | 8 tests / 78 assertions: the ratchet against a fourth copy, plus the payment-flow continuity ratchet §9 raised. |

**No file under `packages/workdo/` was created, modified or deleted.**

`''` not `null`: `PaymentService.php:1817-1845` has the measurement. The card flows reach Tap
through `Payment::charge()`, which builds its JSON by string concatenation and so puts
`"post":{"url":""}` on the wire; these sites `json_encode()` an array, so `null` would arrive as
`"url":null` — a shape no Tap flow in this repository has ever sent and which nobody here can
verify, there being no Tap credential in this container. `''` adds no unknown to a payment rail.

**`redirect.url` is untouched on all three.** That is the *payer's* return, not a server callback;
emptying it would strand every Benefit payer on the gateway. A test asserts it is still non-empty.

---

## 4. Evidence — failing first, then passing, then reverted

### 4.1 Failing first (no implementation present)

```
Tests:    16 failed, 9 passed (64 assertions)
```

The 9 that passed are the ones that must pass *before* the fix, and they are what makes the rest
meaningful:

* `premise_each_controller_takes_its_payment_service_by_constructor_injection` ×3 — the seam is real.
* `premise_process_is_overridable_and_process_benefit_is_private` ×3 — the shape of the fix is forced.
* **`the_vendored_benefit_payload_still_names_the_public_bin` ×3 — the defect, measured on the
  payload literal itself**, scoped to `processBenefit()`'s own braces and comment-stripped.

The 16 failures were the 12 fix assertions (`Class "App\Services\Modules\...Override" does not
exist`) plus, initially, the load premise — see §5.

### 4.2 Passing after the fix

```
tests/Feature/Payments/ModuleBenefitCallbackDestinationTest   25 passed (180 assertions)
tests/Unit/Security/ModuleBenefitCallbackCensusTest             8 passed  (78 assertions)
```

### 4.3 Revert probe A — remove the container binding

Commented out the `foreach` in `AppServiceProvider::register()`:

```
Tests:    6 failed, 19 passed (153 assertions)
  ⨯ the container hands the controller the host override            ×3
  ⨯ the override benefit payload carries no third party destination ×3
```

**And this is the honest reading of which test proves what.** Three tests stayed green with the
binding removed, correctly:

* `process_sends_the_benefit_charge_through_the_host_payload` builds the override directly, so it
  proves the **routing** inside the override, not the binding.
* `the_override_payload_is_the_vendored_payload_except_its_callback_destination` is a source
  comparison and is binding-independent by design.

The binding is proved by the six that failed, and `ModuleBenefitCallbackCensusTest::
test_the_overrides_are_bound_in_the_application_service_provider` holds it a second time at source
level, including that `AppServiceProvider` is still listed in `bootstrap/providers.php`.

### 4.4 Revert probe B — put the bin back in the payload

Restored the literal bin in `DonationBenefitCallbackOverride::benefitChargePayload()`:

```
Tests:    3 failed, 22 passed (174 assertions)
  ⨯ the override benefit payload carries no third party destination  (Donation)
  ⨯ the override payload is the vendored payload except its callback destination  (Donation)
  ⨯ process sends the benefit charge through the host payload  (Donation)
```

Exactly three failures, all on the **Donation** data set and none on the other two — so the
assertions are per-module and scoped, not a global string search that would have failed everywhere.

### 4.5 The ratchet, mutated in three directions

| mutation | result |
|---|---|
| drop `LotteryPrizes` from `VENDORED_SITES` (declared 2, found 3) | **2 failed** — `exactly_the_declared_vendored_files…`, `every_vendored_bin_site_has_a_host_override` |
| declare a fabricated fourth site (declared 4, found 3) — the shape a **new upstream copy** produces | **2 failed**, same two |
| inject a live bin into `CampaignsBenefitCallbackOverride` (a fourth *first-party* copy) | **2 failed** — `no_first_party_file_names_a_callback_bin`, `each_host_override_actually_overrides…` |
| comment out the `foreach` binding (census only, probe A was the Feature suite) | **1 failed** — `the_overrides_are_bound_in_the_application_service_provider`. Both suites catch the binding independently. |
| delete `session(['lottery_request_data' => …])` from the Lottery override | **1 failed** — and **all 25 Feature callback tests stayed green**. See §9. |

All restored; both suites green again.

**Known limitation of the census, stated rather than left implicit.** The vendored sweep filters on
raw bytes for the literal `webhook.site` before stripping comments. That can only *over*-include, so
it cannot miss a plainly-written copy — but a copy assembled by concatenation or escaping
(`'webhook' . '.site'`) would evade it, as it would evade any string census. The ratchet is a guard
against drift, not against an adversary inside the vendored tree.

### 4.6 Comment-stripping is load-bearing, measured

A raw `grep -rc webhook.site app/ --include=*.php` finds **11 hits in 7 files** — the four files
this change adds plus `PaymentService` (4), `BenefitPaymentController` (1). The comment-stripped
census reports **zero offenders**. Without `Tests\Support\StripsSourceComments` the first-party
ratchet would fail on the prose explaining the removal, which is the trap `CLAUDE.md` records six
measured instances of.

### 4.7 No network request was made

Nothing in either suite contacts `api.tap.company` or `webhook.site`. The only transport in the test
path is a PHPUnit partial mock of `sendBenefitCharge()` which records the JSON body and returns a
synthetic `GuzzleHttp\Psr7\Response`. Every other assertion is on an array built in-process or on
source text. No credential exists in this container and none was sought.

---

## 5. Two traps hit while doing this, both already in `CLAUDE.md`

**(a) The premise assertion broke the premise.** `test_premise_no_payment_module_is_loaded_until_it_
is_enabled` asserted `class_exists()` is false *before* enabling. That call wrote a **negative**
entry into Composer's `ClassLoader::$missingClasses`, and `Workdo\` is not a compile-time PSR-4
prefix — `loadAddons()` registers it at runtime — so the later `addPsr4()` could not undo it. Every
*other* test in the file loaded the modules fine; only the one proving they do not load beforehand
failed, with *"Campaigns did not load through loadAddons()"*. Fixed with
`ComposerClassLoaderGuard::forgetWorkdoMisses()` between the two halves. `Tests\TestCase::setUp()`
runs that per test, which clears what the *previous* test left and cannot clear what a test does to
itself.

**(b) A named test double cannot extend a vendored class.** `class RecordingX extends
CampaignsBenefitCallbackOverride` declared in the test file is resolved when PHPUnit **loads** the
file — before any test runs, so before any `add_on_managers` row exists. The vendored grandparent
does not exist yet and the whole suite dies with `Class "…CampaignsBenefitCallbackOverride" not
found` rather than reporting a failure. Measured; replaced with a runtime `getMockBuilder()
->onlyMethods(['sendBenefitCharge'])` partial mock, which is generated *after*
`enableWorkdoModules()`.

**(c) A third, in the census:** stripping comments from all 2,085 vendored PHP files died on
`packages/workdo/BulkDelete/src/resources/views/pages/button.blade.php`, which is **entirely** a
Blade comment — the shared stripper refuses a file it has emptied, correctly. The census now filters
on raw bytes first and strips only the handful of candidates, which is also two orders of magnitude
faster.

---

## 6. Recorded and deliberately NOT fixed

**The unwrap is outside the `try` in all three overrides, as in the vendored originals.**

```php
} catch (\Throwable $th) { return redirect()->back()->with('error', 'Currency Not Supported...'); }
$data = $response->getBody();   // $response undefined if the catch ran
$res = json_decode($data);
return $res->transaction->url;  // ErrorException when Tap 200s with an error body
```

P4-F046 moved this inside the `try` for the *first-party* twin, because Tap does send a 200 carrying
an error body and reading `->transaction` on the resulting `null` is a PHP 8 warning that
`HandleExceptions` converts to an `ErrorException` — a white page. **That is a real second defect on
all three of these rails and it is not fixed here.** This change is authorised to remove the bin;
altering what a merchant or a donor sees when a payment fails is a different decision with a
different blast radius, and bundling it would have cost the one property that makes this change
auditable — that the host payload is the vendored payload with exactly one value changed.

Also unfixed, and for the same reason: the three payloads send `last_name` as the payer's **first**
name (the copy-paste P4-F046(d) corrected first-party), and `phone` is the hardcoded
`965 / 51234567`. Both are reproduced verbatim so the drift ratchet can be exact.

Two dead locals (`$benefit_private_key` in all three, `$currency` in Campaigns) are assigned and
never read in the vendored methods and are **not** reproduced. They cannot affect behaviour.

---

## 7. What this closes, and what it does not

**Closed.** The *egress* half of `GAP-138` / `P04-P5-04`: no live `webhook.site` callback
destination survives anywhere in this application. First-party code names none; the three vendored
literals are **unreachable**, because `process()` for `Benefit` never reaches the vendored private
method, and a fourth copy — vendored or first-party — fails the build.

**NOT closed, and not started: `P5-FIN-6`, Benefit capture verification.** The same register row
asks for it and it is a separate defect:
`PaymentController::getProductStatus` gates its only Tap charge lookup on `payment_type == 'Tap'`
and has **no `benefit` branch at all**, so a storefront Benefit order is written with no capture
verification — settlement rests entirely on the payer's browser reaching `redirect.url`. That
absence is currently *asserted in the direction that is true today* by
`GatewayCallbackDestinationTest::test_recorded_the_storefront_return_still_has_no_benefit_
verification_branch`, which is a deliberate tripwire: **adding a `benefit` branch fails that test**,
and that test is not this work's to edit. Whoever takes `P5-FIN-6` must rewrite it to *require* the
branch.

**NOT covered, by name:**

* **Choosing a callback destination** — the open escalation `P1-SEC-55`. Nothing here is
  configurable and `ModuleBenefitCallbackCensusTest` fails if `DESTINATION` is ever not `''`.
* **The vendored source** still contains the literal and always will until an upstream update
  removes it. The ratchet watches for that and says to retire the override rather than keep it.
* **The three modules' other 40-odd gateways** are untouched — they are delegated to
  `parent::process()` and a positive control asserts the delegation still reaches the vendored
  switch (its `default` arm, the one branch needing no credential and no network).
* **Rotating or revoking the bin itself.** The URL is in git history; this change stops sending to
  it and does not and cannot un-publish what was already sent. That belongs with incident handling,
  not here.

---

## 8. Register / traceability wording requested

Proposed replacement for the `GAP-138` row's status and remediation (the row itself was not edited
by this work — `MASTER-PLAN-GAP-REGISTER.md` and `MASTER-PLAN-REQUIREMENT-TRACEABILITY.md` are
outside this change's file ownership):

> **PARTIAL** — egress closed 2026-11-26, capture verification still open. The public-bin callback
> destination is gone from all five sites: the two first-party ones under P4-F046, and the three
> vendored ones (Campaigns, Donation, LotteryPrizes) neutralised host-side by
> `App\Services\Modules\*BenefitCallbackOverride` bound over the vendored services in
> `AppServiceProvider::register()` — `packages/workdo/` unedited. `post.url` is `''`, the shape the
> card flows already put on the wire; **choosing a destination remains the open P1-SEC-55 and was
> not taken**. Ratcheted in both trees by `ModuleBenefitCallbackCensusTest` (a fourth copy fails the
> build) and proved on the live container by `ModuleBenefitCallbackDestinationTest` (25 tests, the
> modules loaded through the production loader). **Benefit capture verification — task `P5-FIN-6` —
> is NOT started:** `PaymentController::getProductStatus` still has no `benefit` branch, and
> `GatewayCallbackDestinationTest::test_recorded_the_storefront_return_still_has_no_benefit_verification_branch`
> is the tripwire that must be rewritten when it is. Evidence:
> `docs/development/phase-4/PHASE-04-GAP-138-MODULE-BENEFIT-CALLBACK.md`.

---

## 9. Independent review

**The mandated independent review was NOT obtained, and this is the honest statement of that.**
`.claude/agents/tenant-authz-reviewer` is a subagent, and this session has no subagent-spawning tool
(`ToolSearch` for `Task`/`Agent`/`ListAgents` returns nothing; this session is itself a subagent).
**A reviewer that is the author is not an independent review**, and the reviewer's own brief says the
lead verifies by execution. So this section is a **self-review against the reviewer's eight checklist
shapes**, and the independent pass is still owed on this change.

Applied to `app/Services/Modules/*`, `app/Support/Modules/ModuleBenefitCallback.php` and the
`AppServiceProvider` registration. Two findings; one produced a new ratchet.

### FINDING 1 — the flow key. Shape 6 (payment ownership). Certain. **FIXED by a new ratchet.**

Each vendored `processBenefit()` ends by writing the request into the session under the module's own
key, and two things downstream read it: the module's completion writer tests it with `== null`, and
`DisabledModuleRouteGuard` reads the same key through `ModulePaymentFlows::vendoredFlowKey()` to tell
a gateway *return* from a *new* transaction through a switched-off application.

An override that reproduced the payload perfectly and dropped that one line would send a payer to
Tap, take their money, and lose the donation, ticket or campaign on the way back — **with nothing in
the original suite failing.** Measured: deleting
`session(['lottery_request_data' => $request])` left **all 25 Feature callback tests green**.

All three overrides do write the correct key (`campaigns_request_data`, `donation_request_data`,
`lottery_request_data` — checked against `ModulePaymentFlows::VENDORED_FLOW_KEY`, not a literal), so
there was no live defect. It was unguarded, which on a payment path is the same risk one refactor
later. `ModuleBenefitCallbackCensusTest::test_each_host_override_still_writes_the_modules_payment_
flow_key` now asserts it against `ModulePaymentFlows`' own table, and fails on exactly that deletion.

### FINDING 2 — "no callback is lost" was inferred, not measured, for *these* rails. Probable → now certain.

§1 and the P4-F046 comment rest on a measurement taken against **first-party** routes: *"no route in
this repository receives a Tap server-to-server callback."* Three vendored modules are not covered by
a first-party route census, so the claim was carried further than its evidence reached — the kind of
overclaim the reviewer's brief says has been corrected twice here by measurement.

Measured now, two ways, and the conclusion holds:

* `grep -rn "tap\|Tap" packages/workdo/{Campaigns,Donation,LotteryPrizes}/src/routes/*.php` →
  **no matches**. None of the three modules declares any Tap receiver.
* More decisively, it does not depend on a route census at all: `post.url` *was* the bin, so Tap
  posted to the bin and to nothing else. Whatever routes exist, none was receiving it.

### Shapes checked and clean

* **1, guard-class trap** — no `if (! auth('<guard>')->user())` fallback anywhere in the change; no
  identifier is read from request input to select a principal. Campaigns' `\Auth::user()` (default
  `web` guard) is reproduced verbatim from the vendored method and is reached only after the vendored
  controller has already dereferenced `\Auth::user()->creatorId()` at `CampaignsController:89`, so it
  adds no new exposure. Campaigns is a merchant rail, where `web` is the right guard.
* **2, tenant predicate from caller input** — Donation and Lottery resolve the store with
  `getStore($slug)` from the **route** parameter, which is this codebase's documented storefront
  resolution, not a caller-supplied `store_id`. Verbatim vendored. Donation's amount *is* caller-
  supplied (a donor chooses it) and Lottery's is server-side from `lottery_settings.ticket_price`;
  both are passed through unchanged, so the change neither introduces nor removes that property.
* **3, global-scope reliance** — the change adds **no query of any kind**. Measured:
  `grep -n '->where(\|::find('` over all five new app files returns nothing.
* **4, permission checks** — the change adds none and removes none, and a container binding cannot
  alter route middleware: `PlanModuleCheck:Campaigns` and the storefront gating are untouched. No
  `@can`, no `isAbleTo`, no `users.type` write.
* **5, credentials** — the Benefit secret key travels in an `Authorization: Bearer` header exactly as
  the vendored transport sent it. **No logging was added**, deliberately: the vendored
  `catch (\Throwable $th)` discards the throwable, so nothing can carry the key into a log. (The
  first-party P4-F046 fix *did* add logging to its twin; copying that here would have put a merchant
  gateway key one `$th->getMessage()` away from `storage/logs`.) No password or email column is
  written; no literal credential appears.
* **6, order/payment ownership** — amount, currency, payer identity, `redirect.url` and the session
  flow key are all byte-identical to the vendored flow; the drift ratchet proves it key by key. The
  only changed value is what Tap is told to POST to a *server*.
* **7, API/session** — no route, no middleware, no response shape changes. **No 401 is introduced on
  any path**, so the shipped Flutter app's force-logout-on-401 cannot be triggered by this. `bind()`
  (not `singleton()`) keeps resolution transient, matching the autowiring it replaces, so no shared
  state is created.
* **8, tests that cannot fail** — answered per test in §4.3–§4.5. Three groups pass before *and*
  after the fix **by design** and are labelled as such rather than counted as evidence: the seven
  premises, the three `the_vendored_benefit_payload_still_names_the_public_bin` recordings, and the
  three `a_non_benefit_method_still_reaches_the_vendored_switch` positive controls. Every other test
  was shown to fail under at least one revert.
