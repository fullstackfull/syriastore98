# Phase 2.5 — the owner's visual review, corrected

**Review received:** 2026-11-12 · **Branch:** `claude/vibrant-bell-12tquy` ·
**Viewer:** `PHASE-2.5-OWNER-CORRECTIONS-VIEWER.html` (before and after, 14 surfaces, 112 captures)

The owner reviewed the 96 captures behind the 24 PREMIUM VERIFIED surfaces, accepted 12, and asked
for 12 bounded corrections plus one shared footer correction. All thirteen are done. **No design is
marked approved**, here or anywhere else.

---

## 0. The finding that shaped everything else

**Two of the thirteen were photographs, not defects.** Both were already correct in the source and
only wrong in the committed captures.

| reported | actual state |
|---|---|
| Tag list: empty Action column | **Renders Edit and Delete today.** The committed capture (2026-11-06) predates the dev fixture gaining `Edit Tag` / `Delete Tag`. Cropping the same region from the committed capture and from a fresh render side by side settled it in one image. |
| "2026 Laravel" in the footer | **Source already correct and guarded.** `.env.example` was repaired on 2026-11-06 at 12:43 and `MerchantFooterBrandTest` pins it (3 tests). The wave-33 captures were taken at 17:00 the same day, while the running dev `.env` still lagged. Live render reads "2026 Store Syria" in all four combinations. |

So each of the remaining eleven was **reproduced on a running page before anything was changed**.
Changing code to repair a photograph would have been the worse outcome, and in two cases it was the
outcome that was available.

**The footer's blast radius, measured rather than assumed.** Only two evidence folders hold
full-page captures at all (`wave-33`, 36 files; `pkg-b-pages`, 28). Hashing the footer band of all
64 separates them cleanly: every `pkg-b-pages` capture already read "2026 Store Syria". Of the
wave-33 set, only short pages show a footer at all — the rest are taller than the viewport — and the
two that do are **Brand list and Notifications**, which are exactly the two the owner flagged.

---

## 1. The twelve corrections

