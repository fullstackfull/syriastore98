# P2.5-PKG-G Slice 2 — one customer, presented one way

Captured from the running admin by `render-matrix.cjs`. **8 rows, 0 not clean.**

The BEFORE capture restores only the Blade view; the two new design-system rules stay in the
sheet. They are additive and the old markup references neither, so they have no effect on the
before — the difference in these images is the view.

---

## The third structural consequence

The package thesis names three. This is the third: **the same customer is modelled with a different
vocabulary on every screen**, so the area does not read as one product.

## What was measured, and one survey claim that did not survive checking

| claim | verdict |
|---|---|
| `.card` sets `--bs-card-border-width: 0px` in **both** sheets | **true** — `style.css:4993`, so the card had no edge; only the header and footer hairlines painted, leaving two floating lines with no outline around them |
| `.btn` hardcodes `--bs-btn-color: #293240` byte-identically in both sheets | **true** — `style.css:3444`, `style-dark.css:3449`; on the footer's sunken ground that is the measured 1.20:1 |
| `min-w-0` is defined in no stylesheet | **true** — 3 uses, 0 definitions. Tailwind syntax in a codebase with no Tailwind build, so all of them were inert |
| `first_name` / `last_name` both nullable and `getNameAttribute()` a bare concatenation | **true** — a customer with neither rendered an empty avatar, a link whose accessible name was one space, and `aria-label="Account status for "` |
| **`user-card` is "defined nowhere"** | **FALSE as written.** `style.css` carries 22 `.user-card …` selectors |

That last one was checked rather than taken on trust, because removing a class that *is* styled
changes appearance unpredictably. There is no bare `.user-card {}` rule, and every one of its 22
descendant selectors is a profile-page construct — `.change-profile`, `.cover-img-block`,
`.user-about-block`, `.certificated-badge`, `.wid-80`, `.hover-data`, `.label`,
`.dropdown-toggle`. **This card contains none of them**, so the class really is vestigial here,
inherited by copy from the vendored profile template. The conclusion stands; the stated reason did
not.

## What changed

1. **The card root** is `ss-scope ss-card`, which supplies the surface, the border, the radius and
   the column flex that stops the footer floating mid-card — and `ss-scope` turns on the Arabic 1.7
   line-height arm, which no customer view carried.
2. **`col-lg-4` fills the breakpoint gap.** The sequence ran 1/2/2/2/3/4 with nothing between 576
   and 1199px, so 1024, 1152 and 1180 got two ~470px cards. `d-flex` + `w-100` makes a row of cards
   share one height; `g-3` replaces a 44px vertical against a 24px horizontal gutter with one.
3. **The footer actions take `action.blade.php`'s exact classes and keys**, so the grid card and
   the list row offer one vocabulary. This also closes the 1.20:1 and the ~28×30px target —
   `.ss-btn--sm` is 32px min-height, `.ss-btn--icon.ss-btn--sm` 32px min-width.
4. **Two real rules replace two dead class names.** `.ss-min-w-0` and `.ss-stat__value--incard`,
   both `.ss-`-confined so `DesignSystemStylesheetScopeTest` still passes. No dead class name
   survives the slice.
5. **The figure stops outranking the name.** `.ss-stat__value` is `--ss-text-2xl` (the dashboard
   figure size) against `.ss-card__title`'s `--ss-text-lg`, so the order count rendered at **1.56×
   the customer's name** — on a card whose own comment says identity comes first. The modifier
   keeps the tabular figures and the bidi isolation and changes only the size.
6. **`<bdi>` on the address, not `dir="ltr"` on its block.** The attribute flips that block's
   inherited `text-align: start`, so in Arabic the name right-aligned and the address beneath it
   **left**-aligned — two halves of one identity at opposite card edges. It is not simply deleted:
   `order/grid.blade.php` records the measured bidi hazard it was added for.
7. **One display-name resolver** feeds the title, the avatar initials and the switch's aria-label,
   with a visible translated fallback and initials falling back to the e-mail's first character.
