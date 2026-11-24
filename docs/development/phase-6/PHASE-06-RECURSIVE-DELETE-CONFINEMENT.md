# Phase 06 — every first-party recursive delete, and the one that took caller input

**Status: FIXED.** Written after the patch, because this repository is public.

`c3010288` closed the module-removal door. The independent review of that change said, in effect:
*your fix is complete for `permissions` and for `packages/`, but the class it introduced claims to be
"the one place that decides whether a directory may be destroyed" and there is another door of the
same shape it does not reach.* This is that door, plus the census that establishes it is the last one.

---

## 1. The census: four implementations, one caller that takes input

| Implementation | Called by | Path source |
|---|---|---|
| `ModuleController::deleteDirectory()` `:536` | `remove()` (fixed in `c3010288`), and the install path `:333/:351/:361` | a confined canonical path; the install path builds its own temp tree |
| `Utility::delete_directory()` `:568` | **`LanguageController::destroyLang()` `:607`** | **a raw route segment** |
| `AddonController::deleteDirectory()` `:238` | `:137` | a temp tree it just created |
| `helper.php` global `delete_directory()` `:2325` | **nothing but itself** | — |

So exactly one of them was reachable with caller-supplied input, and `helper.php`'s copy is dead
code — a third implementation of the same function that no call site uses. Recorded, not deleted:
removing a global function is a wider change than this slice, and it is inert.

---

## 2. What `DELETE /lang/{lang}` did with its route segment

`routes/web.php:1615` declares `Route::delete('/lang/{lang}')`. The handler built **three** things
from `$lang` with no `basename()`, no allowlist and no path check:

```
:607  Utility::delete_directory($langDir . $lang)      recursive
:626  unlink($langDir . $lang . '.json')               single file
:628  User::where('language', 'LIKE', $lang)->update() a LIKE PATTERN
```

The only guard was a refusal of the **default and fallback language codes**, added in an earlier
slice. That is a check on *which language*, not on *whether this is a language*:

- `.` resolves to `resources/lang` itself — the whole collection rather than one member;
- `..` resolves to `resources/`;
- the single-file delete reached any `*.json` a traversal could name (`../../composer` →
  `composer.json`);
- `%` in a `LIKE` is a **wildcard**, so one request could reassign the language of more users than it
  named. That one needs no traversal at all.

**Live reachability of the traversal forms is not claimed.** nginx and Apache both normalise `.`,
`..` and `%2e` out of the URI before Laravel sees it, so they may be unreachable through a production
web server while being reachable from a test client or `php artisan serve`. The `LIKE` wildcard is
reachable either way.

**Severity:** gated by `isAbleTo('Delete Language')` — operator-reachable, not a privilege
escalation. The same disposition as the module door.

---

## 3. The fix: one shared confinement, two doors

`app/Support/Filesystem/ConfinedDirectory.php` holds the two questions both doors ask, and
`RemovableModule` now **delegates** to it rather than keeping its own copy:

| | |
|---|---|
| `safeSegment($candidate, $noun)` | is this one path component, `^[A-Za-z0-9_-]+$`, compared against the **raw** string? Returns it unchanged or throws. |
| `within($root, $segment, …)` | does `$root/$segment` canonically resolve to a real directory strictly inside `$root`? `realpath()`, so symlinks are resolved and refused rather than followed. |

Neither is a policy check. `RemovableModule` keeps its protected-name list and its `module.json`
test; `LanguageController` keeps its default-and-fallback refusal. The shared class only answers *is
this path what it claims to be*.

**Why shared and not copied.** `RemovableModule`'s own docblock already recorded the reason: this
codebase fixed the identical shape once at `AddonController::destroy` (SEC-F5) and then had it appear
again, under its own number, at `ModuleController::remove()` — because the fix lived in one
controller and the other never learned it. A third copy is how a third door gets it wrong. The
extraction preserved every refusal, in the same order, with the same messages, which the module
tests verify: **65 passed** across both suites immediately after it.

### In `destroyLang`, three things changed

1. **The name is validated first**, and `$lang` is reassigned to the validated value, so no later
   line can reintroduce the raw segment. This also closes the `LIKE` wildcard incidentally — `%` no
   longer survives.
2. **The recursive delete gets a confined canonical path** — but only when the directory exists.
   That condition is deliberate: `within()` throws for a path that does not exist, so applying it
   unconditionally would make an **orphaned manifest entry impossible to remove**, which the old code
   could do. The name check is unconditional; the confinement governs only the filesystem step.
   `test_a_manifest_entry_with_no_directory_is_still_removable` pins it, and a mutation making the
   condition always true is CAUGHT by that test.
