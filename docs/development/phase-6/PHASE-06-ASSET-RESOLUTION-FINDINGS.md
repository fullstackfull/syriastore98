# Phase 06 — asset resolution: what was fixed, and the two questions that remain

Measured 2026-11-24 while closing the second half of ASSET-LINK (task #198). Everything below was
executed, not inferred. Two items are **fixed**; two are **recorded and deliberately not fixed**,
each for a stated reason.

---

## 1. Three copies of one image, and only one of them ships

`uploads/profile/avatar.png` is the shared platform-default `profile_image`, written into the column
by five first-party writers (`Auth\RegisteredUserController:123` and `:277`, `StoreController:264`,
`DeliveryBoyController:66`, `Api\DeliveryBoyApiController:184`) and named in
`StoredUpload::PLATFORM_DEFAULTS` as a file no caller may unlink.

It exists in this container three times. Only one is in version control:

| path | on disk here | git-tracked | reachable as a URL |
|---|---|---|---|
| `uploads/profile/avatar.png` | yes | **yes** | only if the docroot is the project root |
| `public/uploads/profile/avatar.png` | yes | **no** | `/uploads/profile/avatar.png`, if present |
| `storage/app/public/uploads/profile/avatar.png` | yes | **no** (`.gitignore:48`) | `/storage/uploads/...` via the symlink |

**A correction to an earlier record.** Fixing the `public/storage` symlink (commit `86d2d020`) did
not make the default avatar resolve. The 8,117-byte HTTP 200 measured during that work was served
from the third row — a copy placed by hand in this container, which `git check-ignore` confirms is
ignored. A fresh clone has only the first row. The symlink fix stands on its own for every file that
really is in storage; the avatar was never one of them.

## 2. FIXED — the fallback arm pointed into the ignored tree

Five first-party surfaces rendered the no-avatar arm as `/storage/uploads/profile/avatar.png`, which
resolves into `storage/app/public/` whose **contents** `.gitignore:48` ignores on purpose ("Keep the
storage/ directory TREE in version control while ignoring its CONTENTS"). That is a 404 in every
environment, and was observed as one on all four combinations of the operator dashboard audit.

All five now use `asset('images/avatar-placeholder.svg')`, the committed placeholder the owner's
Phase 2.5 visual review already settled on — its correction records that the replacement "is
committed under public/ for exactly that reason", and the asset was already the first-party fallback
in `users/profile.blade.php:97` and `UserDataTable:43`. **No new decision was taken**; these five
were stragglers. Only the no-avatar arm changed, so no surface that had an avatar looks different.

Pinned by `VisualAcceptanceCorrectionsTest::test_no_remaining_first_party_surface_points_the_avatar_at_storage`.

## 3. FIXED — `users/index` doubled the path prefix

The worse of the two, because it fires for the **common** case. `profile_image` is **not** empty for
a new account — the five writers above put `uploads/profile/avatar.png` in it — so the fallback never
ran and the other arm did. That arm prepended a prefix the stored value already carries:

```
stored                   uploads/profile/avatar.png
users/index produced     /uploads/profile/uploads/profile/avatar.png     <- 404 under ANY topology
store/index produced     /uploads/profile/avatar.png
```

Now `!empty($user->profile_image) ? get_file($user->profile_image) : asset('images/avatar-placeholder.svg')`,
which makes all five surfaces behave identically. Pinned by
`test_the_users_list_does_not_prepend_a_prefix_the_stored_value_already_has`.

## 4. RECORDED, NOT FIXED — does `/uploads/profile/avatar.png` resolve at all?

With §3 fixed, all five surfaces now request that one URL for a default-avatar account. Whether it
answers 200 depends on the **deployment topology**, which this phase cannot settle:

- if the production docroot is `public/`, it needs `public/uploads/profile/avatar.png` — **untracked**,
  so a fresh clone does not have it and a deploy step must place it;
- if the docroot is the project root, the tracked `uploads/profile/avatar.png` serves it directly.

Three ways to close it, none taken, because each needs an owner or operator answer:

| | what it does | cost |
|---|---|---|
| (a) | a deploy/setup step copying the tracked file under `public/` | a second per-environment step beside `php artisan storage:link` |
| (b) | track `public/uploads/profile/avatar.png` | duplicates a tracked binary; needs the docroot answer first |
| (c) | point the non-empty arm at `images/avatar-placeholder.svg` too | the platform default stops being a photograph of a person and becomes a generic mark — a visual decision |

**What is needed from the owner or operator: the production docroot, and whether a deploy step may
place files under `public/`.** Until then the fix in §3 is still strictly better: one open question
instead of two defects and a question.

## 5. RECORDED, NOT FIXED — `check_file()` throws where `get_file()` is guarded

Found by this work's own test, and it changed the fix. `check_file()` was the first choice, because
`UserDataTable:43` uses it for the same column. It calls `getAdminAllSetting()`, which at
`helper.php:864` reads `Setting::where('created_by', $user->id)` where
`$user = User::where('type', 'admin')->first()` — **unguarded**. On a database with no type-`admin`
row that is a null dereference, reproduced at `helper.php:2019` under a clean `RefreshDatabase`.

So that fix would have traded a broken image for a blank page. `get_file()` carries explicit null
guards for exactly this shape, documented in its own body as "not a test artefact", and is already
used by `dashboard.blade.php` and `users/profile.blade.php` — so §3 uses it.

**`check_file()`'s own exposure is pre-existing and unchanged here**, and `UserDataTable:43` still
calls it. It is recorded rather than fixed because widening this slice into the settings helper is
how a bounded change stops being reviewable. It belongs with the two `get_file()` null guards as a
third sibling.

## 6. The vendored residue, which is not editable

Four references remain under `packages/workdo/`, which must not be edited:
`SpamPrevent/src/resources/views/admin/reply.blade.php`,
`SpamPrevent/src/resources/views/theme/icon_view.blade.php`, and
`Api/src/documentation/{admin,theme}.json`. The two JSON files are documentation examples rather
than rendered UI. The two Blade views belong to a module that loads in no test run, so nothing here
can reach or verify them.
