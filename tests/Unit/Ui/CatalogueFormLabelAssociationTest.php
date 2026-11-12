<?php

namespace Tests\Unit\Ui;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2.5 / catalogue-unassociated-labels — EVERY FIELD CAPTION IN THE CATALOGUE AND MARKETING
 * MODALS NAMED NOTHING, AND THE CONTROL BESIDE IT HAD NO `id` EITHER.
 * =================================================================================================
 *
 * THE MECHANISM, measured through the real builder rather than inferred.
 * `Konekt\Form\FormBuilder::label()` (vendor/konekt/html/src/FormBuilder.php:247-260) does two
 * things with its FIRST argument: it interpolates it into `for="…"`, and it pushes it onto
 * `$this->labels`. `getIdAttribute()` (:1268-1277) returns `$attributes['id']` first and falls back
 * to the field's NAME only when that name is present in `$this->labels`.
 *
 * So `Form::label('', __('Title'))` breaks BOTH ends of the association in one call:
 *
 *     {!! Form::label('', __('Title'), ['class' => 'form-label']) !!}
 *     {!! Form::text('title', null, ['class' => 'form-control']) !!}
 *
 *     <label for="" class="form-label">Title</label>
 *     <input class="form-control" name="title" type="text">        <-- NO id AT ALL
 *
 * `for=""` is not a valid IDREF, and the empty string went onto `$labels` instead of `title`, so
 * the input never got its auto-id. Nothing in the document can reference the control, and a screen
 * reader announces "edit, blank". Passing the field's own name fixes both ends at once and is the
 * whole repair for 41 of the 53 sites:
 *
 *     <label for="title" class="form-label">Title</label>
 *     <input class="form-control" name="title" type="text" id="title">
 *
 * -------------------------------------------------------------------------------------------------
 * WHY THIS TEST RENDERS THE VIEWS INSTEAD OF READING THEM.
 * -------------------------------------------------------------------------------------------------
 *
 * The whole defect class is markup that LOOKS associated in the template and is not in the
 * document. A test that greps the Blade source for `Form::label('title'` goes green while the page
 * is still broken, because whether the control gets a matching `id` depends on the builder's
 * `$labels` bookkeeping and on whether an explicit `'id'` key overrides it. `for` resolution and
 * duplicate ids are properties of the DOCUMENT. `Tests\Feature\Security\UserInfoDisclosureTest`
 * records the same decision at :233-235, and `ChoiceGroupGuidanceTest` at :164.
 *
 * The fixtures are plain objects rather than persisted models on purpose: nothing here is a
 * question about the database, and `Form::model()` resolves values with `data_get()`, which reads
 * an `stdClass` exactly as it reads an Eloquent model. What is being exercised is the builder.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT THIS FILE DOES **NOT** COVER, so nobody reads it as closing more than it does.
 * -------------------------------------------------------------------------------------------------
 *
 *   - THE TWELVE SWITCH CAPTIONS in product_brand/{create,edit}, coupon/{create,edit} and
 *     testimonial/{create,edit}. They are the same `Form::label('', …)` mechanism but a different
 *     repair: their control already has an `id` AND an empty `<label class="form-check-label">`
 *     associated with it, so the caption has somewhere to go rather than needing a `for`. They are
 *     `switch-no-accessible-name` and `Tests\Unit\Ui\SwitchAccessibleNameTest`. A caption whose
 *     text is one of theirs is therefore deliberately absent from `CAPTIONS` below.
 *   - resources/views/product/{create,edit}.blade.php — 38 more of the same, carved out by
 *     `ChoiceGroupGuidanceTest.php:150-172` as their own ledger row with JavaScript keyed on those
 *     ids. `test_the_scope_excludes_the_product_editor` pins that they stay out.
 *   - the remaining ~175 occurrences across the other 43 views.
 */
class CatalogueFormLabelAssociationTest extends TestCase
{
    /* `get_file()` (app/Helper/helper.php:440-442) reads the platform admin's storage settings
       from `users`, so the two edit views that render a stored image need the tables to exist.
       Nothing here asserts on a row. */
    use RefreshDatabase;

