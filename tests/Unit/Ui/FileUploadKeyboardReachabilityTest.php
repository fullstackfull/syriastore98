<?php

namespace Tests\Unit\Ui;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2.5 / upload-not-keyboard-reachable — ELEVEN IMAGE PICKERS IN THE MERCHANT ADMIN HAD NO
 * KEYBOARD PATH AT ALL. WCAG 2.1 SC 2.1.1 (Level A), and 4.1.2 for the name.
 * =================================================================================================
 *
 * THE MECHANISM. Every one of these uploads hides its `<input type="file">` with `d-none`, and
 * `public/assets/css/style.css:8212-8214` defines that as `display: none !important`. A
 * `display: none` element is "not being rendered": it is out of the tab order AND out of the
 * accessibility tree. A `<label for>` does NOT restore focusability — it forwards a CLICK — and
 * the label itself carries no `tabindex` and no `role`, so there is nothing to Tab to and nothing
 * to announce. The input's only behaviour hook is an inline `onchange`, which never fires because
 * no picker can be opened; and there is no JavaScript substitute anywhere
 * (`data-filename`, `logo_update`, `avatar_update` and `input[type="file"]` appear in no
 * non-minified script under public/js or public/assets/js).
 *
 * THE REPAIR IS A CLASS SWAP: `d-none` -> `ss-visually-hidden` (store-syria-ds.css:1582), which
 * hides the input visually with `position: absolute` + `clip: rect(0,0,0,0)` while leaving it
 * rendered — so it is focusable, operable and announced. `name`, `id`, `data-filename`, `accept`
 * and the inline `onchange` are byte-identical on all eleven.
 *
 * AND THE RING, because a focusable control with no visible indicator is 2.4.7. The browser paints
 * the ring on a 1px clipped box, which is invisible, so it has to be drawn on the styled element
 * the merchant actually sees. In all three markup shapes the input is the IMMEDIATE NEXT SIBLING
 * of that element, so ONE relationship covers every site:
 *
 *     <label for> > div.image-upload + input      category, product_brand, testimonial
 *     <label class="image-upload"> + input        blog
 *     label.ss-gateway-image__pick + input        the payment partial
 *
 * `:focus-visible` on the INPUT, never `:focus-within` on an ancestor: clicking a label focuses the
 * input, so `:focus-within` would paint a ring for POINTER users — the regression
 * store-syria-a11y.css's header and `FocusIndicatorRestorationTest` exist to forbid. And
 * `.image-upload:focus-within` could not match anything at all, because `.image-upload` is the
 * input's PRECEDING SIBLING, never its ancestor.
 *
 * -------------------------------------------------------------------------------------------------
 * ONE THING THE DRAFT PLAN GOT BACKWARDS, and it is the half that would have shipped a new defect.
 * -------------------------------------------------------------------------------------------------
 *
 * `gateway_image.blade.php` named its input with `aria-labelledby="<key>-label"`, pointing at the
 * shared visible caption "Gateway Image". The draft called that attribute "dead weight" because
 * the input was absent from the tree — but it was the INPUT that was absent; the span was always
 * rendered. The moment the class swap puts the input INTO the tree, `aria-labelledby` becomes live,
 * and it outranks the native `<label for>` in the accessible-name algorithm — so it would have
 * SUPPRESSED the per-gateway `<span class="ss-visually-hidden">Upload an image for :gateway</span>`
 * that the file's own header says was added to tell them apart. `SettingController` defines twenty
 * or more gateways and payment_setting renders them on one page, so the repair as drafted would
 * have shipped 20+ focusable upload controls all announcing "Gateway Image". The same defect class
 * `VariantDeleteScopeTest.php:313-317` measured on the variant panels. The attribute is removed;
 * the visible caption stays.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT THIS TEST CANNOT SETTLE, stated rather than implied.
 * -------------------------------------------------------------------------------------------------
 *
 * Whether a POINTER user sees the ring after the OS picker closes. Some engines apply
 * `:focus-visible` when focus returns to the input from a file dialog. That is engine behaviour, it
 * needs a real browser, and no assertion here touches it. What IS pinned statically is the only
 * thing CSS can guarantee: the rule keys on `:focus-visible` on the input and on no `:focus-within`
 * anywhere.
 */
