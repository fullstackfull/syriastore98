# P2-05-5 — staff and roles on the design system

Sixteen screenshots of `/user-list` and `/roles`, taken by
`docs/development/phase-2/tools/shoot-staff-roles-live.cjs`: both screens in all four
configurations (English LTR and Arabic RTL × light and dark) at **two viewports**, 1440 and 390.
Invented fixture data only (`database/seed-p2dev.php`) — no real person, credential or order
appears in any of them.

**140 cases, 140 passed, 0 failed.** The harness is not a camera; these are what it photographed
on a clean pass. `check-row-actions-live.cjs` separately reports **348/348** with these two
screens added to it.

## Why `/user-list` and not `/users`

`/users` renders a **card grid** with no table at all. Pointing a row-oriented harness at it would
meet the "has real data rows" premise with zero and skip every assertion below it — the F102/F103
shape, where a clean run was produced over a screen with nothing on it. `/user-list` is the
DataTable that renders `users/action.blade.php`, which is the partial this slice converted.

## What to look for

**THE ROW ACTIONS.** Five controls on the staff list, none of which had an accessible name at
runtime. The markup looked *more* careful than usual — it pre-set `data-bs-original-title` as well
as `title` — and neither is a name: the first is the tooltip plugin's own storage, and the second
is blanked by that plugin on initialisation. Each now names the **user** as well as the verb.

**THE GATES, which do not agree with each other.** Four handlers guard those five controls and
they use four different rules — `Edit User`, `Delete User`, and the **policies** `resetPassword`
and `update`. A single blanket gate would have been wrong for three of the four. Each control now
mirrors its own handler.

**THE PERMISSION MATRIX** on the create-role modal: 13 of 59 labels pointed at ids that do not
exist, because the label's `for` was built from `$module` while the checkbox id used
`strtolower($module)` — and HTML id matching is case-sensitive. Those are the labels for the
permission **groups** a merchant clicks to build a role. `roles/edit.blade.php` already had the
`strtolower()`; only the create copy had drifted. Now 0 of 59, with edit independently measured at
0 of 93.

**THE PERMISSION CHIPS** on the roles list measured **2.59:1** — white on the host brand green,
the same figure P2-04-4 and P2-05-3 recorded — and every one was an `<a href="#">` that went
nowhere, so assistive technology announced "link" up to eleven times per row. Fixed by correcting
the semantics rather than the colour: they are the non-interactive chips they always were.
Recolouring the fake links would have cleared the contrast assertion and left the page full of
them.

**THE AVATAR CELL** was `<a><img … id="blah3"></a>`: an anchor with no `href` and no text, an id
emitted once per row, and an image with no `alt`. Three defects in one cell, all three found by
the sweep.

## What the harness fails on, beyond taking a picture

Each is a premise that, unmet, makes the image misleading rather than merely empty:

1. the browser is on the screen the filename claims;
2. the **server** sent the stylesheet the combination asked for;
3. the lists rendered real rows;
4. **every `for` resolves and no id is emitted twice** — the defect family this slice met four
   times, and the one a screenshot cannot show: a broken `for` looks exactly like a working one;
5. every visible control has an accessible name, resolved in accname order with `title` **last**
   and read from the **live** attribute;
6. AA contrast over the **effective** background, with alpha compositing;
7. **no horizontal page overflow at 390px**;
8. no Phase 02 string renders in English on the Arabic run;
9. no uncaught page error and no 500 but the known MySQL-only dashboard chart.

One duplicate id is declared by name rather than waived by pattern: `modelCommanModelLabel`, which
`layouts/app.blade.php` emits twice because it renders two shared modals. That is the host's
markup on every admin page. Listing the exact id keeps the check biting on everything else,
including a **new** duplicate in the host.

## What is NOT claimed

The fixture needed three repairs before any of this meant anything, and each would otherwise have
produced a clean-looking result: 0 staff users and 1 role (an empty table satisfies every per-row
assertion); the staff write permissions absent, so the newly-gated controls correctly vanished and
the harness reported 0 actions per row; and `is_enable_login` never applied, because
`firstOrCreate()` does not read column defaults back into the model instance — the attribute was
null in memory while the row carried the default, so the guard comparing against it was always
false. Caught by querying the raw table rather than trusting the seeder's own output.

`users/profile.blade.php` and `users/user_info.blade.php` are converted but are **not** in these
images: the first is a page the harness does not visit, the second is a modal. Their labelling is
covered by `UserInfoDisclosureTest`, which asserts against the rendered modal that every `for`
resolves and no id repeats.