    /**
     * view => [view data, [[visible caption, the id its `for` must resolve to], …]]
     *
     * Every pair below is a caption the repair OWNS. Pairs that were already correct before it
     * (`Name`/`name` on product_brand/create, `Products`/`applied_product` on both coupon views,
     * `User Name`/`username` on both testimonial views) are listed too, as the positive control
     * that the sweep took nothing away.
     */
    public static function views(): array
    {
        $flashSalePairs = [
            ['Name', 'name'],
            ['Start Date', 'start_date'],
            ['End Date', 'end_date'],
            ['Start Time', 'start_time'],
            ['End Time', 'end_time'],
            ['Discount Type', 'discount_type'],
            ['Discount Amount', 'discount_amount'],
        ];

        $couponPairs = [
            // The trap this file exists to keep an eye on: the CAPTION becomes `coupon_name`,
            // the POSTED FIELD stays `coupon_name`. `Form::label`'s first argument is a `for`,
            // never a name. `SettingsPanelContractTest.php:48-54` records a generator that made
            // exactly this mistake in the other direction.
            ['Name', 'coupon_name'],
            ['Type', 'category'],
            ['Products', 'applied_product'],
            ['Exclude products', 'exclude_product'],
            ['Product categories', 'applied_categories'],
            ['Exclude categories', 'exclude_categories'],
            ['Minimum spend', 'minimum_spend'],
            ['Maximum spend', 'maximum_spend'],
            ['Discount', 'discount_amount'],
            ['Usage limit per user', 'coupon_limit_user'],
            ['Limit usage to X items', 'coupon_limit_x_item'],
            ['Expiry Date', 'coupon_expiry_date'],
        ];

        $testimonialPairs = [
            ['Title', 'title'],
            ['Description', 'description'],
            ['Product', 'product_id'],
            ['Rating', 'rating_no'],
            ['User Name', 'username'],
            ['Avatar', 'upload_avatar'],
        ];

        return [
            'product_label/create' => ['product_label.create', [], [['Name', 'name']]],
            'product_label/edit' => ['product_label.edit', [
                'productLabel' => (object) ['id' => 7, 'name' => 'Clearance', 'status' => 1],
            ], [['Name', 'name']]],

            'product_brand/create' => ['product_brand.create', [], [
                ['Name', 'name'],
                ['Image', 'brand_logo'],
            ]],
            'product_brand/edit' => ['product_brand.edit', [
                'productBrand' => (object) ['id' => 9, 'name' => 'Acme', 'logo' => null, 'status' => 1, 'is_popular' => 0],
            ], [
                ['Name', 'name'],
                ['Image', 'brand_logo'],
            ]],

            'coupon/create' => ['coupon.create', ['product' => [], 'category' => []],
                array_merge($couponPairs, [['Usage limit per coupon', 'coupon_limit']])],
            'coupon/edit' => ['coupon.edit', [
                'coupon' => (object) [
                    'id' => 3, 'coupon_name' => 'SPRING', 'coupon_type' => 'percentage',
                    'minimum_spend' => 10, 'maximum_spend' => 100, 'discount_amount' => 5,
                    'coupon_limit' => 1, 'coupon_limit_user' => 1, 'coupon_limit_x_item' => 1,
                    'coupon_expiry_date' => '2030-01-01', 'coupon_code' => 'SPRING',
                    'status' => 1, 'sale_items' => 0, 'free_shipping_coupon' => 0,
                ],
                'product' => [], 'category' => [],
                'applied_product' => [], 'exclude_product' => [],
                'applied_categories' => [], 'exclude_categories' => [],
            ], array_merge($couponPairs, [['Limit', 'coupon_limit']])],

            'flash-sale/create' => ['flash-sale.create', [], $flashSalePairs],
            'flash-sale/edit' => ['flash-sale.edit', [
                'flashsale' => (object) [
                    'id' => 4, 'name' => 'Winter', 'start_date' => '2030-01-01',
                    'end_date' => '2030-01-02', 'start_time' => '09:00', 'end_time' => '18:00',
                    'discount_type' => 'percentage', 'discount_amount' => 10,
                ],
                'sale_condition' => null,
            ], $flashSalePairs],

            'product-question/edit' => ['product-question.edit', [
                'question' => (object) [
                    'id' => 5,
                    'product' => (object) ['name' => 'Widget'],
                    'question' => 'Does it ship?',
                    'answers' => 'Yes.',
                ],
            ], [
                ['Product', 'product_display'],
                ['Question', 'question'],
                ['Answer', 'answers'],
            ]],

            'testimonial/create' => ['testimonial.create', ['categoryTree' => []], $testimonialPairs],
            'testimonial/edit' => ['testimonial.edit', [
                'Testimonial' => (object) [
                    'id' => 2, 'title' => 'Great', 'description' => 'Lovely',
                    'category_id' => 1, 'product_id' => 1, 'rating_no' => 5,
                    'username' => 'Rana', 'avatar' => null, 'status' => 1,
                ],
                'categoryTree' => [], 'product' => [],
            ], $testimonialPairs],

            'faq/create' => ['faq.create', [], [['Topic', 'topic']]],
            'faq/edit' => ['faq.edit', [
                'faq' => (object) [
                    'id' => 6, 'topic' => 'Shipping',
                    'description' => '[{"question":"How long?","answer":"Two days."},'
                        . '{"question":"How much?","answer":"Free."}]',
                ],
            ], [['Topic', 'topic']]],
        ];
    }