class FileUploadKeyboardReachabilityTest extends TestCase
{
    /* `get_file()` reads the platform admin out of `users`. Nothing here asserts on a row. */
    use RefreshDatabase;

    private const A11Y = 'public/css/store-syria-a11y.css';

    private const DESIGN_SYSTEM = 'public/css/store-syria-ds.css';

    /** view => [view data, [[posted name, the id its label points at], …]] */
    public static function uploads(): array
    {
        $category = (object) [
            'id' => 1, 'name' => 'Shoes', 'parent_id' => null,
            'image' => null, 'icon_image' => null, 'trending' => 0, 'status' => 1,
        ];

        return [
            'category/create' => ['category.create', ['categoryTree' => []], [
                ['image', 'upload_image'],
                ['icon_image', 'upload_icon_image'],
            ]],
            'category/edit' => ['category.edit', ['category' => $category, 'categoryTree' => []], [
                ['image', 'upload_image'],
                ['icon_image', 'upload_icon_image'],
            ]],
            'testimonial/create' => ['testimonial.create', ['categoryTree' => []], [
                ['avatar', 'upload_avatar'],
            ]],
            'testimonial/edit' => ['testimonial.edit', [
                'Testimonial' => (object) [
                    'id' => 2, 'title' => 'Great', 'description' => 'Lovely', 'category_id' => 1,
                    'product_id' => 1, 'rating_no' => 5, 'username' => 'Rana', 'avatar' => null,
                    'status' => 1,
                ],
                'categoryTree' => [], 'product' => [],
            ], [['avatar', 'upload_avatar']]],
            'product_brand/create' => ['product_brand.create', [], [['logo', 'brand_logo']]],
            'product_brand/edit' => ['product_brand.edit', [
                'productBrand' => (object) ['id' => 9, 'name' => 'Acme', 'logo' => null, 'status' => 1, 'is_popular' => 0],
            ], [['logo', 'brand_logo']]],
            'blog/create' => ['blog.create', ['blogCategoryList' => []], [
                ['cover_image', 'upload_cover_image'],
            ]],
            'blog/edit' => ['blog.edit', [
                'blog' => (object) [
                    'id' => 4, 'title' => 'Post', 'slug' => 'post', 'short_description' => 's',
                    'content' => 'c', 'category_id' => 1, 'cover_image' => null,
                ],
                'blogCategoryList' => [],
            ], [['cover_image', 'upload_cover_image']]],
            'setting/gateway_image' => ['setting.payment_partials.gateway_image', [
                'gateway' => [
                    'image_key' => 'stripe_image',
                    'name' => 'Stripe',
                    'image_default' => 'uploads/payment_icon/stripe.png',
                ],
                'setting' => [],
            ], [['stripe_image', 'stripe_image']]],
        ];
    }

    private function render(string $view, array $data): string
    {
        $html = (string) view($view, $data)->render();

        $this->assertNotSame('', trim($html), $view . ' rendered nothing');

        return $html;
    }

    /** Every `<input type="file" …>` tag in $html, as its raw attribute string. */
    private function fileInputs(string $html): array
    {
        preg_match_all('/<input\b[^>]*type="file"[^>]*>/', $html, $matches);

        return $matches[0];
    }

