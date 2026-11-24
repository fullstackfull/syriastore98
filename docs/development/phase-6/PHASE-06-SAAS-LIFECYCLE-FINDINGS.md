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

## 5. Recorded, not fixed

Each is at a named line, and each is left because fixing it would change behaviour I cannot verify
the intent of, or would widen this slice past reviewable.

1. **`assignPlan()` still reads the acting user twice more.** `User.php:322`'s
   `if(!empty($user->active_module) && $oldplan->custom_plan == 1)` decides whether to merge the old
   module set into the new one for a custom plan, from the **actor's** `active_module`. On the
   operator path that is the operator's. Changing it alters custom-plan behaviour, so it is reported.
2. **`$oldplan` can be null and is dereferenced.** `Plan::where('id',$this->plan_id)->first()` at
   `:287`, then `$oldplan->custom_plan` at `:322`, guarded only by `&&` short-circuit on the first
   operand — so an actor who has `active_module` set, acting on a subject whose `plan_id` resolves to
   nothing, hits a null dereference.
3. **`assignPlan()` takes one parameter and two are passed.** The signature is
   `assignPlan($planID)` (`:284`); `BankTransferController:167` passes
   `($order->plan_id, $plan->duration)`. PHP discards the extra argument silently. Harmless today
   because the method derives the duration from the plan itself, but it reads as though the caller
   controls the period and it does not.
4. **A plan lifecycle transition lives in a locale middleware** and writes to the database on every
   request. Moving it is a design change with a blast radius across every authenticated page, so it
   is reported rather than done inside a measurement task.
5. **Two disabled redirects** in `SetLocate.php` (`:79`, `:86-87`) mean the product has no
   "your plan expired" path even though one was written. Whether to re-enable it is bound up with
   `SAAS-004`'s grace rule, so it belongs with that decision.

## 6. What this page does not claim

No owner acceptance. No statement about the operator dashboard or `ADM-001`/`ADM-002`, which are a
separate slice. The `SAAS-001`/`002`/`007` pricing, invoicing, usage-limit and MRR/ARR content
remains an owner decision and nothing here anticipates it. And "nothing destroys store data on
expiry" is a statement about the **first-party** code in `app/`: the 70 vendored modules under
`packages/workdo/` were not swept for this question, and must not be edited in any case.