    private function render(string $view, array $data): string
    {
        $html = (string) view($view, $data)->render();

        $this->assertNotSame('', trim($html), $view . ' rendered nothing, so nothing below is measuring it');

        return $html;
    }

    /** Every `<label ... for="X">` in $html, as [X => [its text, …]]. */
    private function labelsByTarget(string $html): array
    {
        preg_match_all('/<label\b([^>]*)>([^<]*)/', $html, $matches, PREG_SET_ORDER);

        $byTarget = [];

        foreach ($matches as $match) {
            if (! preg_match('/\bfor="([^"]*)"/', $match[1], $forMatch)) {
                continue;
            }

            $byTarget[$forMatch[1]][] = trim(html_entity_decode($match[2]));
        }

        return $byTarget;
    }

    /**
     * THE CAPTION IS ASSOCIATED WITH THE CONTROL, AND THE CONTROL REALLY CARRIES THAT id.
     *
     * Both halves are needed. Asserting only that the `for` is non-empty passes on a caption that
     * points at an id nothing emits; asserting only that the id exists passes on a caption that
     * still says `for=""` beside it.
     *
     * @dataProvider views
     */
    public function test_each_caption_resolves_to_the_control_it_names(string $view, array $data, array $pairs): void
    {
        $html = $this->render($view, $data);
        $labels = $this->labelsByTarget($html);

        foreach ($pairs as [$caption, $target]) {
            $this->assertSame(
                0,
                preg_match('/<label[^>]*\bfor="\s*"[^>]*>\s*' . preg_quote($caption, '/') . '/', $html),
                $view . ': the caption "' . $caption . '" still renders as <label for="">, which is '
                . 'not a valid IDREF and matches no element. Pass the field name as Form::label\'s '
                . 'first argument — that also gives the control its auto-id.'
            );

            $this->assertArrayHasKey(
                $target,
                $labels,
                $view . ': no label points at "' . $target . '". Targets present: '
                . implode(', ', array_map(fn ($t) => '"' . $t . '"', array_keys($labels)))
            );

            $this->assertContains(
                $caption,
                $labels[$target],
                $view . ': for="' . $target . '" exists but the label carrying it reads '
                . implode(' / ', array_map(fn ($t) => '"' . $t . '"', $labels[$target]))
                . ', not "' . $caption . '"'
            );

            $this->assertSame(
                1,
                substr_count($html, 'id="' . $target . '"'),
                $view . ': id="' . $target . '" is emitted ' . substr_count($html, 'id="' . $target . '"')
                . ' times. The caption "' . $caption . '" must resolve to exactly one control — zero '
                . 'means the builder never auto-assigned the id, more than one means the caption '
                . 'activates whichever comes first.'
            );
        }
    }