| # | Surface | What was actually wrong | Fix |
|---|---|---|---|
| 1 | Brand create (modal) | Never converted to the design system, so its Name field was a raw Bootstrap control the vendor dark stylesheet painted navy **inside the host's deliberately-white dialog**. Placeholder was a bare English literal. | The documented conversion (`category/create.blade.php:28`), plus `__()` on the placeholder |
| 2 | Testimonial list | `شهادة` is a *certificate*; Avatar was the transliteration `الأفتار`; Rating was `تصنيف` ("classification") | `شهادة عميل`, `الصورة الرمزية`, `التقييم`. Merchant-entered content untouched |
| 3 | Product grid | Printed `'has variant' : 'no variant'` as bare English while `ProductDataTable:63` — the *table* view of the same data — already used `__()`. The translation existed and was never reached | `__()` on both arms |
| 4 | Tag list | (see §0) The column header was declared **unconditionally** while the cells were `@permission`-gated | Declared only when the user holds at least one of the two permissions |
| 5 | Product label list | Breadcrumb `__('Label')` → `العلامة` under a page titled `__('Product Label')` → `ملصق المنتج` | Breadcrumb now uses the page-title key |
| 6 | Flash sale list | No `editColumn` at all: it printed the stored value, and `text-capitalize` dressed `flat` up as a label "Flat" | Display mapping at render time; stored values untouched |
| 7 | Category list | `Trending` → `اتجاهات` ("directions"); the cell read Yes/No, so Arabic showed a bare `لا` | `رائج`; the cell names the state |
| 8 | Category create (modal) | A **second key**: the modal used `Parents Category` (`فئة الوالدين` — a child's guardians) while the list used `Parent Category` | Both read `الفئة الرئيسية` |
| 9 | Attributes list | `Slug` → `السناجب` — *the squirrels*, the animal. `Terms` → `الشروط` (contract conditions) | `المعرّف`; `القيم` via a **new key** (see §2) |
| 10 | Users / staff list | `Avatar` → `الأفتار` | `الصورة الرمزية` |
| 11 | Own profile | Avatar 404; active tab **2.09:1** in dark | A committed asset; **6.42:1** in all four (see §3) |
| 12 | Roles list | Chips printed raw permission identifiers | `__()` on the **print only**; 181 labels added (see §4) |

---

## 2. The collision that would have broken a different screen

`Terms` looked like another literal mistranslation. It is used twice, and the two do not mean the
same thing:

- `ProductAttributeDataTable:81` — a product attribute's *terms*, i.e. its values
- `front_end/sections/payment_list.blade.php:61` — **"Terms & Conditions"** on the storefront
  checkout, where `الشروط` is exactly right

Translating the shared key to `القيم` would have relabelled the legal consent link "Values". The
attribute column got its own key instead, and its **en.json value is the string `Terms`**, so the
English header is byte-identical to before while Arabic reads `القيم`.

This is the general shape of the risk in this round, and it is why every key was checked for other
call sites before being touched.

---

## 3. Item 11, where the first fix did not work

Two defects on one screen.

**The avatar is a shipped hole, not a fixture gap.** The fallback was
`$profile.'/avatar.png'` — `/storage/uploads/profile//avatar.png`, the slash doubled because
`$profile` already ends in one. Measured 404 in all four combinations, and `/user-list` showed three
more. It cannot resolve from any checkout: `.gitignore` carries `/storage/*`, and `public/storage`
is a **tracked regular file** (mode 100755, vendor's initial commit) whose contents are the literal
string `/var/www/html/Product/EcommerceGo/main_file/storage/app/public` — their build machine's
path, not a symlink. `RegisteredUserController` writes that same path as every new user's
`profile_image`, so a fresh deployment has the same hole. The replacement is committed under
`public/`. **The deeper `public/storage` finding is reported, not fixed** — repairing it changes how
every uploaded image in the application resolves, which is not a visual acceptance correction.

**The active tab took three measurements.**

| | ink | fill | ratio |
|---|---|---|---|
| light | `#ffffff` | `rgb(51,93,168)` | 6.42:1 |
| dark, before | `rgb(120,148,198)` | `rgb(51,93,168)` | **2.09:1** |
| dark, after | `var(--ss-onbrand)` | `rgb(51,93,168)` | **6.42:1** |

Adding white in the design system changed nothing, and the browser's own cascade said why:
`custom.css` lifts every plain anchor inside `.dash-content` to brand ink with `!important`, which
is right for the inactive siblings (4.64:1, unchanged) and wrong for the active one, which the host
puts **on** the brand fill. That arm is (0,6,3) and shouts, so no honest undo arm could beat it. The
fix is an **exclusion at the source** — the same shape as the pagination rule sitting immediately
below it, which excludes `.page-item.active` for the identical reason and says so in its own
comment.

---

## 4. Item 12, and what was deliberately left alone

The chips printed identifiers like `Manage Order`. `__()` wraps the **print** only: the value comes
from `permissions()->pluck('name')` and is printed, never compared, assigned or written, and `__()`
returns its key unchanged when untranslated — so **English renders byte-for-byte as before**.

| | before | after |
|---|---|---|
| rows in `permissions` | 206 | 206 |
| name-set md5 | `53067464661e8444…` | `53067464661e8444…` |
| `permission_role` rows | 126 | 126 |

**206 labels were generated from a 99-word vocabulary, not written out phrase by phrase**, so what
needed human review was 14 verbs and 85 nouns. 14 names whose first word is not a verb were written
explicitly because composition reads badly for them.

**181 added; 24 left alone; 6 repaired.** Every permission name that already had Arabic is *also* a
row-action label elsewhere (`*/action.blade.php`), where the existing singular wording is the right
one — a plural permission-list form would read "Edit the coupons" on a button. Six were repaired
anyway, because they are wrong in **any** context, and took singular forms to suit those buttons:

| key | was | reading | now |
|---|---|---|---|
| `Create DeliveryBoy` | `إنشاء " فتى التسليم "` | literal quote marks, word-for-word | `إنشاء مندوب توصيل` |
| `Delete Menu` | `قائمة الحذف` | "the deletion menu" — reversed | `حذف القائمة` |
| `Edit Page` | `صفحة التحرير` | "the editing page" — reversed | `تعديل الصفحة` |
| `Edit Plan` | `خطة التحرير` | "the editing plan" — reversed | `تعديل الخطة` |
| `Edit Shipping Method` | `طريقة الشحن` | the verb is simply missing | `تعديل طريقة الشحن` |
| `Manage Themes` | `إدارة المواضيع` | "topics", not visual themes | `إدارة القوالب` |

**A ratchet caught the one composition error.** `Manage CMS` came out as `إدارة إدارة المحتوى` —
"Manage Manage Content" — because the noun for `CMS` already carried the verb.
`ArabicDoesNotStutterTest` failed on it, which is precisely the failure mode that test names.

---

## 5. What was verified, and how

**Nothing here rests on reading the code.** Every correction was measured on a running page before
and after.

| check | result |
|---|---|
| `render-matrix.cjs`, 12 routes × 4 combinations | 48 rows, **0 not clean** |
| `shoot-modals.cjs`, every modal it knows | **FINDINGS: 0** |
| `check-dark-form-surfaces-live.cjs` | **20 checks, 0 failed** — identical before and after |
| `check-distinct-surface-captures.py` | no two captures share content |
| `detect-refusal-toast-captures.py` | no capture shows a refusal toast |
| `build-coverage-matrix.py --check` | current: **225 / 24 / 28 / 8 / 10 / 0**, unchanged |
| Unit | **1,641 passed**, 12,229 assertions |
| Contract | **38 passed** |
| `VisualAcceptanceCorrectionsTest` | **27 cases**, 125 assertions, each falsified |

**56 captures were replaced** across three evidence folders, and the 56 they replaced are preserved
under `evidence/owner-review-2026-11-12/before/` — so this round has the before/after pairs the
original acceptance package did not.

**Two ratchets failed on this work and neither was widened.** `DarkPageSurfaceTest` refused a
literal `#ffffff` in a token-only block; the rule now uses `--ss-onbrand`, which `custom.css`
already derives for ink on a brand fill, and is the better answer besides.
`ArabicDoesNotStutterTest` caught the CMS stutter above.

---

## 5b. What independent review changed

`tenant-authz-reviewer` was run over the whole change, with items 4 and 12 named as the two to
check hardest. **Both came back clean: no authorization defect.** It confirmed, by reading the
vendored library rather than taking my word for it, that `@permission(X)` compiles to
`app('laratrust')->hasPermission(X)` (`LaratrustServiceProvider:101-103`), that the one-argument
array form really is OR (`UserDefaultChecker:100-118`), that both the header and the cells resolve
the same `User` on the same guard, and that `__()` cannot reach any authorization decision —
repo-wide there is no `isAbleTo(__(`, no `hasPermission(__(`, no `in_array(__(`, and the role write
path uses permission **ids**, not names.

Five secondary findings. **Four were acted on; one is recorded as accepted.**

| # | finding | action |
|---|---|---|
| 1 | `Edit DeliveryBoy` still read `تحرير " فتى التسليم "` — the literal-quote defect whose twin `Create DeliveryBoy` I had repaired, live on `deliveryboy/action.blade.php` | **Repaired** to `تعديل مندوب توصيل`. An inconsistency in my own repair set |
| 2 | Adding `Manage Tax` / `Manage Shipping` / `Manage Coupon` translated three labels on PartialPayments' settings form and left its other three English — that one form went from all-English to **mixed** in Arabic | **Repaired.** The three keys are used nowhere else, so completing them restores the form rather than widening the change |
| 3 | The Tag tests read only `getColumns()`, so reverting the `dataTable()` guard alone left all four passing | **Pinned.** It leaks nothing — the cells are `@permission`-gated — but an assertion watching one of two halves says less than it looks like |
| 4 | My claim that the other 24 permission keys were left alone was pinned for only 4 of them | **Verified directly** instead: `git diff` on `ar.json` shows exactly **9** changed lines — the 6 repairs, the 2 testimonial terms, and one re-ordered key. Review separately confirmed the 181 additions are a contiguous block with **zero** key collisions, so nothing is shadowed |
| 5 | `editColumn('discount_type')` also reaches the CSV/Excel export, so an export now carries the translated label | **Accepted, recorded.** The stored values are untouched, nothing re-imports flash sales, and `$exportButtonConfig = []` means no export button is rendered on any of the 68 tables. `CategoryDataTable` has exported a translated label since before this round, so changing only flash sale would make it the odd one out |

### One thing the review raised that I could NOT measure

The CSS exclusion also applies to **`plans/stripe.blade.php`** — the plan-purchase Order Summary
screen, whose payment-method sidebar uses the identical
`list-group-item list-group-item-action` anchors inside `.dash-content`, with its own script making
the first active.

It should behave exactly as `/profile` does: the markup carries both classes the design-system rule
names, and the exclusion names the compound. **But it was not rendered, and that is a statement of
fact rather than an inference I am dressing up.** `/plan-stripe/{code}` redirects in the dev fixture
— no payment gateway is enabled — and reaching it means standing up a plan-purchase flow on a
payment path for a cosmetic check on a screen that is not among the 24 and not in the owner's
correction list. A gateway flag was inserted, the route still redirected, and the fixture was
restored to its known-good counts (users=5, products=32, permissions=206, gateway rows=0).

Four vendored module views are in the same class, all behind `module_is_active`. Every *other*
`.active` list-group anchor in `.dash-content` was already excluded by the pre-existing
`:not(.nav-link)` — review checked each one rather than assuming.

---

## 6. Still outstanding, and unchanged by any of this

- **No design is approved.** The 12 corrected surfaces are presented for the owner's decision; the
  12 the owner found acceptable remain unsigned too.
- **No security checklist was signed.** `SECURITY-DIFF-REVIEW: completed` is still absent from
  PR #134 and was not added.
- **The publicly served `.git` metadata and historical credentials on the test host remain
  OWNER-DEFERRED — UNRESOLVED.** Nothing in this round touches them.
- **`public/storage` is a tracked regular file containing a vendor build path**, so no `/storage/…`
  URL resolves from a clone. Reported here; not fixed, because it changes how every uploaded image
  in the application resolves.
- Phase 2.5 is **not production-release-ready**, and Phase 03 remains planning-only.
