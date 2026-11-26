<?php

namespace Tests\Unit\Ui;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * P5-11 / GAP-201 / ANA-007 — `<x-ss.stat>`'s caveat could not be seen by a sighted merchant.
 * =================================================================================================
 *
 * MEASURED BEFORE THE REPAIR, by rendering the component through `Blade::render()`:
 *
 *     <span class="ss-stat__period">
 *         last 7 days
 *         <span class="ss-visually-hidden">This is not payments received, and not profit.</span>
 *     </span>
 *
 * `.ss-visually-hidden` is `position:absolute; width:1px; height:1px; clip:rect(0,0,0,0)`
 * (`store-syria-ds.css:2238-2246`) and **there is no `:hover`, `:focus` or `:focus-within` rule
 * anywhere that reveals it** — verified by grepping the stylesheet for a reveal selector on that
 * class and finding none. So the hint was not "hover-only"; it was unreachable by sight.
 *
 * WHY THAT IS A DEFECT AND NOT A DESIGN CHOICE. **Ten** `:hint=` attributes sit on this component
 * across **six** files, and nine of the ten exist to stop a figure being misread:
 *
 *     reports/_sales_stats.blade.php:17  "...what customers were asked to pay, not what they
 *                                         paid, and not profit."
 *     reports/_sales_stats.blade.php:22  "This is not payments received, and not profit."
 *     reports/_sales_stats.blade.php:32  "How many units were ordered, not how many were
 *                                         delivered or paid for."
 *     reports/_sales_stats.blade.php:41  "The amount discounted by coupons, already deducted..."
 *     reports/order_brand_chart.blade.php:22      "...not payments received, and not profit."
 *     reports/order_category_chart.blade.php:22   the same sentence
 *     reports/order_product_chart.blade.php:36    the same sentence
 *     reports/order_product_chart.blade.php:41    "How many units were ordered..."
 *     dashboard/overview.blade.php:376            "...excluding orders that were cancelled..."
 *
 * The tenth, `customer/timeline.blade.php:73` ("Opens this customer's orders"), is a navigation
 * affordance rather than a caveat, and it is equally invisible.
 *
 * Those nine ARE the Phase 05 honesty work, including the wording corrected under the owner's
 * third recommendation. They were written into a slot that renders nothing a sighted merchant can
 * read, so the work shipped and did not arrive. `ANA-007` asks the product to *show* data quality
 * and omissions; a caveat clipped to one pixel does not show anything.
 *
 * THE THREE SIBLING COMPONENTS ALL RENDER THEIR HINT VISIBLY, which is what makes this an
 * oversight rather than a decision: `ss.advanced-fields` emits `<p class="ss-advanced__hint">`,
 * `ss.secret-field` emits `<p class="ss-field__hint">`, and `ss.choice-group` points
 * `aria-describedby` at a visible element. Only `ss.stat` hides it, and NO TEST pinned that —
 * so nothing in the suite would have objected either way. The component arrived with the
 * Phase 2.5 merge `4e878b8d` and is not Phase 05's own code.
 *
 * WHAT THIS CHANGE DOES NOT DO. It does not alter a single figure, period, label or calculation.
 * `<x-ss.stat>` renders; it computes nothing. The hint text itself is unchanged in all ten call
 * sites — only whether it can be seen.
 *
 * THE CENSUS MOVED, TEN TO TWELVE, ON 2026-11-18 (P5-16): `finance/index.blade.php` gained the two
 * ledger tiles `Payments received` and `Estimated gross profit`, each with a hint. The history above
 * describes the ten that existed when the slot was repaired and is left as written.
 */
class StatHintIsVisibleTest extends TestCase
{
    /** The component rendered with a hint, through the real Blade compiler. */
    private function render(array $props = []): string
    {
        $props = array_merge([
            'l' => 'Order value',
            'v' => '1,260.00',
            'p' => 'last 7 days',
            'h' => 'This is not payments received, and not profit.',
        ], $props);

        return Blade::render(
            '<x-ss.stat :label="$l" :value="$v" :period="$p" :hint="$h" />',
            $props
        );
    }

    private function renderWithoutHint(): string
    {
        return Blade::render(
            '<x-ss.stat :label="$l" :value="$v" :period="$p" />',
            ['l' => 'Order value', 'v' => '1,260.00', 'p' => 'last 7 days']
        );
    }

