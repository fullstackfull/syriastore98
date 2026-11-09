# Phase 2.5 — ORDERS area audit: findings, evidence and status

**Audit run:** `wf_cd4c60b8-8cd` · **363 agents, 0 errors** · 21,740,143 subagent tokens ·
4,336 tool calls · completed 2026-11-09.

**Tree audited:** `cb01b7de` on `claude/gracious-volta-duiynj`, `fullstackfull/syriastore98`.

**Every status below was re-verified by hand against the working tree at that SHA**, with the
command in the "reproduce" line. Nothing is recorded as fixed on the strength of a plan.

---

## 1. How the numbers were produced

Three screens — `/order` (table), `/order-grid` (cards), `/order-kanban` (board) — were each read
through **six independent lenses**, every lens blind to the others:

`layout` · `rtl` · `mobile` · `a11y` · `workflow` · `consistency`

= **18 finder agents**. Every finding one of them raised was then handed to **three refuters**, each
with a different angle and each instructed to default to *refuted* when uncertain:

| angle | refutes when |
|---|---|
| `reproduce` | the file:line or the capture does not show what the finding claims |
| `scope` | it needs a database migration, touches prices/payments/stock/business rules, changes a public API response Flutter consumes, requires editing `packages/workdo/`, changes permissions, or was already fixed |
| `harm` | no merchant measurably suffers, the fix makes something else worse, or it is a decision already recorded in the repository |

**A finding that any one of the three refuted is not counted.**

| | |
|---|---:|
| screen x lens pairs | 18 |
| findings raised | **115** |
| refuted | **103** (90%) |
| survived all three angles | **12** |
| verifier deaths (counted as NOT confirmed) | 0 |

**90% of what the finders raised did not survive.** That is the point of the pattern, not a
failure of it: a single-pass review would have handed the next account 115 "defects" and most of
the effort spent on them would have been wasted. The angle that killed the most was `harm` (101),
then `reproduce` (25), then `scope` (19) — a finding can be refuted by more than one.

### Where the raised findings came from, and what survived

| screen | lens | confirmed / raised |
|---|---|---:|
| board | a11y | 0 / 5 |
| board | consistency | 0 / 7 |
| board | layout | 0 / 5 |
| board | mobile | 0 / 5 |
| board | rtl | 0 / 8 |
| board | workflow | 1 / 6 |
| grid | a11y | 1 / 7 |
| grid | consistency | 0 / 9 |
| grid | layout | 1 / 6 |
| grid | mobile | 0 / 4 |
| grid | rtl | 1 / 8 |
| grid | workflow | 2 / 7 |
| list | a11y | 2 / 6 |
| list | consistency | 1 / 5 |
| list | layout | 0 / 6 |
| list | mobile | 0 / 5 |
| list | rtl | 2 / 9 |
| list | workflow | 1 / 7 |

Three readings worth keeping: `board` raised 36 and only one survived — **but it is the most
serious finding in the whole audit**. The three `mobile` lenses raised 14 between them and **none**
survived, which is consistent evidence that the phone work on these screens is genuinely sound.
And `consistency` raised 21 with one survivor, which says the design-system migration of this area
is further along than it looked.

---

## 2. The twelve confirmed findings

Numbering matches the order they were reported to the owner. **Status is verified, not assumed.**

---

### #1 — `Walk-in-customer` is translated as `المشي في العميل` — "the walking inside the customer", word-by-word machine output that is not Arabic.

**Screen:** `list` · **Lens:** `rtl` · **Finder confidence:** `certain` · **STATUS: NOT STARTED**

**Evidence**

`resources/lang/ar.json:3251` — `"Walk-in-customer": "المشي في العميل"`. Rendered on this screen by `app/DataTables/OrderDataTable.php:96`, the Customer Info cell's branch for `customer_id == 0` and not a guest. (`المشي` = walking, `في` = inside, `العميل` = the customer — the English compound was split on the hyphen and each piece translated separately.)

**Why it matters**

Every till / counter order in the Arabic list names its buyer with a nonsense phrase. It is the kind of string a merchant screenshots and sends to support, and it reads as "this product was not built for us". The captures happen to show `Omar Khalil` on every visible row, so no screenshot caught it — only the vocabulary did.

