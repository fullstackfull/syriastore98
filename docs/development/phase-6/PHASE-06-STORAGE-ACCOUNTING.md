# P06-STORAGE — storage was charged through a display formatter, so small files were free

**Date:** 2026-11-24
**Owner instruction:** item 1 of the 2026-11-24 triage directive — *"The merchant-reachable
undercharging is the immediate priority."*
**Severity:** merchant-reachable data integrity. A merchant could store unlimited files below ~5 KB
and be charged nothing for any of them, against a plan quota that is a paid product limit.

---

## 1. The defect

Four first-party sites move `users.storage_limit`, and all four computed the amount the same wrong
way — `number_format($bytes / 1048576, 2)` — and then used that **display string** as a number:

| site | direction |
|---|---|
| `Utility::updateStorageLimit()` | the charge; **40 call sites** |
| `Utility::changeStorageLimit()` | credit-back on delete |
| `Utility::changeproductStorageLimit()` | credit-back on product delete |
| `AppSettingController` (~`:1400`) | credit-back when an upload is refused |

The owner's own inspection named all four, including the two `Utility` credit-backs this slice's
first census had missed.

### Two consequences, at opposite ends of the size range

**(a) LIVE, and the one that matters.** Two decimals quantise the charge to 0.01 MiB. Measured:

```
1 byte      -> "0.00"        5,000 bytes -> "0.00"
5,242 bytes -> "0.00"        5,243 bytes -> "0.01"
```

So **every file under 5,243 bytes was charged nothing**, through any of the 40 doors, in unlimited
quantity. Everything larger was quantised too, so each upload was mischarged by up to 5 KB.

**(b) LATENT, and not claimed as live.** At 1,000 MiB and above `number_format()` inserts a
thousands separator and PHP's arithmetic reads only the leading digits:

```
2,500 MiB -> "2,500.00"  ->  $x - "2,500.00"  subtracts 2
                             Warning: A non-numeric value encountered
```

A 2,500 MiB credit-back returned 2 MiB. **Not reachable today:** the largest configured ceiling is
`max:51200` (50 MB, `config/uploads.php` `preview_video`) and this container caps uploads at
`upload_max_filesize = 2M`, so no validated door accepts a file that large. It becomes live if an
operator raises those limits past 1,000 MiB.

---

## 2. The constraint that shaped the fix — and why "just drop `number_format`" is wrong

The owner's warning was the crux: *"the original users migration defines `storage_limit` as a string
column … Do not assume a PHP float calculation alone proves correct stored accounting."*

Verified: `database/migrations/2014_10_12_000000_create_users_table.php:38` declares
`$table->string('storage_limit')->nullable()`, `plans.storage_limit` is also a `string`, and `User`
casts neither. So the column receives **whatever PHP stringifies**, and for a small file that is
scientific notation:

```
1 byte = 9.5367431640625E-7 MiB  ->  (string) "9.5367431640625E-7"
```

PHP's own `(float)` reads that back correctly. A database-side numeric context does not — MySQL's
`CAST(... AS DECIMAL)` stops at the `E` — and the figure is also rendered to merchants. **Deleting
`number_format` would have traded a rounding defect for a storage-format defect.**

So: **arithmetic in float, persistence as a fixed-point decimal string.**
`number_format($v, 6, '.', '')`, verified across nine magnitudes to emit no exponent and no
separator.

### Precision, chosen by measurement

Six decimals of a mebibyte is a quantum of **1.049 bytes**, so no real file is free. Raising it
buys nothing a byte-sized quantum does not already give.

### A claim of mine that was wrong, and how it was caught

The first draft of both the class docblock and a unit test asserted that the drift is **always in
the platform's favour**, i.e. accumulation can never undercharge. It passed — and it was
**accidental**. `number_format()` rounds to NEAREST, so the direction depends on the file:

```
1 byte      = 9.5367e-7 MiB   -> 0.000001   rounds UP     (over-charges 0.05 bytes)
2,048 bytes = 0.001953125     -> 0.001953   rounds DOWN   (under-charges 0.13 bytes)
```

The Feature test driving the real writer refuted it: fifty 2 KB uploads stored 0.09765 against an
exact 0.09765625. The honest guarantee is that the error per write is bounded by **half a quantum**
with no preferred direction, and that is what the tests now assert. Claiming the stronger property
would have been claiming a safety margin this code does not provide.

A one-directional guarantee **is** available by ceiling the stored total, and it was **rejected**:
ceiling leaves a residue on every upload-then-delete cycle, so a merchant who repeatedly uploads
and removes a file accrues usage they do not hold. An exact round trip was judged worth more than a
sub-byte margin. That is a trade, recorded as one.

