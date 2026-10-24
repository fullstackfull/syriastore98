# P1-SEC-28 acceptance 5 — the store-less upload residual, accepted in writing

**Status:** accepted, with one part of it closed rather than accepted.
**Date:** 2026-10-23. **Measured on** this checkout, PHP 8.3.6, by reading and executing
`App\Services\StoredUpload` and `tests/Feature/Security/StoredFilePathSafetyTest`.

The fifth acceptance criterion of P1-SEC-28 reads: *"The files with no store segment are either
given an owner or the residual is accepted in writing."* This is that writing. It states what the
residual is, how far it reaches, what was closed anyway, and why the rest cannot be given an owner
from inside this phase.

---

## 1. What the residual is, exactly

`StoredUpload::resolve()` enforces three rules on a path that came out of the database: literal
paths only (no glob), inside `base_path('uploads')`, and — when the caller's owner id is known — not
another tenant's folder. The third rule reads the tenant from the path:

```php
$segments = explode(DIRECTORY_SEPARATOR, substr($real, strlen($root) + 1));
while (($segments[0] ?? null) === 'uploads' && count($segments) > 1) { array_shift($segments); }
$first = $segments[0] ?? '';

if (ctype_digit($first) && count($segments) > 1 && ! self::mayTouchStoreFolder((int) $first, $ownerId)) {
    return null;                                  // another tenant's folder
}
```

The predicate is `ctype_digit($first) && count($segments) > 1`. **When either half is false, rule 3
adds nothing at all** — it does not refuse, it simply has no opinion. Two shapes fall through:

| shape | example | why rule 3 is silent |
|---|---|---|
| a named folder | `uploads/profile/avatar.png` | `profile` is not a run of digits |
| a file directly in `uploads/` | `uploads/22_1787046827_1000219049.jpg` | one segment, so `count > 1` is false |

That is not a bug in the predicate. **There is no owner anywhere in those paths to compare against.**
A store-segmented path carries its owner; a store-less one carries none, so no amount of care in
`resolve()` can decide whose file it is.

## 2. Which folders those are, on this checkout

```
uploads/
├── 11/ 15/ 16/ 18/ 19/ 2/ 3/      ← store-segmented: rule 3 applies
├── uploads/<store id>/...         ← the doubled prefix; peeled, so rule 3 applies
├── profile/                       ← store-less  (holds exactly one file: avatar.png)
├── receipt/                       ← store-less
├── logo/                          ← store-less  (SettingController writes fixed names here)
├── stylique/                      ← store-less
├── is_cover_image/                ← store-less  (a target directory in host code)
├── 22_1787046827_1000219049.jpg   ← store-less: a loose file directly in uploads/
└── 68_1789477737_1000219049.jpg   ← store-less
```

`uploads/customer_app/store_<id>/` is **not** in this list. It lives under `storage/uploads`, which
is outside `base_path('uploads')`, so rule 2 refuses it before rule 3 is reached. Checked, because
its `store_<id>` segment looks like exactly the case rule 3 would miss.

## 3. Blast radius

For a tenant to use this they need a store-less path to end up in a row that one of the host's
unlink paths reads. Both halves are bounded, and the bound is what makes this acceptable:

**The write side.** `App\Services\StoredPathPolicy` constrains the path columns on `Product`,
`ProductImage` and `Setting` to the three upload roots. A store-less `uploads/...` value satisfies
that, so it can be stored — e.g. a merchant saving `logo_dark = uploads/profile/avatar.png` through
one of the generic settings writers. Those writers are themselves now behind
`ModuleAdministrativeRouteGuard` and `StoreSettingsAccess` (P1-SEC-31, P1-PLT-07), so the caller must
hold the screen's permission in their own store.

**The unlink side.** Pinned by name and count in
`StoredFilePathSafetyTest::test_the_filesystem_removals_in_app_are_exactly_these`. Every unlink of a
*stored* path goes through `StoredUpload`; the rest are a directory helper, two installers unlinking
temp trees they just created, an operator-only language file, and `SettingController`'s three logo
unlinks whose paths are **constants**, not stored values.

