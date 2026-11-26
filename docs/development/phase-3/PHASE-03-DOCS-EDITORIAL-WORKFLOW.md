# Phase 03 — the Documentation Centre's editorial workflow and freshness labels

**GAP-107 (`P03-D-04`) and GAP-108 (`P03-D-05`), 2026-11-26.**

This document is two things at once, because the gaps ask for both: it is the **written content
release process** that `P03-D-04` requires, and it is the **evidence** for the engineering that
makes the process enforceable. §1–§5 are the process. §6 onwards is the evidence.

It does **not** restate what the documentation centre already is. That is
`PHASE-03-M6-DOCUMENTATION.md` and the `P03-D-01` traceability row.

---

## 1. What was missing, measured

`P03-D-04`, verbatim: *"Establish editorial ownership… and a content release process."* The
traceability row records that the phrase appears exactly once in the repository — in the plan line
itself — and that front matter supported only `title`, `order`, `summary` and `related`, so there
was *no author, owner, reviewer or status field*.

Measured again at the start of this change, against this tree:

| | at HEAD | now |
|---|---|---|
| Front-matter keys the parser accepts | `title`, `order`, `summary`, `related`, `updated` | + `status`, + `version` |
| Articles that can exist without being public | **0** | any article whose `status` is not `published` |
| Surfaces that show where an article is in the pipeline | **0** | `/platform/documentation`, platform operator only |
| Visible freshness label | the raw ISO string `2026-11-25`, in **both** languages | `25 Nov 2026` / `25 تشرين الثاني 2026` |

The practical consequence of the first two rows: **a half-written Arabic translation could not be
committed for review without being published to every visitor of storesyria.com first.** That is
the distinction `P03-D-04` draws between "working documentation" and "an editorial workflow".

---

## 2. The three states

Declared in the front matter of every article file, in both locales.

```
---
title: Create your account
order: 1
summary: Register as a merchant and confirm your email address.
related: getting-started/choose-a-plan
updated: 2026-11-25
status: published
---
```

| `status:` | Meaning | Public? |
|---|---|---|
| `draft` | Being written. Not offered for review yet. | no |
| `review` | Finished and waiting for the approval §3 names. | no |
| `published` | Approved. | **yes** |

`in review`, `in-review`, `under review`, `needs review` and `reviewing` all read as `review`;
case and surrounding whitespace do not matter. The vocabulary lives in exactly one place,
`app/Support/Docs/DocumentationStatus.php`, and nothing else in the application may decide what
"published" means.

### The two defaults are different, and the asymmetry is the design

- **No `status:` key at all → `published`.** Such a file predates the field. The corpus was 32
  published articles before it existed, and a field whose introduction removes 32 working help
  pages from production cannot be introduced. This is not merely convenient: it is **load-bearing
  and measured**. `tests/Feature/Platform/PlatformDocsBilingualTest::an_untranslated_article_is_
  served_in_english_and_says_so` writes a fixture article with no status and asserts HTTP 200;
  flipping the default to `draft` turns that test red (M8 in §8).
- **A `status:` nobody can read (`status: publishd`) → `draft`.** The author plainly meant to say
  something and we cannot tell what. The only safe reading of an unintelligible publication state
  is "not approved yet".

**Neither default is a loophole**, because `tests/Unit/Ui/DocumentationEditorialStatusTest`
requires every shipped article file to declare an explicit, *recognised* status, in both locales,
with the two agreeing — and it runs in the Unit suite, which is a CI gate. The runtime is lenient
in the direction that keeps readers served; CI is strict in the direction that keeps the workflow
real.

---

## 3. The release process

### 3.1 Roles

| Role | Who | What they do |
|---|---|---|
| **Documentation owner** | **NOT YET NAMED — owner decision, see §7** | Owns the corpus. Decides what is written, approves the move to `published`, and is the person a dispute about help content goes to. |
| **Drafter** | anyone with repository access | Writes or edits an article in both locales. |
| **Arabic reviewer** | **NOT YET NAMED — owner decision, see §7** | A native Arabic reader. Approves the Arabic text of an article before it is published. |
| **Approver** | the documentation owner | Merges the change that sets `status: published`. |

