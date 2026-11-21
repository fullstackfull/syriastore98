<?php

namespace Tests\Feature\Marketing;

use App\Models\Permission;
use App\Models\User;
use App\Services\TableFilterMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE FIVE MARKETING LISTS HAVE A FILTER BAR A PERSON CAN USE. MKT-002 part 2c.
 * =================================================================================================
 *
 * Parts 1, 1b and 2a gave the screens a vocabulary, a permission, a receipt and predicates that
 * narrow. None of that is reachable without a control, and MKT-002's requirement is the advanced
 * FILTER pattern -- so this asserts the bar exists, is operable, and says what it is doing.
 *
 * ONE SHARED COMPONENT, ASSERTED PER SCREEN. `components/ss/marketing-filters.blade.php` reads its
 * filters from `TableFilterMemory::vocabularyFor()` and its wording from `headingFor()` /
 * `optionsFor()`, so a select cannot offer a value the receipt has no words for. These tests drive
 * each of the five pages rather than the component, because a component that renders correctly and
 * is included nowhere is the defect this project has already shipped once (`SlotLayoutViewsRender`).
 *
 * BOTH LANGUAGES. CLAUDE.md requires any UI change to be checked in Arabic (RTL) and English (LTR);
 * the heading assertions therefore run in both locales, and the Arabic pass additionally refuses a
 * raw English heading leaking through an untranslated `__()`.
 */
class MarketingFilterBarTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> screen => [route, permission] */
    private function screens(): array
    {
        return [
            'coupon' => ['coupon.index', 'Manage Coupon'],
            'newsletter' => ['newsletter.index', 'Manage Newsletter'],
            'flash-sale' => ['flash-sale.index', 'Manage Flash Sale'],
            'wishlist' => ['wishlist.index', 'Manage Wishlist'],
            'abandon-cart' => ['abandon.carts.handled', 'Manage Cart'],
        ];
    }

    private function merchantWith(string $permission): User
    {
        [$merchant] = $this->makeTenant();

        Permission::firstOrCreate(['name' => $permission], ['display_name' => $permission]);
        $this->grantPermission(User::find($merchant->id), $permission);

        return User::find($merchant->id);
    }

    public function test_every_marketing_list_renders_a_get_form_pointing_at_its_own_route(): void
    {
        foreach ($this->screens() as $screen => [$route, $permission]) {
            $html = $this->actingAs($this->merchantWith($permission))
                ->get(route($route))->assertSuccessful()->getContent();

            $this->assertStringContainsString('method="GET" action="' . route($route) . '"', $html,
                "{$screen} has no filter form, or one that submits somewhere else");
            $this->assertStringContainsString('role="search"', $html,
                "{$screen}'s filter bar is not announced as a search region");
        }
    }

    public function test_every_filter_in_a_screens_vocabulary_has_a_labelled_control(): void
    {
        foreach ($this->screens() as $screen => [$route, $permission]) {
            $html = $this->actingAs($this->merchantWith($permission))
                ->get(route($route))->assertSuccessful()->getContent();

            foreach (TableFilterMemory::vocabularyFor($screen) as $name) {
                $id = 'ss-filter-' . $screen . '-' . str_replace('_', '-', $name);

                $this->assertStringContainsString('for="' . $id . '"', $html,
                    "{$screen}'s {$name} control has no label, so it is unusable by name");
                $this->assertStringContainsString('id="' . $id . '" name="' . $name . '"', $html,
                    "{$screen} has a label for {$name} pointing at no control");
            }
        }
    }

    public function test_the_dates_are_date_inputs_and_the_vocabularies_are_selects(): void
    {
        foreach ($this->screens() as $screen => [$route, $permission]) {
            $html = $this->actingAs($this->merchantWith($permission))
                ->get(route($route))->assertSuccessful()->getContent();

            foreach (TableFilterMemory::vocabularyFor($screen) as $name) {
                $id = 'ss-filter-' . $screen . '-' . str_replace('_', '-', $name);
                $isDate = TableFilterMemory::optionsFor($screen, $name) === [];

                $this->assertStringContainsString(
                    $isDate
                        ? 'type="date" class="ss-input" id="' . $id . '"'
                        : 'class="ss-select" id="' . $id . '"',
                    $html,
                    "{$screen}'s {$name} is the wrong kind of control for its vocabulary"
                );
            }
        }
    }

    public function test_every_option_a_select_offers_is_one_the_receipt_can_word(): void
    {
        /* The drift this closes: a select offering a value `label()` has no wording for narrows the
           list and says nothing, which also hides the Clear button. Both sides now read the same
           array, and this asserts the rendered markup really does. */
        foreach ($this->screens() as $screen => [$route, $permission]) {
            $html = $this->actingAs($this->merchantWith($permission))
                ->get(route($route))->assertSuccessful()->getContent();

            foreach (TableFilterMemory::vocabularyFor($screen) as $name) {
                foreach (TableFilterMemory::optionsFor($screen, $name) as $value => $wording) {
                    $this->assertStringContainsString('value="' . $value . '"', $html,
                        "{$screen} does not offer {$name}={$value} that its own vocabulary words");
                    $this->assertStringContainsString($wording, $html,
                        "{$screen} offers {$name}={$value} under different words than the receipt uses");
                }
            }
        }
    }

    public function test_apply_carries_the_marker_that_writes_the_memory(): void
    {
        foreach ($this->screens() as $screen => [$route, $permission]) {
            $html = $this->actingAs($this->merchantWith($permission))
                ->get(route($route))->assertSuccessful()->getContent();

            $this->assertStringContainsString('name="' . TableFilterMemory::APPLY . '" value="1"', $html,
                "{$screen}'s Apply does not carry ss_apply, so filtering would never be remembered");
        }
    }

    public function test_clear_appears_only_when_a_filter_is_actually_in_force(): void
    {
        foreach ($this->screens() as $screen => [$route, $permission]) {
            $merchant = $this->merchantWith($permission);
            $clearHref = route($route, [TableFilterMemory::CLEAR => 1]);

            $unfiltered = $this->actingAs($merchant)->get(route($route))->assertSuccessful()->getContent();

            $this->assertStringNotContainsString($clearHref, $unfiltered,
                "{$screen} offers Clear on an unfiltered list, so the button means nothing");

            /* And with every control on "Any", which a GET form submits as empty strings -- the
               exact shape that made the product screen show Clear when nothing was on. */
            $empties = array_fill_keys(TableFilterMemory::vocabularyFor($screen), '');

            $allAny = $this->actingAs($merchant)->get(route($route, $empties))->assertSuccessful()->getContent();

            $this->assertStringNotContainsString($clearHref, $allAny,
                "{$screen} offers Clear when every control is on Any");
        }
    }

    public function test_clear_appears_when_a_filter_is_in_force(): void
    {
        $filters = [
            'coupon' => ['ss_status' => '1'],
            'newsletter' => ['ss_source' => 'guest'],
            'flash-sale' => ['ss_window' => 'running'],
            'wishlist' => ['ss_status' => '1'],
            'abandon-cart' => ['ss_items' => 'several'],
        ];

        foreach ($this->screens() as $screen => [$route, $permission]) {
            $html = $this->actingAs($this->merchantWith($permission))
                ->get(route($route, $filters[$screen]))->assertSuccessful()->getContent();

            $this->assertStringContainsString(route($route, [TableFilterMemory::CLEAR => 1]), $html,
                "{$screen} narrowed the list and offered no way out");
        }
    }

    /**
     * BOTH LANGUAGES, SWITCHED THE WAY THE APPLICATION REALLY SWITCHES THEM.
     *
     * THE FIRST VERSION OF THIS TEST RENDERED ENGLISH TWICE. It called `app()->setLocale('ar')` and
     * then made a request -- but `SetLocate` resolves the locale per request from
     * `Session::get('LANGUAGE')` or the authenticated user's `language` column, and overwrites
     * whatever the test had set. So the "Arabic" pass asserted English against English, and its
     * assertion (that the rendered wording IS whatever `__()` resolves to) was additionally true
     * for any untranslated key. Two independent reasons the same test could not fail.
     *
     * `ArabicUiCoverageTest` is what caught it, on a string this bar introduced. The repair is to
     * set the merchant's OWN language -- which is how a real Arabic merchant is configured -- and
     * to resolve expectations through `Lang::get($key, [], $locale)`, which does not depend on
     * whatever locale happens to be ambient when the assertion runs.
     */
    public function test_the_bar_is_worded_in_both_languages(): void
    {
        foreach ($this->screens() as $screen => [$route, $permission]) {
            foreach (['en', 'ar'] as $locale) {
                $merchant = $this->merchantWith($permission);
                $merchant->language = $locale;
                $merchant->save();

                $html = $this->actingAs($merchant->fresh())
                    ->get(route($route))->assertSuccessful()->getContent();

                $this->assertStringContainsString('aria-label="', $html,
                    "{$screen}'s filter bar has no accessible name in {$locale}");

                foreach (TableFilterMemory::vocabularyFor($screen) as $name) {
                    $heading = TableFilterMemory::headingFor($name);
                    $expected = \Illuminate\Support\Facades\Lang::get($heading, [], $locale);

                    if ($locale === 'ar') {
                        /* Against the APP catalogue, for the reason written out in
                           `test_every_option_wording_has_an_arabic_entry`: a vendored module can
                           define the same key and mask a missing first-party translation. */
                        $catalogue = json_decode((string) file_get_contents(base_path('resources/lang/ar.json')), true);

                        $this->assertArrayHasKey($heading, $catalogue,
                            "`{$heading}` has no entry in THIS repository's Arabic catalogue");
                        $this->assertNotSame($heading, $expected,
                            "`{$heading}` has no Arabic entry, so an Arabic merchant reads English");
                    }

                    $this->assertStringContainsString($expected, $html,
                        "{$screen} is missing the {$name} heading in {$locale}");
                }
            }
        }
    }

    public function test_every_option_wording_has_an_arabic_entry(): void
    {
        /* The headings were checked and the OPTIONS were not, which is the larger half: a merchant
           reads "Running now" and "More than one item" far more often than the word "When". */
        /*
         * READ STRAIGHT OUT OF `resources/lang/ar.json`, NOT THROUGH `Lang::get()`, AND THAT IS THE
         * POINT OF THIS VERSION.
         *
         * A VENDORED MODULE CAN DEFINE THE SAME JSON KEY AS A FIRST-PARTY STRING, and one does:
         * `packages/workdo/LynomiaChat/src/resources/lang/ar.json` carries `"Running now"` as
         * `قيد التشغيل الآن`, while this slice added `جارٍ الآن` to the app catalogue. MEASURED: the
         * app's entry wins, and the module's acts as a FALLBACK -- so deleting the app entry still
         * resolved to Arabic, and a `Lang::get()`-based check could never fail for any key some
         * module happens to share. The first version of this test was exactly that check, and a
         * mutation removing the app entry survived it.
         *
         * So the assertion is about THIS repository's own catalogue: a first-party string must have
         * a first-party translation, whatever a module also happens to define. Recorded as
         * `GAP-224`, because the shadowing itself is a drift risk this slice only noticed.
         */
        $catalogue = json_decode((string) file_get_contents(base_path('resources/lang/ar.json')), true);

        $this->assertIsArray($catalogue, 'premise: the Arabic catalogue is readable JSON');

        $missing = [];

        foreach (array_keys($this->screens()) as $screen) {
            foreach (TableFilterMemory::vocabularyFor($screen) as $name) {
                foreach (array_keys(TableFilterMemory::optionsFor($screen, $name)) as $value) {
                    $source = (string) (self::ENGLISH_OPTION[$screen][$name][$value] ?? '');

                    if ($source === '') {
                        continue;
                    }

                    if (! array_key_exists($source, $catalogue)) {
                        $missing[] = "{$screen}.{$name}={$value}: {$source}";
                    }
                }
            }
        }

        $this->assertSame([], $missing, implode("\n  ", array_merge(
            ['These filter options render in ENGLISH on an Arabic merchant\'s screen:'],
            $missing
        )));
    }

    /** The English source of every option, so the Arabic check compares catalogues not renderings. */
    private const ENGLISH_OPTION = [
        'coupon' => [
            'ss_status' => ['1' => 'Active', '0' => 'Inactive'],
            'ss_type' => [
                'percentage' => 'Percentage',
                'fixed product discount' => 'Fixed amount off a product',
                'flat' => 'Flat amount off the basket',
            ],
            'ss_expiry' => ['expired' => 'Expired', 'current' => 'Still valid'],
        ],
        'newsletter' => [
            'ss_source' => ['customer' => 'A registered customer', 'guest' => 'An e-mail only'],
        ],
        'flash-sale' => [
            'ss_status' => ['1' => 'Active', '0' => 'Inactive'],
            'ss_window' => ['running' => 'Running now', 'upcoming' => 'Not started yet', 'ended' => 'Finished'],
            'ss_type' => ['percentage' => 'Percentage', 'flat' => 'Flat amount'],
        ],
        'wishlist' => ['ss_status' => ['1' => 'Active', '0' => 'Inactive']],
        'abandon-cart' => ['ss_items' => ['one' => 'One item', 'several' => 'More than one item']],
    ];
}
