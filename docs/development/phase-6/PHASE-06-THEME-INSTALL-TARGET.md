# Phase 06 — a theme archive chose its own install directory

**Status: FIXED.** And the finding is **narrower than the Phase 06 sweep recorded** — the correction
is as much the point of this note as the fix.

Directive item 2(C): *"theme installation/upload must validate everything BEFORE writing or
extracting into executable application locations."*

---

## 1. What the sweep said, and what is actually true

**"Theme installation extracts before validating" is REFUTED.**
`InstallAddonRequest::archiveRejectionReason()` runs **before** any extraction and is substantial:

- normalises `\` to `/` so Windows-built archives are checked too;
- refuses absolute paths, drive letters (`C:`), `..`, a `../` prefix, `/../` anywhere, a `/..` suffix;
- refuses by name `.htaccess`, `.htpasswd`, `.user.ini`, `.npmrc`, `.netrc`, `web.config`, `php.ini`
  and anything starting `.env`;
- enforces an **extension allowlist**, with documented exemptions for plain dot-entries (`.gitkeep` —
  63 of them ship in `packages/workdo`) and extension-less files that really occur in addons.

Authorization is asserted **twice** — in `InstallAddonRequest::authorize()` and again at the top of
`ThemeInstall()` — deliberately, because SEC-F5 records that relying on the route group alone is what
failed in SEC-F2. A previous slice did this work.

**"Uploaded PHP renders on every storefront using that theme" is true and is NOT a defect.**
`ALLOWED_ENTRY_EXTENSIONS` contains `php` and `blade.php` because a theme **is** Blade code.
Installing a theme means installing code, by design, and only `manage-platform-addons` may do it.

## 2. What survived

`ThemeInstall()` called:

```php
$zip->extractTo(base_path('themes/'));
```

**before deriving any name.** Traversal was already blocked, so nothing escaped `themes/` — but
nothing checked whether the archive targeted a directory that **already held an installed theme**. An
archive whose entries read `<installed-theme>/…` replaced that theme's files in place, and every
store on it rendered the replacement. The archive, not the operator, picked the target.

**Severity:** operator-reachable, not a privilege escalation — the same disposition as the
module-removal door. Worth closing because one operator action should *add* a theme, not silently
replace another.

## 3. The fix is one deletion, and that is why

Read the rest of the method: the archive is extracted a **second** time into
`themes/tmp_<uniqid>`, and the **temp copy is what everything downstream uses** —
`scandir($tempPath)` finds the root folder, `moveExtractedFiles($tempPath, …)` moves the files, and
the destination is `$extractPath`, derived from the uploaded **filename** through
`safeDirectoryName()`. **Nothing between the two extractions read a byte the first one wrote.**

So the premature extraction contributed nothing to the outcome and everything to the side effect.
Deleting it closes the window and leaves the install behaving exactly as before.

## 4. Evidence

**3 mutations: 2 CAUGHT, 1 SURVIVED — and the survivor is the confirmation, not a gap.**

| Mutation | |
|---|---|
| restore the premature extraction → the overwrite test | **CAUGHT** |
| restore it → the structural pin (exactly one `extractTo`, the temp one) | **CAUGHT** |
| restore it → does the install **control** still pass? | **SURVIVED** |

That third one is the evidence the deletion is safe: with the line restored, a theme still installs
correctly, which is what "the install never depended on it" means. Had the control **failed**, the
deletion would have broken installation and the fix would have been wrong.

```
tests/Feature/Addons/   4 passed (14 assertions)
Unit suite              6,166 passed, 1 skipped (37,442 assertions)
Contract suite             45 passed (182 assertions)
themes/ before and after every run   IDENTICAL, 3 entries
```

Every directory the test touches is one it created: `themes/` holds the real shipped themes, so the
fixture uses `zz9`-prefixed names and `tearDown` removes exactly what `setUp` did not see. The
"victim" of the overwrite test is a disposable directory the test makes itself — **no assertion names
a shipped theme.**

The structural pin is comment-stripped and scoped to the method, because the new comment **quotes the
deleted line verbatim**: an unstripped search would match its own explanation and pass forever. That
is the hazard CLAUDE.md records as having hit four censuses in one day.

## 5. Recorded, not fixed

`Addon->theme_id` is set from the **unsanitised** `$filename` while the directory uses
`safeDirectoryName($filename)`. For any filename the sanitiser alters, the row and the directory
disagree — and `ThemeDelete()` reads the row by `theme_id` but builds the path with
`safeDirectoryName()`, so it would delete the right directory while matching the row on a different
string. Not reachable as a path escape (both sides sanitise the path), but a real naming
inconsistency. Out of scope for this fix, which is one deletion.