---

## 3. What was deliberately not changed

Per the owner's instruction to preserve the approved storage-limit policy and avoid a quota
redesign:

- **The unit stays mebibytes.** Storing bytes would remove the drift entirely, but every reader
  treats the column as megabytes — `PlatformCapabilities::limit('Storage (MB)', ...)`,
  `DashboardController` renders `. ' MB'`, and `HomeController:572-578` divides it by the plan's
  own limit for a percentage. Changing the unit silently would break all three.
- **The plan comparison keeps its `<=` and its `-1` exemption.** A plan whose limit equals the new
  total still refuses. Both sides are now cast to float so the comparison is numeric on both, which
  is the only change in it.
- **No historical usage is rewritten.** Existing stored values are read as they are. Whether past
  under-charging should be reconciled is an owner decision with no measurement behind it here, and
  it is **not** taken.
- **`changeproductStorageLimit()` still dereferences `$user` unguarded.** Pre-existing; adding a
  guard would change which callers silently stop crediting, which is a separate decision.

---

## 4. Evidence

| | |
|---|---|
| failing first | **all 7** original Feature cases failed against the unfixed writer, through the real column with `refresh()` — including a 1 KB upload storing `0.0` |
| after | Unit **33/33**, Feature **9/9** storage + **19/19** `DashboardFigureHonestyTest` |
| mutations | **14 meaningful, 14 caught** — five only after the harness exposed a test gap |
| censuses | the three line-keyed inventories re-pointed and verified a pure move; 18/18 |
| blocking suites | Unit **6,199 passed / 1 skipped** and Contract **45 passed**, re-run on this tree |

### THIS SLICE BROKE THREE GREEN TESTS, AND THE FULL FEATURE GATE IS HOW IT WAS FOUND

Not a near miss and not found by review: the first version of this change was **committed**, and the
local Feature gate on the combined tree returned **three undeclared failures** —
`DashboardFigureHonestyTest::test_the_storage_card_does_not_claim_a_quota_of_zero`,
`…_says_unlimited_rather_than_minus_one` and `…_states_the_quota_when_the_plan_records_one`.

**The cause.** Six decimals in the column made the dashboard note print `"1.988443MB / 100MB"`, and
for usage under ~105 bytes PHP's float-to-string switches to scientific notation —
measured, `"4.8E-5MB"`. The repair applied `number_format($x, 2)` in the view. That removes the
exponent and renders **12 as `"12.00"`**, so all three sentences changed. The view's own docblock
lists those three sentences verbatim (`"12MB / 500MB"`, `"12 MB used of unlimited storage"`,
`"12 MB used"`), so the change disagreed with the documentation beside it as well as with the tests.

**The repair of the repair** is `StorageUsage::display()`: two decimals with trailing zeros trimmed,
widening to the stored precision only when two decimals would collapse a nonzero figure to `"0"`.
Four properties no single `number_format()` call satisfies — whole prints whole, no exponent at any
magnitude, a stored file never reads as zero usage, and a dashboard figure stays short.

**AND THE THREE RECOVERED TESTS STILL COULD NOT SEE THE REAL DEFECT.** Mutating the view back to raw
concatenation left **all three green**, because every one of their fixtures stores exactly 12 MB and
`(string) 12.0` is already `"12"`. They pin the sentence *shape*; they cannot pin the formatting,
which is the half that was broken. `test_a_sub_kilobyte_usage_is_not_shown_to_the_merchant_as_an_exponent`
was added for that: it stores one byte, asserts as a **premise** that the figure still stringifies
with an exponent, and then asserts the rendered page does not contain one. With it, the raw-float
mutation is CAUGHT — and so is a mutation that formats at two decimals, which would silently tell a
merchant storing one byte that they are storing `0.00`.

**What is still not pinned, stated rather than papered over:** the QUOTA side of `"X / Y"`. A plan's
limit is a whole number of megabytes, so `(string) 500.0` is `"500"` either way and no assertion can
distinguish the two. It is formatted anyway, so the halves cannot drift if a fractional limit is ever
configured, and the view says so at the line.

### The five mutations that survived first, and what each taught

0. **The view concatenated the raw float again — SURVIVED**, for the reason set out above: three
   whole-number fixtures cannot tell a formatter from a concatenation. CAUGHT once the one-byte case
   existed.
