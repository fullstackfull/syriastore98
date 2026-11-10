# P2.5-SET — the settings hub's AJAX tabs

21 merchant-visible tabs, 4 combinations each (en-LTR / ar-RTL × light / dark).
**84 captures, 84 distinct. 21 tabs measured, 0 findings.**

---

## 1. Why a plain capture could not do this

The settings hub is eleven matrix rows behind one URL. Its panels are Bootstrap pills that POST to
`setting.form` and swap the panel over XHR, and the active tab is remembered in the **session**
(`resources/views/setting/index.blade.php:29`), not in the URL. Navigating to `/setting` eleven
times photographs the same tab eleven times.

`render-matrix.cjs` gained `--click-each '<selector>'`: one capture per matching element, named
after its `data-tab`. It is a general capability, not a settings-specific script — the next group
of in-page panels uses the same flag.

Two harness defects were fixed while building it, both of which would have produced clean-looking
evidence of nothing:

| | |
|---|---|
| **all 21 panels reported empty** | the panel selector assumed `.tab-pane.active`. This hub writes the XHR result straight into `#pills-tabContent` (`index.blade.php:398`), so there is no pane to find. `--panel` now defaults to both shapes. |
| **6 panels empty, a different 6 each run** | a race. `networkidle` + a 400 ms settle is not a signal. A varying result is the signature of one, and a race here screenshots a `.ss-skeleton` placeholder, which photographs as a clean empty screen. Replaced with `waitForFunction` polling for real text **with no skeleton left in the panel**. |

---

## 2. Measured, not only photographed

A screenshot is evidence a surface exists. It is not evidence it is good — this phase has twice
found 1.00:1 white-on-white and English words in the Arabic UI sitting in an image all along.