**So the worst case is: one tenant, holding a settings permission in their own store, unlinks one
file in a store-less folder belonging to another tenant or to the platform.** It is a destructive
act, not a disclosure: nothing is read, copied or returned to the caller, no row changes, no money
moves. What is lost is an image or a receipt file. The database row that referenced it survives and
renders broken.

## 4. What was closed rather than accepted

One member of the residual is not acceptable, because it has a single named target with
platform-wide reach: **`uploads/profile/avatar.png`**. It is the default `profile_image` written for
every new user (`app/Http/Controllers/Auth/RegisteredUserController.php:123`) and every new delivery
boy (`app/Http/Controllers/Api/DeliveryBoyApiController.php:157`), and it is the only file in that
folder. Unlinking it breaks the avatar of every account that never uploaded one — across all
tenants — from one tenant's own logo save.

`StoredUpload::PLATFORM_DEFAULTS` now refuses it for **every** caller, the platform operator
included, before the owner is looked at. A platform default is not an editable file; replacing it is
a deployment step, not a settings save. The comparison is on the **resolved real path**, so
`uploads/./profile/avatar.png`, `uploads/<store>/../profile/avatar.png` and a symlink to it are all
the same answer.

Measured: `StoredFilePathSafetyTest::test_the_shared_default_avatar_inside_uploads_is_never_unlinked_by_anyone`
(three owners × resolve and delete, plus three traversal spellings) and a positive control,
`test_an_ordinary_file_in_the_same_store_less_folder_is_still_resolvable`, so the rule names one file
rather than blanketing the folder — otherwise a user could never replace their own profile image.
Removal checks: the rule removed → 1 fails; the rule compares the stored string instead of the
resolved path → 1 fails; the rule blankets the folder → the positive control fails. 3 of 3 bite.

The earlier test with a similar name,
`test_a_shared_file_at_the_repository_root_such_as_the_default_avatar_is_never_unlinked`, does **not**
cover this: it uses a decoy at the repository root and ImportExport's bundled image, both of which
are refused by rule 2 for being outside `uploads/`. The file actually at risk is inside it.

## 5. Why the rest is not given an owner here

Three options were considered and each is worse than the residual:

1. **Move the files into an owned namespace.** This is the only change that would really give them an
   owner, and it is forbidden: owner decision **D11** is to leave historical uploads in the tree and
   in history, never to delete, rewrite or force-push them. It would also break every row that
   references the old path, across all tenants, with no way to tell which rows those are without a
   full-table scan of every path column in the live database — which this phase may not touch.
2. **Refuse every store-less path when an owner is known.** Fail-closed, one line, and it breaks real
   journeys: `uploads/profile/` and `uploads/receipt/` hold per-*user* and per-*payment* files, not
   per-store ones, so a user replacing their own profile image and a buyer replacing their own
   receipt would both stop working — silently, on the delete half, after the new file was already
   written and the quota debited.
3. **Carry the owner in the row instead of the path.** Correct, and it is a schema change: a new
   owner column on every table with a path column, backfilled for existing rows whose owner is
   exactly the thing nobody can currently determine. That is GATE F territory and needs an approved
   migration plan.

The proportionate answer is the one taken: close the single platform-wide target, keep the bound on
both the write side and the unlink side, and record the rest here so that the next person to touch
`StoredUpload` knows the predicate's silence is deliberate and measured rather than overlooked.

## 6. What would change this

Any of the following turns the residual back into work:

* a **new host unlink** of a stored path that does not go through `StoredUpload` — the census test
  fails on sight, by file and count;
* a **new platform default inside `uploads/`** — it belongs in `PLATFORM_DEFAULTS`, and the test
  above is the pattern to copy;
* a decision to **relax `StoredPathPolicy`** so a path column can hold something outside the three
  roots, which would widen the write side this acceptance depends on;
* the owner approving **option 3** above, at which point rule 3 can read the owner from the row and
  the residual closes for every shape.
