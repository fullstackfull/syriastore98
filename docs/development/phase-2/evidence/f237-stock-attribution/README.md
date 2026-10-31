# F237 — phase two of the stock attribution, gate evidence

Run on `83195355`, 2026-10-31. **The owner's seven requirements are met, with two limits
stated below rather than left to be discovered.**

## The result

| suite | result | exit |
|---|---|---|
| Feature | **2 failed, 17 skipped, 7024 passed** (34916 assertions) | 1 — *expected*, two declared tests fail |
| Known-Outcome assertion | **`OK: the observed outcome set matches the manifest exactly`** | **0** |
| Unit | **657 passed** (4094 assertions) | 0 |
| Contract | **26 passed** (83 assertions) | 0 |
| MariaDB roster | **passed: 32   failed: 0** on `10.11.14-MariaDB` | 0 |

`7024 + 2 + 17 = 7043` testcases, and `7043 − 7030` (the `065b4df5` baseline in
`../resume-gate-065b4df5/`) `= +13` — exactly the thirteen tests this change adds. No other
outcome moved.

As the assertion script says itself, and it is worth repeating: this is **not** a statement that
the suite is green, only that it fails and skips exactly what the project has recorded. **D-30
holds** — not owner acceptance, not release readiness.

## What was wrong

`d73fbe12` landed "E13 slice 2 — two-phase attribution" and the design was right. Only phase one
was wired. `StockMovement::attributeToOrder()` had **zero production callers** — the only mentions
under `app/` were its own definition and a **docblock** in the trait that promised "the caller
attributes it once the order exists".

So every placement row kept `order_id = NULL`; `movedOutFor()` predicates on `order_id` in **both**
its main query and its `$observed` fallback, so it answered `UNKNOWN` for every real order; and
because `UNKNOWN` means *fall through and restore*, the E13-C cancellation guard landed in
`4b917079` **never refused anything on a real code path**.

Stated precisely, because the first write-up of this was not: in production the migration is
deliberately not run (D-15 condition 9), so `available()` is false and the guard answers `UNKNOWN`
there **by design**. The defect is that the mechanism did not work **even in development and test,
where the table exists** — it was unexercised by any real code path anywhere.

**Why fifty-five existing tests passed over it:** they call `attributeToOrder()` **by hand**, so
they exercised a state no request could produce. Each half was proven in isolation — the trait
threads a token, attribution is idempotent when invoked — and **nothing connected them**.

## Four defects in the fix itself, found by review and by this gate

None was found by the 92 passing targeted tests. That is the argument for both.

| # | severity | what |
|---|---|---|
| **F1** | **HIGH, production-visible** | the first version logged at **error for every order on all eleven money paths**. `stockEvidence()` mints the correlation token **unconditionally**, so the "no token" early return never fires in production where the table is absent; attribution then found no table, `attributionStateFor()` answered `UNKNOWN`, and `UNKNOWN` was treated as failure. It contradicted the governing rule of the service it calls — `StockMovement.php:137-139`, *"UNKNOWN is not a failure mode, it is the whole point"* — and `OrderController:1872-1877`, which accepts being "one log line heavier" at **info**, once per cancellation. **No test could have caught it**: `RefreshDatabase` creates the table, so the suite never sees production's state. Fixed with an `available()` guard and a test that **drops the table**. |
| **F2** | medium-high | a **partial** recording failure read as success. The test was `attributeToOrder() > 0` — *"did any row move"*. A row can be missing while the stock really moved (`ProductStockService::recordMovement()` returns without writing when the model has no `store_id`; `record()` logs and writes nothing if the insert throws). The consequence is a **D-15 conditions 4/5 violation**: `movedOutFor()`'s `$observed` fallback asks whether **the order** moved stock, not **this line**, so once a sibling line is attributed the unrecorded line reads **`NOT_MOVED`** and the cancellation **skips its restore** — stock consumed on a cancelled order. Now counted and **reported, not repaired**: the missing row cannot be reconstructed and inventing one would be manufacturing evidence. |
| **F3** | medium | **nine of the eleven sites open no transaction at all**, and the wiring script had stamped the identical comment *"Inside the transaction on purpose"* on every one. Comments corrected; the census now asserts the no-transaction set **by name** instead of skipping the check in silence. |
| **F4** | medium | the migration's **"absent now means absent" was false.** `PaymentService` does not use the trait, carries its own `decrementStockOrFail()` whose calls pass **no `$evidence`**, and persists its own order. Claim **withdrawn**; a declared-exception ratchet names it. |