    /**
     * THE PICKER IS IN THE TAB ORDER, AND IN THE ACCESSIBILITY TREE.
     *
     * Driven on the rendered document rather than the template, because `d-none` on a file input is
     * exactly the shape that reads as deliberate in a template and is a 2.1.1 failure in a
     * document. Nothing else on the tag may move: the whole repair is one class.
     *
     * @dataProvider uploads
     */
    public function test_every_file_input_is_focusable(string $view, array $data, array $expected): void
    {
        $html = $this->render($view, $data);
        $inputs = $this->fileInputs($html);

        $this->assertCount(count($expected), $inputs,
            $view . ': expected ' . count($expected) . ' file input(s), found ' . count($inputs)
            . '. The census this repair was measured against has moved.');

        foreach ($inputs as $input) {
            $this->assertStringNotContainsString('d-none', $input,
                $view . ': this file input is still `d-none`, which style.css:8212 defines as '
                . '`display: none !important` — out of the tab order and out of the accessibility '
                . 'tree. A <label for> forwards a click; it does not restore focusability. ' . $input);

            $this->assertStringContainsString('ss-visually-hidden', $input,
                $view . ': the file input must be hidden with `ss-visually-hidden`, which keeps it '
                . 'rendered, focusable and announced. ' . $input);
        }

        foreach ($expected as [$name, $id]) {
            $this->assertStringContainsString('name="' . $name . '"', $html,
                $view . ' stopped posting ' . $name . '. AdminApiTenancyTest.php:359 posts '
                . '`icon_image` and OperatorCatalogueWriteTest.php:445 posts `cover_image` as real '
                . 'UploadedFiles — a renamed field is a server-contract break, not a class swap.');

            $this->assertStringContainsString('id="' . $id . '"', $html,
                $view . ': the input lost id="' . $id . '", so the styled label that drives it now '
                . 'points at nothing');

            $this->assertSame(1, substr_count($html, 'for="' . $id . '"') > 0 ? 1 : 0,
                $view . ': no label points at ' . $id);
        }
    }

    /**
     * THE PREVIEW STILL FIRES, AND THE `for`/`id` PAIRING THAT DRIVES THE PICKER IS INTACT.
     *
     * The inline `onchange` reads `this.files[0]` and writes an `<img>` src. A visually-hidden
     * input IS rendered, so `change` dispatches normally — but a later "cleanup" that moved the
     * input out of its `<label>` would break the click path silently, so pin it.
     */
    public function test_the_inline_preview_handler_survives(): void
    {
        foreach ([
            ['category.create', ['categoryTree' => []], 'categoryImage'],
            ['product_brand.create', [], 'brandLogo'],
            ['testimonial.create', ['categoryTree' => []], 'avatarImage'],
        ] as [$view, $data, $target]) {
            $html = $this->render($view, $data);

            $this->assertStringContainsString(
                "document.getElementById('" . $target . "').src = window.URL.createObjectURL(this.files[0])",
                $html,
                $view . ': the inline onchange preview is gone, so choosing a file no longer shows it'
            );

            $this->assertSame(1, preg_match('/<label for="[^"]+">\s*<div class="image-upload/', $html),
                $view . ': the styled .image-upload div is no longer inside the <label for> that '
                . 'drives the picker, so clicking it does nothing');
        }
    }