The two unnamed rows are deliberately blank. `P03-D-04`'s own traceability row records
`Responsible: owner (ownership) + phase 03 (process doc)` — the process is engineering's, the
names are the owner's, and inventing a name here would be inventing an authority.

### 3.2 The steps

1. **Draft.** Create or edit `resources/docs/en/<category>/<nn>-<slug>.md` **and** its Arabic twin
   `resources/docs/ar/<category>/<nn>-<slug>.md`. Set `status: draft` in both, and set `updated:`
   to the day of the edit. An article that exists in one language only is a translation gap the
   editorial screen shows in red.
2. **Offer for review.** Change both files to `status: review` and open the change for review.
   Neither file is public at any point in steps 1–2.
3. **Review the Arabic.** The Arabic reviewer opens `/platform/documentation` (platform operator
   only), finds the row, and follows its **Preview** link. The preview renders the article through
   the *same view the reader gets*, with a notice saying it is not published. Reviewing the Arabic
   means reading it rendered, right-to-left, not reading the Markdown.
4. **Approve and publish.** The documentation owner sets `status: published` in both files and
   merges. **The transition is the merge.** The article is public on the next deploy.
5. **Revisit.** Any later edit sets `updated:` to the day of the edit. That is what the reader's
   freshness label reports.

### 3.3 Why there is no button

The screen at `/platform/documentation` is **read-only on purpose** and
`tests/Unit/Ui/DocumentationEditorialScreenContractTest` fails if a form, a button or a filesystem
write appears in it. Three reasons, in order of weight:

1. **The transition is already a better record than a button could write.** A merge has an author,
   a timestamp, a diff and a reviewer. A `status` column has a value.
2. **A web write would be lost.** The corpus is files in the repository; a button rewriting
   `resources/docs/*.md` writes to a container filesystem that the next deploy replaces. The
   approval would vanish with no trace, which is a worse workflow than none.
3. **It is why the content is in the repository at all.** `DocumentationLibrary`'s own docblock
   sets this out: prose in a diff can be reviewed, searched and asserted; prose in a row cannot.

If the owner later wants in-app authoring, that is a product decision with a database behind it,
and the contract test is the right place for somebody to discover the decision was made.

---

## 4. Who can see an unpublished article

**The platform operator, and nobody else.** The gate is `PlatformOperatorOnly` on both routes *and*
`UserPolicy::isOperator()` inside both handlers.

**It is identity and not a permission name, and that is the substantive choice.** A merchant
creates their own roles through the product, so any permission name is substantially
self-assignable; the documentation centre is the *platform's* own published content and an
unpublished article is the platform's unreleased work. `platform/analytics`, `platform/resources`
and `platform/revenue` take the same gate for the same reason, and this screen joins them.

It also could not have been written as a permission check even if the shape were right:

- `@can()` and `$user->can()` are **always false** here (`permissions_as_gates => false`), so that
  gate refuses everybody including the operator;
- `isAbleTo()` **alone** refuses the platform operator, because there is no super-admin bypass.

Both are driven in `tests/Feature/Documentation/EditorialWorkflowGateTest` and asserted in
`tests/Unit/Ui/DocumentationEditorialScreenContractTest` (M3 and M5 in §8).

**The consequence, stated rather than hidden: the operator cannot delegate documentation review to
a non-operator user today.** Doing so needs a real permission seeded in
`app/Models/Utility.php::$arrPermissions` — a gate naming an unseeded permission refuses everybody
— and seeding one is a decision about who may read unreleased platform content. §7 carries it as
an owner question.

---

## 5. What a reader sees: the freshness label

Every article carries `updated:`, an ISO date, and the article page renders it **in the reader's
own language**:

```html
<p class="ss-ps-help">
    آخر تحديث:
    <time datetime="2026-11-25"><bdi>25 تشرين الثاني 2026</bdi></time>
</p>
```

- **`datetime` stays ISO** — it is the only part a machine reads.
- **The visible text is localised** through `App\Support\TimelineMoment::formatDate()`, the helper
  the customer timeline already uses. It asks for **`ar_SY` and not `ar`** on purpose: the generic
  Arabic locale gives the transliterated Gregorian names a Syrian merchant does not write
  (`نوفمبر`), the Syrian variant gives the Levantine ones (`تشرين الثاني`).
