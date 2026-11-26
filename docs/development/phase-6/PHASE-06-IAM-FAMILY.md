# Phase 06 — the IAM family (`IAM-001`…`IAM-004`): measured, and what was built

**Written after the changes, because this repository is public.** Status per decision is in §2;
nothing here is authorised to merge or release, and `CLAUDE.md` §4.1 names the four states that
must never be collapsed into one label.

The approved texts, verbatim from `docs/development/master-plan/DECISION-ID-REGISTER.md:237-240`
(plan section **06-G — الهوية والصلاحيات المؤسسية**, base plan line 436 / with-amendments line 450):

| id | approved text | English gloss |
|---|---|---|
| `IAM-001` | صلاحيات دقيقة لموظفي Super Admin. | Fine-grained permissions for Super Admin **staff** |
| `IAM-002` | مصادقة قوية وإدارة الجلسات. | Strong authentication and session management |
| `IAM-003` | موافقات ثنائية للإجراءات الخطرة. | **Two-person** approval for dangerous actions |
| `IAM-004` | سجل تدقيق مركزي واستجابة للحوادث. | A **central** audit log, and incident response |

**Tracker coverage, measured by grep over both files on 2026-11-26:** `IAM-002` and `IAM-004`
appear in **neither** `MASTER-PLAN-REQUIREMENT-TRACEABILITY.md` nor
`MASTER-PLAN-GAP-REGISTER.md`. `IAM-001` and `IAM-003` appear in the gap register only
(`IAM-003` is `GAP-198`, *"Not located. No two-person approval for dangerous actions"*);
the register's own §"requirements the matrix never carried" lists `IAM-003` at `:539`.

---

## 1. The architectural fact the whole family sits on

Operator status in this codebase is **a string comparison on `users.type`**, and nothing grants a
subset of it. `CLAUDE.md` §1 records the two halves and both were re-measured here:

- `permissions_as_gates => false`, so `can()` and `@can` are always false;
- **`isAbleTo()` alone REFUSES the platform operator** — there is no super-admin bypass.