    /**
     * THE DEFECT. The hint must not be rendered inside the visually-hidden class.
     */
    public function test_the_hint_is_not_rendered_inside_a_visually_hidden_element(): void
    {
        $html = $this->render();

        $this->assertStringContainsString(
            'This is not payments received, and not profit.',
            $html,
            'premise: the hint reaches the markup at all.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/class="ss-visually-hidden"[^>]*>\s*This is not payments received/',
            $html,
            'The caveat is clipped to 1x1 by .ss-visually-hidden and no rule reveals it, so a '
            . 'sighted merchant cannot read it. That is precisely what ANA-007 forbids.'
        );

        $this->assertStringNotContainsString(
            'ss-visually-hidden',
            $html,
            'and the component should not be emitting that class at all once the hint is visible.'
        );
    }

    /**
     * It must be its own element with the design system's hint class, not loose text appended to
     * the period — otherwise it inherits the period's styling and reads as part of the period.
     */
    public function test_the_hint_is_its_own_element_carrying_the_design_system_hint_class(): void
    {
        $html = $this->render();

        $this->assertMatchesRegularExpression(
            '/<p[^>]*class="ss-stat__hint"[^>]*>\s*This is not payments received/',
            $html,
            'The hint should render as its own <p class="ss-stat__hint">, matching the sibling '
            . 'components (ss-advanced__hint, ss-field__hint).'
        );
    }

    /**
     * ACCESSIBILITY MUST IMPROVE, NOT MERELY SURVIVE. Before this change the hint sat inside
     * `.ss-stat__period`, so a screen reader announced it as a continuation of the period with no
     * association to the figure it qualifies. Associating it with the VALUE via
     * `aria-describedby` is what makes it a description of the number.
     */
    public function test_the_hint_is_programmatically_associated_with_the_value(): void
    {
        $html = $this->render();

        $this->assertMatchesRegularExpression(
            '/<p[^>]*class="ss-stat__hint"[^>]*id="([^"]+)"|<p[^>]*id="([^"]+)"[^>]*class="ss-stat__hint"/',
            $html,
            'The hint needs an id for aria-describedby to point at.'
        );

        preg_match('/<p[^>]*class="ss-stat__hint"[^>]*id="([^"]+)"/', $html, $m);
        $hintId = $m[1] ?? null;

        if ($hintId === null) {
            preg_match('/<p[^>]*id="([^"]+)"[^>]*class="ss-stat__hint"/', $html, $m2);
            $hintId = $m2[1] ?? null;
        }

        $this->assertNotNull($hintId, 'could not read the hint id back out of the markup.');

        $this->assertMatchesRegularExpression(
            '/<span[^>]*class="ss-stat__value"[^>]*aria-describedby="' . preg_quote((string) $hintId, '/') . '"'
            . '|<span[^>]*aria-describedby="' . preg_quote((string) $hintId, '/') . '"[^>]*class="ss-stat__value"/',
            $html,
            'The VALUE must carry aria-describedby pointing at the hint, so the caveat is '
            . 'announced as a description of the figure rather than as stray text.'
        );
    }

    /**
     * TWO TILES ON ONE PAGE MUST NOT SHARE AN ID. `<x-ss.stats>` renders up to six of these, and
     * `_sales_stats` renders four hints in one grid, so a static id would emit duplicates and
     * every `aria-describedby` after the first would resolve to the wrong tile.
     */
    public function test_two_tiles_do_not_share_a_hint_id(): void
    {
        $html = Blade::render(
            '<x-ss.stats :label="$g">'
            . '<x-ss.stat :label="$l" value="1" :period="$p" :hint="$h1" />'
            . '<x-ss.stat :label="$l" value="2" :period="$p" :hint="$h2" />'
            . '</x-ss.stats>',
            [
                'g' => 'This month', 'l' => 'Order value', 'p' => 'all time',
                'h1' => 'First caveat.', 'h2' => 'Second caveat.',
            ]
        );

        preg_match_all('/class="ss-stat__hint"[^>]*id="([^"]+)"/', $html, $m);
        $ids = $m[1] ?? [];

        $this->assertCount(2, $ids, 'both tiles should have rendered a hint.');
        $this->assertSame($ids, array_unique($ids), 'the two tiles emitted the same id.');
    }

