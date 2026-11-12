# P2.5-PKG-L — the customer timeline, against the owner's six observations

The owner reviewed the screen, declined to promote it, and named six things. This is what each one
turned out to be and what was done about it, with before/after captures in all four
language/theme combinations at 1440, 390 and 320 — 12 before, 12 after, 24 in all.

Captured by `docs/development/phase-2.5/tools/shoot-customer-timeline.cjs` against the running
merchant admin. Every row asserts it is signed in, on the right screen, in the right direction and
the right theme before its image is kept.

## The six observations

### 1. Excessive whitespace

**What it was.** `.tl-container` is `max-width: 50%` and alternates `left`/`right` around a centre
rail, so every card leaves the other half of the canvas empty — roughly 450px of card beside 450px
of nothing on a 1440 screen, seven times, plus ~150px of vertical gap between consecutive cards
because a left card and the right card after it do not share a row.

**And the alternation carries no meaning.** `$ssSide` is `$key % 2` — the row's position in the
list. A reader following a chronological sequence has to zig-zag across 900px to do it.

**What was done.** One column, one 2px rail on the leading edge, the markers on it. Then the card
itself became a flex container so its content flows *across* rather than stacking: the event badge
and the timestamp share row 1, the facts wrap across row 2.

| | before | after |
|---|---|---|
| en desktop | 1807px | **1234px** (−32%) |
| ar desktop | 1812px | **1239px** (−32%) |
| en 320 | 2046px | 1858px |
| ar 320 | 2072px | 1917px |

The first pass alone (one column, cards still stacking) gave 1676px — it had only *moved* the
empty half of the canvas inside the cards. The second pass is what earned the figure.

### 2. Event-card hierarchy

Every fact was a `Label:- value` row of equal weight, one per line, with the timestamp on a line of
its own. `:-` is not punctuation in either language, and in Arabic it trails a right-to-left label
as a stray mark. The labels lost it, the facts flow inline, and the badge now leads a header row
that carries the time at the far edge.

### 3. The two date representations — the one that mattered most

**What it was.** On the registration card:

```
Register Date:-  2026-10-08 01:24:56      the customer's own registration moment,
                                          a raw SQL string in a green pill
07 Nov 2026 01:24 AM                      the ACTIVITY LOG ROW's created_at, unlabelled
```

Two dates, two formats, no labels, a month apart — **and the second was identical on all seven
cards**, because that is when the rows were written. The timeline's own chronology was invisible,
and nothing on the screen said which date meant "when this happened".

**What was done.** Both are labelled and both use one format. The log timestamp is now
`Recorded 07 Nov 2026, 1:24 AM`; the event's own date keeps its own label
(`Register Date`, `Last Login Date`, `Order Date`) and stops being a pill painted in the event's
colour — a timestamp should not look like a status badge.

**No stored value changed.** The owner's instruction is explicit on that; `App\Support\TimelineMoment`
parses and prints the same columns the view already read.

### 4 & 5. Arabic localisation, labels and typography

| | before | after |
|---|---|---|
| page title | `خط الزمن للعميل` (literal) | `الخط الزمني للزبون` |
| the word for *customer* | `العميل` — while the sidebar says `الزبائن` | `الزبون`, agreeing with the sidebar |
| dates | `07 Nov 2026 01:24 AM` — Latin month and meridiem in a right-to-left line | `07 تشرين الثاني 2026، 1:24 ص` |
| payment type | `cod` | `الدفع عند الاستلام` |
| `Variant` | `الخيار` ("the option") | `المتغير` |

**`ar_SY`, not `ar`.** Carbon's generic Arabic gives the transliterated Gregorian names (`أكتوبر`);
`ar_SY` gives the Levantine ones a Syrian merchant writes (`تشرين الأول`). The locale is set per
instance, never globally — `Carbon::setLocale()` would change every date in the admin.

### 6. 320px and 390px

The vendored phone block pins `text-align: left` on three selectors, so **at 767px and below the
Arabic cards aligned against the direction of the page**. The override uses `start`, and every new
rule in the block uses logical properties, so RTL needs no second copy in `rtl-custom.css`.

It also hid the rail's markers on phones and pushed the rail behind the content. With one column
the rail is the only thing tying the cards together, so both come back.

**And one defect was found only by looking at the 320px capture, not predicted:**
`margin-inline-start: auto` is right on a wide card, where it puts the timestamp opposite the badge
on one line. On a phone they cannot share a line, so the timestamp wrapped onto its own row and
`auto` then pushed it to the far edge of *that* row — a right-aligned fragment under a left-aligned
badge, belonging to neither. It is a plain full-width row now.

## Also found, and fixed in the same pass

**The same value was five colours down one page.** "Linen Tea Towel Set" was green on *added to
wishlist*, grey on *removed from wishlist*, crimson on both cart rows and blue on the order;
"S-Natural" was amber on one card and brown on the next. The seven suffix classes
(`wishlist-timeline`, `add-cart-timeline`, …) key on **the event**, not on what the chip contains —
so "green means wishlist" teaches a reader nothing transferable, and the event already has a colour
in the badge above. Every value chip is now one neutral chip; the event keeps its colour.

**And the first version of that chip was too loud, which the 390px dark capture showed.** It used
the fixed `--ss-grey-100`/`--ss-grey-900` pair, giving a near-white pill on a `#262b33` card —
legible at about 16:1 and *louder than the event badge above it*, inverting the hierarchy the
change exists to establish. The chip takes `--ss-surface-hover`/`--ss-fg` instead, which re-theme
with the card. That is the opposite choice from P2.5-PKG-J two blocks away in the same stylesheet,
and correctly so: the SweetAlert sheet stays white in dark mode, the timeline card does not.

## What is NOT claimed

- **This is not a PREMIUM promotion.** The owner's instruction is that the timeline is not promoted
  without visual acceptance, and nothing here changes its status.
- The alternating `$ssSide` class is still computed and still emitted in the markup. The layout no
  longer uses it; removing it is a view change with its own output-neutrality question and is not
  part of this slice.
- The confirm button's missing fill, the stats row's borderless treatment and the fact that
  `Total Orders` reads 55 against seven timeline events are untouched.

## Files

```
docs/development/phase-2.5/tools/shoot-customer-timeline.cjs   the capture harness
app/Support/TimelineMoment.php                                  parse, localise, print
tests/Unit/Ui/CustomerTimelinePresentationTest.php              12 cases, the text and data half
public/css/store-syria-ds.css                                   the layout, scoped to `.ss-timeline`
resources/views/customer/timeline.blade.php                     labels, dates, the DS hook
resources/lang/{ar,en}.json                                     the terminology above
```

## Reproducing

```
docs/development/phase-2.5/tools/serve-dev.sh
node docs/development/phase-2.5/tools/shoot-customer-timeline.cjs --out /tmp/pkg-l --label after
```