    /**
     * THE FAQ REPEATER CANNOT USE AN id AT ALL, so it is the one site where the caption is a
     * `<span>` and the name comes from `aria-label`.
     *
     * `public/js/repeater.js` rewrites only `name` (:921-937) and contains no `attr('id')` handling
     * whatsoever, so any id inside `data-repeater-item` is duplicated once per FAQ item at render
     * AND again per clone at runtime. `id="answer"` was already in the template and already
     * duplicated for any FAQ with two description items. A `for`/`id` pair here is unfixable by
     * construction; `aria-label` is carried by every clone and collides with nothing.
     *
     * TWO description items, so the `@foreach` really runs twice and a duplicate id would show.
     */
    public function test_the_faq_repeater_names_its_fields_without_an_id(): void
    {
        $html = $this->render('faq.edit', [
            'faq' => (object) [
                'id' => 6, 'topic' => 'Shipping',
                'description' => '[{"question":"How long?","answer":"Two days."},'
                    . '{"question":"How much?","answer":"Free."}]',
            ],
        ]);

        /* The premise: the fixture really did render both rows. */
        $this->assertSame(2, substr_count($html, 'name="question"'),
            'the two-item fixture did not produce two repeater rows, so nothing below is measuring '
            . 'the duplicate-id case');

        $this->assertSame(0, substr_count($html, 'id="answer"'),
            'id="answer" is still emitted inside data-repeater-item, so it duplicates per FAQ item '
            . 'and again per clone');

        $this->assertSame(2, substr_count($html, 'aria-label="Question"'),
            'the Question field in each repeater row must carry its own accessible name');
        $this->assertSame(2, substr_count($html, 'aria-label="Answer"'), 'same for Answer');

        /* The visible caption stays visible, and is no longer a label that names nothing. */
        $this->assertStringContainsString('>Question</span>', $html);
        $this->assertStringContainsString('>Answer</span>', $html);
        $this->assertSame(0, preg_match('/<label[^>]*\bfor="\s*"/', $html),
            'faq/edit still carries a label with an empty for');

        /* And no id in the fragment is emitted twice — the property the repeater breaks. */
        $this->assertSame([], $this->duplicateIds($html),
            'duplicate ids in faq/edit: ' . implode(', ', $this->duplicateIds($html)));
    }

    /** The `@else` branch — a FAQ with no description items renders a different pair of fields. */
    public function test_the_faq_repeaters_empty_branch_is_named_too(): void
    {
        $html = $this->render('faq.edit', [
            'faq' => (object) ['id' => 6, 'topic' => 'Shipping', 'description' => null],
        ]);

        $this->assertSame(1, substr_count($html, 'name="question"'),
            'premise: the empty-description fixture must reach the @else branch, which renders one row');

        $this->assertSame(0, substr_count($html, 'id="answer"'));
        $this->assertSame(1, substr_count($html, 'aria-label="Question"'));
        $this->assertSame(1, substr_count($html, 'aria-label="Answer"'));
        $this->assertSame([], $this->duplicateIds($html));
    }

    private function duplicateIds(string $html): array
    {
        preg_match_all('/\sid="([^"]+)"/', $html, $matches);

        return array_keys(array_filter(array_count_values($matches[1]), fn ($n) => $n > 1));
    }