    /**
     * THE PAYMENT PARTIAL NAMES EACH GATEWAY, NOT ALL OF THEM THE SAME.
     *
     * With `aria-labelledby` present, every one of the twenty-odd gateways on
     * `payment_setting.blade.php` would announce "Gateway Image": step 2B of the accessible-name
     * algorithm outranks step 2D's native `<label for>`. Removing the attribute lets the name fall
     * through to the label, whose visually-hidden span carries the gateway's own name. The visible
     * caption stays exactly where it is; its `id` is simply no longer referenced.
     */
    public function test_each_gateway_upload_announces_its_own_gateway(): void
    {
        $html = $this->render('setting.payment_partials.gateway_image', [
            'gateway' => [
                'image_key' => 'stripe_image', 'name' => 'Stripe',
                'image_default' => 'uploads/payment_icon/stripe.png',
            ],
            'setting' => [],
        ]);

        $this->assertStringNotContainsString('aria-labelledby', $html,
            'aria-labelledby is back on the gateway upload. It outranks the <label for>, so all '
            . 'twenty-odd gateways would announce the shared caption "Gateway Image" — the defect '
            . 'VariantDeleteScopeTest:313-317 measured on the variant panels.');

        $this->assertStringContainsString('Upload an image for Stripe', $html,
            'the per-gateway accessible name is gone');
        $this->assertStringContainsString('class="ss-visually-hidden"', $html,
            'the per-gateway name must stay visually hidden, so the icon-only design is unchanged');

        /* The visible caption is untouched. */
        $this->assertStringContainsString(
            '<span class="ss-label" id="stripe_image-label">Gateway Image</span>',
            $html,
            'the visible caption changed; only the aria-labelledby that referenced it was removed'
        );

        /* And `accept` is byte-identical: it is outside UploadFieldAcceptContractTest's scope and
           must neither be removed here nor copied to the other ten. */
        $this->assertStringContainsString('accept="image/*"', $html);

        $source = (string) file_get_contents(
            base_path('resources/views/setting/payment_partials/gateway_image.blade.php')
        );

        $this->assertStringNotContainsString(
            'the input stays in the accessibility tree and in the tab order via its label',
            $source,
            'the paragraph defending `d-none` is back in the file. It is false — `.d-none` is '
            . '`display: none !important` — and it is the one place the wrong rationale was '
            . 'written down, so a reviewer citing it would restore the defect.'
        );
    }

    /**
     * THE RING IS DRAWN ON THE ELEMENT THE MERCHANT CAN SEE, FROM THE DESIGN SYSTEM'S OWN TOKENS,
     * AND ONLY FOR KEYBOARD FOCUS.
     *
     * The two declarations are byte-identical to the ones already at the head of the same file, so
     * they cannot drift from the ring every other control paints.
     */
    public function test_the_focus_ring_rule_is_keyboard_only_and_token_based(): void
    {
        $css = (string) file_get_contents(base_path(self::A11Y));
        $code = $this->codeOf($css);

        foreach ([
            '.image-upload:has(+ input[type="file"]:focus-visible)',
            '.ss-gateway-image__pick:has(+ input[type="file"]:focus-visible)',
        ] as $selector) {
            $this->assertStringContainsString($selector, $code,
                'store-syria-a11y.css does not paint a focus ring for ' . $selector . '. The input '
                . 'is now focusable, and the browser paints its ring on a 1px clipped box — which '
                . 'is invisible. WCAG 2.4.7.');
        }

        $this->assertStringNotContainsString(':focus-within', $code,
            'a `:focus-within` rule would paint the ring when a POINTER user clicks the label, '
            . 'because clicking a label focuses its input. That is the regression this file exists '
            . 'to forbid, and on these shapes `.image-upload:focus-within` cannot match anyway: '
            . '.image-upload is the input\'s PRECEDING SIBLING, never its ancestor.');

        /* The ratchets FocusIndicatorRestorationTest already owns, re-asserted here because this
           change is the first to append to that file since they were written. */
        $this->assertStringNotContainsString('outline: none', $code);
        $this->assertStringNotContainsString(':focus {', $code,
            'a bare `:focus` would show the ring to pointer users on every control in the product');
        $this->assertStringContainsString('*:focus-visible', $code,
            'premise: the system-wide restoration this rule sits beside is still here');

        foreach (['--ss-focus-width', '--ss-focus-ring', '--ss-focus-offset'] as $token) {
            $this->assertStringContainsString($token, $css);
        }

        $this->assertMatchesRegularExpression('/var\(--ss-focus-ring,\s*#[0-9a-f]{3,8}\s*\)/i', $css,
            'the ring colour has no concrete fallback, so a page without the design system would '
            . 'paint `outline: 2px solid` with no colour');
    }