    /**
     * SILENT WHEN THERE IS NOTHING TO SAY. Most tiles pass no hint; none of them should gain an
     * empty element or a dangling aria-describedby.
     */
    public function test_a_stat_without_a_hint_renders_no_hint_element(): void
    {
        $html = $this->renderWithoutHint();

        $this->assertStringNotContainsString('ss-stat__hint', $html);
        $this->assertStringNotContainsString('aria-describedby', $html,
            'a dangling aria-describedby pointing at nothing is itself an accessibility defect.');
    }

    /**
     * NO STRAY SPACE BEFORE THE BRACKET ON A TILE WITH NO HINT.
     *
     * Written the obvious way -- `class="ss-stat__value" @if ($hintId) ... @endif>` -- Blade emits
     * `<span class="ss-stat__value" >` for every tile WITHOUT a hint, which is most of them. That
     * is invisible in a browser and fatal to an exact-markup assertion:
     * `Feature\DesignSystem\SaleByReportHonestyTest::test_the_sales_report_separates_counts_from_money`
     * asserts the literal `ss-stat__value"> 7 </span>` to prove the order COUNT renders as a bare
     * number rather than as money, and the space broke it. The behaviour was never wrong; the
     * markup was, and the full Feature gate caught it before the push.
     *
     * Pinned here because the fix is one character of whitespace in a Blade directive, which is
     * exactly the kind of thing a later edit reintroduces without noticing.
     */
    public function test_a_tile_without_a_hint_emits_no_stray_space_before_the_bracket(): void
    {
        $flat = preg_replace('/\s+/', ' ', $this->renderWithoutHint());

        $this->assertStringContainsString(
            'class="ss-stat__value">',
            $flat,
            'A tile with no hint must close the opening tag immediately after the class '
            . 'attribute. A stray space breaks exact-markup assertions elsewhere in the suite.'
        );

        $this->assertStringNotContainsString(
            'class="ss-stat__value" >',
            $flat,
            'stray space before the bracket -- the Blade @if is not tight against the quote.'
        );
    }

    /**
     * And the attribute still arrives properly spaced when there IS a hint, which is the other
     * half of the same one-character decision.
     */
    public function test_a_tile_with_a_hint_separates_the_attribute_properly(): void
    {
        $flat = preg_replace('/\s+/', ' ', $this->render());

        $this->assertMatchesRegularExpression(
            '/class="ss-stat__value" aria-describedby="[^"]+">/',
            $flat,
            'exactly one space between the class attribute and aria-describedby, and no space '
            . 'before the bracket.'
        );
    }

    /**
     * THE STYLE MUST EXIST AND MUST FOLLOW THE THEME. CLAUDE.md records a measured case of option
     * names rendering white-on-white at 1.00:1 in dark mode, and `store-syria-ds.css:1864-1870`
     * is the block that exists to stop exactly that for `<p>` hints — it force-mutes
     * `.ss-field__hint`, `.ss-advanced__hint` and their siblings because an element selector in
     * the host dark sheet otherwise wins. A new `<p>` hint class that is NOT in that list is the
     * same defect waiting to happen.
     */
    public function test_the_hint_class_is_styled_and_is_theme_safe(): void
    {
        $css = file_get_contents(base_path('public/css/store-syria-ds.css'));

        $this->assertMatchesRegularExpression(
            '/\.ss-stat__hint\s*\{/',
            $css,
            '.ss-stat__hint has no style rule, so the hint renders at browser default.'
        );

        $this->assertMatchesRegularExpression(
            '/\.ss-stat__hint[^{]*,[^{]*\{[^}]*--ss-fg-muted[^}]*!important|\.ss-stat__hint,/s',
            $css,
            '.ss-stat__hint must join the dark-mode muted-colour list at store-syria-ds.css:1864, '
            . 'or the host dark stylesheet wins on an element selector and the caveat can render '
            . 'unreadable — the defect CLAUDE.md records as measured at 1.00:1.'
        );
    }

    /**
     * THE CENSUS, so the scope of this change cannot drift silently. Twenty hints on this component
     * across eight files (ten across six until P5-16 added the two ledger tiles on /finance; twelve
     * across seven until P5-4b added the six on /analytics; eighteen until P5-5 added the coupon
     * tile; nineteen until P5-6 added repeat purchase), counted from the views themselves rather than restated from prose.
     *
     * Counted by walking BACK from each `:hint=` to the nearest `<x-` tag: a forward regex over
     * the tag body is wrong here, because `product/edit.blade.php` and `customer/timeline.blade.php`
     * both carry Blade comments containing `>` inside a component tag, which truncates the body
     * and misattributes the attribute. That is not hypothetical — it is how this count was first
     * measured as nine across five, and the error reached three committed documents before the
     * recount caught it.
     */
    public function test_the_hint_count_on_this_component_is_the_measured_one_across_ten_files(): void
    {
        $views = base_path('resources/views');
        $hits = [];

        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($views));

