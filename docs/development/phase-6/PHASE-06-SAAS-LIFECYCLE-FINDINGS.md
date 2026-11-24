# Phase 06 — the SaaS lifecycle: does anything destroy store data?

Measured 2026-11-24. Every statement below was executed or read from the code at the cited line, and
the distinction this page keeps is the one the owner's instruction of 2026-11-24 asks for:
**a missing business-policy decision is not the same thing as missing approved scope.** Pricing and
grace-period length are the owner's. "Do not delete the merchant's data" is a binding requirement
with no decision attached, and it is the question this page answers.

---

## 1. The priority question, and its answer

> Does any shipped code DELETE or hard-disable store data on expiry, on downgrade, or on plan change?

**On expiry: no — because almost nothing happens on expiry at all.** That is the measured reason,
and it matters, because it is a very different finding from "expiry is implemented and safe".

| where | what it does with `plan_expire_date` |
|---|---|
| `User::assignPlan()` (`:303`, `:307`, `:312`) | **writes** it, from `Carbon::now()` plus the plan's duration |
| `Auth\RegisteredUserController`, `BankTransferController`, `PaytmPaymentController` | **write** it |
| `Api\DashboardController:8165`, `:8176` | **display** it to the Flutter app |
| `plans/index.blade.php:251`, `store/index.blade.php:170` | **display** it; `:251` compares it to today purely to label a card "Expired" |
| `SetLocate.php:62-90` | the **only** place that acts on it — see §2 |

Nothing else reads it. There is **no scheduled command** for plans (`routes/console.php` schedules
exactly four: `zid:process-events`, `analytics:send-scheduled-reports`,
`analytics:check-metric-thresholds`, `marketing:detect-recovery-candidates`), and **no middleware**
refuses an expired merchant — `PlanModuleCheck` contains zero references to expiry, checked.

## 2. The one enforcement point, and it is in the locale middleware

`app/Http/Middleware/SetLocate.php` runs plan lifecycle work on every request for a type-`admin`
user:

- **`:62-80`** — if `plan_expire_date` has passed **and** `plan_id` is empty, assign the first
  `Unlimited` plan. Both commented-out `return redirect()->route('plan.index')` lines are still
  there, so the intended "your plan expired" redirect was disabled at some point.
- **`:82-90`** — if `trial_expire_date` has passed, `$user->assignPlan(1)` — a **hardcoded plan id**.
  `assignPlan()` opens with `Plan::find($planID)` inside `if($plan)`, so on a database without a
  plan id 1 this is a **silent no-op** and the trial simply never ends.

What `assignPlan()` then does to the subject:

- recomputes `plan_expire_date` from now;
- **removes module availability** not granted by the new plan (`user_active_modules` rows);
- **deactivates** staff users beyond `max_users` (`is_active = 0`), it does not delete them.

**No store content, product, order, category, setting or wallet row is touched.** So the approved
data-preservation requirements are **not violated**: `SAAS-003` (no store-data deletion on expiry),
`SAAS-005` (reactivation without data loss), `SAAS-006` (no deletion of content over a downgraded
plan's limits) and `CTRL-004` (no deletion of a feature's data when availability is switched off)
all hold — `user_active_modules` is availability, not data.

## 3. What IS missing, and it is scope rather than policy

`SAAS-004` — *"تقييد البيع الجديد بعد انتهاء فترة السماح، مع الحفاظ على الطلبات القائمة والتتبع
والدعم"* (restrict new selling after the grace period, while preserving existing orders, tracking
and support) — **is not implemented.** Nothing restricts anything on expiry; a merchant whose plan
lapsed keeps selling.

The split to hold on to:

- **the mechanism is policy-neutral** — a gate that refuses new selling while leaving existing
  orders, tracking and support reachable needs no commercial decision to build;
- **the parameters are the owner's** — how long the grace period is, and what counts as "new
  selling" (new orders only? new products? storefront visible?).

Also missing: there is **no grace-period concept in the schema at all**. `plan_expire_date` is a
single date, so "expired" and "out of grace" cannot be distinguished today. That is a prerequisite
for `SAAS-004` and is stated here rather than built, because inventing a grace rule would be
settling the owner's decision.

## 4. FIXED — `assignPlan()` deleted one user's entitlement while creating another's

Found while measuring the above. `SAAS-002` covers upgrades and downgrades, and the method that
performs them targeted two different users inside one loop body:

