# Store Syria × Lynomia — tracked production-readiness blockers

**One register, carried forward across phases.** Each completion report had its own "known
limitations" section, which is the right place to record what a phase found but the wrong place
to track what is still open: a blocker raised in Phase 1 and still open in Phase 5 appears in
five documents, with no single answer to "what is stopping go-live today".

This file is that answer. It is updated at the end of every phase, and each entry carries the
phase that raised it, the phase that closed it if any, and **who can close it** — because the
most important property of these entries is that most of them cannot be closed from inside
these two repositories at all.

Status at: **Phase 5, in progress.** Phase 4 closed and pushed
(Lynomia `88630724`, Store Syria `152888c4`).

---

## A. OPEN — nothing in these repositories can close these

### A1. No run against a live Lynomia has ever happened

| | |
|---|---|
| Raised | Phase 1, restated every phase since |
| Closes by | A staging deployment of both sides, by the owner |
| Severity | **The single largest unknown.** Everything else here is bounded; this is not. |

Every test in both repositories, and every browser harness, runs against the other side's
contract *as this work understands it*. The contract is pinned by specs on the Lynomia side and
by a scriptable fake (`tests/Feature/Lynomia/InteractsWithLynomia.php`) on the Store Syria side.
Both were written from the same reading of the same code, which is exactly why they agree — and
agreement between two expressions of one reading is not evidence that the reading is right.

**What a staging run must cover,** in this order, because each step is a prerequisite for the
next:

1. Provision a workspace from Store Syria and complete the SSO handoff (Phase 1).
2. Open the native inbox, send and receive on a real channel (Phase 2).
3. Connect a store, push a commerce event, see it in an agent's panel (Phase 3).
4. Build, publish and run one flow per starter template on a test WhatsApp number (Phase 4).
5. Run the four browser harnesses against the *deployed* pages rather than rendered fixtures
   (`tests/Browser/run.sh`, pointed at the staging host via `STUDIO_BASE`).
6. Confirm the consent gate refuses a promotional send to a contact with no grant (Phase 3/5).
7. Phase 5's own additions, listed in the Phase 5 report when it is written.

### A2. `APP_KEY` was published and has not been rotated

| | |
|---|---|
| Raised | Pre-Phase-1; recorded in `docs/security/SECRET-ROTATION-CHECKLIST.md` |
| Closes by | The owner, following that checklist |
| Severity | High, and **accepted** as an operational blocker rather than an authorization to bypass security |

The practical consequence for this integration, and the reason it is named in
`packages/workdo/LynomiaChat/src/config/lynomia.php` at the top of the file: Laravel's
`encrypted` cast derives its key from `APP_KEY`, so an encrypted column protects nothing in this
installation. That is why **no credential introduced by Phases 1–5 is stored in the database at
all** — the platform token is read from the environment per use, per-person Lynomia bearers are
cached in an `array` store that does not outlive the request, and each store's webhook secret is
derived by HMAC from a master secret rather than stored.

That is the only mitigation available from inside this scope. It does not close the blocker.

### A3. GATE F — 15 tables that live code reads and writes have no migration

| | |
|---|---|
| Raised | Pre-Phase-1; `database/migrations/_gate_f_pending/README.md` |
| Closes by | The owner, with `SHOW CREATE TABLE` output from production |
| Severity | High for financial work; **not on the Lynomia integration's critical path** |

Six of the fifteen are money-bearing. The provisional drafts are held out of the live migration
path deliberately and by two independent mechanisms, because a guarded migration (`Schema::hasTable`)
is safe when its definition is right and a silent trap when it is a guess — a provisional
definition that created a table first would make the later correct one a no-op, and the wrong
column types would become permanent and invisible.

Phase 4's financial fix did not change this and its test file asserts the tables are still
absent, so the limit stays visible rather than decaying into folklore.

### A4. Store Syria is PRE_UAT for cart recovery

| | |
|---|---|
| Raised | Phase 3 (`Commerce::Switches::PRE_UAT[:recovery]`) |
| Closes by | A reviewed code change after provider UAT, **not a setting** |
| Severity | Medium — it correctly withholds a capability rather than failing one |

This is working as designed and is listed here so it is not mistaken for a defect. Phase 4's
automation vocabulary reports the cart trigger as unavailable with reason
`recovery_unavailable` rather than offering a rule that would silently never fire. Any Phase 5
campaign type that depends on abandoned carts inherits the same restriction and must report it
the same way.

---

## B. OPEN — closable inside these repositories, scheduled

### B1. The `flow_sessions` concurrent index on a production-sized table

| | |
|---|---|
| Raised | Phase 4 (`20261020000002_add_agent_bot_created_at_index_to_flow_sessions`) |
| Closes by | Running it against a production-shaped database and watching it |
| Severity | Low |

Written `disable_ddl_transaction!` + `algorithm: :concurrently` so it does not lock the table,
and it ran clean against the test database. It is listed because a concurrent index build on a
large table is the one migration in Phase 4 that deserves to be watched rather than assumed.

### B2. Phase 5's own blockers

Added as Phase 5 proceeds. Anything Phase 5 finds that it cannot honestly close is recorded
here rather than in a limitations list nobody re-reads.

---

## C. CLOSED

| # | Blocker | Raised | Closed |
|---|---|---|---|
| C1 | The Feature suite reported success without running — three Auth controllers call `die` when `storage/installed` is absent, which it is on any fresh clone, so CI saw exit 0 with no tests executed | Phase 1 | Phase 1 (`tests/bootstrap.php` creates the marker and refuses to run if it cannot) |
| C2 | `ActivatedModule()` memoised on nothing, so the first non-empty module list in a process answered for every later principal — one tenant's entitlements answering another's question | Phase 1 | Phase 1 (memo moved to the container, keyed by resolved owner id) |
| C3 | No CI enforcement that the accepted-failure set had not grown | Phase 2 | Phase 2 (`.github/scripts/assert-known-test-outcomes.php`, which refuses to run unless **both** `--log-junit` and `--log-teamcity` are supplied, so dropping one CI flag cannot silently disable half the control) |
| C4 | Phase 4's broad Lynomia regression sweep had not been seen green | Phase 4 | Phase 4 — 6,937 examples, 0 failures, 46 pending, all 46 the single `Chatwoot.encryption_configured?` MFA guard |
| C5 | The browser harness rendered to one directory while the server served another, so a run could silently verify a stale build — and did | Phase 4 | Phase 4 (`tests/Browser/run.sh` renders, serves and runs from one derived root) |

---

## D. Standing restrictions — not blockers, and not to be "resolved"

These are deliberate and stay in force until the owner lifts them explicitly. They are listed
so that a later phase does not read them as oversights and close them.

- `LYNOMIA_INTEGRATION_ENABLED` defaults **false**. Nothing performs a remote call while false.
- `LYNOMIA_COMMERCE_EVENTS_ENABLED` defaults **false**, and while off **nothing is recorded**
  rather than recorded and held.
- `.env`, `APP_KEY` and production credentials are not modified by any phase.
- No live communication-provider account is connected; no production messaging is activated.
- Nothing is merged or deployed.
- The per-person Lynomia credential cache defaults to the `array` store, which is not a cache —
  it costs one extra call per merchant request and is the right default, because this
  installation's default cache driver is `file` and any other default would write a live bearer
  to disk in plaintext.