- **`<bdi>` replaced the `dir="ltr"` that stood here.** Forcing LTR was right for `2026-11-25`, a
  run of neutral characters the bidi algorithm is otherwise free to reorder inside an Arabic
  paragraph. It is wrong for `25 تشرين الثاني 2026`: under an LTR base direction the two Arabic
  words form one right-to-left run and display in the wrong order against the digits. `<bdi>`
  isolates the value and resolves its direction from its own first strong character.

The previous implementation printed the ISO string as the visible text in both languages and
justified it in a comment: *"a localised month name would need Arabic calendar data this
application does not ship"*. **That is false in this repository** — Carbon ships Arabic month
names, and `TimelineMoment` three directories away was already using them. The help pages of a
product called Store Syria printed their dates in no language at all.

### The release label is plumbed and deliberately unused

`P03-D-05` offers a choice: *"a version OR last-reviewed field"*. The last-reviewed half shipped on
2026-11-25. The release half is built as **optional plumbing with no value**:

- `version:` parses, is capped at 40 characters, and renders beside the date when an article
  declares one;
- **no shipped article declares one**, and `tests/Unit/Ui/DocumentationReleaseLabelTest` records
  that as a measurement with a message, not as a requirement;
- the same test re-measures the reason: `composer.json` carries no `version`, `config/app.php`
  carries none, and **nothing in this application numbers a release**. A release label would have
  had to be invented, and an invented release number on a help page looks authoritative while
  meaning nothing.

So the day the owner names a release scheme, it is a front-matter edit and not a code change. §7
carries the question.

---

## 6. The strings this change introduced

**Seven**, and they are not yet in the language files: the agent that wrote this was not permitted
to edit `resources/lang/en.json` or `resources/lang/ar.json`, because concurrent appends to those
two files lose each other's keys. Until they are written, each renders in **English** on an Arabic
screen — `__()` returns its key, and the key here is the English text.

`tests/Feature/Documentation/EditorialStringsAreBilingualTest` is **red until they are added**, by
design, and carries the same Arabic so the two cannot drift.

| `en.json` key (value = the key) | `ar.json` value |
|---|---|
| `Draft` | `مسودة` |
| `In review` | `قيد المراجعة` |
| `Not translated` | `غير مُترجَمة` |
| `Documentation editorial` | `تحرير الوثائق` |
| `Applies to version` | `ينطبق على النسخة` |
| `Every help article and the state it is in. Only published articles are visible to readers; an article changes state when that change is reviewed and deployed.` | `كلّ مادة مساعدة والحالة التي هي فيها. المواد المنشورة فقط هي التي يراها القرّاء؛ وتتغيّر حالة المادة عندما تُراجَع تلك التغييرات وتُنشَر.` |
| `Every documentation article, with its editorial state in each language.` | `كلّ مادة في الوثائق، مع حالتها التحريرية في كلّ لغة.` |

**Why only seven.** The surfaces were built out of keys both files already carry — `Last Updated`,
`Status`, `Category`, `Title`, `Version`, `Preview`, `Published`, `Not set`, `Not published yet`,
`Nothing published yet` — and the per-language column headers print each language's **own endonym**
(`English`, `العربية`) rather than a translated noun, which is both the better convention and two
keys nobody has to write.

**One measured subtlety.** `__('Draft')` already answers `مسودة` today, but **not from
`resources/lang/ar.json`**: `packages/workdo/EMailBox` and `packages/workdo/LynomiaChat` each ship
`"Draft": "مسودة"` in their own `ar.json`, and those are merged into the JSON namespace. The
Arabic therefore appears by accident of a vendored module's translation file, which an addon change
could remove. The row above is still owed.

---

## 7. Owner questions

Three, none of them engineering work.

1. **Who is the documentation owner, and who reviews the Arabic?** §3.1 defines both roles and
   leaves both names blank. `P03-D-04`'s traceability row already assigns ownership to the owner.
2. **Does the product have releases to name?** If yes, `version:` is live plumbing waiting for a
   value and §5 says how to use it. If no, the date is the whole of `P03-D-05` and the register
   row should say so rather than leaving the clause open.
