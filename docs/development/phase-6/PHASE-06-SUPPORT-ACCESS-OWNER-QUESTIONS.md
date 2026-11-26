# `SUPPORT-004` / `SUPPORT-005` — the five numbers the mechanism refuses to invent

> **Status.** The MECHANISM is built, tested and committed on `ws/p6-support`. The five questions
> below are the only things standing between it and being switched on. None of them is an
> engineering question, and none is answerable from anything in this repository — the recovered
> plan under `docs/development/master-plan/` states none of them, which was checked by reading
> `DECISION-ID-REGISTER.md` and the base plan rather than inferred.
>
> **The migration is NOT applied to production.** Two new additive tables, approved for development
> and isolated database testing only, as with the D8 consent tables and the ANA-003 schedule table.
> Applying it needs the owner's separate authorisation.

---

## What the owner approved

Verbatim from `docs/development/master-plan/DECISION-ID-REGISTER.md`:

| id | approved text | English |
|---|---|---|
| `SUPPORT-004` | وصول دعم مؤقت ومحدد بموافقة التاجر وسجل تدقيق. | Temporary, scoped support access with merchant consent and an audit log |
| `SUPPORT-005` | وصول طوارئ منفصل وبصلاحيات وموافقات أعلى. | Separate emergency access, with higher permissions and approvals |

Every adjective is a clause. **Before this work, one of `SUPPORT-004`'s four was implemented.**
Measured on this branch before anything was written:

```
grep -rl "support_access\|SupportAccess\|EmergencyAccess\|break.glass\|breakGlass" \
     app/ routes/ database/ tests/
→ (no output)
```

| clause | before | now |
|---|---|---|
| audit log | **present** — `App\Support\Audit\RecordImpersonation` writes one row per impersonation take/leave; `StoreController::switchStore()` writes `operator_store_switched` | extended: eight new action names, grant as the subject |
| temporary | **absent** — an impersonation session ends when the operator clicks "exit", or never | `expires_at` NOT NULL, a configured ceiling enforced at creation, a null expiry reads as EXPIRED |
| scoped | **absent** — `UserPolicy::impersonate` is all-or-nothing | one store per grant, plus a scope name; only `full` admits impersonation |
| merchant consent | **absent** — the operator self-authorised | only the merchant the grant took *from the store* may consent |
| `SUPPORT-005` emergency | **absent entirely** — no second path, so the emergency route IS the ordinary route, which is what منفصل forbids | a separate path authorised by N distinct approvals *instead of* consent |

---

## The five questions

Each maps to a key in `config/support_access.php`. Every value shipped is a **placeholder**, marked
as one in the config file beside its question.

### 1. Should the mechanism be ENFORCED at the impersonation door?

`support_access.enforce` — **ships `false`.**

`SUPPORT-004` says support access requires merchant consent, so the approved answer is eventually
`true`. It ships `false` for one reason, stated rather than hidden: **with no grants in the table,
`true` refuses every support login on the first deploy**, and support staff would be unable to help
a merchant who is on the phone. Switching it on has a prerequisite — a merchant-facing screen on
which to consent, which is not built here (see *What is not built*, below).

**`false` is not "the mechanism does nothing."** In that mode every ungranted support login writes
a `support_access_ungranted` audit row. So the gap `SUPPORT-004` names becomes **countable before it
is enforced**, and the count of ungranted support logins over a month is the best input to this
decision. Nobody has that number today.

> **Recommended sequence.** Deploy with `false`, read the count after a representative period, build
> the consent screen, then switch to `true`. The enforcing path is exercised in CI in both modes
> today, so turning it on is a config change and not a code change.

### 2. How long may a grant last?

`support_access.max_duration_minutes` — **ships `1440`** (24 hours).
`support_access.emergency.max_duration_minutes` — **ships `240`** (4 hours).

Chosen to be short enough to be obviously temporary and long enough to span one support
conversation, with emergency shorter because it skips the merchant entirely. `SupportAccessGrants::request()`
refuses a longer duration, so the ceiling is enforced at the only door that creates a grant.

The **existence** of a bound is the approved decision and is enforced in the schema (`expires_at` is
NOT NULL; the table rejects NULL, which was measured). The **value** is the owner's.

### 3. Which slices of a merchant's data should support be able to ask for separately?

`support_access.scopes` — **ships `['orders', 'catalogue', 'customers', 'settings', 'full']`.**

Derived from the panel's existing permission families (`Manage Order`, `Manage Product`,
`Manage Customer`, and the settings screens), not invented.

**A measured consequence the owner should know before answering.** `full` is the only scope that
authorises impersonation, and that is not a preference — it follows from what impersonation does.
`Impersonate::take()` signs the operator into the `web` guard *as the merchant*, so from that moment
every permission the merchant holds is held by the session and `getCurrentStore()` answers for the
merchant's store everywhere. **There is no filter anywhere in that path that could confine an
impersonated session to orders.** A grant for `orders` that admitted impersonation would appear in
the merchant's consent prompt as a narrow request and deliver a broad one.

So today the narrow scopes are **expressible and consentable, and enforced at one door** (they are
refused there). Making them *useful* means building read surfaces that serve a scoped grant — which
is engineering, listed under *What is not built*, and not a question for the owner.

### 4. How many people must approve emergency access?

`support_access.emergency.required_approvals` — **ships `2`**, meaning "more than the one person
asking".

**The parts that are not numbers are already enforced and are deliberately not configurable**,
because they are structural properties of "approval by others" rather than policy:

- the approvers must be **distinct people** — `UNIQUE (grant_id, approver_id)`, plus a check in the
  writer and a de-duplicating fold in the gate;