    /**
     * THE TWO FLASH-SALE HEADINGS ARE NOT FIELD CAPTIONS AND MUST NOT BE LABELS.
     *
     * "Apply this Campaign when these conditions are matched:" heads the condition repeater; there
     * is no single control for it to name. A `<span>`, not a `<p>`: `custom.css:4685` scopes
     * `.modal-content p:not([class^="ss-"])` to grey-600 at specificity (0,4,3) and beats the
     * `.form-label` rule at :4670 (0,3,2), so a `<p class="form-label">` would render two contrast
     * tiers lighter than every sibling caption in the same modal. Mirrored at rtl-custom.css:4677.
     */
    public function test_the_condition_heading_is_not_a_label(): void
    {
        foreach ([
            ['flash-sale.create', []],
            ['flash-sale.edit', [
                'flashsale' => (object) [
                    'id' => 4, 'name' => 'Winter', 'start_date' => '2030-01-01',
                    'end_date' => '2030-01-02', 'start_time' => '09:00', 'end_time' => '18:00',
                    'discount_type' => 'percentage', 'discount_amount' => 10,
                ],
                'sale_condition' => null,
            ]],
        ] as [$view, $data]) {
            $html = $this->render($view, $data);
            $heading = 'Apply this Campaign when these conditions are matched:';

            $this->assertStringContainsString(
                '<span class="form-label mb-0">' . $heading . '</span>',
                $html,
                $view . ': the condition heading must be a span carrying the caption styling, not a label'
            );

            $this->assertSame(0, preg_match('/<label[^>]*>\s*' . preg_quote($heading, '/') . '/', $html),
                $view . ': the condition heading is still a <label>, which names a control that does '
                . 'not exist');

            $this->assertStringNotContainsString('<p class="form-label', $html,
                $view . ': a <p class="form-label"> renders grey-600 on the white modal in dark mode '
                . '(custom.css:4685 beats :4670 on specificity). Use a span.');
        }
    }

    /**
     * NEGATIVE CONTROL — #category SURVIVES ON THE COUPON TYPE SELECT.
     *
     * The caption now says `for="category"` because the select carries an explicit
     * `'id' => 'category'`, which `getIdAttribute()` returns ahead of the field name. Renaming that
     * id to `coupon_type` so it matched the field name would have made the `for` look tidier and
     * killed coupon-type selection: ten JavaScript references drive the `.format` show/hide from
     * `#category` (coupon/create.blade.php:160,161,174,193; coupon/edit.blade.php:121,127,128,129,
     * 142,161).
     */
    public function test_the_coupon_type_select_keeps_its_javascript_id(): void
    {
        foreach (['coupon/create', 'coupon/edit'] as $view) {
            $source = (string) file_get_contents(base_path('resources/views/' . $view . '.blade.php'));

            $this->assertStringContainsString("'id' => 'category'", $source,
                $view . ": the coupon_type select lost 'id' => 'category'. Ten JS references drive "
                . 'the fixed-product-discount toggle from #category.');

            $this->assertGreaterThan(0, substr_count($source, "'#category'") + substr_count($source, '"#category"'),
                $view . ': premise — the JS references #category');
        }
    }

    /**
     * NEGATIVE CONTROL — THE POSTED FIELD NAMES ARE BYTE-IDENTICAL.
     *
     * Renaming a `for` is not renaming a field. `coupon_name` is the one that would have broken
     * silently: its caption reads "Name", so the tempting edit is the input, not the label.
     */
    public function test_the_posted_field_names_did_not_move(): void
    {
        foreach (self::views() as $label => [$view, $data, $pairs]) {
            $html = $this->render($view, $data);

            foreach (self::postedNames()[$label] ?? [] as $name) {
                $this->assertStringContainsString('name="' . $name . '"', $html,
                    $label . ' stopped posting ' . $name . ', which is a server-contract change, not '
                    . 'an accessibility one');
            }
        }
    }

    private static function postedNames(): array
    {
        return [
            'product_label/create' => ['name', 'status'],
            'product_label/edit' => ['name', 'status'],
            'product_brand/create' => ['name', 'logo', 'status', 'is_popular'],
            'product_brand/edit' => ['name', 'logo', 'status', 'is_popular'],
            'coupon/create' => ['coupon_name', 'coupon_type', 'minimum_spend', 'maximum_spend',
                'discount_amount', 'coupon_limit', 'coupon_limit_user', 'coupon_limit_x_item',
                'coupon_expiry_date', 'coupon_code', 'status', 'sale_items', 'free_shipping_coupon'],
            'coupon/edit' => ['coupon_name', 'coupon_type', 'minimum_spend', 'maximum_spend',
                'discount_amount', 'coupon_limit', 'coupon_limit_user', 'coupon_limit_x_item',
                'coupon_expiry_date', 'coupon_code', 'status', 'sale_items', 'free_shipping_coupon'],
            'flash-sale/create' => ['name', 'start_date', 'end_date', 'start_time', 'end_time',
                'discount_type', 'discount_amount'],
            'flash-sale/edit' => ['name', 'start_date', 'end_date', 'start_time', 'end_time',
                'discount_type', 'discount_amount'],
            'product-question/edit' => ['Product', 'question', 'answers'],
            'testimonial/create' => ['title', 'description', 'category_id', 'product_id',
                'rating_no', 'username', 'avatar', 'status'],
            'testimonial/edit' => ['title', 'description', 'category_id', 'product_id',
                'rating_no', 'username', 'avatar', 'status'],
            'faq/create' => ['topic'],
            'faq/edit' => ['topic', 'question', 'answer'],
        ];
    }

