# Phase 2.5 — visual acceptance package

**For the owner to look at and decide on.** 24 surfaces, each captured in four combinations.

> **This package asks for a decision. It does not record one.**
> Everything in it is *engineering verification*: the running application was driven and
> photographed. **Owner visual approval has not been given and is not implied by anything here.**
> §4 is where you give or withhold it.

---

## 1. What PREMIUM VERIFIED means, and what it does not

`PREMIUM VERIFIED` is the coverage matrix's highest evidence state. It means exactly this:

* the surface was **driven in the running application**, not read in the source;
* it was **captured in all four combinations** — English LTR and Arabic RTL, light and dark;
* those four files **exist and were checked** (the matrix generator refuses a claim whose captures
  are absent, and it refused several during this phase until they were real).

It does **not** mean a designer approved it, and it does not mean you have seen it. That is the
distinction this package exists to put in front of you.

| | who decides | state |
|---|---|---|
| Engineering verification | engineering | **done** — 24 of 24, all four captures present, both integrity gates green |
| **Owner visual approval** | **you** | **NOT GIVEN** |

---

## 2. Why all 24 are web, and where Flutter stands

The owner's directive asked for the package grouped by web and Flutter. **There is no Flutter group,
because no Flutter surface is PREMIUM VERIFIED.** From the matrix's own per-area table:

| area | total | premium | redesigned | blocked | out of scope | pending |
|---|---:|---:|---:|---:|---:|---:|
| Flutter merchant admin app | 51 | **0** | 47 | 2 | 1 | 0 |
| Flutter merchant/employee admin app | 24 | **0** | 16 | 0 | 6 | 0 |

So the 75 Flutter surfaces in scope are carried at `REDESIGNED AND VERIFIED` (63 of them) or are
blocked / out of scope. Saying "0 premium" is not a gap being hidden: `PREMIUM VERIFIED` is a
distinct state used where a surface met the premium bar, and the Flutter work was not assessed
against it. **If you want a Flutter visual acceptance pass, that is a separate request** — the
captures exist, but they are not what this package covers.

---

## 3. The 24, grouped by the state they were found in

Grouping by **audit verdict** rather than by screen area, because what you most need to know when
looking is *how much changed*.

### Found DEFICIENT — the largest visual change  (4)

These four were judged materially below standard before the work. If any surface in this package is going to need another pass, it is most likely one of these.

| surface | route | evidence folder | the four captures |
|---|---|---|---|
| **Brand create (modal)** | `GET /product-brand/create` | `pkg-b-modals/after/` | `brand-create__en_ltr_light.png`<br>`brand-create__en_ltr_dark.png`<br>`brand-create__ar_rtl_light.png`<br>`brand-create__ar_rtl_dark.png` |
| **Coupon detail** | `GET /coupon/{coupon}` | `pkg-b-pages/after/` | `coupon-detail__en_ltr_light.png`<br>`coupon-detail__en_ltr_dark.png`<br>`coupon-detail__ar_rtl_light.png`<br>`coupon-detail__ar_rtl_dark.png` |
| **Testimonial list** | `GET /testimonial` | `pkg-b-pages/after/` | `testimonial__en_ltr_light.png`<br>`testimonial__en_ltr_dark.png`<br>`testimonial__ar_rtl_light.png`<br>`testimonial__ar_rtl_dark.png` |
| **Product list (grid view)** | `GET /product-list → product.list` | `wave-33/after/` | `_product_list__en_ltr_light.png`<br>`_product_list__en_ltr_dark.png`<br>`_product_list__ar_rtl_light.png`<br>`_product_list__ar_rtl_dark.png` |

### Found NEEDS-POLISH — real but smaller changes  (10)

These ten were serviceable and short of premium. The work closed specific gaps rather than reshaping the screen.