        foreach ($rii as $file) {
            if ($file->isDir() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (! preg_match_all('/:?hint=/', $source, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($m[0] as [$_, $offset]) {
                $before = substr($source, 0, $offset);
                $tag = strrpos($before, '<x-');

                if ($tag === false) {
                    continue;
                }

                if (preg_match('/<x-([a-zA-Z0-9.:-]+)/', substr($source, $tag), $c)
                    && $c[1] === 'ss.stat') {
                    $hits[] = str_replace($views . '/', '', $file->getPathname());
                }
            }
        }

        // 10 -> 12 and 6 -> 7 files, P5-16 (owner decisions D1 and D2). `finance/index.blade.php` now carries TWO
        // `<x-ss.stat>` hints -- "Payments received" and "Estimated gross profit" -- and both exist for the reason
        // the other nine do: to stop a figure being misread (it is not order value, it excludes shipping and tax,
        // it starts where the financial record starts). They are built by `FinanceLedgerFigures`, so each hint
        // is a real sentence rather than a placeholder, and they render visibly through the same component fix.
        // 12 -> 18 and 7 -> 8 files, P5-4b. `analytics/index.blade.php` carries SIX, and every one is
        // there for the reason the other twelve are -- to stop a figure being misread. Orders says it
        // does not exclude cancellations; page views says a view is a REQUEST and not a person, which
        // is the whole basis of the conversion figure; conversion says what it divides and, when there
        // is nothing to divide by, says that instead of printing nought per cent; buyers says guest
        // orders are counted in neither column; and the two money tiles are the same
        // FinanceLedgerFigures pair /finance shows, inherited rather than recomputed so the two
        // screens cannot come to disagree. All six are built in MerchantAnalyticsFigures beside the
        // numbers they qualify, so each is a real sentence and none can drift from its figure.
        // 18 -> 19, P5-5. `analytics/index.blade.php` gains "Coupon discounts given", whose hint is
        // the one on this screen that can say the figure is WITHHELD: section 9 is authoritative only
        // for a period whose redemption records are complete, and for any earlier period the tile
        // shows no number and the hint says from when it could. File count unchanged.
        // 19 -> 20, P5-6. "Repeat purchase" on /analytics, whose hint carries three disclosures the
        // figure cannot be read without: how many recognisable buyers it divided, that only PAID
        // orders are counted, and how many orders each of the two exclusions dropped. Its PERIOD also
        // differs from its neighbours' on purpose, which the hint has to make legible.
        // 20 -> 23 and 8 -> 9 files, P5-7. `platform/analytics.blade.php` is the operator's
        // cross-tenant screen and adds three: Stores, whose hint says how many stores are LEFT OUT
        // of the money figures rather than counted as zero; Orders, which says a count of orders is
        // not a count of sales; and Buyers, which says one person with accounts in two stores is
        // counted in each, because nothing links the two accounts. The money figure on that screen
        // is a per-currency TABLE rather than a tile -- the number of currencies is however many the
        // platform's stores use -- so its own disclosure is a plain `ss-stat__hint` paragraph and is
        // deliberately not counted here.
        // 23 -> 24, GAP-217. `reports/_sales_stats` gains a hint on `Shipping charged`, the one
        // MONEY tile on that card that carried none at all -- no definition and no caveat -- while
        // its figure is accumulated exactly like the two above it, `+= (float) $order->delivery_price`
        // off a FLOAT column. The same change APPENDS the precision caveat to `Coupon value
        // applied`, which already had a definition, so that one does NOT move the count: GAP-217's
        // first draft said both tiles carried nothing and had to be corrected the same day by
        // re-measuring the six tiles one at a time. The file count stays at nine because
        // `_sales_stats` already had hints. `Orders` on that card still has no hint; it is a COUNT,
        // so no money caveat applies, and giving it a DEFINITION is recorded in GAP-217 rather
        // than slipped in here, because it would move this number again.
        // 24 -> 25, ANA-005. `reports/_sales_stats` gains `Payments received`, the fourth of owner
        // decision D2's four figures to reach a screen and the only tile on that card that is not
        // accumulated in the handler's own loop over `orders`: it reads the entitlement ledger and is
        // dated by the day the money ARRIVED, so it disagrees with the two order-value tiles beside it
        // on purpose. Its hint is therefore not decoration -- it is the only thing on the card that
        // explains why the two numbers differ. The file count stays at nine because `_sales_stats`
        // already had hints.
        // 25 -> 26 [GAP-222]: `finance/index.blade.php` gains the wallet tile's hint, which carries D2's
        // "A running balance, not revenue." plus the currency disclosure -- the sentence that says the
        // balance may be held in a DIFFERENT currency from the rest of the page. It replaces a standalone
        // `<p class="ss-stat__hint">` that the same slice removed, so the hint did not appear from nowhere:
        // it moved inside the component that associates it with its figure via `aria-describedby`. The file
        // count stays at nine because this file already had the two ledger tiles' hints.
        // 26 -> 29 [ANA-001]: `reports/finance_report.blade.php` carries all three of its tiles'
        // hints -- the two ledger caveats and the wallet's currency disclosure. A NEW file, so the
        // span below moves 9 -> 10; every other hint on the component is unchanged.
        // 29 -> 30 [P5-R13]: `platform/analytics.blade.php` gains `Repeat purchase`, the first of the
        // NINE figures the operator screen was missing against the merchant screen's thirteen. Its
        // hint is load-bearing in a way the merchant twin's is not, and carries one sentence that
        // exists only on the cross-tenant screen: one person with accounts in two stores is counted
        // in EACH, so the figure is a rate over customers of a store and not over people. "35% of
        // buyers came back" would otherwise read as a statement about human beings. It also carries
        // the merchant version's three disclosures -- how many recognisable buyers it divided, that
        // only PAID orders are counted, and how many orders each exclusion dropped -- plus, when a
        // start date is actually shown, that the period is the LATEST verified start among the
        // included stores. Its PERIOD differs from the two tiles beside it on purpose: Orders and
        // Buyers use the recent window, this one uses the ledger's intersection.
        // The file count stays at ten because this file already carried three hints.
        // 30 -> 33 [GAP-156]: `customer/timeline.blade.php` gains THREE, and it is the first file
        // on this list where a hint had to be added to a figure that was ALREADY THERE. `Total
        // Spend` sums `orders.final_price` with no payment predicate -- cancelled, refunded and
        // never-paid orders each in full -- and owner Option A makes that permanent rather than
        // fixable, because `orders.payment_status` is the customer's settlement record and a
        // cancellation must not rewrite it. So the figure stays and the hint says what it is: order
        // value, not money received. The other two are the new ledger tiles beside it, `Payments
        // collected` and `Net retained after refunds`, whose hints carry §15's two coverage
        // boundaries -- orders the financial record cannot see, and payments the wallet recorded no
        // movement against.
        //
        // THE FILE COUNT STAYS AT TEN, and the first draft of this note said it moved to eleven --
        // the assertion below refused it, which is the ratchet doing its job on the person
        // updating it. `customer/timeline.blade.php` was ALREADY on the list: its Total Orders
        // tile has carried `Opens this customer's orders` since P2.5. Three hints were added to a
        // file that had one, not to a file that had none.
        // 33 -> 34 [GAP-162]: `platform/analytics.blade.php` gains `Page views`, the third of the
        // cross-tenant counterparts. ONLY THIS ONE OF THE THREE MOVES THIS COUNT, and the reason is
        // worth stating: `devices` and `Estimated gross profit` are TABLES, whose disclosure is a
        // standalone `<p class="ss-stat__hint">` beside the table rather than a `:hint` on the
        // component -- so they are invisible to this census by construction. A reader comparing
        // "three figures shipped" with "one hint added" would otherwise suspect two missing
        // disclosures.
        //
        // Its hint carries three things, two of which exist only on the cross-tenant screen:
        // what a page view is and is not (the merchant screen's own sentence -- requests, not
        // people, crawlers included); the LATEST attribution start among the stores that have
        // logged one, because visit rows carried no `store_id` until 2026-11-20 and nothing
        // backfills them, so a total across stores is only as covered as its least-covered
        // contributor; and the count of stores that have never logged one at all, whose zero
        // cannot be told apart from "never recorded". The last two are conditional, for the reason
        // `MerchantAnalyticsFigures::visitCoverageNote()` records: a permanent "may be incomplete"
        // note on every render is wallpaper, not a disclosure.
        // The file count stays at ten because this file already carried four hints.
        // 34 -> 35 and 10 -> 11 files [GAP-195 / OPS-002]: `superadmin/platform_resources.blade.php`,
        // the platform operator's resource measurements, carries ONE `:hint` -- inside a `@foreach`
        // over the processor rows, so one occurrence in the source serves four tiles. It is here
        // for the same reason as the other thirty-four: to stop a figure being misread. Three of
        // those four tiles are load averages, which mean nothing without the processor count beside
        // them, and every row's hint also carries the sentence for the state this screen has and
        // the others do not -- the host REFUSED the measurement, which must read as "we cannot say"
        // and never as a zero. The hint is assembled in the view from the producer's `reason`,
        // `hint` and `source`, because `x-ss.stat` has one visible hint slot and the producer keeps
        // the three apart so that its own tests can assert a reason exists for every withheld
        // figure.
        //
        // THE MEMORY AND DISK BLOCKS ON THAT SCREEN ADD NOTHING TO THIS COUNT, and the reason is
        // the one already recorded above for `devices` and `Estimated gross profit`: they are
        // TABLES, whose disclosure is a `<p class="ss-stat__hint">` in a row of its own rather than
        // a `:hint` on the component, so they are invisible to this census by construction. A
        // reader comparing "fourteen figures shipped" with "one hint added" would otherwise suspect
        // thirteen missing disclosures.
        // 35 -> 38 and 11 -> 12 files [GAP-192 / SAAS-007]:
        // `superadmin/platform_revenue.blade.php` carries THREE, one per tile, and they are the
        // reason this screen exists separately from `platform/analytics` rather than as two more
        // tiles on it. MRR's hint says the figure is what merchants pay the platform and NOT what
        // shoppers pay merchants — the separation is the recovered requirement itself, and a
        // reader who mistakes one for the other has the whole screen backwards. ARR's says it is a
        // restatement of the same subscriptions and not a forecast, so nobody reads a growth
        // projection into an identity. The subscriber count's says that every subscription which
        // is NOT live, paid and recurring is counted in its own row below rather than folded in,
        // which is what stops the headline figure from quietly settling a commercial question
        // nobody has been asked.
        //
        // THE SUBSCRIPTION-KIND AND RECEIPTS TABLES ON THAT SCREEN ADD NOTHING HERE, for the
        // reason already recorded twice above: a table's disclosure is a standalone
        // `<p class="ss-stat__hint">` rather than a `:hint` on the component, so it is invisible
        // to this census by construction. That screen carries eight bucket sentences and four
        // table-level ones in that shape.
        // 38 -> 33 and 12 -> 13 files [GAP-152 / P05-04]. THE COUNT WENT DOWN AND THE DISCLOSURE
        // WENT UP, which is the one direction this census cannot read on its own, so it is stated
        // here. Six `:hint=` attributes were REMOVED -- the `Payments received` and
        // `Estimated gross profit` tiles on `finance/index`, `reports/finance_report` and
        // `analytics/index` -- and one was added, in `components/ss/figure.blade.php`, which is
        // where all six now get theirs.
        //
        // WHY THAT IS NOT A LOST CAVEAT. `<x-ss.figure>` takes a `App\Support\Metrics\CaveatedFigure`
        // and has NO `hint` prop: the sentences are computed from the figure's caveat list, so a
        // screen cannot render the value and omit them. Spelled as three independent strings, those
        // six tiles rendered `MetricCaveat::EXCLUDES_PAYMENT_FEES` -- which `StoreRevenueMargin`
        // attaches to every margin figure, translated and ratcheted -- on NONE of them. The three
        // screens are pinned by `CaveatCarrierReachesEverySurfaceTest` (which enumerates each
        // figure's caveats at run time rather than listing sentences) and the component by
        // `CaveatedFigureRendersItsCaveatsTest`.
        //
        // The thirteenth file is the component itself, and it is the one entry here that is not a
        // screen: every future carrier-rendered figure adds disclosure WITHOUT moving this count,
        // so a reader comparing figures shipped against hints added should start from that file.
        $this->assertCount(33, $hits,
            "x-ss.stat hint count moved. Found:\n  " . implode("\n  ", $hits));

        $this->assertCount(13, array_unique($hits),
            'x-ss.stat hints should span thirteen files: ' . implode(', ', array_unique($hits)));
    }
}