1. **`persist()` deleted from the charge site — SURVIVED.** The 1,536-byte case stringifies as plain
   decimal, so nothing caught it. Only a file of a few bytes produces an exponent. A **one-byte**
   case was added; now CAUGHT.
2. **The credit-back reverted to the display string — SURVIVED.** Nothing drove
   `Utility::changeStorageLimit()` at all. A Feature case was added with a real disposable file
   under `uploads/` (the method resolves through `StoredUpload::resolveAll()` and then deletes it).
   Still survived — because with usage exactly equal to the charge, `persist()`'s clamp maps both
   the right and the wrong arithmetic to `"0.000000"`. Starting the merchant at **5 MiB** of prior
   usage discriminates: the old form leaves 4.997378. Now CAUGHT.
3. **A third was refused by the harness** as a non-unique anchor (`anchor appears 3 times — NOT
   APPLIED, proves nothing`), which is guard #1 of the mutation harness working as designed. The
   anchor was re-cut with surrounding context.

One further mutation was **dropped as not meaningful** rather than contrived around: removing
`minus()`'s zero clamp at a call site cannot be caught, because `persist()` clamps as well. That is
defence in depth, not redundancy — `minus()`'s own contract is pinned directly by a unit test.

---

## 5. The requirements the owner named, and where each is tested

| requirement | test |
|---|---|
| tiny files | `test_a_file_too_small_for_two_decimals_is_still_charged` (3 sizes), `test_a_small_upload_is_charged_in_the_database` |
| repeated uploads | `test_repeated_uploads_accumulate`, `test_many_small_uploads_accumulate_in_the_database` |
| boundary limits | `test_the_plan_limit_still_refuses_an_upload_that_exceeds_it`, `test_an_upload_inside_the_plan_limit_is_still_accepted` |
| deletion credits | `test_a_deletion_credits_back_what_the_upload_charged`, and through the real deleter in `..._in_the_database` |
| cumulative rounding | `test_cumulative_rounding_stays_within_the_quantum` (10,000 writes) |
| large numeric values | `test_a_figure_above_a_thousand_is_not_cut_at_the_separator`, `test_a_large_figure_is_stored_whole` |
| nonnegative usage | `test_usage_never_goes_negative` |
| isolation between merchants | `test_one_merchants_upload_leaves_another_merchants_usage_alone`, asserted through `withoutGlobalScopes()` because `User` carries none |
| save and reload fractional | `test_a_fractional_figure_survives_save_and_reload`, `test_a_one_byte_upload_does_not_store_an_exponent` |

---

## 6. For the owner

- **Historical under-charging is unquantified and unreconciled.** Every merchant's recorded usage is
  lower than their true usage by an unknown amount — the sum of every sub-5 KB file they have ever
  uploaded, plus up to 5 KB per larger file. Answering "by how much" needs a production replica,
  which this session has no access to. A reconciliation would be a **data change to live accounts**
  and is explicitly not taken.
- **The latent half becomes live if upload limits are raised past 1,000 MiB.** It is fixed here
  either way, so raising them is now safe in this respect.

---

## 7. The independent review, and what it changed

The owner required an independent financial/data-integrity review before this committed. It ran
read-only with no shell — every figure in it was hand arithmetic from the source — and it found
**one blocking item, one live merchant-visible defect, four assertions that could not fail, and one
false docblock claim**. All are fixed. Two of its findings I **refuted by execution**, and that is
recorded below rather than quietly dropped.

### Fixed

