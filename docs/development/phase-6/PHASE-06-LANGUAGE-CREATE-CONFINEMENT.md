# P06-MOD-3 — the confinement went on the delete door and not on the create door that feeds it

**Date:** 2026-11-24
**Commits:** this one; follows `9a486500` (delete door) and `c3010288` (module door)
**Found by:** independent adversarial review of `9a486500` — 4 lenses × 3 refuters, 11 findings
survived refutation, this one at **certain** confidence
**Severity:** operator-reachable arbitrary directory and file write. **Not** a privilege escalation.

---

## 1. What the review said, and what measuring it added

The reviewer's finding, verbatim: the extracted confinement helper "was applied to the delete door
and not to the create door, which writes `resources/lang/<raw POST value>` with no validation at
all".

Measured rather than accepted:

- `LanguageController` contains **zero** `validate(` or `Validator` calls. Not "weak validation" —
  none.
- `storeLanguage()` reads `$request->code` from the **POST body**. That matters beyond tidiness: the
  `{lang}` route segment this slice's predecessor confined is constrained by the router to `[^/]+`,
  so it could not contain a slash. A body field has no such constraint.
- Three filesystem sinks are built from it: a `\File::copy()` of `en.json` to
  `resources/lang/<code>.json`, a `mkdir()` + `chmod(…, 0777)` of `resources/lang/<code>`, and a
  `Filesystem::copyDirectory()` of the whole `resources/lang/en` tree into it.

Where that lands, by resolving the handler's own string arithmetic against this repository's
`base_path()`:

| `code` | directory | sidecar |
|---|---|---|
| `ar` | `<base>/resources/lang/ar` | `<base>/resources/lang/ar.json` |
| `../../public/x` | **`<base>/public/x`** | **`<base>/public/x.json`** |
| `  ar  ` | `<base>/resources/lang/  ar  ` | … |

`resources/lang/en` holds **six `.php` files**, so the recursive copy places executable PHP in
whatever directory the code names — the document root included. The copied *content* is the shipped
English translation set, not caller-supplied bytes, so this is a write-where primitive, not direct
code injection. Stated that way deliberately: the distinction is the difference between this and a
P0.

**Severity, and why it is not inflated.** The route is `POST store-language`
(`routes/web.php:1614`) behind `isAbleTo('Create Language')`, which is seeded only to the platform
operator. So the door is operator-reachable — the same disposition as the module-removal door
(`c3010288`) and the language-delete door (`9a486500`), and **not** reachable by a merchant,
a shopper or a driver.

---

## 2. A second defect, found by measuring the first

Not in the review. The directory is `strtolower($request->code)`; the manifest key was the **raw**
`$request->code`. So `code=AR` created the directory `ar` and the `language.json` key `AR`, and
nothing downstream can reconcile them — the switcher, `manage.language` and `DELETE /lang/{lang}`
each take **one** code and use it as both the key and the directory name. Such a language is
offered in the UI and resolves to nothing.

The shipped tree already shows what that drift costs: **`tr` is a manifest key with no directory,
and `th` is a directory with no manifest key.** Whether those two came from this defect is not
claimed — only that the state it produces already exists.

---

## 3. The fix

One validated value, used for everything:

```php
try {
    $code = ConfinedDirectory::safeSegment($request->code, 'language code');
} catch (PathNotConfined $refusal) {
    return redirect()->back()->with('error', __('A language code may contain only letters, numbers, hyphens and underscores.'));
}
…
$langCode = strtolower($code);     // the directory
$data[$langCode] = $request->name; // and the manifest key: the same string
```

`safeSegment()` returns the candidate **unchanged or throws** — the refuse-don't-sanitise rule
`RemovableModule` established, for the reason recorded there: acting on a sanitised value operates
on a target the caller did not name.

**Why `within()` is not also called here**, asked rather than assumed: it resolves the target with
`realpath()`, which is `false` for a path that does not exist — and a create door's target never
exists. The property it would prove is already established by construction, because `$langCode` is
a single segment of `[A-Za-z0-9_-]` concatenated onto a literal root.

**Why `strtolower()` after the allowlist is not a sanitise-and-continue:** lowering a string that
is already `[A-Za-z0-9_-]+` cannot reintroduce a separator, a `..` or a NUL. It is kept because
every shipped language directory is lowercase.

### Two refusal messages corrected at the same time