**Suggested fix (the finder's; not yet adjudicated)**

Change `resources/lang/ar.json:3251` to `"زبون عابر"` (the term a Syrian shop uses for a counter customer) or `"زبون من المحل"`. Not `"عميل مباشر"`, which reads as a direct-sales channel.

**Reproduce**

```
python3 -c "import json;print(json.load(open('resources/lang/ar.json',encoding='utf-8'))['Walk-in-customer'])"  ->  المشي في العميل
```

**What the three refuters found** — all three returned `refuted: false`.

* **`reproduce`** — NOT REFUTED — the finding reproduces exactly, on every axis I tested, including the ones most likely to break it. EXACT-EVIDENCE CHECK (all confirmed verbatim): 1. `resources/lang/ar.json:3251` is literally `"Walk-in-customer": "المشي في العميل"`. Correct line number, correct key, correct value. `grep -n 'Walk-in'` returns only 3250 and 3251, so no duplicate key shadows it, and json_decode over the whole file (3515 keys, parses cleanly) resolves `Walk-in-customer` to `المشي في العميل`. 2. `app/DataTables/OrderDataTable.php:96` is literally `return __('Walk-in-customer');`, and it is the `else` of `if ($item->is_guest == 1)` / `elseif ($item->customer_id != 0)` — precisely the "customer_id == 0 and not a guest" branch the finding describes. 3. It really is the Customer Info cell: the closure opens at line 69 as `->editColumn('customer_id', ...)`, and line 562 titles that column `'customer_id' => ['title' => __('Customer Info')]`. 4. It really renders on /order: `OrderController::index(Request $request, OrderDataTable $dataTable)` at line 565, and `resources/views/order/index.blade.php…
* **`scope`** — SURVIVES the scope angle. I checked each disqualifier and none fires; the evidence is also exactly as stated. EVIDENCE CONFIRMED VERBATIM - /home/user/syriastore98/resources/lang/ar.json:3251 -> "Walk-in-customer": "المشي في العميل". - /home/user/syriastore98/app/DataTables/OrderDataTable.php:96 -> `return __('Walk-in-customer');` in the else branch under `if ($item->is_guest == 1) ... elseif ($item->customer_id != 0) ...`, i.e. exactly the counter-order branch claimed. NOT ALREADY FIXED. Not one of the four items the brief lists as fixed today. `git log -S'المشي في العميل' -- resources/lang/ar.json` returns a single commit, 2528db62 (Nov 1), which ADDED the line; nothing has changed it since, and `git status --porcelain` on ar.json is clean. Today's commit cb01b7de did touch ar.json, but only 4 lines (the date/title work), not this key. NOT ALREADY KNOWN. Not one of the five open items. The substring المشي appears in ZERO files under docs/ and tests/; PHASE-2.5-REPORT-AR-2026-11-08.md and PHASE-2.5-TASK-LEDGER.md contain no walk-in entry. NO MIGRATION, NO BUSINESS-RULE/PAYMENT IMPAC…
* **`harm`** — NOT REFUTED — the claim survives the harm angle on every test I applied. VERIFIED AS STATED. resources/lang/ar.json:3251 is verbatim "Walk-in-customer": "المشي في العميل". Rendered by app/DataTables/OrderDataTable.php:96, the else branch of the customer_id cell (is_guest != 1 && customer_id == 0). Render path closed end-to-end: the same file's line 562 titles the column __('Customer Info') -> ar.json:760 -> معلومات العميل, which is verbatim the header visible in _order__ar_rtl_light.png. Same file, same __() mechanism, same page — no override. The translation is genuinely nonsense (المشي = the walking, في = inside, العميل = the customer); the hyphenated compound was split and translated piecewise. REACHABILITY IS REAL, NOT THEORETICAL. PosController::store() writes customer_id = '0' (:522, when no customer is selected) and is_guest = 0 (:510, because the merchant at the till is authenticated on the web guard) — exactly the else branch's condition. POS is a CORE route (routes/web.php:1344, Route::resource('pos', PosController::class)), not a disabled WorkDo module. So it fires on ever…

---

### #2 — The actions column is headed `العمل` ("the work / the labour"), not `الإجراءات` ("actions").

**Screen:** `list` · **Lens:** `rtl` · **Finder confidence:** `certain` · **STATUS: NOT STARTED**

**Evidence**

`resources/lang/ar.json:94` — `"Action": "العمل"` — rendered as this table's last column title by `app/Helper/helper.php:2978` (`->title(__('Action'))`). Visible as the far-left column header in `_order__ar_rtl_light.png` and `_order__ar_rtl_dark.png`, and as the last row label in `_order__ar_rtl_light_390.png` and `_order__ar_rtl_dark_390.png`, where `العمل` sits above the four icon buttons. The English capture `_order__en_ltr_light.png` shows the same column headed `Action`.

**Why it matters**

`العمل` is the noun for work/employment — it is the dictionary's first hit for "action" and the wrong sense entirely. A merchant scanning the Arabic header row reads a column called "Work" over a row of edit and delete buttons. It is the single most-seen mistranslation in the product: `buildDataTableColumn()` puts this header on every one of the 67 admin tables.

**Suggested fix (the finder's; not yet adjudicated)**

Change `resources/lang/ar.json:94` to `"إجراءات"`. One key, every table. (`الإجراءات` with the article if the surrounding headers are definite — the rest of this table's headers are: `رقم الطلب`, `التاريخ`, `السعر`.)

**Reproduce**

```
python3 -c "import json;print(json.load(open('resources/lang/ar.json',encoding='utf-8'))['Action'])"  ->  العمل
```

**What the three refuters found** — all three returned `refuted: false`.

* **`reproduce`** — NOT REFUTED — reproduced on every point. (1) resources/lang/ar.json:94 is verbatim `"Action": "العمل",` — correct key, correct line. (2) app/Helper/helper.php:2978 is verbatim `->title(__('Action'))->addClass('text-center ss-col-locked ss-col-pinned');` — correct line, inside buildDataTableColumn() (declared helper.php:2880). (3) Reach confirmed: app/DataTables/OrderDataTable.php:559 calls buildDataTableColumn([...]) with no second argument, so $action defaults true and the header is appended; no override path exists, since resources/lang/ar/ contains only auth/installer_messages/myfatoorah/pagination/passwords/validation.php and a dotless key resolves against ar.json. (4) I read all five named captures. _order__ar_rtl_light.png and _order__ar_rtl_dark.png: the trailing (far-left) column header reads العمل, above the four icon buttons. _order__ar_rtl_light_390.png and _order__ar_rtl_dark_390.png: in the stacked responsive view العمل is the last row label with the four icons on the line beneath it. _order__en_ltr_light.png: same column headed Action. Every capture shows what the findi…
* **`scope`** — NOT REFUTED on the scope angle. I checked each refutation criterion and the claim survives all seven; I also found new corroboration the original finding did not have. EVIDENCE I ACTUALLY VERIFIED - /home/user/syriastore98/resources/lang/ar.json:94 is `"Action": "العمل",` at HEAD (`git show HEAD:resources/lang/ar.json | grep -n '"Action"'` -> line 94). Working tree is clean (`git status --porcelain` empty), so there is no uncommitted fix either. - /home/user/syriastore98/app/DataTables/OrderDataTable.php:559 `return buildDataTableColumn([...])` -> /home/user/syriastore98/app/Helper/helper.php:2978 `->title(__('Action'))`. The chain the finding asserts is real. - I read the images. `_order__ar_rtl_light.png`: the pinned trailing-edge column header reads `العمل`. `_order__ar_rtl_dark_390.png`: the stacked row label `العمل` sits directly above the four icon buttons. `_order__en_ltr_light.png`: the same column is headed `Action`. All three match the claim exactly. SCOPE CRITERIA, ONE BY ONE 1. Already fixed today - NO. The four declared fixes are the page titles, the `#ORD` bidi wrap, `U…
* **`harm`** — NOT REFUTED. I tried to kill this on the harm angle and it survived all three refutation tests. RENDERING CONFIRMED IN THE IMAGES. `العمل` is the pinned trailing column header in /home/user/syriastore98/docs/development/phase-2.5/evidence/pkg-m-orders/after/_order__ar_rtl_light.png and _order__ar_rtl_dark.png; _order__en_ltr_light.png shows the same column headed `Action`. In _order__ar_rtl_light_390.png and _order__ar_rtl_dark_390.png the stacked layout converts the header into the ROW LABEL for the action row, so `العمل` is the only text attached to the four unlabelled icon buttons. TEST 1 — IS IT A RECORDED DECISION? No. I searched docs/development/phase-2.5/PHASE-2.5-DECISION-REGISTER.md, PHASE-2.5-ADJUDICATED-DISAGREEMENTS.md, all 48 phase-2.5 docs, tests/ and .github/. No entry. The OPPOSITE precedent exists and is decisive: PHASE-2.5-DECISION-REGISTER.md:860-876 (F-DESIGN-17) fixed `"Home": "البيت"` -> الرئيسية with the reasoning "the same file carries `"Home Page": "الصفحة الرئيسية"` ... a literal dictionary equivalent where the interface needs the conventional term." That is…

---

### #3 — Every bulk-select checkbox on the order list — the header select-all and the one on each row — has no accessible name at all.

**Screen:** `list` · **Lens:** `a11y` · **Finder confidence:** `certain` · **STATUS: NOT STARTED**

**Evidence**

app/Helper/helper.php:2384 sets the column heading to the bare markup `->title('<input type="checkbox" id="select-all">')` and :2390 renders each row cell as `'<input type="checkbox" class="select-row" value="' + this.id + '">'`. No `<label>`, no `aria-label`, no `title`, and the heading cell contains nothing but the input, so the column has no header text either — public/js/store-syria-ds.js:1794 states this as a known fact ("An empty heading -- the select-all column -- gets an empty label rather than none"). I grepped public/js, resources/ and packages/workdo/BulkDelete (its script.blade.php:105 renders the same unnamed input) for any aria/label on `.select-row` or `#select-all`: there is none. Visible in _order__en_ltr_light.png and _order__ar_rtl_light.png as a bare checkbox in the first table column with a blank column heading above it, and in _order__en_ltr_dark_390.png where the stacked mobile card opens with a lone checkbox and no text beside it (every other field in that card carries its column label).

**Why it matters**

A screen-reader user moving down the list hears "checkbox, not checked" ten times with nothing saying which order each one selects, and the select-all is announced with no name at all. The control these checkboxes feed is Bulk Delete, so the un-named control is the one that destroys records irreversibly. At 390px it is also visually unlabelled: it is the only field in the stacked card with no caption.

**Suggested fix (the finder's; not yet adjudicated)**

Give the header input `aria-label="{{ __('Select all orders') }}"` at app/Helper/helper.php:2384, and give the row input a name in the `render()` string at :2390 — at minimum `aria-label="' + <translated 'Select this row'> + '"`, better the record's own identifier, which the DataTables row data already carries (the action column at resources/views/order/action.blade.php already names each button with `:id`, so the same phrasing is available).

**Reproduce**

```
sed -n '2384p;2390p' app/Helper/helper.php   ->  two <input type="checkbox"> with no aria-label, no <label>, no title
```

**What the three refuters found** — all three returned `refuted: false`.

* **`reproduce`** — REPRODUCED — every file:line and every capture claim checks out exactly, and I could not break it on any angle. LINE NUMBERS, verified by opening the files: - app/Helper/helper.php:2384 is literally `->title('<input type="checkbox" id="select-all">')`. Correct line, correct text. - app/Helper/helper.php:2390 is literally `return \'<input type="checkbox" class="select-row" value="\' + this.id + \'">\';`. Correct line, correct text. - public/js/store-syria-ds.js:1794 is the first line of the cited comment: `/* An empty heading -- the select-all column -- gets an empty label rather than`. Correct line, correct quote. The code it documents (:1806) writes `cell.setAttribute('data-ss-label', spans || headings[i] === undefined ? '' : headings[i])`, so the checkbox cell genuinely gets `data-ss-label=""`. - packages/workdo/BulkDelete/src/resources/views/pages/script.blade.php:105 is `return '<input type="checkbox" class="select-row" value="' + full.id + '">';`. Correct line. - resources/views/order/action.blade.php really does name each button with the record id (`aria-label="{{ __('Approve t…
* **`scope`** — SCOPE VERDICT: the finding survives. It is in scope, not already fixed, and violates none of the exclusion criteria. I checked all seven, plus whether it was addressed already. 1) NOT one of the four "already fixed today". Those were titles, the <bdi> order id, Utility::dateFormat localisation, and the `pending` translation. The PKG-M record at /home/user/syriastore98/docs/development/phase-2.5/evidence/pkg-m-orders/README.md lists exactly nine findings F-M-01..F-M-09 and marks five fixed; no checkbox finding exists in it at all. 2) NOT one of the five "already known and open". The nearest is F-M-09, and the README defines it narrowly: "four unlabelled icon buttons in the action row — Delete, edit, view and one more". That is the per-row Action column (visible in _order__ar_rtl_light.png as four icons at the trailing edge), a different control, a different file (/home/user/syriastore98/resources/views/order/action.blade.php), and a different mechanism (Bootstrap Tooltip emptying `title`, documented at /home/user/syriastore98/app/Helper/helper.php:2404-2418). The bulk-select inputs ar…
* **`harm`** — NOT REFUTED. I went looking for a recorded decision, a mitigating naming mechanism, and a fix that backfires. The first two do not exist, and only the claim's *optional* fix variant backfires. VERIFIED EXACTLY AS STATED. /home/user/syriastore98/app/Helper/helper.php:2384 is `->title('<input type="checkbox" id="select-all">')` and :2390 is `return '<input type="checkbox" class="select-row" value="' + this.id + '">';`. /home/user/syriastore98/app/DataTables/OrderDataTable.php:559 reaches them via `buildDataTableColumn()` (helper.php:2899), so /order really is served by this column. I read the images: in _order__en_ltr_light.png and _order__ar_rtl_light.png the first column heading is blank with a bare checkbox under it in both directions; in _order__en_ltr_dark_390.png the stacked card opens with a lone checkbox and is the only field in that card with no caption. No `aria-label`, `<label>` or `title` anywhere on `.select-row`/`#select-all`. WHY HARM IS REAL AND NOT TASTE — the repository's own standard. A prior wave named every *other* control in that same row, each with a docblock cal…

---

### #4 — The `.ss-select` dropdown chevron is a hard-coded `#5b6665` inside a background-image data URI, so in dark mode all four order filters' chevrons sit at 2.39:1 — below the 3:1 non-text minimum.

**Screen:** `list` · **Lens:** `a11y` · **Finder confidence:** `certain` · **STATUS: NOT STARTED**

**Evidence**

public/css/store-syria-ds.css:1022 — `background-image: url("data:image/svg+xml,…stroke='%235b6665'…")`. That hex is baked into the URI, not a token, and line 1022 is the only occurrence in the file (grepped), so neither `@media (prefers-color-scheme: dark)` nor `[data-ss-theme="dark"]` ever changes it. `--ss-surface` is `#262b33` in dark (same file, :553 and :605). #5b6665 on #262b33 = 2.39:1. Measured off the captures at the inline-end of all four selects (Order Status / Returns / Payment Status / Source): _order__en_ltr_dark.png and _order__ar_rtl_dark.png give composited ink rgb(86,98,97) on rgb(38,43,51) = 2.25:1 at every one of the four. Light mode is fine (5.18–5.42:1 measured in _order__en_ltr_light.png / _order__ar_rtl_light.png). The fields that render it on this screen are resources/views/order/partials/filters.blade.php:60, :90, :98 and :119.

**Why it matters**

The chevron is the only thing that marks these four boxes as dropdowns — `appearance: none` at store-syria-ds.css:959-978 removes the native one. In dark mode the filter bar reads as four flat text boxes, so a merchant with reduced contrast sensitivity has no cue that the filters open at all. It is a global design-system rule, so every `.ss-select` in the admin is affected, not only this screen.

**Suggested fix (the finder's; not yet adjudicated)**

Stop painting the chevron as a fixed-colour image: use `mask-image` with the same SVG and `background-color: currentColor` (or `var(--ss-fg-muted)`), so it follows the theme; or, minimally, redefine `.ss-select`'s `background-image` inside the two dark blocks with a stroke that reaches 3:1 on `#262b33`.

**Reproduce**

```
grep -c 5b6665 public/css/store-syria-ds.css   ->  1   (the hex is inside the data: URI at :1022)
```

**What the three refuters found** — all three returned `refuted: false`.

* **`reproduce`** — NOT REFUTED — I tried to break this on four fronts and every load-bearing claim reproduced exactly, including all six file:line citations. VERIFIED IN CODE - public/css/store-syria-ds.css:1022 is verbatim the quoted declaration: `background-image: url("data:image/svg+xml,…%3Cpath d='M1 1.5 6 6.5l5-5' stroke='%235b6665'…")`. Exact line, not off by one. - `grep -n '5b6665\|5B6665'` returns line 1022 and nothing else. - store-syria-ds.css:959–978 is exactly the `.ss-input, .ss-select, .ss-textarea` block, with `background: var(--ss-surface)` at :968 and `appearance: none` at :977 (`-webkit-appearance: none` at :976). Exact range. - :553 (`[data-ss-theme="dark"]`) and :605 (`@media (prefers-color-scheme: dark)` → `[data-ss-theme="auto"]`) both read `--ss-surface: #262b33`. Both exact. - resources/views/order/partials/filters.blade.php:60, :90, :98, :119 each carry `<select class="ss-select" …>` — ss_status, ss_return, ss_payment, ss_source = Order Status / Returns / Payment Status / Source, in the order the finding names them. All four exact. resources/views/order/index.blade.php:106 inc…
* **`scope`** — SURVIVES the scope angle. (1) NOT already fixed: working tree clean at HEAD cb01b7de, and `git log -L1020,1026:public/css/store-syria-ds.css` shows the chevron declaration untouched since the commit that introduced it (0bd19dad, Phase 01) — never revisited by WAVE-31/32 or any PKG. None of the four items fixed today (titles, <bdi> order ids, Utility::dateFormat, payment-status translation) touches it. (2) NOT on the known-open list (mm/dd/yyyy placeholders, split date range, kanban select+button, status chip, four unlabelled icon buttons) — and it adds a specific NEW instance with a named cause: PHASE-2.5-TASK-LEDGER.md:1718 (Wave 32) already fixed this exact defect shape for the HOST's `.form-select` (hard-coded `%23343a40`, 1.22:1 → 11.81:1) at store-syria-ds.css:3765, confined to `[data-ss-theme="dark"] .dash-content .form-select`. That fix cannot reach these controls because resources/views/order/partials/filters.blade.php:60,:90,:98,:119 carry `class="ss-select"` ONLY, with no `form-select`. So the design system's own select kept the identical defect with its own hex while the h…
* **`harm`** — NOT REFUTED. I tried to kill it on harm and could not; the repository's own records argue against me. WHAT I MEASURED (PIL, peak ink vs modal background, 26x26 boxes): - _order__en_ltr_dark.png, all four filter chevrons: ink rgb(91,102,101) = exactly #5b6665 on rgb(38,43,51) = 2.39:1. Same four in _order__ar_rtl_dark.png (chevron flipped to the inline-start edge by store-syria-ds.css:1027): 2.39:1. - Light mode: 5.94:1 (the finding said 5.18-5.42; it understated it. Its "composited rgb(86,98,97) = 2.25:1" is an antialiased sample, not the peak. Both are below 3.) - Blast radius is wider than the finding says. In _order_kanban__en_ltr_dark.png the four filters AND every per-card "Move to..." select read 2.39:1 (resources/views/order/partials/kanban-card.blade.php:68, `ss-select ss-select--sm`, one per card). That is the control a merchant uses to advance fulfilment, not a filter. 30 `.ss-select` renderings across 12 views. - Cited lines verified exactly: filters.blade.php:60, :90, :98, :119 are the four `<select class="ss-select">`; `grep -c 5b6665 public/css/store-syria-ds.css` = 1; …

---

### #5 — The order list's free-text search silently returns nothing for the customer name, payment type, status and date the table itself prints — the list has zero `filterColumn()` definitions.

**Screen:** `list` · **Lens:** `workflow` · **Finder confidence:** `certain` · **STATUS: NOT STARTED**

**Evidence**

`grep -c filterColumn app/DataTables/OrderDataTable.php` returns **0**. Five columns are `editColumn`'d onto a display value that is not the stored value: `order_date` (/home/user/syriastore98/app/DataTables/OrderDataTable.php:63, renders a localised date), `customer_id` (:69-98, renders the customer's name + mobile from an integer FK), `final_price` (:99-101, renders `$150.00` from a decimal), `payment_type` (:102-170, renders a resolved, translated gateway label from a lowercase gateway id), `delivered_status` (:227-284, renders a translated badge from an int 0-8). None of them has a `filterColumn`, so yajra compiles `orders.customer_id LIKE '%Omar%'`, `orders.delivered_status LIKE '%معلق%'` and so on, which match nothing.

The same file proves the author knew the rule and applied it to only two columns: `getColumns()` sets `'searchable' => false` on `shipping_method` (:566-570) and `order_source` (:578-582), with the comment at :572-577 — "the stored value is a machine vocabulary ... while the cell shows a TRANSLATED label. A free-text search would match the machine value and display the label". The other five were left searchable.

The sibling lists carry the fix this one lacks: /home/user/syriastore98/app/DataTables/OrderRefundDataTable.php:104-130 has `filterColumn('order_id')` and `filterColumn('refund_status')`, whose comment names exactly this defect — "an Arabic merchant typing the label they can see ('المعالجة') matches nothing ... Same reason ProductDataTable carries a filterColumn for every column whose editColumn moved the display off the raw value". /home/user/syriastore98/app/DataTables/AbandonCartDataTable.php:56-61 resolves `customer_id` through `whereHas('UserData', ... first_name/last_name LIKE ...)`.

Capture `_order__ar_rtl_light.png`: the first row prints `Omar Khalil`, `نقداً عند التسليم`, `تشرين الثاني 6، 2026` and `معلق : …`, with the `بحث...` box directly above them. Every one of those four strings returns "no matching records". `_order__en_ltr_light.png` shows the same row in English (`Omar Khalil`, `Cash On Delivery`, `Nov 6, 2026`, `Pending :`).

**Why it matters**

Finding one order is the single commonest reason a merchant opens this screen — a customer phones and gives their name, not an order number. The filter bar (resources/views/order/partials/filters.blade.php) has no customer field and no order-id field, so Search is the only free-text way in, and it works only for the order number and the raw price. The merchant types the name they can see on the row in front of them and the screen answers that there is no such order. It is worse on the Arabic page, where four of the eight columns are translated and therefore all four are unsearchable.

**Suggested fix (the finder's; not yet adjudicated)**

Add `filterColumn()` to `OrderDataTable::dataTable()` for the four columns whose display has moved off the stored value, exactly as `OrderRefundDataTable.php:104-130` already does: `customer_id` → `whereHas('CustomerData', fn($q) => $q->where('first_name','like',"%$kw%")->orWhere('last_name','like',"%$kw%")->orWhere('mobile','like',"%$kw%"))`; `delivered_status` → map the translated labels back to their `HomeController::ORDER_STATUS_*` codes and `whereIn`; `payment_type` → match the resolved label back to the stored gateway ids, keeping a raw `LIKE` as the first limb; `order_date` → accept the rendered format as well as `Y-m-d`. If any of those is not worth the code, mark it `'searchable' => false` so the control stops lying, as the two computed columns already are. Minimum viable slice: `customer_id` alone.

**Reproduce**

```
grep -c filterColumn app/DataTables/OrderDataTable.php   ->  0
```

**What the three refuters found** — all three returned `refuted: false`.

* **`reproduce`** — NOT REFUTED — it reproduces exactly, line for line and pixel for pixel. I tried four ways to break it and all four failed. WHAT I CHECKED 1. The grep. `grep -c filterColumn app/DataTables/OrderDataTable.php` → 0 (597-line file). No `->filter()` override either, so `$autoFilter` stays true and yajra's own global search runs. 2. Every cited line range in /home/user/syriastore98/app/DataTables/OrderDataTable.php is exact to the line, not approximate: - `order_date` opens at :63, closes :68 — cited :63 ✓ - `customer_id` :69–98 ✓ (:69 is the `editColumn`, :98 is its `})`) - `final_price` :99–101 ✓ - `payment_type` :102–170 ✓ - `delivered_status` :227–284 ✓ (labels array :253–263, `(int) $item->delivered_status` :280) - `shipping_method` `'searchable' => false` :566–570 ✓, the P2-04-11 comment :572–577 ✓ (its text is quoted verbatim and correctly), `order_source` :578–582 ✓ - `rawColumns(...)` at :285 does list all five. HomeController.php:527-535 confirms `ORDER_STATUS_*` really are ints 0–8. 3. Both sibling citations are exact: /home/user/syriastore98/app/DataTables/OrderRefundDataTable.…
* **`scope`** — VERDICT: IN SCOPE, NOT ALREADY FIXED, NOT ALREADY KNOWN. I could not refute it on the scope lens; every one of the seven refutation criteria fails to apply, each checked against the repository rather than assumed. 1. NOT ALREADY FIXED TODAY. `grep -c filterColumn /home/user/syriastore98/app/DataTables/OrderDataTable.php` = 0 at HEAD. Stronger: `git log -S"filterColumn" -- app/DataTables/OrderDataTable.php` returns NOTHING, so the file has never contained one in its whole history (its only three commits are d456f46c, 4b89e9b8, 2528db62). The four fixes the brief lists as landed today are the titles, the `<bdi>` order ids, `Utility::dateFormat` localisation (cb01b7de) and the untranslated `pending` — none touches search. 2. NOT ALREADY KNOWN AND OPEN. I read the package's own survey commits. 196ddcc3 records F-M-01..F-M-06 and 16fb25a8 records F-M-05/07/08/09 — the nine PKG-M findings are the titles, mm/dd/yyyy, the split date range, untranslated `pending`, the bidi `#`, unlocalised dates, the `معلق : Nov 6, 2026` chip and the four unlabelled icon buttons. Search is absent from all nin…
* **`harm`** — NOT REFUTED on the harm angle. The claim survives, and what I found makes the harm worse rather than lighter. One of its four columns is covered by a recorded decision; the core of it is not. WHAT I VERIFIED FIRST-HAND - `grep -c filterColumn /home/user/syriastore98/app/DataTables/OrderDataTable.php` = 0. Every citation in the finding is accurate: `order_date` :63, `customer_id` :69, `final_price` :99, `payment_type` :102, `delivered_status` :227, `rawColumns()` :285, and in `getColumns()` `shipping_method` :566-570 / `order_source` :578-582 are the only two with `'searchable' => false`. `'customer_id' => ['title' => __('Customer Info')]` at :562 carries no flags at all. - The control is real and prominent. In `_order__en_ltr_light.png` and `_order__ar_rtl_light.png` a `Search...` / `بحث...` box sits directly above the header row, and row 1 prints `Omar Khalil` / `Cash On Delivery` / `Nov 6, 2026` / `Pending :` (AR: `Omar Khalil` / `نقداً عند التسليم` / `تشرين الثاني 6، 2026` / `معلق : …`). The filter bar in the same images offers Order Status, Returns, Payment Status, Source, From, …

---

### #6 — The Order Id cell is still vendored Bootstrap `btn btn-primary btn-sm` sitting inside the one column the design system marks as the row identifier, and the two rules collide: the ident colour wins and paints near-black ink on brand blue at 2.63:1 in light, while the pill itself ignores the theme entirely.

**Screen:** `list` · **Lens:** `consistency` · **Finder confidence:** `certain` · **STATUS: NOT STARTED**

**Evidence**

`app/DataTables/OrderDataTable.php:56-61` builds the cell as `<a … class="btn btn-primary btn-sm text-sm">` wrapping a leftover empty `<span class="btn-inner--icon"></span>` (WorkDo/Argon markup that paints nothing). `app/DataTables/OrderDataTable.php:560` declares that same column `'addClass' => 'ss-dt-ident'`, and `public/css/store-syria-ds.css:5393-5397` says `td.ss-dt-ident, td.ss-dt-ident a { color: var(--ss-dt-ident-fg) !important }` — `!important`, so it beats Bootstrap's un-flagged `.btn-primary{color:#fff}`. Measured off the captures, interior of the first-row pill only (corners excluded): `_order__en_ltr_light.png` x370-495,y792-808 → fill `#335DA8` (1257px), ink `#1A1D23` (173px), **zero white pixels** — #1A1D23 on #335DA8 is **2.63:1**, below the 4.5:1 AA minimum and below even 3:1. `#1A1D23` is exactly `--ss-dt-ident-fg` at `store-syria-ds.css:5287`. In `_order__en_ltr_dark.png` the identical region is fill `#335DA8`, ink `#F0F2F6` (= the dark ident token at `:5302`), so the fill does not move with the theme: on the same dark page the real design-system primaries measure `#2AC3E5` (Create Order at ~1180,106-1295,132; Apply filters at ~1295,484-1400,512), making the ten navy order-id pills the only light-theme brand blue left on a dark screen. Both other order screens render this same value as plain text: `resources/views/order/grid.blade.php:145` and `resources/views/order/partials/kanban-card.blade.php:33`. The customer area settled the same column on a quiet text link — `app/DataTables/CustomerDataTable.php:66-70` is `<a class="text-primary">` and nothing more, which is what the `td.ss-dt-ident a` rule was written for. The contradiction is inside one file: the status column of this very table carries a 20-line comment headed "THE DESIGN SYSTEM'S BADGE, NOT THE VENDORED THEME'S BUTTONS" (`OrderDataTable.php:230-247`) and the source column already emits `<span class="ss-badge …">` (`:225`) — the decision was applied to two columns and not to the first one.

**Why it matters**

The merchant's primary scanning column is unreadable-grade in light mode (2.63:1) and is the loudest thing on the page: ten filled brand-weight pills outrank the single real primary action, Create Order. In dark mode the list shows two different "primary blue" fills at once. And the pill's href is byte-identical to the pencil's (`route('order.view', Crypt::encrypt($order->id))` at `OrderDataTable.php:57` and `resources/views/order/action.blade.php:85`), so the same destination is offered twice per row in two contradictory visual languages.

**Suggested fix (the finder's; not yet adjudicated)**

Replace the markup at `OrderDataTable.php:56-61` with the shape the grid and the customer list already use — a bare `<a href=…>` with no `btn`/`btn-primary`/`btn-inner--*` classes — and let `td.ss-dt-ident a` supply the colour and weight it was written to supply. If a chip is genuinely wanted here, use `ss-badge`, which this same file already emits two columns over.

**Reproduce**

```
sed -n '56,61p' app/DataTables/OrderDataTable.php   ->  class="btn btn-primary btn-sm text-sm" still present
```

**What the three refuters found** — all three returned `refuted: false`.

* **`reproduce`** — NOT REFUTED — the claim survives every check I made, including the pixel counts, which reproduce exactly. I opened each cited file:line and re-measured each cited region myself. CODE, verbatim at the cited lines: - `/home/user/syriastore98/app/DataTables/OrderDataTable.php:56-61` is exactly the quoted markup. :57 is `<a href="' . route('order.view', \Illuminate\Support\Facades\Crypt::encrypt($item->id)) . '" class="btn btn-primary btn-sm text-sm" data-bs-toggle="tooltip" title="' . __('Invoice ID') . '">`, and :58 is the leftover empty `<span class="btn-inner--icon"></span>`. Exact. - `OrderDataTable.php:560` is `'product_order_id' => ['title' => __('Order Id'), 'addClass' => 'ss-dt-ident'],`. Exact, to the line. - `/home/user/syriastore98/public/css/store-syria-ds.css:5393-5397` is the rule, closing brace included. The real selector is longer than quoted (`.ss-dt-card-body table.dataTable > tbody > tr > td.ss-dt-ident` / `… td.ss-dt-ident a`), but the operative parts are verbatim: `color: var(--ss-dt-ident-fg) !important` (plus `font-weight … !important`). Exact range. - `store-syri…
* **`scope`** — SCOPE ANGLE — the claim survives; I could not refute it. NOT ALREADY FIXED TODAY. The PKG-M survey produced exactly nine findings (F-M-01..F-M-09), recorded in /home/user/syriastore98/docs/development/phase-2.5/evidence/pkg-m-orders/README.md (predecessor FINDINGS-BEFORE.md at commit 16fb25a8). The README's own status table maps them one-for-one onto the brief: F-M-01/04/05/07 fixed, F-M-02/03/06/08/09 open. The Order Id pill is none of the nine, so it is in neither the "already fixed" nor the "already known and open" list. NOT ALREADY ADDRESSED IN CODE. `git log --follow -- app/DataTables/OrderDataTable.php` puts its last touch at d456f46c (P2.5-WAVE-1), before today's three PKG-M commits (196ddcc3, 16fb25a8, cb01b7de); cb01b7de's file list does not include it, and `git status --porcelain` is clean. `btn btn-primary btn-sm text-sm` is present at HEAD at app/DataTables/OrderDataTable.php:57, with the empty `btn-inner--icon` span at :58. The cited CSS is accurate (store-syria-ds.css:5393-5397 with !important, tokens at :5287 and :5302), `'addClass' => 'ss-dt-ident'` is at OrderDataTab…
* **`harm`** — NOT REFUTED — the claim survives every harm-angle test I could put to it, and I independently reproduced its central measurement to the pixel. **1. The measurement is real, not inferred.** I re-measured the first-row pill interior in `_order__en_ltr_light.png` at x370-495, y792-808 (PIL, exact RGB histogram): fill `#335DA8` 1399px, ink `#1A1D23` 173px, **zero white pixels**. Computed WCAG contrast `#1A1D23` on `#335DA8` = **2.63:1** — below AA 4.5:1 and below even the 3:1 non-text floor. `#1A1D23` is exactly `--ss-dt-ident-fg` (`public/css/store-syria-ds.css:5287`), and `store-syria-ds.css:5393-5396` paints `td.ss-dt-ident a` with `!important`, which beats Bootstrap's unflagged `.btn-primary{color:#fff}`. `app/DataTables/OrderDataTable.php:560` does declare that column `'addClass' => 'ss-dt-ident'`, and `:56-61` does build the cell as `btn btn-primary btn-sm`. Confirmed in the dark capture too: identical region, fill `#335DA8`, ink `#F0F2F6` (the dark ident token at `:5301`). **2. "Taste with no measurable cost" — refuted.** The loss is caused by the collision, not by the brand colou…

---

### #7 — The order reference — the card's own title and its only identifying fact — is truncated to the point where two different orders render identical cards.

**Screen:** `grid` · **Lens:** `layout` · **Finder confidence:** `certain` · **STATUS: NOT STARTED**

**Evidence**

_order_grid__en_ltr_light.png, row 1: cards 1 and 2 both read `#P25CUSTFILTE…`, both `Omar Khalil`, both `$150.00`; the only text that differs is `Nov 6, 2026` vs `Nov 5, 2026`. _order__en_ltr_light.png (the list view of the same two orders) shows their real ids: `#P25CUSTFILTER-2-2` and `#P25CUSTFILTER-2-1` — they differ in the LAST character, which is exactly what the ellipsis removes. Same in Arabic: _order_grid__ar_rtl_light.png and _order_grid__ar_rtl_dark.png, the two right-hand cards of row 1, both `#P25CUSTFILTER-...`. Cause: resources/views/order/grid.blade.php:132 puts the title and the status badge in one `d-flex ... justify-content-between gap-2` row, and :143 gives the title `text-truncate`, so the reference competes for a ~236px content width with a `Pending` badge that never shrinks.

**Why it matters**

The card view of a merchant's orders is unusable for exactly the ids it is most needed for. Two cards that are the same order to the eye are two different orders, with different money attached; the merchant has to click through or switch to the list to tell them apart. The file's own comment at :99-116 declares the reference to be the card's title and the customer to be meta — the layout then clips the title and spends the full card width on a name and a price it is not identified by.

**Suggested fix (the finder's; not yet adjudicated)**

Give the reference the full content width: move the status badge out of the title row onto the meta line at grid.blade.php:149 (it stays inside the `card-header`, which is what Tests\Unit\Ui\OrderGridStatusLabelTest asserts), or drop `text-truncate` from :143 and let a long reference wrap to a second line. Do not fix this by shrinking the badge — the ids in this fixture differ only in their final character, so any truncation reintroduces the collision.

**Reproduce**

```
sed -n '143,146p' resources/views/order/grid.blade.php   ->  ss-card__title text-truncate, badge in the same flex row
```

**What the three refuters found** — all three returned `refuted: false`.

* **`reproduce`** — NOT REFUTED — every file:line and every capture named in the finding says what it claims. I tried to break it on three axes (line numbers, image evidence, mechanism) and it survived all three. CODE, at the exact lines claimed (/home/user/syriastore98/resources/views/order/grid.blade.php): - :131 opens `<div class="card-header p-3 border-bottom h-100">` - :132 is verbatim `<div class="d-flex align-items-start justify-content-between gap-2">` — the title/badge row, exactly as described - :143 is verbatim `<a class="ss-card__title text-truncate" dir="ltr"` — `text-truncate` is on the title, exactly as described; the reference itself is at :145 (`<bdi>#{{ $order->product_order_id }}</bdi>`) and the status badge is the next sibling in the same flex row at :146 (`OrderStatusTone::badge(...)`) - :149 is the meta line `<span class="ss-card__meta d-block text-truncate mt-1">`, which the fix proposes as the badge's new home IMAGES, all four grid captures (/home/user/syriastore98/docs/development/phase-2.5/evidence/pkg-m-orders/after/): - _order_grid__en_ltr_light.png and _order_grid__en_ltr_da…
* **`scope`** — SCOPE angle: the claim survives every exclusion I was asked to test. VERIFIED IN THE IMAGES. _order_grid__en_ltr_light.png row 1: cards 1 and 2 both read `#P25CUSTFILTE…`, both `Omar Khalil`, both `$150.00`, differing only by `Nov 6, 2026` vs `Nov 5, 2026`. _order_grid__ar_rtl_light.png: the two right-hand cards of row 1 both `#P25CUSTFILTER-…`. _order__en_ltr_light.png shows the same two orders with full references `#P25CUSTFILTER-2-2` and `#P25CUSTFILTER-2-1`, differing in the LAST character — exactly what the ellipsis removes. Cited lines are exact: grid.blade.php:132 is the `d-flex align-items-start justify-content-between gap-2` row, :143 the `ss-card__title text-truncate` anchor, :146 the badge, :149 the meta line. NOT ALREADY FIXED, NOT ALREADY KNOWN. It is none of the four items fixed today and none of the five listed as known/open. The authoritative package record /home/user/syriastore98/docs/development/phase-2.5/evidence/pkg-m-orders/README.md enumerates all NINE PKG-M findings F-M-01…F-M-09 with fixed/open state; card-title truncation is not among them — this is a tenth, …
* **`harm`** — The finding survives the harm angle, and production makes it worse than the fixture shows. Verified: grid.blade.php:132 is the flex row, :143 the truncated title, :146 the badge, :149 the meta line, and the comment at :99-116 does declare the reference the title — every citation is exact. The badge genuinely cannot yield: store-syria-ds.css:1362-1375 gives .ss-badge `display:inline-flex; white-space:nowrap` with no flex-shrink, so its automatic minimum size is its full text width; the images prove the mechanism, since the narrower Arabic badge (معلق) buys the title exactly one more glyph (`#P25CUSTFILTER-...` vs `#P25CUSTFILTE…`). MEASURABLE PRODUCTION COST, which the finding understates: every reference generator in the codebase is `'0'.date('YmdHis')` or `$customerId.date('YmdHis')` — PaymentService.php:2785,2789; PaymentController.php:3522,3531,4978,4987; PayUPaymentController.php:704,713; AuthorizeNetPaymnetController.php:1031,1035; Api/ApiController.php:3969,9558,9562 — so a real reference is 16-20 rendered glyphs, against a title budget calibrated from the image at ~155px ≈ 14 …

---

### #8 — `Walk-in-customer` is machine-translated into nonsense Arabic: `المشي في العميل` ("the walking in the customer")

**Screen:** `grid` · **Lens:** `rtl` · **Finder confidence:** `certain` · **STATUS: NOT STARTED**

**Evidence**

resources/lang/ar.json: `"Walk-in-customer": "المشي في العميل"` (read programmatically; the key resolves, so no coverage test catches it). Rendered on this screen at resources/views/order/grid.blade.php:155, the `@else` branch for an order with `customer_id == 0` and `is_guest != 1`. Not present in today's captures — every seeded order on /order-grid has a named customer (`Layla Haddad`, `Omar Khalil`), so this branch never painted.

**Why it matters**

`المشي` is the gerund "walking" (the physical act) and `في العميل` is "inside the customer". The phrase is not merely stiff, it is unparseable — it translates the English idiom word-by-word and the result names no one. A merchant with over-the-counter sales sees their most common customer type labelled with a phrase that means nothing, on the card that represents the money they took.

**Suggested fix (the finder's; not yet adjudicated)**

resources/lang/ar.json — `"Walk-in-customer"` → `"زبون مباشر"` (what a Syrian shop actually says for a counter sale; `بيع مباشر` if the register should be the transaction rather than the person). Check the same key where the order list and the Kanban card render it so all three agree.

**Reproduce**

```
same key as #1; rendered at resources/views/order/grid.blade.php:155
```

**What the three refuters found** — all three returned `refuted: false`.

* **`reproduce`** — NOT REFUTED — the finding reproduces exactly as described, at the exact line claimed. I tried four refutation angles and all four failed. WHAT I VERIFIED 1. file:line is exact. /home/user/syriastore98/resources/views/order/grid.blade.php:155 is `{{ __('Walk-in-customer') }}`. The surrounding branch is exactly as described: `@if ($order->is_guest == 1)` at :150, `@elseif ($order->customer_id != 0)` at :152, `@else` at :154, the string at :155, `@endif` at :156. No off-by-one. 2. The translation string is exact. /home/user/syriastore98/resources/lang/ar.json:3251 is `"Walk-in-customer": "المشي في العميل"`. I did not trust terminal rendering — I dumped the codepoints in logical order and got three words: [0] U+0627 U+0644 U+0645 U+0634 U+064A = المشي ("the walking"), [1] U+0641 U+064A = في ("in"), [2] U+0627 U+0644 U+0639 U+0645 U+064A U+0644 = العميل ("the customer"). "the walking in the customer". The semantic analysis in the finding is correct. 3. The key really resolves — I tried to break this and could not. `resources/lang` is the only lang directory (no `lang/` at root), which Lar…
* **`scope`** — VERDICT: the finding SURVIVES the scope angle — but its FIX as written does not, and I can add four specific things it lacked. I changed nothing (read-only). === CORE CLAIM VERIFIED === /home/user/syriastore98/resources/lang/ar.json:3251 — `"Walk-in-customer": "المشي في العميل"`, still present at HEAD cb01b7de with a clean working tree (branch claude/gracious-volta-duiynj). /home/user/syriastore98/resources/views/order/grid.blade.php:155 — `{{ __('Walk-in-customer') }}` in the `@else` after `is_guest == 1` / `customer_id != 0`. Route is routes/web.php:1245 → OrderController::order_grid_view() (app/Http/Controllers/OrderController.php:2615-2629, `return view('order.grid', ...)`). === NOT ALREADY FIXED, NOT ALREADY KNOWN === Not among the four fixed today (titles, <bdi> order ids, date localisation, `pending`). Not among the five known-open items. Not among F-M-01…F-M-09 in docs/development/phase-2.5/evidence/pkg-m-orders/README.md, the only PKG-M findings record. The only "Walk-in" hits in docs/ are F146 (about `payment_type`, not the translation) at docs/development/phase-2/PHASE-02-…
* **`harm`** — NOT REFUTED. I attacked this on the harm angle and it survived; my checking strengthened it and turned up a second instance the finding did not name. WHAT I VERIFIED 1. The evidence is literally accurate. Read programmatically, /home/user/syriastore98/resources/lang/ar.json:3251 holds `"Walk-in-customer": "المشي في العميل"`, and :3250 holds a second, different key `"Walk-in Customer": "عميل متجول"`. /home/user/syriastore98/resources/views/order/grid.blade.php:155 renders the hyphenated one in the `@else` of the `is_guest == 1` / `customer_id != 0` chain. The Arabic is a word-by-word gloss (Walk→المشي, in→في, customer→العميل); the same row in fr/ru/he/it is the same machine output, so the whole row is machine-generated. 2. THE BRANCH IS NOT DEAD — it is the default counter sale. This is where I expected to refute, since the finding concedes it never painted. The code says otherwise: - /home/user/syriastore98/app/Http/Controllers/PosController.php:301-304 assigns `$customer` only inside `if (!empty($request->vc_name))`. With no customer picked, `$customer` is never set, `isset($custome…

---

### #9 — The truncated order reference cannot be read, because the one tooltip the control can show is hardcoded to the constant word "Invoice ID"

**Screen:** `grid` · **Lens:** `a11y` · **Finder confidence:** `certain` · **STATUS: NOT STARTED**

**Evidence**

_order_grid__en_ltr_light.png and _order_grid__en_ltr_dark.png, cards 1 and 2: the title renders `#P25CUSTFILTE…`. _order_grid__ar_rtl_light.png and _order_grid__ar_rtl_dark.png, cards 3 and 4 (the same two orders, mirrored): `#P25CUSTFILTER-…`. Source: resources/views/order/grid.blade.php:143-145 — `class="ss-card__title text-truncate"` with `data-bs-toggle="tooltip" title="{{ __('Invoice ID') }}"`. The bundled Bootstrap v5.1.3 (public/assets/js/plugins/bootstrap.min.js, `_fixTitle`) moves that string into `data-bs-original-title` and removes the native `title`, and `getTitle()` reads `data-bs-original-title` first — so hover and focus both show "Invoice ID" and never the elided characters. Note the truncation point also differs by direction (one character more survives in RTL), so the two builds hide different amounts of the same id.

**Why it matters**

The order reference is the card's title and the thing a merchant matches against an email, an invoice or a courier manifest. For every id longer than about 13 characters it is unreadable on this screen, and the single affordance that could reveal it is spent restating a label the layout already makes obvious.

**Suggested fix (the finder's; not yet adjudicated)**

Put the value in the tooltip: `title="{{ $order->product_order_id }}"` at grid.blade.php:145. If the word "Invoice ID" must survive, it belongs in a `visually-hidden` span beside the value, the way the date label is handled at line 178.

**Reproduce**

```
sed -n '145p' resources/views/order/grid.blade.php   ->  title="{{ __('Invoice ID') }}" (a constant, not the value)
```

**What the three refuters found** — all three returned `refuted: false`.

* **`reproduce`** — REPRODUCED — the finding survives. I checked every anchor it gives and each one is exact. FILE:LINE IS CORRECT, NOT APPROXIMATELY CORRECT. /home/user/syriastore98/resources/views/order/grid.blade.php line 143 opens `<a class="ss-card__title text-truncate" dir="ltr"`, line 144 is the href, and line 145 is `data-bs-toggle="tooltip" title="{{ __('Invoice ID') }}"><bdi>#{{ $order->product_order_id }}</bdi></a>`. The cited range 143-145 and the fix target (line 145) are both right. The fix's cited precedent is right too: line 178 is the `<span class="visually-hidden">{{ __('Date') }}</span>` pattern, verbatim. THE BOOTSTRAP MECHANISM IS CORRECT DOWN TO THE MINIFIED SOURCE. /home/user/syriastore98/public/assets/js/plugins/bootstrap.min.js carries the `Bootstrap v5.1.3` banner. `_fixTitle(){const t=this._element.getAttribute("title"),e=typeof this._element.getAttribute("data-bs-original-title");(t||"string"!==e)&&(this._element.setAttribute("data-bs-original-title",t||""),...,this._element.setAttribute("title",""))}` and `getTitle(){const t=this._element.getAttribute("data-bs-original-title…
* **`scope`** — SCOPE ANGLE: the claim SURVIVES. I could not refute it on any of the seven scope criteria, and I checked each against the repository rather than against the brief. NOT ALREADY FIXED — verified in the file and in today's diff. /home/user/syriastore98/resources/views/order/grid.blade.php:145 still reads `data-bs-toggle="tooltip" title="{{ __('Invoice ID') }}"`. The stronger point: today's commit cb01b7de touched that exact line (`git diff --stat cb01b7de^ cb01b7de -- resources/views/order/grid.blade.php` = 4 insertions, 2 deletions; `git log -S'<bdi>#{{ $order->product_order_id }}'` names only cb01b7de). So the `<bdi>`/`dir="ltr"` repair the brief lists as already-fixed today was made ON this element and left the tooltip untouched. This is an adjacent defect the today-fix passed over, not a restatement of it. NOT ALREADY KNOWN AND OPEN — the package keeps its own finding ledger at /home/user/syriastore98/docs/development/phase-2.5/evidence/pkg-m-orders/README.md, which enumerates all nine PKG-M findings F-M-01…F-M-09 with state (five fixed, four open: F-M-02, F-M-03, F-M-06, F-M-08, F-…
* **`harm`** — NOT REFUTED. I went looking for a reason to dismiss this and found the opposite: the harm is real, it is worse than the finding says, and nothing in the repository records it as a decision. WHAT I CONFIRMED IN THE IMAGES /home/user/syriastore98/docs/development/phase-2.5/evidence/pkg-m-orders/after/_order_grid__en_ltr_light.png and _order_grid__en_ltr_dark.png: cards 1 and 2 both render `#P25CUSTFILTE...`. _order_grid__ar_rtl_light.png and _order_grid__ar_rtl_dark.png: the same two orders render `#P25CUSTFILTER-...`. The direction-dependent truncation point is real (12 glyphs survive LTR, 14-15 RTL) and has a plain cause the finding does not give: the status chip beside the title is `Pending` in English and `معلق` in Arabic, so the narrower chip leaves the title more room. THE HARM IS CONCRETE, AND BIGGER THAN "AN ID IS HARD TO READ" _order__en_ltr_light.png (the list view, same fixture) shows the two full ids: `#P25CUSTFILTER-2-2` and `#P25CUSTFILTER-2-1`. In the grid both collapse to the same string, with the same customer (Omar Khalil), the same `$150.00`, the same `Pending` chip.…

---

### #10 — The card view has no filter, no search and no sort, and it silently discards the filter the merchant arrived with — so the one thing a merchant comes to an order screen to do, find an order, is impossible here.

**Screen:** `grid` · **Lens:** `workflow` · **Finder confidence:** `certain` · **STATUS: NOT STARTED**

**Evidence**

`app/Http/Controllers/OrderController.php:2615` — `order_grid_view(Request $request)` never reads `$request`; line 2627 is the whole query: `Order::where('store_id', $storeId)->orderBy('created_at','desc')->paginate(16)`. `resources/views/order/grid.blade.php` contains zero `ss-select`/`ss-input`/sort controls in all 302 lines (grep count 0). The entry point `resources/views/order/index.blade.php:23` is `route('order.grid')` with **no** `request()->query()`, while the Board View link ten lines down at `:33` is `route('order.kanban', request()->query())` — the two sibling links were written differently. The grid's own way back, `grid.blade.php:12`, is `route('order.index')`, also without the query. Captures: `_order__en_ltr_light.png` shows the list's six-control filter bar (Order Status / Returns / Payment Status / Source / From / To + Apply filters) and a Search box; `_order_grid__en_ltr_light.png` and `_order_grid__ar_rtl_light.png` show the same screen with none of them. The aggravator is `OrderController.php:582-586`: `/order` restores a remembered filter from `TableFilterMemory` even on a bare URL, so after list → cards → list the list is filtered again and the cards never were, with nothing on the card screen stating its scope.

**Why it matters**

The merchant filters to "returns, last 7 days", clicks the cards icon to scan them visually, and gets every order in the store with no warning and no way to re-filter. The list's own "Needs attention" panel in `_order__en_ltr_light.png` says 58 orders are waiting to be confirmed; at 16 cards a page that is four pages of cards that read `$10.00 / Layla Haddad / Pending` and differ only in an id that is itself truncated (see the next finding). There is no way to reach a specific order from this screen at all. It is the view you would use to triage, and it cannot triage.

**Suggested fix (the finder's; not yet adjudicated)**

Narrowest: make `order_grid_view` reuse the same query-string vocabulary the list and board already share (`ss_status`, `ss_return`, `ss_payment`, `ss_source`, `ss_from`, `ss_to`) and render `resources/views/order/partials/filters.blade.php` on this screen with its form `action` pointing at `order.grid`; then change `index.blade.php:23` to `route('order.grid', request()->query())` and `grid.blade.php:12` to `route('order.index', request()->query())` so all three view links carry filters the way the board link already does. If filtering here is genuinely out of scope, the minimum is to state the scope on the page ("All orders — N") so the merchant knows their filter was dropped.

**Reproduce**

```
sed -n '2615p;2627p' app/Http/Controllers/OrderController.php   ->  $request is never read; the query has no filter
```

**What the three refuters found** — all three returned `refuted: false`.

* **`reproduce`** — NOT REFUTED — every file:line and both captures reproduce exactly, with nothing off by a line. Controller, /home/user/syriastore98/app/Http/Controllers/OrderController.php: - :2615 is exactly `public function order_grid_view(Request $request)`. - :2627 is exactly `$orders = Order::where('store_id', $storeId)->orderBy('created_at', 'desc')->paginate(16);` and it is the only query in the method (body 2615-2633: permission gate, `resolvedCurrentStoreId()`, null guard, that query, `view('order.grid', compact('orders'))`). - `$request` occurs exactly once in the method — in the signature on 2615 — and is never read. Confirmed by grepping the 2615-2633 range: the only hit is the signature. - :582-586 is the aggravator as described: `TableFilterMemory::shouldRestore($request)` -> `recall('order')` -> `redirect()->route('order.index', $remembered)`. And app/Services/TableFilterMemory.php:134-137 is `public static function shouldRestore(Request $request): bool { return $request->query() === []; }` — so a bare `/order` really does restore a remembered filter. `TableFilterMemory::` appears only…
* **`scope`** — SCOPE ANGLE: the finding SURVIVES. I could not refute it on any of the six disqualifiers, and I confirmed the core claim in both the code and the real captures. WHAT I CHECKED, AND WHAT I FOUND 1. Already fixed today? No. The brief's four fixed items are the titles, the `<bdi>` order ids, `Utility::dateFormat` localisation and the `pending` payment status — all four are recorded as fixed in /home/user/syriastore98/docs/development/phase-2.5/evidence/pkg-m-orders/README.md ("What was done"). None is this. 2. Already known and open? No. The brief's five open items are that README's F-M-02, F-M-03, F-M-06, F-M-08 and F-M-09. I read all nine survey findings (F-M-01…F-M-09) in full: **not one of them concerns filtering, search, sort or the dropped query string on the card view.** This is a new finding in the project's own tracking, not a restatement. 3. Already addressed in the repo? No. Working tree is clean; HEAD is cb01b7de (2026-11-08 17:47), and no commit touching /home/user/syriastore98/resources/views/order/grid.blade.php adds filter controls. `grep -c "ss-select|ss-input|ss-field|…
* **`harm`** — NOT REFUTED. I tried all three harm grounds (taste, fix-makes-things-worse, recorded decision) and each failed against the repository's own evidence. Three sentences in the write-up are overstated and should be trimmed, but the defect and its cost survive. CONFIRMED - Images at /home/user/syriastore98/docs/development/phase-2.5/evidence/pkg-m-orders/after/: _order_grid__en_ltr_light.png and _order_grid__ar_rtl_dark.png show the card screen with nothing above the first row of cards but title, breadcrumb and three icon buttons — no filter bar, no search, no sort, in both directions and both themes. _order__en_ltr_light.png shows the list's six-control bar plus Search, entries-per-page and sortable headers. - /home/user/syriastore98/app/Http/Controllers/OrderController.php:2615 `order_grid_view(Request $request)` never reads `$request`; :2627 is the whole query. The only forms in the 302-line grid.blade.php are the per-card delete form and the return-request AJAX. - Line numbers all correct: resources/views/order/index.blade.php:23 bare `route('order.grid')`, :33 `route('order.kanban', …

---

### #11 — Two different orders render with the identical truncated title, and the delete confirmation does not name the order — so a destructive action is confirmed against a target the merchant cannot distinguish.

**Screen:** `grid` · **Lens:** `workflow` · **Finder confidence:** `certain` · **STATUS: NOT STARTED**

**Evidence**

`_order_grid__en_ltr_light.png`, the first two cards of row 1, both read `#P25CUSTFILTE…`; `_order_grid__ar_rtl_dark.png` and `_order_grid__ar_rtl_light.png` both read `#P25CUSTFILTER-…` on the same two cards. `_order__en_ltr_light.png` shows the same two orders in the list, untruncated and distinct: `#P25CUSTFILTER-2-2` and `#P25CUSTFILTER-2-1`. Cause: `resources/views/order/grid.blade.php:143` — `class="ss-card__title text-truncate"`, in a flex row that also holds the status badge at `:146`, so a long status label (`Return request processing` / `Partially Paid`) squeezes the title further. The delete button at `grid.blade.php:221-224` passes only generic text to the confirm dialog — `data-confirm="{{ __('Are You Sure?') }}"`, `data-text="{{ __('This action can not be undone…') }}"` — and the full id lives only in the invisible `aria-label` at `:224`; the visible tooltip is just `Delete`/`حذف`.

**Why it matters**

On this screen the only thing distinguishing those two cards is `Nov 6` vs `Nov 5` in 12px meta text at the opposite corner. The merchant clicks the trash on one of them, gets a dialog that names no order, confirms, and `OrderController::destroy` (2316-2333) permanently removes it. A delete flow in which neither the card nor the dialog identifies the row being destroyed is one wrong click from an unrecoverable loss.

**Suggested fix (the finder's; not yet adjudicated)**

Two small changes: put the order reference into the dialog — `data-text="{{ __('Order :id will be permanently deleted. This action can not be undone.', ['id' => $order->product_order_id]) }}"` (note the `>`-in-Blade-expression hazard documented at `grid.blade.php:210-220`, so keep it after `data-confirm` like the `aria-label`); and stop the title truncating to ambiguity — allow it to wrap to two lines, or move the status badge below the title so the id gets the full card width.

**Reproduce**

```
sed -n '143p;221,224p' resources/views/order/grid.blade.php   ->  truncated title + a delete dialog that names no order
```

**What the three refuters found** — all three returned `refuted: false`.

* **`reproduce`** — SURVIVES. I checked every cited file:line and all four captures; each says exactly what the finding claims. CAPTURES (read, not inferred): - /home/user/syriastore98/docs/development/phase-2.5/evidence/pkg-m-orders/after/_order_grid__en_ltr_light.png — row 1, cards 1 and 2 both render `#P25CUSTFILTE…`. Everything else on the two cards is identical: badge `Pending`, customer `Omar Khalil`, `$150.00`, label `Price`. The ONLY differing pixel is the date, `Nov 6, 2026` vs `Nov 5, 2026`, in muted text at the opposite corner of the header. Both cards carry a live red trash button. - _order_grid__ar_rtl_dark.png and _order_grid__ar_rtl_light.png — the same two cards (rightmost two of row 1 under RTL) both render `#P25CUSTFILTER-…`. The finding's distinction between the EN truncation point (`#P25CUSTFILTE…`) and the AR one (`#P25CUSTFILTER-…`) is exactly what the images show. - _order__en_ltr_light.png — the list renders the same two orders untruncated and distinct: `#P25CUSTFILTER-2-2` (Nov 6, 2026) and `#P25CUSTFILTER-2-1` (Nov 5, 2026), both Omar Khalil, both $150.00. So the ambiguity is c…
* **`scope`** — SCOPE ANGLE: the claim survives every one of the seven refutation conditions. I read the images and checked the repository; I changed nothing. === 1. ALREADY FIXED TODAY? NO === The repo's own record of today's work is /home/user/syriastore98/docs/development/phase-2.5/evidence/pkg-m-orders/README.md, whose closing table names exactly what was repaired: F-M-01 (titles), F-M-04 (`pending`), F-M-05 (`<bdi>`), F-M-07 (dates), F-M-08 date-half. Title truncation and dialog naming are in none of them. Confirmed against git: the only PKG-M commits are 196ddcc3, 16fb25a8 (survey, no code) and cb01b7de (the five repairs). Sharper than that: F-M-05's fix edited THE SAME ELEMENT. /home/user/syriastore98/resources/views/order/grid.blade.php:143-145 is the `<a class="ss-card__title text-truncate" dir="ltr">...<bdi>#{{ $order->product_order_id }}</bdi></a>` that was touched today to add `<bdi>`, and the truncation survived that edit. The element was looked at this afternoon and this was missed — the opposite of already-fixed. === 2. ALREADY KNOWN AND OPEN? NO === The brief's five known-open items …
* **`harm`** — NOT REFUTED on the harm angle. I read the three grid screenshots and traced every link of the harm chain in code; each one holds, and two production facts make the harm worse than the screenshot shows. VISUAL PREMISE CONFIRMED. In _order_grid__en_ltr_light.png the first two cards are identical on every visible field but one: both `#P25CUSTFILTE…`, both `Omar Khalil`, both `$150.00`, both `Pending`; only `Nov 6, 2026` vs `Nov 5, 2026` differs. Reconfirmed in _order_grid__ar_rtl_dark.png (`#P25CUSTFILTER-…` on the same two cards). THE REFUTATION I EXPECTED TO WIN, AND WHY IT FAILED. The natural refutation is that P25CUSTFILTER-2-1/2-2 are dev seed fixtures (docs/development/phase-2.5/tools/seed-dev-customer-filter-fixtures.php:60) with an artificially long shared prefix, so the ambiguity is manufactured. But the live generator at app/Services/PaymentService.php:2785,2789 is `'0' . date('YmdHis')` (guest) or `$orderCustomerId . date('YmdHis')` (signed-in). Real ids are DATE-PREFIXED, so truncation strips the trailing time digits — exactly the discriminating part. Two same-day orders con…

---

### #12 — Pressing a card's Move button without choosing a status emails and SMSes the customer a status-change notification, and writes an order note reading "Order status changed to .", for a change that did not happen

**Screen:** `board` · **Lens:** `workflow` · **Finder confidence:** `certain` · **STATUS: NOT STARTED**

**Evidence**

The control cannot be disarmed: resources/views/order/partials/kanban-card.blade.php:69 is `<option value="">{{ __('Move to…') }}</option>` — not `disabled` — the select at :68 has no `required`, the submit at :74 is always enabled, and there is no JavaScript on the screen (`grep -c '<script'` is 0 in both resources/views/order/kanban.blade.php and the partial). So `delivered=""` posts. app/Http/Controllers/OrderController.php:1737 sets `$data['order_status'] = $request->delivered;` (empty), :1738 calls the writer, which matches no branch and returns `status => 'error'` (app/Models/Order.php:772-775). But the notification block runs BEFORE that result is read: :1793 `Utility::sendEmailTemplate('Status Change', $order['delivery_informations']['email'], ...)`, :1800 `event(new SendOrderReviewMail(...))`, :1803-1813 `OrderNote::order_note_data([... 'status' => 'Order status change', 'changeble_status' => $request->delivered])`, :1819-1820 `$msg = __("...Your Order is $request->delivered, !Hi $order_id..."); Utility::SendMsgs('Status Change', $mobile_no, $msg);` — and only then :1824 `if ($responce['status'] == 'success')`. app/Models/OrderNote.php:181 renders that note as `'Order status changed to '.$data['changeble_status'].'.'` → literally "Order status changed to .". The merchant sees only a flash reading "That order status is not recognised."

**Why it matters**

The captures show the hazard's shape: in _order_kanban__en_ltr_light.png the Pending column stacks three cards whose `Move to…` select and `Move` button are pixel-identical and vertically aligned, so clicking Move on the wrong card, or clicking it before touching the select, is an ordinary slip — and the board is the only surface that exposes an unset value, because the detail page always sends a chosen `data-value`. The cost of that slip is borne by the buyer: a "Status Change" email and an SMS reading "Your Order is , !Hi ORD-2043". The merchant is told nothing was changed, so they will not know a message went out, and the order's own timeline now carries a false entry. The error text also blames the data rather than telling the merchant to choose a status.

**Suggested fix (the finder's; not yet adjudicated)**

Two narrow changes. In kanban-card.blade.php:69 make the placeholder `disabled selected` and add `required` to the select at :68, so the browser refuses an empty submit and points at the control. Independently, move the notification block in OrderController.php:1791-1822 inside the `if ($responce['status'] == 'success')` branch at :1824 — a status-change email, SMS and order note should not be emitted for a write that returned an error.

**Reproduce**

```
sed -n '68,69p;74p' resources/views/order/partials/kanban-card.blade.php   ->  <option value=""> is NOT disabled, the select has no required, the submit is always enabled
```

**What the three refuters found** — all three returned `refuted: false`.

* **`reproduce`** — REPRODUCED. I opened every cited file:line and the capture; the mechanism is there as described. MARKUP (/home/user/syriastore98/resources/views/order/partials/kanban-card.blade.php) — all three citations exact: - :68 `<select class="ss-select ss-select--sm" id="ss-move-{{ $ssOrder->id }}" name="delivered">` — no `required`. - :69 `<option value="">{{ __('Move to…') }}</option>` — no `disabled`, and no later option carries `selected`, so it is the default selection and it posts. - :74 `<button type="submit" class="ss-btn ss-btn--secondary ss-btn--sm">{{ __('Move') }}</button>` — unconditionally enabled. - `grep -c '<script'` is 0 in both kanban.blade.php and the partial (I ran it). I also checked the refutation angle the finding did not: kanban.blade.php pushes nothing into the layout's `@stack('scripts')` / `@stack('custom-script')` (/home/user/syriastore98/resources/views/layouts/app.blade.php:812-814) — it declares only `@section('page-title'|'breadcrumb'|'content')` — and no asset references `ss-board__card-move`. Nothing disarms the control. CONTROLLER (/home/user/syriastore98/a…
* **`scope`** — VERDICT ON THE SCOPE ANGLE: the claim SURVIVES. I could not refute it on any of the six scope grounds, and three of them I expected to land. NOT ALREADY FIXED. At HEAD cb01b7de (2026-11-08, the latest commit) /home/user/syriastore98/resources/views/order/partials/kanban-card.blade.php:68-74 still reads `<select class="ss-select ss-select--sm" id="ss-move-{{ $ssOrder->id }}" name="delivered">` / `<option value="">{{ __('Move to…') }}</option>` — no `disabled`, no `required`, submit always enabled — and /home/user/syriastore98/app/Http/Controllers/OrderController.php:1793 (sendEmailTemplate), :1800 (SendOrderReviewMail), :1803-1815 (OrderNote::order_note_data) and :1818-1820 (SendMsgs) all still sit ABOVE `if ($responce['status'] == 'success')` at :1824. Every line number in the finding is exact. The four items fixed today are recorded in the "What was done" table of /home/user/syriastore98/docs/development/phase-2.5/evidence/pkg-m-orders/README.md (F-M-01 titles, F-M-04 `pending`, F-M-05 `<bdi>`, F-M-07 dateFormat); none touches this control or this handler. NOT ONE OF THE KNOWN-OPEN …
* **`harm`** — CLAIM SURVIVES. I tried to refute it on all four grounds the HARM angle allows and could not. WHAT I VERIFIED MYSELF Reachability (markup + images). resources/views/order/partials/kanban-card.blade.php:68-74 — select has no `required`, :69 placeholder `<option value="">` is neither `disabled` nor `selected`-off, submit at :74 always enabled. `grep -c '<script'` is 0 in kanban.blade.php, kanban-card.blade.php AND partials/filters.blade.php (the claim checked only two of the three; the third is also 0, so there is genuinely no JS on the screen). Both captures confirm it: in _order_kanban__en_ltr_light.png the Pending column stacks three cards whose `Move to…` / `Move` pairs are pixel-aligned, and in _order_kanban__ar_rtl_dark.png the same `نقل إلى...` / `نقل` pair sits on all four rendered cards with the placeholder displayed as the live selection. Laravel's ConvertEmptyStringsToNull turns the post into `null`, which changes nothing downstream. Every cited line is exact. OrderController.php:1737 `$data['order_status'] = $request->delivered;`, :1738 the writer call, :1793 sendEmailTempl…

---

## 3. The 103 refuted findings

They are **not** a backlog. Each was examined by three agents and at least one found it did not
hold. The full list with the refuting reasons is in the run's journal:

```
/root/.claude/projects/-home-user/<session>/subagents/workflows/wf_cd4c60b8-8cd/journal.jsonl
```

That path is **outside the repository** and does not survive the container. A condensed index of
titles and the angle that killed each one follows, so the next account can see what was already
considered and not re-raise it.

| screen / lens | title | killed by |
|---|---|---|
| list / layout | The table card spends 175px of height on a toolbar whose two control clusters would fit on one line, 52px of it entirely empty | harm |
| list / layout | The order-status pill is sliced in half by the pinned Action column in Arabic too — the recorded measurement says RTL is clean, and today's date local | harm |
| list / layout | Four different content gutters stack down one column, so no two blocks on the page share a left edge | harm |
| list / layout | Each "Needs attention" row puts 761–851px of empty space between a sentence and the button that acts on it | harm |
| list / layout | The wrapped "To" date field renders 982px wide — 4.7x the "From" field it is one half of | harm |
| list / layout | At phone width the "Action" row is the only value in the stacked row card that does not sit on the card's value column | harm |
| list / rtl | The order-id cell is the only one of the three order screens whose `#` is not bidi-isolated, so the Arabic table renders the reference as `P25CUSTFILT | harm |
| list / rtl | Arabic dates keep the en-US component order and a Latin comma — `تشرين الثاني 6, 2026` where Arabic is `6 تشرين الثاني 2026`. | harm |
| list / rtl | One screen names the payment concept with two different Arabic roots, and the "Needs attention" link sends the merchant from one word to the other. | harm |
| list / rtl | The Customer Info column says `العميل` while the sidebar the merchant just clicked says `الزبائن` — the same disagreement PKG-L already settled elsewh | harm |
| list / rtl | Today's page-title fix changed the `<h1>` but not the breadcrumb, so the Arabic page now reads `الطلبات` in the heading and `الطلب` one line below it. | harm |
| list / rtl | The export button's Arabic accessible name is `الصادرات` — "exports" in the balance-of-trade sense, not "export this list". | harm, scope |
| list / rtl | The grid button still says `عرض الشبكة` ("grid view") although today's fix renamed the page it opens to `بطاقات الطلبات` ("order cards"). | harm, reproduce, scope |
| list / mobile | At phone width the whole <thead> is visually clipped, so there is no way to sort the order list by touch — and the select-all checkbox disappears with | harm |
| list / mobile | The Order Status value overflows its badge and is cut off at 320px, and today's Arabic date localisation has pushed the Arabic badge to the same limit | harm |
| list / mobile | The row action strip is four 32x32 controls at 4px apart inside a 153px cell, with Delete 4px from Edit and no room left to enlarge them | harm, reproduce |
| list / mobile | The per-row bulk-select checkbox is a bare 13x13 CSS-pixel target with no label, and its select-all partner is in the clipped head | harm, reproduce |
| list / mobile | In the stacked card the Action row is the only row whose content does not line up with the value column | harm |
| list / a11y | The native calendar-picker glyph on the From and To date filters renders pure black on the dark field — 1.48:1. | harm |
| list / a11y | The column-sort arrows — the only cue that an order-list column is sortable — are drawn at opacity 0.3, giving 1.53:1 in light and 1.89:1 in dark. | harm |
| list / a11y | The table's search box and the "Entries Per Page" select have their focus outline explicitly removed and replaced with a 25%-opacity glow at roughly 1 | harm, reproduce, scope |
| list / a11y | The order list's breadcrumb still says `Order` / `الطلب` directly under the heading that was corrected today to `Orders` / `الطلبات`. | harm |
| list / workflow | Permanent deletion is the only bulk action the order list offers, and it is the loudest control on the toolbar; the fulfilment transition a merchant a | harm, scope |
| list / workflow | The toolbar's Bulk Delete hard-deletes orders by raw id with no permission check, no store predicate and none of the cascade the identical-looking row | harm, reproduce, scope |
| list / workflow | Export hands back every order the store has ever taken, ignoring the filters the merchant applied in the bar directly beneath the button. | harm |
| list / workflow | Row selection is destroyed by every table redraw and "select all" means only the ten rows on screen, yet the confirmation for the destructive bulk act | harm |
| list / workflow | The order-id badge and the "Edit" pencil are two controls to the identical URL, and two of the four row icons are two different read-only previews of  | harm, reproduce |
| list / workflow | Nothing the merchant does on this screen updates the "Needs attention" counts, so the number they are working to zero never moves. | harm, reproduce |
| list / consistency | The `<bdi>` repair for Arabic order ids landed on the grid and the kanban card but not on the list's DataTable, so the Arabic order list still prints  | harm |
| list / consistency | The order row's delete confirmation uses a different convention from the customer row's — generic strings and no `data-confirm-tone`, so it still gets | harm, reproduce, scope |
| list / consistency | The breadcrumb directly under the heading still says `Order` / `الطلب` while the heading now says `Orders` / `الطلبات` — the plural fix changed the ti | harm |
| list / consistency | The tooltip on the Order Id cell calls the value an Invoice ID, directly under a column header that calls it an Order Id — two different commerce noun | harm |
| grid / layout | The price renders at 28px against an 18px title, so the figure outranks the order it belongs to — the exact inversion the design system already identi | harm |
| grid / layout | The card view drops the filter region and the primary action that its two sibling views of the same data both carry, so the three order screens do not | harm, reproduce |
| grid / layout | The action strip is a padding-driven, mostly empty second region: ~27% of each card's height holds three 28px icons in a band that is ~55% blank and i | harm, reproduce |
| grid / layout | The `Price` label and the date sit in one row but are offset by exactly 4px, in every card, in both directions — a label's inherited bottom margin bre | harm |
| grid / layout | The column ladder has no md/lg rung, so the deck is 2-up from 576px all the way to 1199px and the 16-per-page size leaves an orphan card at xl. | harm |
| grid / rtl | The breadcrumb separator points DOWN in Arabic instead of left — the RTL override double-flips an already-logical corner | harm |
| grid / rtl | The Arabic date is an English date wearing Arabic words: `تشرين الثاني 7, 2026` — US month-day-year order with an ASCII comma | harm |
| grid / rtl | The Show modal's title rebuilds the `ORD-2043#` bidi defect that was fixed two lines above it in the same file | harm |
| grid / rtl | `Export` is translated as the trade noun `الصادرات` (a country's exports), on an orders screen in a commerce admin | harm, scope |
| grid / rtl | The status badge labels are the wrong part of speech, use the wrong verb, and disagree in gender with `الطلب` | harm, scope |
| grid / rtl | The breadcrumb leaf says `الطلب` where the heading above it says `بطاقات الطلبات` and the sidebar says `الطلبات` — three names for one section on one  | harm |
| grid / rtl | The pagination's prev/next arrows are hard-coded directional SVGs that nothing mirrors for RTL | harm |
| grid / mobile | The order card's three action controls are 32×29 px at every viewport — the card gets no phone treatment at all, and Delete sits 9 px from Edit | harm, scope |
| grid / mobile | On a phone the three card actions have no visible name at all: their only label is a hover-triggered Bootstrap tooltip, which a touch device cannot pr | harm |
| grid / mobile | `min-w-0` at order/grid.blade.php:160 is an inert Tailwind class — the design system fix for this exact defect was applied to the customer grid and mi | harm |
| grid / mobile | At phone width the card view offers no way to narrow or jump: it is the only one of the three order screens with no filter bar, and below 767 px the n | harm, scope |
| grid / a11y | On keyboard focus the Delete button's trash glyph drops to 1.00:1 — the icon vanishes into the focus fill, and the outline that would have compensated | harm, reproduce |
| grid / a11y | The three per-card action buttons draw their only boundary at #f1f1f1 in BOTH themes: 1.13:1 against the white card in light, 12.62:1 against the dark | harm |
| grid / a11y | The 16-card collection has no list semantics and no heading on any card, so a screen-reader user gets 16 undifferentiated text runs with nothing to na | harm |
| grid / a11y | On page 1 the pager exposes two controls both named "Next Page" — the disabled previous arrow is labelled Next, and previous is never named | harm |
| grid / a11y | `aria-current="page"` names the wrong page: the breadcrumb says "Order", which is the name of a different, existing screen | harm |
| grid / a11y | The three dialog/POST controls on each card are anchors, not buttons — and the return handler never calls preventDefault, so Approve/Reject also throw | harm |
| grid / workflow | `Create Order`, the list's primary action, does not exist on the card view — switching presentation removes the merchant's main write action. | harm |
| grid / workflow | Approving or rejecting a return is a one-click, unconfirmed inventory write whose reject button is labelled `Cancel`, and the card keeps showing the o | harm |
| grid / workflow | The trash icon is a permanent, unreversed deletion of a financial record, and its confirmation dialog paints the destructive button green and the safe | harm, scope |
| grid / workflow | The pencil labelled `Edit` and the card title link to exactly the same URL — a duplicated control in a three-icon row. | harm |
| grid / workflow | A merchant with no orders — or whose store has none yet — gets a completely blank screen with no message and no way forward. | harm |
| grid / consistency | The order card's action row is still vendored Bootstrap buttons while the order LIST's identical action cell was converted to the design system — same | harm |
| grid / consistency | The price renders at the dashboard stat size (28px), so the card's own stated title renders at 0.64x its figure — the design system already ships `.ss | harm |
| grid / consistency | The card view has no empty state at all, although the product already holds written, translated, icon-chosen empty copy for orders and both sibling or | harm, scope |
| grid / consistency | The card is built from Bootstrap/WorkDo card regions and a `border` utility instead of `.ss-card` and its header/body/footer, and the file carries no  | harm |
| grid / consistency | The card view is the only one of the three order views with no filter bar, so switching from the list to the cards silently discards the merchant's fi | harm, reproduce |
| grid / consistency | No result count on the card view — the same `hasPages()` gate in the shared pagination partial that the customer grid already worked around | harm, reproduce, scope |
| grid / consistency | `.ss-order-card__money` and `.ss-order-card__date` are `ss-`-prefixed class names with no rule in any stylesheet in the repository | harm, reproduce, scope |
| grid / consistency | The return Approve/Cancel pair renders as two filled buttons where the list renders the same pair quiet, and `btn-primary` puts an unguarded foregroun | harm, reproduce, scope |
| grid / consistency | The primary action disappears on the card view: the list header offers Create Order, the card header does not | reproduce |
| board / layout | The board's columns are stretched to the tallest column's height, so every short or empty column paints hundreds of pixels of bare sunken surface that | harm |
| board / layout | The board is the only one of the three order views that leaves the page header's action zone empty, because it repeats its own title in a card header  | harm |
| board / layout | The `To` date field stretches to ~980px — 4.7x the width of the `From` field directly above it — because `.ss-filterbar__field` grows without a cap an | harm |
| board / layout | The column count lands in a different horizontal position in each column of the same row, because the header's `space-between` has three children in a | harm |
| board / layout | Four different left insets inside one vertical stack: the filter labels, the card title, the column rail and the column's own content each start at a  | harm, reproduce |
| board / rtl | The board's "Picked Up" column is translated "تم التقاطها" (photo-taking / snatching), while the order preview renders the SAME status "تم الاستلام" — | harm, reproduce |
| board / rtl | Three of the seven order-status labels attach the feminine pronoun ـها to a noun the same screen calls masculine ("الطلب"), so the board headers read  | harm |
| board / rtl | The sixth board column is headed "إلغاء" — the imperative Cancel button label, not a state — because the board reads `__('Cancel')` where the preview  | harm |
| board / rtl | Every card's payment chip reads "مقبوض" (cash collected), under a filter labelled "حالة الدفع", while the third value of the same three-value vocabula | harm |
| board / rtl | "Walk-in Customer" is translated "عميل متجول" — an itinerant/roving customer — and it uses عميل where the sidebar on the same screenshot says الزبائن | harm |
| board / rtl | The Kanban breadcrumb's middle crumb says "الطلب" (the order, singular) and links to a page whose heading and sidebar entry both say "الطلبات" | harm |
| board / rtl | The two date filters are not merely localised wrong — the whole native control renders LTR inside the RTL page, with the picker button on the inline-S | harm |
| board / rtl | "Saved views" is translated "العروض المحفوظة" — in an e-commerce admin عروض reads as saved promotional offers | harm, reproduce |
| board / mobile | At phone width the board column is wider than the board's visible content box, so no sliver of the next column shows and six of the seven stages have  | harm, reproduce |
| board / mobile | The move control — the card's only action, and the board's only phone-facing control — is built at 32px and 40px, below the 44px target this project d | harm, reproduce, scope |
| board / mobile | `.ss-select--sm` drops the move select to 13px, defeating the 16px the base rule exists to guarantee ("prevents iOS zoom-on-focus"), and the class is  | harm |
| board / mobile | Applying any filter from the board navigates to the list, so on a phone the only filter journey the board offers ends by leaving the board — and the w | harm |
| board / mobile | The 70vh inner card scroller spans essentially the whole phone viewport width, so a vertical swipe on the board scrolls a 50-card column instead of th | harm, reproduce |
| board / a11y | The Move submit button — the control that actually writes the order's status — has no per-order accessible name; a column renders up to 50 buttons all | harm |
| board / a11y | The card's payment-status badge is unlabelled, and in Arabic it prints the exact same word as the column it is sitting in — `معلق` inside a column hea | harm |
| board / a11y | Six of the nine column status chips have no fill a reader can see: `--ss-neutral-bg` and `--ss-surface-sunken` are the same token, so a neutral `.ss-b | harm, reproduce |
| board / a11y | The nine board columns carry no heading element, and this screen holds the only two `<h5 class="ss-card__title">` in the repository — so its outline i | harm |
| board / a11y | The order-id link on each card is distinguished from the text beneath it by colour alone, at 2.64:1 (light) and 1.74:1 (dark) — below the 3:1 that WCA | harm, reproduce |
| board / workflow | The board's Move control bypasses the fulfilment state machine the order detail page enforces, so a cancelled or returned order can be reopened to Del | scope |
| board / workflow | The platform operator can open the board and is authorised to move orders, but every card's Move control is hidden from them, because `@permission` ha | harm, scope |
| board / workflow | The board computes the three "needs attention" counts on every load and renders none of them, so the return-request queue — which no board column can  | harm |
| board / workflow | A capped column keeps the NEWEST 50 orders and the card carries no date, so the oldest and most urgent orders are exactly the ones the board hides — a | harm |
| board / workflow | There is no bulk move: a 58-deep Pending column must be worked one card at a time, each one a separate form POST and a full board reload | harm, scope |
| board / consistency | The board hand-rolls the status badge and drops the design system's status dot, so the same order status renders one way on /order and /order-grid and | harm, reproduce |
| board / consistency | The kanban card carries a second, disagreeing payment-status tone table: an unpaid order is inert grey on the board and warning-amber everywhere else | harm |
| board / consistency | The board's order-source chip is always neutral, discarding the brand/info variants the order list uses for the same three values | harm |
| board / consistency | The board is the only collection view in the admin with no `@section('action-button')`, so it loses the shared view switcher and Export, and the card  | harm |
| board / consistency | The board's empty state is a hand-rolled `.ss-empty` that omits the icon and the title class, so it renders as a 13px muted sentence instead of the de | harm |
| board / consistency | The board hand-builds `.ss-card` rather than using `<x-ss.card>`, and its two card titles are the only `<h5 class="ss-card__title">` in the entire vie | harm |
| board / consistency | A neutral `ss-badge` on a board column header is the exact same colour as the column, so the 'not a fulfilment stage' chips have no chip | harm |

---

## 4. What was NOT audited

* `/order-view/{id}` (order detail), `/order-preview/{id}` (side panel), the AJAX order modal,
  `createOrder`, the receipt, the shipping label, the refund screens. The audit covered the three
  **list presentations** only.
* 320px for `/order-grid` and `/order-kanban` — captures exist only at 1440 and 390 for those two.
  The `mobile` finders said so rather than guessing, and that is why no 320 finding appears.
* Anything behind a permission the dev fixture merchant does not hold.