3. **The sidecar `unlink` is guarded with `is_file()`** — which also fixes a pre-existing 500:
   `unlink()` on a missing path emits a warning that Laravel promotes to `ErrorException`, so
   deleting a language whose `<code>.json` had already gone threw *after* `language.json` had been
   rewritten, leaving the operator a 500 and a half-done removal.

### And the recursion itself

`Utility::delete_directory()` gained `is_link($dir) → unlink($dir)`, **before** the `file_exists()`
check. The ordering is its own assertion: `file_exists()` follows the link, so for a **broken** link
it answers false and the old code returned true while leaving the dangling link in place.

This is the half confinement cannot do. `within()` proves the **top** of the tree is inside the root
and says nothing about what is nested below it; `is_dir()` follows links, so a symlink inside an
accepted language directory escaped. **Not live on this checkout, and measured:** `find` reports zero
symlinks under `resources/lang` and under `packages/workdo`.

---

## 4. Evidence

Failing-first for every test. **11 mutations applied, 11 CAUGHT** (26 across this slice and
`c3010288` together):

| Mutation | |
|---|---|
| `Utility`: drop the symlink guard → nested escape | CAUGHT |
| `Utility`: drop the symlink guard → top-level walk | CAUGHT |
| `Utility`: check `is_link` **after** `file_exists` | CAUGHT |
| `Utility`: the guard fires for everything | CAUGHT |
| Language: the code is no longer validated | CAUGHT |
| Language: the recursive delete takes the concatenated path again | CAUGHT |
| Language: confinement applied even when the directory is absent | CAUGHT |
| Confinement: `within()` stops resolving symlinks | CAUGHT |
| Confinement: drop the root-itself refusal | CAUGHT |
| Confinement: drop the prefix test | CAUGHT |
| Confinement: `safeSegment` compares a **trimmed** value (the NUL bug) | CAUGHT |

```
tests/Unit/Filesystem/    30 passed (68 assertions)
tests/Feature/Language/    6 passed (38 assertions)
Unit suite                6,150 passed, 1 skipped (37,390 assertions)
Contract suite               45 passed (182 assertions)
modules intact / language directories intact   70 / 33
```

### No destructive test ever pointed at a real path

`DELETE /lang/..` against the unfixed handler deletes `resources/`. A mutation that removes the
confinement does the same thing to a route test on **fixed** code. There is no ordering of those two
runs in which both are safe — so:

- the escapes (traversal, `.`, `..`, symlink-out-of-root, root-itself, non-existent, file-not-dir)
  are proved in `tests/Unit/Filesystem/ConfinedDirectoryTest.php` against throwaway temp directories;
- the recursion's symlink behaviour is proved in `RecursiveDeleteDoesNotFollowSymlinksTest`, likewise;
- the **wiring** is pinned structurally — comment-stripped and scoped to the method — asserting the
  validation's **position** relative to all three sinks, not merely its presence, because presence is
  what the broken version already had;
- the **accept path** is driven through the real route against a language the test creates
  (`zz9test`) and removes, with `resources/lang/language.json` backed up in `setUp` and restored in
  `tearDown`, because the handler rewrites that tracked file.

### Two censuses needed updating, and that is the control working

`StoredFilePathSafetyTest` asserts the exact set of `File::delete(`/`unlink(` sites in `app/`.
`ModuleController` went 1 → 2 and `Utility` 3 → 4. Both are now declared **with their reason**, which
is what the census exists to force: a new filesystem removal has to be classified by a person. The
declaration comment is stripped before the pattern runs, so prose about `unlink` can neither satisfy
nor inflate the count — the hazard CLAUDE.md records as having hit four censuses in one day.

---

## 5. Recorded, not fixed

- **`helper.php:2325`'s `delete_directory()` is a third copy with no caller.** Inert. Deleting a
  global function is wider than this slice.
- **`AddonController::deleteDirectory()` `:238` is a fourth copy** and still has the `is_dir`-follows-a-link
  behaviour. Its only caller passes a temp tree it created itself, so no caller input reaches it —
  but it belongs to the theme/addon upload slice, which is where it will be handled rather than
  patched piecemeal here.
- **Whether the `LIKE` should have been `=` all along.** `User::where('language','LIKE',$lang)` now
  receives a validated segment, so the wildcard is closed — but `LIKE` without wildcards is still a
  slower, looser comparison than `=` and the intent was plainly equality. Left alone because changing
  it alters which rows a legitimate call matches (collation and trailing-space semantics differ), and
  that is a behaviour question rather than the security fix.