    /** A stylesheet with its comments blanked — blanked, not deleted, so line numbers survive. */
    private function codeOf(string $css): string
    {
        return (string) preg_replace_callback(
            '#/\*.*?\*/#s',
            fn (array $m) => preg_replace('/[^\n]/', ' ', $m[0]),
            $css
        );
    }

    /**
     * ONE FILE, NOT THREE. `public/css/custom.css` serves LTR and `public/css/rtl-custom.css`
     * serves Arabic and they are mirrored duplicates — a rule in one reaches half the merchants.
     * `store-syria-a11y.css` is linked ONCE, after the four-way theme `@if`, so one
     * direction-agnostic declaration covers LTR/RTL x light/dark. And it cannot live in
     * `store-syria-ds.css`, where `DesignSystemStylesheetScopeTest` requires every selector to be
     * confined by an `.ss-` token: `.image-upload` carries none.
     */
    public function test_the_rule_lives_in_exactly_one_stylesheet(): void
    {
        foreach ([self::DESIGN_SYSTEM, 'public/css/custom.css', 'public/css/rtl-custom.css'] as $sheet) {
            $this->assertStringNotContainsString(
                ':has(+ input[type="file"]:focus-visible)',
                (string) file_get_contents(base_path($sheet)),
                $sheet . ' now carries the upload focus ring too. Two copies of one fact is how the '
                . 'two mirrored stylesheets drift apart, and `.image-upload` in store-syria-ds.css '
                . 'would fail DesignSystemStylesheetScopeTest outright.'
            );
        }

        $layout = (string) file_get_contents(base_path('resources/views/layouts/app.blade.php'));

        $this->assertSame(1, substr_count($layout, 'store-syria-a11y.css'),
            'store-syria-a11y.css must be linked exactly once, after the theme @if');
        $this->assertStringContainsString('store-syria-ds.css', $layout,
            'the layout must still link store-syria-ds.css — it is where `.ss-visually-hidden` is '
            . 'defined, and without it eleven bare file inputs render as visible "Choose file" '
            . 'buttons on nine merchant forms');
    }

    /**
     * THE PREMISES THE SWAP RESTS ON, asserted rather than assumed — because if either moved, the
     * repair would look right and do nothing.
     */
    public function test_the_two_classes_still_mean_what_the_repair_assumes(): void
    {
        $theme = (string) file_get_contents(base_path('public/assets/css/style.css'));

        $this->assertMatchesRegularExpression('/\.d-none\s*\{\s*display:\s*none\s*!important/', $theme,
            'premise: `.d-none` really is `display: none !important`, which is why it removes the '
            . 'input from the tab order. It must NOT be weakened to fix this — it is used across '
            . 'the admin and all 70 vendored modules, and tests/Browser/p4e-commerce-automation.mjs'
            . ':141 reads it on #lync-rule-errors.');

        $ds = (string) file_get_contents(base_path(self::DESIGN_SYSTEM));

        $this->assertMatchesRegularExpression(
            '/\.ss-visually-hidden\s*\{[^}]*position:\s*absolute\s*!important[^}]*clip:\s*rect\(0,\s*0,\s*0,\s*0\)/s',
            $ds,
            'premise: `.ss-visually-hidden` hides by clipping an absolutely positioned box rather '
            . 'than by `display: none`. The `clip` is what makes the hiding robust: `width: 1px` '
            . 'carries no `!important` and `.form-control` sets `width: 100%` at equal specificity, '
            . 'so if the head were ever reordered the input would still be clipped to nothing '
            . 'rather than appearing full-width.'
        );
    }