3. **May documentation review be delegated to a non-operator user?** Today it cannot (§4). Doing so
   needs a permission seeded in `Utility.php`, which is a decision about who may read unreleased
   platform content.

---

## 8. Evidence

### 8.1 Failing first

| Test file | Against the code before the change | After |
|---|---|---|
| `tests/Feature/Documentation/ArticleFreshnessLabelRendersPerLocaleTest` | **3 failed**, 2 passed | 5 passed |
| `tests/Unit/Ui/DocumentationEditorialStatusTest` | **37 failed**, 1 passed | 49 passed |
| `tests/Feature/Documentation/EditorialWorkflowGateTest` | **8 failed**, 6 passed (the 6 were the public-gate half, already implemented when the file was written) | 16 passed |
| `tests/Unit/Ui/DocumentationReleaseLabelTest` | 4 failed with the parse reverted; 2 failed with the render reverted | 8 passed |

The 2 that passed in the first row are the premise and the label test, which is the correct shape:
the label `Last Updated` already existed in both language files, and only the *formatting* was
wrong.

### 8.2 Mutations — nine applied, nine caught

| # | Mutation | Caught by |
|---|---|---|
| M1 | The public read methods read `all()` again, so drafts are public | 4 tests: the article URL, the index, the category page and **search** |
| M2 | `PlatformOperatorOnly` removed from **both routes** | *nothing* — and that is the point: all 16 still passed, which is what proves the in-handler check is load-bearing rather than decorative |
| M3 | On top of M2, `UserPolicy::isOperator()` replaced by `strtolower(trim(...))` | the `' super admin'` leading-space case, **and** the source census |
| M4 | On top of M2, the handler check removed entirely | merchant, self-granted-permission merchant, staff, lookalike |
| M5 | `isAbleTo('Manage Documentation')` instead of the operator test | 5 tests — every operator case fails, which is the "no super-admin bypass" trap |
| M6 | `DocumentationStatus::isPublic()` returns `true` for every state | 8 tests across both files |
| M7 | The release label removed from the view and left only inside a `{{-- --}}` comment | 2 tests — the censuses strip comments |
| M8 | `WHEN_UNDECLARED` flipped from `published` to `draft` | `PlatformDocsBilingualTest` (an existing test this change may not edit) **and** the default's own pin |
| M9 | `<bdi>` removed from the localised date | the bidi pin |
| — | `instanceof User` removed from the handler's principal check | a `Customer` whose `type` column says `super admin` was served **HTTP 200** |

The last row is a finding of the review in §9 rather than a planned mutation.

### 8.3 Rendered evidence, both languages, both themes

Captured by driving real requests through the application and rendering the resulting HTML in
Chromium at 1280×1000. Measured from the DOM, not from the source:

| page | `lang` / `dir` | `<time>` text | `datetime` | worst contrast of the new elements |
|---|---|---|---|---|
| article, en, light | `en` / `ltr` | `25 Nov 2026` | `2026-11-25` | 4.71:1 **AA** |
| article, en, dark | `en` / `ltr` | `25 Nov 2026` | `2026-11-25` | 5.78:1 **AA** |
| article, ar, light | `ar` / `rtl` | `25 تشرين الثاني 2026` | `2026-11-25` | 4.71:1 **AA** |
| article, ar, dark | `ar` / `rtl` | `25 تشرين الثاني 2026` | `2026-11-25` | 5.78:1 **AA** |
| editorial, en, light/dark | `en` / `ltr` | `25 Nov 2026` | — | 5.58:1 **AA** |
| editorial, ar, light/dark | `ar` / `rtl` | `25 تشرين الثاني 2026` | — | 5.58:1 **AA** |
| draft preview, en, light/dark | `en` / `ltr` | `20 Nov 2026` | `2026-11-20` | 4.71:1 **AA** (notice 14.67:1) |
| draft preview, ar, light/dark | `ar` / `rtl` | `20 تشرين الثاني 2026` | `2026-11-20` | 4.71:1 **AA** (notice 13.08:1) |

The `<time>` element's computed direction follows the page in every row and `<bdi>` is present in
every row. The Arabic draft notice renders as `لم تُنشر بعد الوضع: مسودة`.