`App\Http\Middleware\PlatformOperatorOnly` is therefore the gate on every operator surface, and it
is binary. Its blast radius is **40 routes** as of this change (it was measured at 37 before the
three `platform/approvals` routes below; the partition table in that middleware's docblock and
`PlatformOperatorOnlyPrincipalTest::GROUP_SIZES` were updated in the same commit, which is what
that test's failure message asks for).

---

## 2. The four decisions, by bucket

| id | bucket | what exists now |
|---|---|---|
| `IAM-001` | **MISSING FUNCTIONALITY** (large; contains an owner decision) + **MISSING VERIFICATION** (done) | The gap is now an asserted property in both directions. The mechanism is **not** built. |
| `IAM-002` | **partly MISSING FUNCTIONALITY** (done, operator half) + **EXTERNALLY BLOCKED** (token half) | Operator sign-in, sign-out and failed sign-in now reach the central audit log. Token lifetime is untouched. |
| `IAM-003` | **MISSING FUNCTIONALITY** (built) + **OWNER DECISION** (the action list, the quorum, provisioning) | A complete two-person approval mechanism, wired to one action, **off by default**. |
| `IAM-004` | **partly MISSING FUNCTIONALITY** (done for the operator's sessions and for every approval transition) + **MISSING FUNCTIONALITY** (remaining: catalogue writes) | Every transition of an approval, and every operator session event, is an `audit_logs` row. |

---

## 3. `IAM-001` — fine-grained permissions for Super Admin staff

### What was measured

**Operator access is all-or-nothing, and that is now asserted from both sides**
(`tests/Feature/Security/OperatorStaffPermissionGranularityTest`):

- a `User` holding **every** `Permission` row in the database, who is not type `super admin`, is
  refused `403` on a representative route from every operator group;
- a `User` of type `super admin` holding **no role and no permission at all** is admitted to all of
  them.

There is no third state. An operator assistant who should read the platform revenue screen but must
not disable a plan **cannot be expressed today**.

### Why it cannot simply be built on Laratrust permissions

The codebase already states the blocker beside its own operator routes (`routes/web.php`, the
`platform/*` block) and it is the decisive fact: *"A merchant creates their own roles through the
product, so a permission is substantially self-assignable."* `RoleController::store()` is reachable
with the `Create Role` permission that the seeded global `admin` role carries for every
self-registered merchant. The `permissions` table is **shared with every tenant's own role editor**.
And `isAbleTo()` would refuse the operator anyway. So a permission name is wrong in both directions
at once.

### The one partial mechanism that exists

`config/superadminaccess.php` — **13 names** a merchant may not grant themselves, filtered
server-side on both role write paths by `RolePolicy::isGrantableBy()`, already covered by
`RoleEscalationTest`. It is a **grantability denylist**, not an operator capability set: the
operator does not hold those permissions, which a case now asserts.

**A drift was found and corrected here.** `PlatformAnalyticsController`'s docblock said the list
holds *"15 names"*; it holds **13**, and `RolePolicy` says "thirteen" twice and was right. The
sentence is corrected, the count is asserted against the config, and a comment census over `app/`
fails if any prose beside that config states a different number — the correction cannot silently
un-correct itself.

### What `IAM-001` would need (not built, and why)

A **capability namespace a tenant cannot reach at all** — not a `permissions` row. Concretely: a
first-party list of operator capabilities, a grant table keyed on `users.id`, and `PlatformOperatorOnly`
re-expressed as "operator AND holds this capability", with the owner (a full operator) holding every
capability implicitly so the control cannot lock the platform out.

**Not built in this slice for three reasons, each of them real:**

1. **It re-gates 40 routes.** That is the single largest blast radius in the operator surface, and
   `CLAUDE.md` records that reversing the operator-first ordering is a lockout rather than a
   tightening — `FinancePermission` has done it once already.
2. **There is no second operator account to grant anything to.** See §5.
3. **Which subsets exist is a policy question** (§5, question 1), and the plan does not state it.

---

## 4. `IAM-002`, `IAM-003`, `IAM-004` — what was built

### `IAM-003` — two-person approval (the mechanism)

| file | what it is |
|---|---|
| `database/migrations/2026_11_26_000100_create_operator_action_approvals_table.php` | new additive table; `up()` is a no-op when the table exists |
| `app/Models/OperatorActionApproval.php` | the row. **Deliberately not `Cachable`** — it is written and read back in the next request, which is why `AuditLog` and `Permission` are also exceptions |
| `config/operator_approvals.php` | the switch, the quorum, the **wired** action, and the **proposed and not wired** list with the measured reason for each |
| `app/Support/Iam/TwoPersonApproval.php` | the service: `gate()`, `approve()`, `reject()` |
| `app/Support/Iam/ApprovedActionExecutor.php`, `ExecutionOutcome.php`, `GateDecision.php`, `ApprovalResult.php` | the executor contract and the three-outcome value objects |
| `app/Support/Iam/Executors/DisablePlanExecutor.php` | the one wired effect |
| `app/Http/Middleware/RequiresTwoPersonApproval.php` | the door, appended to the `web` group |
| `app/Http/Controllers/OperatorApprovalController.php` + `resources/views/platform/approvals.blade.php` | the second operator's queue |

**The two properties that make such a mechanism wrong in practice are each closed and each has its
own case:**

1. **Self-approval** — `approve()` compares `$approver->id` against the stored `requested_by` as
   integers, and returns a named reason (`REASON_SELF_APPROVAL`). The test keys on the **reason**,
   not on "nothing happened": a stale row, a missing executor, a non-operator and a crash all
   produce "nothing happened".
2. **Effect at request time** — `gate()` never performs anything; the effect lives in an executor
   that only `approve()` calls, so there is no code path from the request to the effect.

Three further properties, each with a case and a mutant: **execution happens once** (the
`pending → approved` transition is one conditional `UPDATE` carrying the old state, so concurrency
is answered at the engine); **the executor re-asks its own safety question at execution time** (a
merchant who subscribes between the request and the approval is not silently overridden); and
**every transition reaches `audit_logs` with the actor of that step** — an approval attributed to
the requester could not evidence two-person control at all.

**`plan.disable` is the one wired action, chosen and not invented:** the repository already treats
it as dangerous (`PlatformOperatorOnly` on the route, plus the handler's own operator test added
after `GAP-307`), disabling a plan withdraws a product every merchant buys, and its effect is one
column on one row and so **can** be performed at approval time from a recorded payload. An action
whose effect needs the requester's own live session — impersonation — cannot be, and is in
`proposed_actions` saying exactly that rather than wired to a mechanism that does not fit it.

**`is_disable` is inverted: 1 means ENABLED.** Measured, and not first by this slice —
`PlatformSiteController.php:161` holds the measurement and its evidence. So `is_disable = 0` is the
dangerous direction, and an executor written from the column's name would have governed the
harmless one.

### `IAM-002` / `IAM-004` — the operator's sessions

`AuthenticatedSessionController` carried **zero** references to `AuditLog` — measured by reading the
whole file. So the trail recorded what the operator did and never that they had signed in, from
where, or that somebody had tried and failed.

`app/Support/Iam/OperatorSessionAudit.php` records `operator_signed_in`, `operator_signed_out` and
`operator_sign_in_failed`. Three design points, each measured:

- **An `Illuminate\Auth\Events\Login` listener was considered and rejected.**
  `app/Http/Middleware/CustomAuth.php:110-117` calls `auth()->login($tokenable)` on every
  Sanctum-authenticated request, and that middleware guards 189 route declarations — a listener
  would write **one audit row per API request**.
- **The failed attempt carries no actor.** Nobody is authenticated, so the targeted operator is the
  **subject** and `user_id` is null. A row naming the victim as the actor would read, to anybody
  auditing it later, as the operator having done something.
- **No caller-supplied text reaches the row, and it is not an existence oracle.** The posted address
  is used only to look a row up, and nothing is written unless it resolves to a real operator — so
  the table cannot be probed for which addresses exist, nor filled with an attacker's prose (the
  shape of P1-SEC-79/80). The write rate is bounded by `LoginRequest::ensureIsNotRateLimited()`
  (5 attempts per email-and-IP key).

**Nothing a visitor sees changed.** `LoginRequest::authenticate()`'s exception is caught, a row is
written, and the **same** exception is re-thrown, so the response, status, error bag and throttle
behaviour are byte-for-byte what they were. A case drives a wrong password and asserts the ordinary
refusal still arrives.

**No `Log::` line was added**, so `docs/security/P01-OBSERVABILITY-INVENTORY.md` and the other three
`file:line` inventories are untouched — checked: none of them names any file this slice edits.

---

## 5. OWNER DECISIONS — named, not answered

### 1. Which operator actions require two people, and the quorum

`config/operator_approvals.php` holds the proposal. Each entry is an action the repository
**already** guards as dangerous, with its citation and the reason it is not wired:

| action | why it is dangerous | why not wired |
|---|---|---|
| `plan.order.refund` | `PlanController::refund()` marks any plan order refunded and calls `assignPlan(1)` on a caller-supplied `user_id`, downgrading an **arbitrary** merchant | it moves money; `CLAUDE.md` requires an owner escalation for material payment changes |
| `plan.bank_transfer.approve` | `BankTransferController::orderapprove()` grants a paid plan on one person's reading of an uploaded receipt | same |
| `user.delete` | `UserController::destroy()`; `docs/security/P0-USER-DELETION-CASCADE.md` is the analysis | the door is shared with **merchants deleting their own staff**, which must not need an operator; governing it needs the narrower action "the operator deletes a type-`admin` row" |
| `store.delete` | destroys a tenant's storefront | same shared-door problem |
| `operator.impersonate` | `StoreController::LoginWithAdmin()`; `OperatorCatalogueWrite`'s docblock records that impersonation is the one delegation mechanism that exists and that it is not a sound one | **the mechanism here does not fit it** — see §4; it needs a second "grant" mode, deliberately not built on speculation |
| `module.disable` | withdraws a feature from every tenant | `CTRL-002`/`CTRL-004` own the semantics of disabling a feature and forbid destroying its data; wiring a gate first would fix the wrong half |

The quorum is **2** and only 2 is implemented — the table carries a single `approved_by` column, so
a higher quorum is a schema change. A case asserts the configured value, so a 3 cannot be set and
silently behave as 2.

### 2. Enforcement is OFF by default, and turning it on needs three things

`PermissionTableSeeder::run()` creates **exactly one** `super admin` row, and no path in the
application writes that type. **Two-person approval with one operator account is a lockout, not a
tightening** — the failure `CLAUDE.md` names by name. So enforcement needs:

1. at least two operator accounts provisioned (there is no self-service path — that is `IAM-001`);
2. the action list above, confirmed or changed;
3. the quorum, confirmed.

A premise test asserts the shipped default is off, so the switch cannot flip by accident on an
upgrade and start refusing operator actions nobody decided to govern.

### 3. Should every user's sign-in be recorded, or only the operator's?

Only the operator's is recorded, for the volume reason in §4. Recording every merchant sign-in is a
**retention and personal-data** question (an IP address per sign-in) and the answer decides a volume
this schema has no partitioning for. Named rather than assumed in either direction.

### 4. `IAM-002`'s token half is EXTERNALLY BLOCKED and was not touched

`docs/security/P01-TOKEN-EXPIRY-PROPOSAL.md` holds a staged plan whose §Recommendation is *"Do
stages 0 (done), 1 and 2. Hold 3 until stage 1 has numbers"* — an owner decision that is pending.
Sanctum tokens never expire, every one carries `['*']`, `CheckAbilities` is imported and never used,
and there is no refresh path. **Nothing in this slice changes any of that**, deliberately: the
operator's interactive session is a separable half and is what was addressed.

---

## 6. What `IAM-004` still does not cover

`App\Services\OperatorCatalogueWrite`'s docblock records the measurement, and it still holds:
**no catalogue write is audit-logged anywhere in this application.** `AuditLog::record` is called
for plan orders, users, password resets, refunds, store finance, theme versions, impersonation,
Lynomia account links — and now operator sessions and every approval transition. It is **not**
called for a product, a price, a stock level or a variant. So a price edited while impersonating is
still indistinguishable in the record from one the merchant made themselves.

That is a different family's door (the catalogue write paths) and is reported rather than closed
here. It is the remaining `IAM-004` gap and the reason impersonation is not the *"explicit and
specific delegation"* the owner's F206 decision asks for.

---

## 7. Limitations of what was built, stated rather than left to be discovered

1. **THE MECHANISM IS NOT REACHABLE IN THE SHIPPED DEFAULT.** `operator_approvals.enforced` is
   `false`, so on a stock install `gate()` returns `proceed` and the product behaves exactly as it
   did. The code is complete and tested in both modes, and a premise test pins the default — but
   "built and tested" is not "in force", and §5.2 is what it waits on.
2. **ONLY `plan.disable` IS WIRED.** Six further actions are proposed with evidence and not wired
   (§5.1). `RequiresTwoPersonApproval::GOVERNED` has one entry.
3. **A PENDING APPROVAL NEVER EXPIRES.** A request recorded today can be approved months later.
   The executor re-asks its own safety question at execution time, which is what stops a stale
   approval overriding a world that has changed — but the window itself is unbounded, and how long
   an ask should stand is an owner question rather than a number to invent. It needs a column and a
   sweep, neither of which is built.
4. **THE FINGERPRINT DEDUPE IS CROSS-REQUESTER, AND THAT IS NOT A HOLE.** An identical ask by a
   second operator returns the first operator's pending row. Two distinct people still have to sign
   off — the second cannot approve a row they are recorded as having requested only if they ARE the
   recorded requester, and they are not — so dual control holds. It is stated because the behaviour
   is surprising on first reading.
5. **THE GATE'S NON-OPERATOR BRANCH IS UNREACHABLE FOR THE ONE WIRED DOOR.**
   `PlatformOperatorOnly` refuses a non-operator on `plan-disable` first. The branch stays because
   the gate is generic middleware and must fail closed on a door that has no such route gate; it is
   covered by a direct-call case rather than over HTTP, for the `GAP-307` reason.
6. **WITH ENFORCEMENT ON, A NON-OPERATOR'S REFUSAL BODY ON `plan-disable` WOULD CHANGE** from
   `{status, message}` to `{error}`, both HTTP 403. With enforcement off — the shipped default —
   nothing about that route's responses changes at all. No path gains a 401, which would force a
   logout in the shipped Flutter app.
7. **`proposed_actions`' `not_wired_because` TEXT IS ENGLISH-ONLY.** It is owner-decision metadata
   rendered on the approvals screen while the list is unconfirmed, and it goes away when §5.1 is
   answered. Every other string on that screen, and every flash message, is in `resources/lang/ar.json`
   (48 entries added) and asserted by `ArabicUiCoverageTest`.
8. **HUMAN ACCEPTANCE IS OUTSTANDING for the approvals screen.** It has not been looked at by a
   person in Arabic (RTL) or in dark mode, and `CLAUDE.md` requires both directions plus a contrast
   check with `APP_DEBUG` **off**. It is built from `x-ss.*` components and `@extends('layouts.app')`
   with no directional CSS and no raw echo, so it should mirror with the layout — but "should" is
   not a measurement.

---

## 8. `IAM-002` — a second finding: there is no server-side session store to manage

Measured 2026-11-26, and reported rather than acted on, because the remedy is a migration decision
that is the owner's:

| what | where | measured |
|---|---|---|
| the configured driver | `config/session.php` | `env('SESSION_DRIVER', 'database')` |
| the shipped default | `.env.example:29` | `SESSION_DRIVER=database` |
| a migration creating `sessions` | `database/migrations/` | **none.** `grep -rn "Schema::create('sessions'" --include=*.php .` outside `vendor/` returns **nothing** — not in the host, not in any of the 70 vendored modules |
| what the suite runs on | `phpunit.xml:40` | `SESSION_DRIVER=array`, so **no test touches the question** |
| the measured production structure | `docs/GATE-F-VERIFIED-SCHEMA.md` | `sessions` is **not listed** |

Two consequences, and the second is the one `IAM-002` turns on:

1. **A fresh environment built from this repository's own migrations cannot serve
   `SESSION_DRIVER=database`.** This is the same shape as `audit_logs` before GATE F — a table live
   code depends on that no migration creates — so either production has it out of band or
   production runs a different driver. Which, this repository cannot say.
2. **"إدارة الجلسات" (session management) has nothing to manage.** Listing an operator's active
   sessions, or revoking them after a password change, needs a server-side session store to read.
   With no `sessions` table, neither is implementable, and the operator sign-in/sign-out audit rows
   added in §4 are the closest thing available: they say a session *began* and *ended*, not which
   are open now.

**Not fixed here.** Adding the table is a migration, and `CLAUDE.md` §2 makes a persistent migration
an owner escalation; GATE F exists because a guessed structure becomes permanent and invisible once
a migration has created a table. The honest step is to measure production (does `sessions` exist,
and what is `SESSION_DRIVER` really set to?) before anything is written. That measurement needs
production access, which this workstream does not have — so `IAM-002`'s session-store half is
**EXTERNALLY BLOCKED**, separately from its token half.