`tools/measure-settings-tabs.cjs` reads each **loaded panel** (not the page — the shell is already
verified and its findings would drown the panel's own) for four things:

| | |
|---|---|
| **contrast** | every text node against its composited background, WCAG 1.4.3 floors, alpha flattened up the ancestor chain. Maths taken unchanged from `census-low-contrast-text.cjs`, including the clipped-away check that stops it reporting deliberate screen-reader labels. |
| **overflow** | the panel's own box as reference, and any descendant extending past it — the defect a viewport-cropped screenshot hides off-frame |
| **latin** | Latin-script words in the Arabic UI, minus a declared allowlist |
| **nameless** | form controls with no accessible name, which is most of what a settings tab *is* |

**Final run: 21 tabs × 4 combinations, `FINDINGS: 0`.** Raw data in `measurements.json`.

### The tool was proved non-vacuous before its clean result was believed

With the Arabic currency keys stashed it reports **26 findings**; with them restored, **0**. A
measurement that cannot fail is not a measurement.

### The Latin allowlist is read from the application

`measure-settings-tabs.cjs` parses `App\Payments\PaymentTypeLabel::BRANDS` rather than carrying a
hand-written copy. After P2.5-SET-03 (below) fifty-two gateway names are *deliberately* Latin on
the Arabic page; a hand-maintained list would drift out of step and report the correct behaviour as
a defect, and the obvious "fix" would be to translate a brand name again. It throws rather than
falling back to an empty list, because an empty brand list makes every gateway a finding.

---

## 3. Three defects found, all invisible in the screenshots

### P2.5-SET-01 — a `<code>` at 3.73:1

`whatsapp_msg_setting.blade.php:89` puts `<code>{item_variable}</code>` inside a `<label>`. The DS
chip treatment was scoped to `.ss-vars__def code`, so this one fell through to Bootstrap's own
`$pink` `#e83e8c`: **3.82:1 light, 3.73:1 dark** against a 4.5 floor. It survived review because
pink on a form reads as a deliberate accent.

The first fix was a bare `code` selector, and **`DesignSystemStylesheetScopeTest` rejected it —
correctly**. This stylesheet loads on every admin page across all 70 vendored modules, so an
unconfined element selector restyles WorkDo markup the design system does not own. The guard was
right and the selector was wrong; it is now confined to the three places this application actually
puts a `<code>`: `.ss-label`, `.ss-table`, `.ss-vars__def`. **Nothing was added to that test's
`UNCONFINED_ALLOWED`.**

Token pair re-measured before being widened: `--ss-brand` on `--ss-brand-subtle` is **5.89:1**
light (`#335da8` on `#f4f5f8`) and **7.18:1** dark (`#2ac3e5` on `#0e2a33`). The panel's worst text
went 3.73:1 → 5.65:1.

### P2.5-SET-02 — thirteen currency names, wrapped but never translated

The Arabic settings page printed `Syrian Pound`, `US Dollar` and eleven more in Latin script, and
the disabled-gateway note read `(يوكاسا) (retired - no longer available)`.

**Both call sites were already wrapped in `__()`** — `currency_setting.blade.php:198` and
`SettingController.php:2277` — which is exactly why nobody caught it. `__()` returns its own
argument when the key is absent, so a missing key and a correct translation are indistinguishable
at the call site. This is the third instance of that shape in the phase; the dashboard chart's
`__(date('d-M'))` was the first.

**The stored data was not touched.** `currency_rates.currency_name` holds the canonical English
name, seeded by `SettingController::$defaultCurrencies` and editable by the merchant. Translating
at display time fixes rows that already exist as well as new ones, needs no migration, and leaves
a store that renamed its own currency showing the name it chose.

Pinned by `tests/Unit/Localisation/CurrencyNameTranslationTest.php` — which asserts the resolved
value **differs from the key**, the only assertion that tells a missing key from a translation.

### P2.5-SET-03 — payment brand names translated as words

The Arabic payment tab listed:

| gateway | read as | which means |
|---|---|---|
| Stripe | `الشريط` | "the ribbon" |
| Paystack | `كومة الدفع` | "payment pile" |
| Flutterwave | `موجة التفجير` | "blast wave" |
| DPO | `مدير حماية البيانات` | "Data Protection Officer" |
| Tap | `اضغط` | "press" |
| PhonePe | `هاتف` | "telephone" |
| Sspay | `- لا` | nothing at all |

**A merchant looking for Stripe could not find it.** That is a functional defect on the payment
surface, not a copy nitpick.

**This was not a new decision.** `app/Payments/PaymentTypeLabel.php` already holds the first-party,
tested rule — its `BRANDS` map renders all 52 gateways in the provider's own spelling, and the
order list already follows it. The two surfaces disagreed about the same gateway: `Stripe` in
orders, `الشريط` in settings. This change makes the settings tab agree with the decision that
already existed, and the existing test `a brand name is never passed through the translator` still
passes.

**It also reaches stored data.** `TapPaymnetController.php:132` and
`BenefitPaymentController.php:244` write `__('Tap')` / `__('Benefit')` straight into
`orders.payment_type`, so an order placed in an Arabic session stored `اضغط` — a value
`PaymentTypeLabel` cannot resolve, because it matches on a lowercase ASCII slug. Future orders
store `Tap`. **No existing row was touched; this edits a language file only.**

32 keys removed (20 word-translated, 12 wrapped in stray quotes or parentheses — `" برينتري "`
carried its own padding *inside* the quotes). **Faithful Arabic renderings were deliberately
kept**: `بايبال`, `سكريل`, `بايتم`, `واتساب` and nine more. Those are the brand in Arabic script —
a merchant still finds the gateway — and they are standard usage.

Pinned by `tests/Unit/Localisation/PaymentBrandNameTranslationTest.php`, which reads
`PaymentTypeLabel::BRANDS` rather than restating it, so the two cannot drift. Its third test
asserts the kept renderings are still there, so the suite cannot pass by someone deleting every
brand key.

---

## 4. What is **not** claimed

### The Storage and Cache tabs are not merchant surfaces

`setting/index.blade.php:75` opens `@if (auth()->user() && auth()->user()->type == 'super admin')`
and closes at `:116`. Seven tabs sit inside it — **Cookie, Cache, Storage, reCAPTCHA, ChatGPT,
Style, SEO**. A merchant (`users.type == 'admin'`, the root of their own tenant) never sees any of
them. The hub renders 21 tabs for a merchant and all 21 were captured; these are not among them
because the application does not offer them.

**The dev fixture was not elevated to reach them.** Making the merchant a `super admin` would have
produced four screenshots per tab that were evidence of nothing — a platform-operator surface
photographed while pretending to be a merchant. Operator UX is Phase 06 by the approved six-phase
plan. Both rows are recorded **OUT OF SCOPE** with that reason, not left PENDING.

### The 17-tab combined row covers twelve of its seventeen

Merchant-visible and each clicked, measured and captured in its own right: tax option, payment,
stock, refund, PWA, Twilio, WhatsApp ×3, email ×2, loyalty. The other five — reCAPTCHA, Cookie,
SEO, Style, ChatGPT — are in the same super-admin block as Storage and Cache.

### Payment gateway logos 404 in this environment, and that is the fixture

`/storage/uploads/payment/*.png` (ozow, braintree, powertranz and nine more) return 404 during the
drive. `storage/app/public/uploads/payment/` is empty in the dev fixture and the directory is
gitignored — these are runtime assets a production install ships. **Verified not to affect the
surface**: the gateway rows render an icon glyph, not those images, and the Arabic RTL dark capture
shows no broken-image placeholders. Recorded as an environment limitation, not an application
defect, and not used to discount any measurement.

### No PREMIUM VERIFIED promotion

All ten rows are claimed **REDESIGNED AND VERIFIED**. Promotion to PREMIUM VERIFIED needs the
owner's visual acceptance and is not taken here.

---

## 5. Reproducing

```bash
docs/development/phase-2.5/tools/grant-dev-harness-permissions.sh
docs/development/phase-2.5/tools/serve-dev.sh &
```

```bash
node docs/development/phase-2.5/tools/measure-settings-tabs.cjs --json measurements.json
```

```bash
node docs/development/phase-2.5/tools/render-matrix.cjs --paths /setting --click-each 'button[data-tab]' --out docs/development/phase-2.5/evidence/web-settings-tabs-2026-11-09/after
```

Check the title is the page's own (`Store Syria - الإعدادات`), never `Store Syria - Dashboard` —
that title is the signature of a refusal redirect and it is what made an earlier run worthless.

## 6. Tests

| | |
|---|---|
| `php artisan test --testsuite=Unit` | **1556 passed** |
| `CurrencyNameTranslationTest` | 3 passed; **2 fail** with `ar.json` reverted |
| `PaymentBrandNameTranslationTest` | 3 passed; **3 fail** with `ar.json` reverted |
| `PaymentTypeLabelTest` + `tests/Unit/Localisation/` | 39 passed |
| `DesignSystemStylesheetScopeTest` | 6 passed (it rejected the first version of the CSS fix) |
| `php artisan test --testsuite=Feature` | **8334 passed, 2 declared failures, 1 declared skip, 0 errors** — gate matches the manifest exactly. Not green, and not claimed to be. |