| surface | route | evidence folder | the four captures |
|---|---|---|---|
| **Brand list** | `GET /product-brand` | `wave-33/after/` | `_product_brand__en_ltr_light.png`<br>`_product_brand__en_ltr_dark.png`<br>`_product_brand__ar_rtl_light.png`<br>`_product_brand__ar_rtl_dark.png` |
| **Tag list** | `GET /tag` | `pkg-b-pages/after/` | `tag__en_ltr_light.png`<br>`tag__en_ltr_dark.png`<br>`tag__ar_rtl_light.png`<br>`tag__ar_rtl_dark.png` |
| **Product label list** | `GET /product-label` | `wave-33/after/` | `_product_label__en_ltr_light.png`<br>`_product_label__en_ltr_dark.png`<br>`_product_label__ar_rtl_light.png`<br>`_product_label__ar_rtl_dark.png` |
| **Flash sale list** | `GET /flash-sale` | `pkg-b-pages/after/` | `flash-sale__en_ltr_light.png`<br>`flash-sale__en_ltr_dark.png`<br>`flash-sale__ar_rtl_light.png`<br>`flash-sale__ar_rtl_dark.png` |
| **Product question list** | `GET /product-question` | `pkg-b-pages/after/` | `product-question__en_ltr_light.png`<br>`product-question__en_ltr_dark.png`<br>`product-question__ar_rtl_light.png`<br>`product-question__ar_rtl_dark.png` |
| **FAQ list** | `GET /faqs` | `pkg-b-pages/after/` | `faqs__en_ltr_light.png`<br>`faqs__en_ltr_dark.png`<br>`faqs__ar_rtl_light.png`<br>`faqs__ar_rtl_dark.png` |
| **Page-header component** | `partial/component` | `pkg-a-09/after/` | `pageheader__en_ltr_light.png`<br>`pageheader__en_ltr_dark.png`<br>`pageheader__ar_rtl_light.png`<br>`pageheader__ar_rtl_dark.png` |
| **Notifications (the bell's destination)** | `GET /merchant-notifications` | `wave-33/after/` | `_merchant_notifications__en_ltr_light.png`<br>`_merchant_notifications__en_ltr_dark.png`<br>`_merchant_notifications__ar_rtl_light.png`<br>`_merchant_notifications__ar_rtl_dark.png` |
| **Footer + script bundle** | `partial/component` | `pkg-a-09/after/` | `footer__en_ltr_light.png`<br>`footer__en_ltr_dark.png`<br>`footer__ar_rtl_light.png`<br>`footer__ar_rtl_dark.png` |
| **x-ss.stat / x-ss.stats (KPI cell + grid)** | `partial/component` | `pkg-a-10/after/` | `stat__en_ltr_light.png`<br>`stat__en_ltr_dark.png`<br>`stat__ar_rtl_light.png`<br>`stat__ar_rtl_dark.png` |

### Found PREMIUM-ALREADY — verified, not redesigned  (10)

These ten were judged already at standard. **Nothing about them was redesigned**, and that is why they carry no before/after: the work was to DRIVE and CAPTURE them so the judgement rests on the running application rather than on a reading of the code. A reviewer who disagrees with the original "already premium" verdict is disagreeing with the audit, not with a change.

| surface | route | evidence folder | the four captures |
|---|---|---|---|
| **Category list** | `GET /category` | `wave-33/after/` | `_category__en_ltr_light.png`<br>`_category__en_ltr_dark.png`<br>`_category__ar_rtl_light.png`<br>`_category__ar_rtl_dark.png` |
| **Category create (modal)** | `GET /category/create` | `pkg-b-modals/after/` | `category-create__en_ltr_light.png`<br>`category-create__en_ltr_dark.png`<br>`category-create__ar_rtl_light.png`<br>`category-create__ar_rtl_dark.png` |
| **Attributes list** | `GET /product-attributes` | `wave-33/after/` | `_product_attributes__en_ltr_light.png`<br>`_product_attributes__en_ltr_dark.png`<br>`_product_attributes__ar_rtl_light.png`<br>`_product_attributes__ar_rtl_dark.png` |
| **Attribute options (DS pilot screen)** | `GET /attribute-option/{id?}` | `pkg-b-pages/after/` | `attribute-option__en_ltr_light.png`<br>`attribute-option__en_ltr_dark.png`<br>`attribute-option__ar_rtl_light.png`<br>`attribute-option__ar_rtl_dark.png` |
| **Users / staff list (table view)** | `GET /user-list` | `wave-33/after/` | `_user_list__en_ltr_light.png`<br>`_user_list__en_ltr_dark.png`<br>`_user_list__ar_rtl_light.png`<br>`_user_list__ar_rtl_dark.png` |
| **Own profile (WorkDo)** | `GET /profile` | `wave-33/after/` | `_profile__en_ltr_light.png`<br>`_profile__en_ltr_dark.png`<br>`_profile__ar_rtl_light.png`<br>`_profile__ar_rtl_dark.png` |
| **Roles list** | `GET /roles` | `wave-33/after/` | `_roles__en_ltr_light.png`<br>`_roles__en_ltr_dark.png`<br>`_roles__ar_rtl_light.png`<br>`_roles__ar_rtl_dark.png` |
| **Workspace chip (store switcher, sidebar)** | `partial/component; switching posts to POST /change-sto` | `pkg-a-09/after/` | `chip__en_ltr_light.png`<br>`chip__en_ltr_dark.png`<br>`chip__ar_rtl_light.png`<br>`chip__ar_rtl_dark.png` |
| **Dashboard overview region (KPI surface)** | `partial/component (included by GET /dashboard)` | `pkg-a-10/after/` | `kpi__en_ltr_light.png`<br>`kpi__en_ltr_dark.png`<br>`kpi__ar_rtl_light.png`<br>`kpi__ar_rtl_dark.png` |
| **x-ss.attention / x-ss.attention-item** | `partial/component` | `pkg-a-10/after/` | `attention__en_ltr_light.png`<br>`attention__en_ltr_dark.png`<br>`attention__ar_rtl_light.png`<br>`attention__ar_rtl_dark.png` |

---

## 3.1 A second cut: was a change actually MADE? — and where it disagrees with §3

The owner asked specifically to *"identify actual visual changes and distinguish them from screens
that were already premium"*. §3 above groups by the **audit verdict recorded at discovery**. That is
not the same question, and on seven rows the two answers differ, so both are given rather than one
silently replacing the other.

**Measured on the filesystem, and these are hard facts, not judgements:**

| | |
|---|---|
| surfaces | **24** — the coverage matrix and this package name the identical 24, checked row by row |
| capture files | **96 of 96 present** (24 × 4 combinations), every one non-empty and carrying a real PNG magic number |
| `before/` captures | **none exist for any of the 24**, in any of the five backing folders |

**The second cut, by whether a visual change was actually made:** **13 CHANGED, 11 ALREADY-PREMIUM.**

- **CHANGED (13):** Brand list · Tag list · Product label list · Flash sale list · Testimonial list ·
  FAQ list · Brand create (modal) · Category create (modal) · Product list (grid view) ·
  Own profile (WorkDo) · Notifications · Footer + script bundle · x-ss.attention
- **ALREADY-PREMIUM (11):** Coupon detail · Product question list · Page-header component ·
  x-ss.stat / x-ss.stats · Category list · Attributes list · Attribute options · Users / staff list ·
  Roles list · Workspace chip · Dashboard overview region

### The seven rows where the two cuts disagree

| surface | §3 discovery verdict | change actually made? |
|---|---|---|
| Coupon detail | DEFICIENT | no |
| Product question list | NEEDS-POLISH | no |
| Page-header component | NEEDS-POLISH | no |
| x-ss.stat / x-ss.stats | NEEDS-POLISH | no |
| Category create (modal) | PREMIUM-ALREADY | yes |
| Own profile (WorkDo) | PREMIUM-ALREADY | yes |
| x-ss.attention | PREMIUM-ALREADY | yes |

**This disagreement is reported, not resolved.** The two cuts come from different sources — §3 from
the audit verdict recorded when the surface was first reviewed, this one from the task ledger and
the git history of the backing files — and neither was re-derived from the other. Three rows judged
below standard appear to have been captured without a change, and three judged already-premium
appear to have been changed anyway; a verdict revised mid-phase would explain either direction, and
no attempt is made here to say which happened per row.

**Which to use for what.** For *"how much should I scrutinise this screen"*, §3's verdict is the
better guide — it records what a reviewer thought when they looked. For *"is there anything new to
look at"*, this cut is. For the acceptance decision itself the distinction does not change what is
being asked: all 24 are captured, none has a before/, and the owner is being asked to accept the
screens as they stand today.

---

## 4. Before / after — what exists, and what would mislead you

**No `before/` directory exists in any of the five folders backing these 24.** That is a real
limitation and it is stated rather than worked around.

It is also, for most of these surfaces, less of a loss than it sounds, and the reason matters:

* **Ten were `premium-already`.** There is no "before" because nothing was redesigned. A
  before/after would be the same picture twice.
* **Much of what changed on the other fourteen is invisible in pixels.** The repairs in this phase
  were heavily *programmatic*: a `for` attribute that pointed at nothing, a control with no
  accessible name, a label association inside a repeater. A screen that gains an `aria-label`
  photographs identically before and after. **Putting two identical images side by side and calling
  it a before/after would be worse than offering none** — it would suggest nothing changed.
* **Where the change WAS visual it was measured, and the measurement is the honest before/after.**
  Contrast repairs in this phase carry numbers, e.g. `2.66:1 → 0 findings` on a delete control,
  `4.413:1 → 4.655:1` on an alert, `1.04:1 → paired tokens` on a destructive button. A ratio is a
  more reliable before/after than a screenshot of a button.

**What you can ask for.** A genuine pixel before/after is reconstructible for any of the fourteen
changed surfaces by checking out the pre-change view from git and re-driving it. That is real work
(~4 captures per surface) and it was not done speculatively. Name the surfaces you want it for.

---

## 5. What backs these captures

Three integrity checks run over the evidence the matrix cites, and **all three are green**:

| check | what it catches | result |
|---|---|---|
| `build-coverage-matrix.py --check` | a claim whose four captures are absent | **pass** — 295 surfaces |
| `check-distinct-surface-captures.py` | two surfaces claimed as distinct with identical pixels | **pass** — 0 collisions |
| `detect-refusal-toast-captures.py` | a capture that photographs a permission refusal instead of the surface | **pass** — 932 captures checked |

The last two were **both red when this acceptance process began**, and both were red because of
real false evidence, not because of a strict threshold:

* four captures named for the WooCommerce import screen were pictures of `/webhook/create`,
  because that surface's refusal is `redirect()->back()` and the browser went back to the page the
  sweep had just driven;
* four captures backing *Attribute options list (DS pilot)* were pictures of the **dashboard with a
  "permission denied" toast**, because the route was driven without the id its handler requires.

Neither affected the 24 in this package. Both are recorded in the final acceptance report.

---

## 6. The decision this package asks for

Nothing below is pre-filled.

1. **Accept all 24** as visually approved for Phase 2.5; or
2. **Accept with exceptions** — name the surfaces you want reworked; or
3. **Defer** pending a pixel before/after for named surfaces (see §4); or
4. **Reject** named surfaces with the change you want.

Visual approval of these 24 is **independent of release readiness**. Mandatory security gates are
red and are reported separately; approving a screen's appearance does not approve a release.