    /**
     * THE RING COLOUR, COMPUTED HERE RATHER THAN QUOTED.
     *
     * `--ss-focus-ring` is `--ss-blue-500` in light mode. Read the hex out of store-syria-ds.css
     * and run the WCAG relative-luminance formula on it against the `#ffffff` modal surface the
     * ring is painted beside: MEASURED 4.44:1, above the 3:1 SC 1.4.11 asks of a non-text focus
     * indicator. The same number is in the comment above the rule. Asserting a ratio nobody
     * computed is how a contrast claim goes stale the first time a token is retuned, so this reads
     * the live token instead of a literal.
     *
     * The dark value `--ss-cyan-400` is measured at 8.27:1 on the host dark page by the design
     * system's own note (store-syria-ds.css:114); computed here against `#1a1d23` it is 9.02:1.
     * Either way it clears 3:1 comfortably, and the exact dark page background is the host
     * theme's, not a value this test can pin.
     */
    public function test_the_ring_colour_clears_the_non_text_contrast_threshold(): void
    {
        $ds = (string) file_get_contents(base_path(self::DESIGN_SYSTEM));

        $this->assertMatchesRegularExpression(
            '/--ss-focus-ring:\s*var\(--ss-blue-500\)/',
            $ds,
            'premise: the light-mode focus ring still resolves to --ss-blue-500'
        );
        $this->assertSame(1, preg_match('/--ss-blue-500:\s*(#[0-9a-fA-F]{6})/', $ds, $hex),
            'premise: --ss-blue-500 is declared as a literal hex');

        $ratio = $this->contrast($hex[1], '#ffffff');

        $this->assertGreaterThanOrEqual(3.0, $ratio,
            'the focus ring measures ' . number_format($ratio, 2) . ':1 against the white modal '
            . 'surface. SC 1.4.11 asks 3:1 of a non-text indicator, and a ring nobody can see is '
            . 'the 2.4.7 failure this rule was added for.');

        /* The number in the comment above the rule, so the two cannot drift apart. */
        $this->assertSame('4.44', number_format($ratio, 2),
            '--ss-blue-500 was retuned to ' . $hex[1] . ', which measures '
            . number_format($ratio, 2) . ':1 rather than the 4.44:1 recorded in '
            . 'store-syria-a11y.css §3 and in store-syria-ds.css:103. Update both comments.');

        $this->assertStringContainsString('4.44:1',
            (string) file_get_contents(base_path(self::A11Y)),
            '§3 must state the measured ratio, not leave it to be rediscovered');
    }

    /** WCAG 2.x relative luminance and contrast ratio, from the specification. */
    private function contrast(string $foreground, string $background): float
    {
        $luminance = static function (string $hex): float {
            $hex = ltrim($hex, '#');
            $channels = [];

            foreach ([0, 2, 4] as $offset) {
                $value = hexdec(substr($hex, $offset, 2)) / 255;
                $channels[] = $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            }

            return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
        };

        $a = $luminance($foreground);
        $b = $luminance($background);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /**
     * NEGATIVE CONTROL FOR THE DETECTOR — the scanner can still see the original markup.
     *
     * Every assertion above is a regex over rendered HTML or CSS text, so drive the input scanner
     * over the shape that shipped and confirm it reports it.
     */
    public function test_the_scanner_detects_the_original_markup(): void
    {
        $broken = '<label for="upload_image"><div class="image-upload bg-primary pointer w-100 logo_update"></div>'
            . '<input type="file" class="form-control file d-none" name="image" id="upload_image"></label>';

        $inputs = $this->fileInputs($broken);

        $this->assertCount(1, $inputs, 'the file-input scanner cannot see the original tag');
        $this->assertStringContainsString('d-none', $inputs[0],
            'the scanner reads an attribute string that does not contain the class it is checking');
        $this->assertStringNotContainsString('ss-visually-hidden', $inputs[0]);

        /* And the pointer-regression scanner really fires on the selector it forbids. */
        $this->assertStringContainsString(':focus-within',
            $this->codeOf('.image-upload:focus-within { outline: 2px solid red; }'),
            'the :focus-within scanner reads the wrong text');
    }
}