Two more came from the gate rather than the review:

* **the cited-anchor ratchet caught the change three times over.** A `PaymentController` docblock
  cites four line numbers; the attribution call moved three of them, and **correcting the comment
  moved all four again**, because editing a docblock adds lines above the code it cites. The
  ratchet's own docblock already recorded this happening twice before. Broken by measuring the file
  as it stands and changing **only the digits**.
* **the observability inventory exposed a wrong level choice, not a stale count.** Asking why the
  four new log lines were *not* flagged revealed the documented taxonomy
  (`../../../security/P01-OBSERVABILITY-INVENTORY.md:32-35`): `critical`/`warning` are *"a control
  fired"* or *"something a human should look at"*, while `error` is *"almost entirely pre-existing
  third-party-integration failure reporting … none of them is a security signal"*. Missing stock
  evidence is a security signal, so `error` was wrong on the merits — and because only `critical`
  and `warning` are enumerated, those lines had **no stated destination**, the exact condition that
  inventory exists to prevent. Converted to `warning`.

## Limits — not satisfied, and not papered over

1. **Atomicity holds for 2 of the 11 sites.** Only `PaymentController::getProductStatus` and
   `::whatsapp` open a transaction. The seven gateway controllers and both API writers do not, so
   the order is already committed when the attribution runs. That is **safe** — a crash in between
   leaves the row unattributed, which reads `UNKNOWN` and makes the cancellation restore, the
   behaviour that held before F237 — but it is **not** the atomicity the requirement asks for.
   Adding transactions to seven gateway handlers is a larger change than F237.
2. **`PaymentService` records no evidence at all** for the orders it writes. Safe for the same
   reason (no rows ⇒ `UNKNOWN` ⇒ restore). It belongs with the **E13-C residual** slice, which also
   owns two of its unthreaded restore sites (`:207`, `:209`).
3. **A multi-line `Log::warning(` is invisible to the observability ratchet.** Its pattern requires
   the message literal on the same line, so three of these four lines escaped until collapsed. That
   is a general gap in a control the project relies on, affecting any future log line formatted that
   way. **Deliberately not fixed here** — widening the scanner would pull in every multi-line log
   call in `app/` at once.

## Files

| file | what it is |
|---|---|
| `known-outcome-assertion.txt` | the manifest assertion in full, exit 0 |
| `suite-totals.txt` | Feature/Unit/Contract totals and the two declared failures as observed |
| `mariadb-roster.txt` | all 32 MariaDB verification scripts, `passed: 32 failed: 0` |

## Reproducing

```bash
php artisan test --testsuite=Feature \
  --log-junit storage/logs/feature-junit.xml \
  --log-teamcity storage/logs/feature-teamcity.txt
php .github/scripts/assert-known-test-outcomes.php \
  storage/logs/feature-junit.xml .github/known-test-outcomes.json \
  storage/logs/feature-teamcity.txt
bash tests/Support/Security/bin/run-mysql-verifications.sh
```

**The Feature gate needs one undocumented precondition** — `public/uploads` must be a symlink into
the uploads root, or `PaidDownloadEntitlementTest`'s F217 case fails. See **F238** in
`../resume-gate-065b4df5/README.md`; the path is gitignored, so it cannot be committed.