```
User.php:345   userActiveModule::create(['user_id' => $this->id,  ...])     the subject
User.php:351   userActiveModule::where('user_id', $user->id)->delete()      \Auth::user()
```

They coincide whenever a merchant buys their own plan, which is every self-service gateway path —
which is why it was never seen. They **diverge on the operator-driven path**:
`BankTransferController::orderapprove()` loads the buyer with
`$user = User::find($order->user_id)` (`:154`) and calls `$user->assignPlan(...)` (`:167`) while the
operator is signed in. So approving a merchant's bank transfer onto a smaller plan:

- left the **merchant** holding modules the new plan does not grant — the downgrade did not
  downgrade, and unpaid entitlement stayed live;
- deleted those module rows from the **operator's own** account.

Fixed to `$this->id`, matching the `create()` on the line above. Pinned in both directions by
`tests/Feature/Saas/PlanAssignmentTargetsTheRightUserTest.php`, with the self-service path asserted
unchanged. 4 of 5 mutations caught; the fifth is a deliberate control that must survive.

This is **not** a data-loss defect of the `SAAS-003` kind — `user_active_modules` is availability.
It is an entitlement-targeting defect, and it needed no owner decision.

### 4.1 What the independent review added, including one thing that makes the fix bigger

The entitlement fix was reviewed read-only before being pushed. It survived on every live path —
`$this` is the right subject for all ~30 callers, and the structural reason is decisive: every other
write in the method was already `$this`-keyed (`plan_id`, `plan_expire_date`, `active_module`,
`save()`, and both cascades), so the delete was the *only* `$user`-keyed statement in it. Four things
came back that the commit message did not have:

**(a) It also removes a LIVE exception on the unauthenticated callback path.** The old statement
dereferenced `$user->id` directly, not inside `empty()`, so with `\Auth::user()` null — which is the
case at `PaymentCallbackController.php:200`, and in any CLI or queue invocation — it raised
"Attempt to read property id on null" whenever `$modulesToRemove` was non-empty, i.e. on exactly the
downgrades that reach the loop. That fired **after**
`PlanPurchaseIntent::consumeForProviderCallback()` had consumed the intent: payment captured, intent
spent, entitlement never saved — the "needs a human" state that controller documents at `:181-193`.
So the benefit is larger than "a downgrade did not downgrade", and the earlier record understated it.

**(b) A real limit on the fix.** The whole module block sits inside `if(!empty($modules))`
(`User.php:319`), so a downgrade **to a plan whose `modules` is empty** skips it entirely — true of
the seeded free plan that `PlanController::refund` and `SetLocate.php:86` assign. The fix bites on
plan-to-plan changes where the new plan grants a non-empty module list.

**(c) Restoring the operator's rows widens nothing.** `helper.php:1932-1933` gives a user whose
`type` is `super admin` every active module outright, so the operator's access never consults
`user_active_modules` at all. The narrowing half is the real half: for a merchant those rows *are*
the entitlement (`helper.php:1974`, `:1960-1971`, read at `:1904`, consumed by
`PlanModuleCheck:50-51`).

**(d) There is no second control behind the `user_id` predicate.** `userActiveModule` is **not** in
`config/tenancy.php`'s `scoped_models`, and `user_active_modules` has no `store_id` column at all, so
`StoreScope` adds no predicate for any principal and both the create and the delete see the whole
table. Isolation is *entirely* the `user_id` predicate — which is the strongest argument for having
corrected it.

## 4a. FIXED — two cascades read `created_by` as a tenant

Found by that same review, both **pre-existing** and neither introduced by the entitlement fix.

**The staff cascade could deactivate every merchant on the platform.** `User.php:376` selected the
subject's staff as `User::where('created_by', '=', $this->id)->where('type', '!=', 'super admin')`
and deactivated everyone past `$plan->max_users`. Correct for a merchant; catastrophic for the
operator, because every merchant is created with `created_by = 1`
(`Auth\RegisteredUserController:133` and `:286`, `StoreController:280`) and user 1 is the operator.
With the operator as subject that query selects every merchant, in `->get()` order with no
`orderBy`. Reachable through `StoreController::activePlan` and
`PlanRequestController::acceptRequest`, which both take the subject from input and are
operator-gated — so self-inflicted rather than an escalation, which is why this is a safety guard
and not a security report. **It is the same trap CLAUDE.md already records costing an account
takeover: "`created_by = 1` is NOT a tenant, and reading it as one was an account-takeover."**