Review finding 7: both of the delete door's new `catch` blocks reused, verbatim, the message
written for the default/fallback refusal — so an operator who typed a malformed code was told the
language is the default or the fallback, which is false, and it is the only feedback the screen
gives. Three distinct conditions now carry three distinct messages.

---

## 4. Evidence

| | |
|---|---|
| failing first | 3 of 4 failed against the unfixed handler; the 4th is the control and passed, because the feature worked |
| after | **4/4**, and the delete door's **9/9** still pass |
| mutations | **5 applied, 5 caught** |
| censuses | `StoredFilePathSafetyTest` + `LanguageSwitchCacheScopeTest` + `tests/Unit/Filesystem/` + both language suites: 136 passed |
| working tree | asserted clean after every run; `language.json` is backed up verbatim and restored in `tearDown()` |

The mutations, each a thing that could have been wrong:

| mutation | caught by |
|---|---|
| take the raw POST code (no allowlist) | `test_a_code_the_allowlist_refuses_creates_nothing` |
| same, but does the ORDER test notice? | `test_the_code_is_validated_before_any_path_is_built_from_it` |
| manifest key back to the raw request code | `test_the_manifest_key_names_the_directory_that_was_created` |
| refuse every code — does the control notice the outage? | `test_a_valid_code_still_creates_a_language` |
| delete the `return` from the delete door's refusal branch | `test_a_refused_code_returns_instead_of_continuing` (see §5) |

### Why no traversal is driven through the route

The same reasoning as the delete door's, for the same reason. A failing-first test for
`code=../../public/x` has to run against the **unfixed** handler, which would then really write
`<base>/public/x.json` and copy six PHP files into `<base>/public/x` — in the working tree of a real
repository. A mutation that removes the validation does the same thing to a route test on fixed
code. There is no run order in which both are safe.

So: the path arithmetic is resolved against a disposable root, the wiring is pinned **structurally**
(comment-stripped via `StripsSourceComments`, and scoped to `storeLanguage()` alone — unscoped it
would answer "yes" for a handler that validates nothing, because `destroyLang()` in the same file
already calls `safeSegment`), and the codes driven through the route are ones whose pre-fix writes
land *inside* `resources/lang` and are removed again by `tearDown()`.

---

## 5. Three test gaps the same review found in this slice's own predecessor, all closed

1. **Finding 4/11: no test drove the `LIKE` query.** The only coverage of the user-reassignment
   line was a source-position assertion, so — in the reviewer's words — "even a `'LIKE'`→`'='`
   mutation would not have been caught". That is the mutation that **was the fix**. Closed with
   three tests that create `User` rows and assert on `users.language`, and four mutations.
2. **Finding 10/11: the "a refused code returns" assertion was vacuous.** It bounded its search
   from the first `catch (PathNotConfined` all the way to the recursive delete — a span containing
   **three** `return redirect()` statements, so it passed with the return deleted from the one
   branch it existed to pin. It now brace-matches the catch block and requires exactly one return
   inside it. **Mutation-proved**: deleting that return now fails the test.
3. **Finding 9/11: every line number in the evidence document's census table was stale.** The
   review found the `ModuleController` row citing `:536` for a method at `:504`. Re-measuring found
   two more rows the review had passed as correct, both moved by later commits in this same slice.
   Corrected, dated, and the re-derivation command is now in the document — because nothing in CI
   reads prose, so it rots silently.

---

## 6. Recorded, not fixed

- **`chmod($dir, 0777)`** makes a directory of `.php` files world-writable. Pre-existing, not what
  this slice was opened for, and tightening it could break deployments where the web user differs
  from the user that ran the request. **For the owner**, not silently left.
- **`storeLanguageData()`** writes caller-supplied content to a path named by its route segment,
  refusing only emptiness, and **`manageLanguage()`** `include`s every file in a directory named by
  its route segment. Both were raised by the same review (findings 16 and 15 of 23 before dedup;
  neither survived into the final eleven, so neither is carried here as confirmed). They are the
  remaining doors of this family and are **not** closed by this change.
- **Review finding 3/11 — a non-conforming language becomes undeletable.** Real, and now
  bounded: the producer is this create door, which is closed, and the shipped manifest holds
  **sixteen keys, every one of them `[A-Za-z0-9_-]+`** (measured). So no undeletable entry exists
  today, and none can be minted through the product. A pre-existing one would be removable by
  editing `resources/lang/language.json`, a tracked repository file. An escape hatch in the handler
  was considered and rejected: it would have to accept exactly the values the traversal needs.