| # | finding | what it was |
|---|---|---|
| F1 | **blocking** | Four assertions in `ThemeImageUploadTypeTest` encoded the OLD quantisation. `pngOf(300 * 1024)` is exactly 307,200 bytes = 0.29296875 MiB, which the old charge rounded to `0.29`. Confirmed by execution: `0.292969` vs `0.29`. The four figures are restated at the real quantum. |
| F2 | **live, merchant-visible** | `dashboard.blade.php` concatenated the float into the Storage Status card, so it read **"1.988443MB / 100MB"**; and below ~105 bytes of usage PHP switches to exponential — measured as **"4.8E-5MB"**. Formatted for display at two decimals; the float is untouched. |
| F4 | false claim | The class docblock said "no historical usage is rewritten … existing stored values are read as they are". `stored()` normalises: a negative figure reads 0 and is persisted as 0, and a legacy `"2,500.00"` reads 2.0 and is persisted as `"2.000000"`. Defensible normalisations of values no reader can use — but they are changes, and the docblock now says so. |
| F5 | **unfalsifiable assertion** | `test_cumulative_rounding_stays_within_the_quantum` computed its bound FROM `DECIMALS`, the constant under test. At `DECIMALS = 2` the drift is 0.00954 and the bound becomes 50 — it passes. An absolute floor was added; **mutation-proved**: `DECIMALS = 2` now fails it. |
| F6 | tautological premise | A "premise" asserted `(float) "2,500.00" === 2.0` — a fact about the **cast**, not the defect, which was subtraction in arithmetic context. It now performs the arithmetic and asserts both the lost 2,498 MiB **and** that PHP raises `A non-numeric value encountered`, captured with a local error handler rather than suppressed (`@` does not work: PHPUnit's handler sees it first, and the test was reporting a warning the known-outcome gate has no declaration for). |
| F12 | stale evidence | `mutate-tenant-root-fixes.py`'s mutant **PU70** targeted the deleted `number_format` line, so the harness printed `NOT APPLIED` and the script exited non-zero. Retargeted at the new call site; the anchor occurs exactly once. |

### F3 — PARTIALLY REFUTED by execution

The review held that `StorageUsage::minus()`'s zero clamp disarms two regression detectors in
`StoreWipeMediaOrderingTest`, whose documented failure signal is a **negative** figure (`-3.00`),
now clamped to `0.00`. The reasoning is right, and the fixture was changed accordingly: a positive
floor (`PRIOR_USAGE = 10.00`, a named constant every premise derives from) so one credit leaves
10.00 and a double credit leaves 7.00.

**But the double credit the review cited is not reachable.** It named
`ProductMediaReclaimer.php:44-51`. Mutated the reclaimer to credit **twice** and the test still
passed — **SURVIVED**. The reason is a control the review did not account for:
`StoredUpload::resolve()` requires `is_file($real)`, and the first credit **deletes the files**. A
second call therefore sizes **zero bytes** and credits nothing. The files' absence is itself the
idempotence guard.

So: the clamp does remove a negative signal, the fixture is strictly better for it, and **no
reachable double credit was constructed**. Recording the survived mutation rather than claiming the
detector is restored.

### F7–F11, F13–F15 — verified clean, and worth having on the record

The review walked things this slice could not have, and found no defect in them. Quoting the
measurements rather than the conclusions:

- **Every one of the ~40 charge sites checks the return value**, enumerated individually. No refusal
  can be ignored while the file is still stored, and the refusal returns before `$user->save()`.
- **The plan comparison is unchanged in verdict** for `''`, `'0'`, `'-1'`, `'-1.00'`, `' -1 '`,
  `'10'`, `'1e400'` and null-ish. It changes for a **non-numeric non-empty** value (`'unlimited'`):
  the old string comparison **allowed every upload**, the cast now refuses every upload. Latent
  (both writers validate `required|numeric`, the inputs are `Form::number`), and it **fails closed**
  — an outage for that plan, not free storage. Not widened for.
- **No site derives the charged id from request input**, across 36 `creatorId()` sites and the rest.
- **No SQL-side arithmetic, `SUM()`, `ORDER BY`, `CAST` or string comparison on
  `users.storage_limit`** anywhere in first-party or vendored code, so the 6-decimal string breaks
  no reader. One nuance worth keeping: the `CAST(... AS DECIMAL)` justification in the class
  docblock has **no live reader behind it** — the justification that actually holds is the rendering
  one, which is F2.
- **The ten line-number inventory entries are byte-correct**, independently re-measured, and no
  comment census is satisfied or broken by the new docblocks.

### Carried forward — NOT closed, and not this slice's

**F9.2: upload doors that never charge at all.** The review enumerated them, all merchant-reachable
and all pre-existing: `ThemeCustomizeController:292/:312/:334`, `UserController:545`,
`AppSettingController:307`, `SettingController:2238`, and in the admin API
`Api/DashboardController:1861/:1872/:1920/:1933/:3152/:3204/:3365/:3379/:4154/:5997` — **including
the product-create cover image and the whole gallery**, which is the door the shipped Flutter app
uses. Plus `BankTransferController:52` and `PaymentService:2463`, which are shopper-reachable.

So **"every upload is now charged correctly" would be false**, and this document does not say it.
What this slice fixes is that every upload which *is* charged is charged its real size. The doors
that charge nothing are a separate slice.

Related and already tracked: `upload_theme_image()`'s `$user_id = 0` default charges nobody
(`0 ?? x` is `0`, `User::find(0)` is null), six of its seven callers pass `0`, and
`P01-SECURITY-WORK-QUEUE.md:6681` holds it OPEN pending a decision on the counters — **which this
slice is**. That row is now unblocked.