**One thing the capture found that is NOT this change's.** `layouts/app.blade.php:59-66,85` takes
the admin document's `lang` and `dir` from `users.language` and the `SITE_RTL` **setting**, not
from the active locale — so an operator whose own row says English sees Arabic strings inside
`lang="en" dir="ltr"`. That is the asymmetry `app/Services/DocumentDirection.php` already
documents and reports to the owner; it affects every admin screen equally and nothing here
changes it.

---

## 9. Independent review

**The `tenant-authz-reviewer` subagent could not be launched: no agent-launching tool was available
to the session that made this change.** The eight defect shapes in `.claude/agents/
tenant-authz-reviewer.md` were therefore walked by hand, with measurements. **The review should be
re-run properly before merge.** What it found:

| Shape | Verdict |
|---|---|
| 1 — the guard-class trap | **One finding, fixed.** No handler here falls back to request input, but `UserPolicy::isOperator()` takes `$actor` untyped and reads `$actor->type`, and `customers` **has** a `type` column with `'type'` in `Customer::$fillable`. Removing the `instanceof User` check and driving `actingAs($customer, 'customers')` returned **HTTP 200** with the platform's unpublished content. Fixed by requiring an `App\Models\User`; pinned by `test_a_customer_carrying_the_operators_type_is_refused`. **Hardening, not a live hole**: reaching this route as a `Customer` needs the default guard to be `customers`, and these routes sit in the `web` group with `auth` and no `custom.auth`. |
| 2 — a tenant predicate from caller input | **Clean.** The two path segments are looked up as **keys in an array built from the filesystem**, never concatenated into a path, so `..%2F..%2F..%2Fcomposer` is simply absent and 404s (driven). |
| 3 — relying on the global scope | **Not applicable, verified.** The change reads no Eloquent model and no tenant data; the corpus is files. |
| 4 — checks that refuse the operator or never fire | **Deliberate deviation, recorded.** The gate has an operator limb and **no permission limb**, because the screen is operator-only like its three neighbours (§4). `@can`, `Gate::allows`, `->can(` and `isAbleTo(` are all asserted ABSENT from the controller. |
| 5 — credential and token boundaries | **Not applicable.** No password, email, token or session write. |
| 6 — order, payment, account ownership | **Not applicable.** No money path, no `Order`, no encrypted id. |
| 7 — API and route handling | **Clean, measured.** `platform/documentation` is declared once (grep), does not collide with the earlier public `platform/docs/{category}` patterns, and resolves to this controller for an operator (driven). A guest is **redirected, never 401** — asserted, because repo B maps any 401 to a forced logout. |
| 8 — tests that cannot fail | **Every claim has a mutation in §8.2.** Two honest caveats: (a) `test_a_preview_of_something_that_does_not_exist_is_a_404` passed before the routes existed and is meaningful only paired with the preview test that proves the route resolves; (b) M1 did **not** redden `test_the_library_exposes_the_status_on_every_article_it_indexes`, because every shipped article is published — the Feature fixtures are the control there, not the corpus. |

---

## 10. What is complete and what is not

**Complete**

- `P03-D-05`'s freshness half: localised per locale, both directions, both themes, contrast-checked.
- `P03-D-04`'s process: written (§3), with roles, steps and the reason there is no button.
- `P03-D-04`'s enforcement: three states, only `published` public, across all four read paths.
- `P03-D-04`'s observability: the operator's editorial screen and the draft preview.
- The CI controls that keep all of it honest: explicit status on every file in both locales,
  locale agreement, and the gate's own tests.

**Not complete**

- **The documentation owner and the Arabic reviewer are not named** (§7 q1). The process has roles;
  the owner has the names.
- **`P03-D-05`'s release clause carries no value** (§7 q2). The plumbing is built and unused.
- **Seven translations are owed** (§6). Until they are written, seven strings render in English on
  an Arabic screen and `EditorialStringsAreBilingualTest` is red.
- **Delegation to a non-operator is impossible** (§7 q3).
- `P03-D-02`'s troubleshooting category and role-based how-tos are **out of scope here** and remain
  as GAP-105 records them. This change adds the workflow, not articles.