The store cascade at `:405` is the same wrong question with a smaller blast radius: stores are
created with `created_by => $user->id` (the merchant), so an operator subject selects only stores the
operator personally created. Guarded anyway — leaving one of a pair guarded is how a guard rots, and
a mutation reverting only the store half survived the staff test until it got its own.

Both now skip for a `super admin` subject. **Nothing is lost:** per 4.1(c) an operator is not
plan-limited in the first place. The guard is on the cascades rather than on the method's audience
deliberately — refusing `assignPlan()` outright for an operator would change whether an operator may
buy a plan at all, and that is a product question.

**A fixture lesson worth keeping.** The control test ("a merchant's own staff are still limited")
failed before any fix, which should have been impossible. Measured: `makeMerchant()` defaults to
`created_by => 1`, and in a fresh `RefreshDatabase` the first user takes id 1 — so a lone merchant
has `created_by === $this->id` and the cascade selected **the merchant itself** alongside its staff
(`1(admin), 2(staff), 3(staff), 4(staff)`), eating the single seat. A fixture artefact, not the
product, since in production user 1 is the operator. Creating the operator first restores
production's shape. The underlying edge is real but is not this fix's business: a type-`admin` user
whose id is 1 consumes a seat in its own cascade.

## 4b. FIXED — `$oldplan` was nullable and dereferenced

`Plan::where('id', $this->plan_id)->first()` (`User.php:288`) can be null and `$oldplan->custom_plan`
(`:324`) was reached whenever the `&&`'s first operand was truthy. Laravel promotes the warning to an
`ErrorException`, so this threw **after** `plan_id` and `plan_expire_date` were mutated in memory and
**before** `$this->save()` — on a gateway path, money captured and entitlement unsaved. Now
`$oldplan?->custom_plan`. Null is falsy, so every case that did not already throw is byte-identical:
it takes the replace branch, which is what already happened.

**AND A TRAP THE REVIEW NAMED, LEFT ALONE ON PURPOSE.** That same condition's first operand reads the
**acting** user's `active_module`, not the subject's. Changing it to `$this` looks like the identical
correction as the delete and is the opposite: every merchant who has held a plan has a non-empty
`active_module`, so `$this` would make the operand true on exactly the operator-driven downgrade path
just narrowed, and the merge branch would then **preserve** modules the new plan does not grant
whenever the old plan was a custom plan. That re-opens the over-entitlement through the other
operand, and whether a custom plan keeps its extras through an operator-initiated change is the
owner's call. **Do not carry it as a follow-on.**

## 5. Recorded, not fixed

Each is at a named line, and each is left because fixing it would change behaviour I cannot verify
the intent of, or would widen this slice past reviewable.

1. **`assignPlan()`'s custom-plan merge still reads the acting user.** See 4b — this one is
   *deliberately* not fixed, because the obvious fix widens entitlement and needs an owner decision.
   Recorded so nobody "corrects" it.
2. **`assignPlan()` takes one parameter and two are passed.** The signature is
   `assignPlan($planID)` (`:284`); `BankTransferController:167` passes
   `($order->plan_id, $plan->duration)`. PHP discards the extra argument silently. Harmless today
   because the method derives the duration from the plan itself, but it reads as though the caller
   controls the period and it does not.
3. **A plan lifecycle transition lives in a locale middleware** and writes to the database on every
   request. Moving it is a design change with a blast radius across every authenticated page, so it
   is reported rather than done inside a measurement task.
4. **Two disabled redirects** in `SetLocate.php` (`:79`, `:86-87`) mean the product has no
   "your plan expired" path even though one was written. Whether to re-enable it is bound up with
   `SAAS-004`'s grace rule, so it belongs with that decision.

## 6. What this page does not claim

No owner acceptance. No statement about the operator dashboard or `ADM-001`/`ADM-002`, which are a
separate slice. The `SAAS-001`/`002`/`007` pricing, invoicing, usage-limit and MRR/ARR content
remains an owner decision and nothing here anticipates it. And "nothing destroys store data on
expiry" is a statement about the **first-party** code in `app/`: the 70 vendored modules under
`packages/workdo/` were not swept for this question, and must not be edited in any case.