    /**
     * NEGATIVE CONTROL — THE TESTIMONIAL PRODUCT SELECT AGREES WITH THE AJAX THAT REPLACES IT.
     *
     * `TestimonialController::get_product` (app/Http/Controllers/TestimonialController.php:252)
     * answers `{status, html}` and its html carries `id="product_id"`. testimonial/create was the
     * sole view whose select said `id="product-dropdown"`, so NO single `for` could be right in
     * both states: correct before the AJAX fired and dangling after it. The outlier is fixed in the
     * view; the shared contract (`get.product` is also called by wishlist/index.blade.php:43) is
     * untouched.
     */
    public function test_the_testimonial_product_select_matches_the_ajax_replacement(): void
    {
        $html = $this->render('testimonial.create', ['categoryTree' => []]);

        $this->assertStringContainsString('id="product_id"', $html);
        $this->assertStringNotContainsString('product-dropdown', $html,
            'testimonial/create still emits id="product-dropdown", which the AJAX replaces with '
            . 'id="product_id" the first time a category is chosen');

        $index = (string) file_get_contents(base_path('resources/views/testimonial/index.blade.php'));

        $this->assertStringNotContainsString('product-dropdown', $index,
            'the index still appends options to #product-dropdown. That loop iterated the '
            . '{status, html} object, appended two options whose value was literally "undefined", '
            . 'and the next line discarded them by replacing the span\'s innerHTML.');

        $controller = (string) file_get_contents(base_path('app/Http/Controllers/TestimonialController.php'));
        $this->assertStringContainsString('id="product_id"', $controller,
            'premise: the controller is the thing the view now agrees with, and it is unchanged');
    }

    /**
     * NEGATIVE CONTROL FOR THE DETECTOR ITSELF — the scanners above can still see the defect.
     *
     * Every assertion in this file is a regex over rendered HTML. If the regexes were wrong the
     * whole file would go green against the unfixed views and prove nothing, which is the failure
     * mode this project has paid for repeatedly. So drive them over a fragment that carries the
     * original defect and confirm each one fires.
     */
    public function test_the_scanners_detect_the_original_defect(): void
    {
        $broken = '<label for="" class="form-label">Title</label>'
            . '<input class="form-control" name="title" type="text">'
            . '<label for="answer">Answer</label>'
            . '<textarea id="answer"></textarea><textarea id="answer"></textarea>';

        $this->assertSame(1, preg_match('/<label[^>]*\bfor="\s*"[^>]*>\s*Title/', $broken),
            'the empty-for scanner cannot see a literal for=""');

        $this->assertArrayNotHasKey('title', $this->labelsByTarget($broken),
            'the label scanner reported an association the fragment does not have');

        $this->assertSame(0, substr_count($broken, 'id="title"'),
            'premise: the unfixed shape really does emit no id for the control');

        $this->assertSame(['answer'], $this->duplicateIds($broken),
            'the duplicate-id scanner cannot see a repeated id');
    }

    /**
     * SCOPE — THE PRODUCT EDITOR STAYS OUT.
     *
     * `ChoiceGroupGuidanceTest.php:150-172` reserves resources/views/product/create.blade.php (17
     * labels) and edit.blade.php (21) as their own ledger row, with JavaScript keyed on those ids.
     * Dragging them in here is the unrequested widening that comment names.
     */
    public function test_the_scope_excludes_the_product_editor(): void
    {
        foreach (array_keys(self::views()) as $view) {
            $this->assertStringStartsNotWith('product/', $view,
                'the product editor is a separate tracked row and must not be swept here');
        }
    }
}