- **no approver may be the grantee** — an operator cannot approve their own emergency access;
- **one recorded decline is fatal**, however many approvals sit beside it. There is no majority
  rule, and inventing one would be inventing policy;
- a requirement of **zero refuses** rather than self-approving, so a deleted config key cannot turn
  into unlimited access.

### 5. WHO may approve emergency access?

`support_access.emergency.approver_permission` — **ships `null`.**

This is the one question that is genuinely unanswerable here. `null` means "any platform operator
other than the grantee", which is the widest honest reading and the only one this repository can
implement today: **there is no "support lead", "security officer" or equivalent role in `roles`**,
and inventing one would be inventing an org chart.

Set it to a Laratrust permission name once the owner has said who holds it, and
`SupportAccessGate::approverIsEligible()` will additionally require every approver to hold it.

> **Note for whoever sets it.** The check tests operator status **first** and only then the
> permission, because `isAbleTo()` refuses the platform operator (`permissions_as_gates => false`;
> CLAUDE.md §1). Setting this key does not lock the operator out. The check is also strictly
> narrower than the permission alone: an approver must be an operator **and** hold the permission.
> If the owner wants a non-operator approver role, that is a code change and it is named here.

### 5b. A sub-question inside question 1, which the mechanism answers conservatively

**May a merchant's staff member consent on the merchant's behalf?** Today: **no** — only the
tenant-root merchant whose `id` the grant took from the store's `created_by`.

Who inside a merchant's organisation may hand their data to support is a real decision the plan does
not state, and admitting every staff member with a panel login would answer it the widest way. The
narrow reading is reversible; the wide one is not. `SupportAccessGrants::consent()` is where it
changes.

---

## What is built, and where

| | |
|---|---|
| schema | `database/migrations/2026_11_26_000100_create_support_access_grants_tables.php` — two additive tables |
| the grant | `app/Models/SupportAccessGrant.php` — not `Cachable`, not `StoreScope`d, narrow `$fillable`; each a security property with a test |
| one approval | `app/Models/SupportAccessApproval.php` |
| the predicate | `app/Support/SupportAccess/SupportAccessGate.php` — every clause in one place |
| the lifecycle | `app/Support/SupportAccess/SupportAccessGrants.php` — request, consent, decide, revoke, recordUse |
| the vocabularies | `app/Support/SupportAccess/{AccessKind,SupportAccessScope}.php` |
| the trail | `app/Support/SupportAccess/SupportAccessAudit.php` — the **existing** `audit_logs`, no second table, no `Log::` line |
| the live door | `app/Http/Controllers/StoreController.php`, `LoginWithAdmin()` |
| the numbers | `config/support_access.php` — every key beside its question |

**Tests.** `tests/Feature/Support/SupportAccessGrantMechanismTest.php` (clause by clause, each
refusal with a positive control), `SupportAccessEnforcementTest.php` (the real door, both config
modes), `SupportAccessGrantStructureTest.php` (the three structural properties),
`SupportAccessOwnerQuestionsTest.php` (this document against the config file).

**24 mutations, all caught — after four survivors were repaired by strengthening the tests.** The
most useful was collapsing the gate's two paths into `isConsented() || emergencyIsApproved()`,
which survived all 52 tests because both "the paths are separate" cases measured the *writer* (which
throws) and never asked the *gate*. The crossed states are now planted past the writer. The full
list is in the commit message of `3517ea8ae`.

---

## What is NOT built, and is engineering rather than a decision

Named so no one reads the list above as complete.

1. **A merchant-facing consent screen.** The mechanism has `consent()`; there is no page on which a
   merchant sees a pending request, its reason, its scope and its expiry, and grants or refuses it.
   This is the prerequisite for question 1's `true`.
2. **A merchant-facing "who has access to my data" screen**, listing live and historical grants with
   their use counts — the natural reader of the trail this writes.
3. **An operator-facing request and approval screen.** `request()` and `decide()` are callable;
   there is no UI.
4. **Read surfaces that honour a narrow scope.** See question 3: the narrow scopes are expressible,
   consentable and refused at the impersonation door, but no surface yet *serves* a scoped grant.
5. **An expiry sweep.** Grants expire by `expires_at` being in the past, which the gate checks on
   every read, so an expired grant authorises nothing — correct without a sweep. A scheduled job
   would only tidy the table and notify. Note that `docs/` records production may have no
   `schedule:run` cron entry at all, so nothing here depends on one.
6. **The Flutter half.** `routes/api.php` has no support-access endpoint. `SUPPORT-002` (contextual
   help and announcements for web **and** Flutter) is a separate decision and is untouched by this
   work.

---

## Related, and deliberately not answered here

- **`D-P6-14`** — whether `SupportTicketController::ticket_status_change()` should have a permission
  gate, and whether `index()` should keep excluding the platform operator. Still the owner's;
  `TicketStatusChangeAudienceTest` pins today's audience as a *measurement* rather than an approval.
  A previous session wrote the gate and then removed it for this reason, and this work did not
  re-add it.
- **`GAP-176` (b)** — `support_tickets.ticket_id` still has **no unique index**. The generator
  carries 24 bits of entropy per second, which makes a collision improbable rather than impossible;
  the index is the structural fix over a column that already holds colliding values, which is a
  different kind of migration from the two additive tables here.
- **`SUPPORT-001`'s knowledge base and videos** overlap Phase 03's separately scoped bilingual
  Documentation Center (`app/Support/Docs/DocumentationLibrary.php` exists). **Which phase owns them
  is an owner question** and building either before it is answered risks two documentation systems.
