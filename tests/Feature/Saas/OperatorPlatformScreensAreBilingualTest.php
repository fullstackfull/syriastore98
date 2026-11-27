<?php

namespace Tests\Feature\Saas;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;

/**
 * NAV-004 — THE FOUR NEWLY-NAVIGABLE OPERATOR SCREENS, IN BOTH LANGUAGES AND BOTH DIRECTIONS.
 * =================================================================================================
 *
 * `OperatorPlatformNavigationTest` asserts these four are now REACHABLE; this file asserts they are
 * USABLE once reached, for the half of that question a test can settle without a browser: the
 * direction, the language, and the refusal.
 *
 *   kyc.queue                 platform/verification   the identity-paper review queue
 *   platform.approvals.index  platform/approvals      the two-person approval queue
 *   platform.incidents.index  platform/incidents      the platform incident log
 *   platform.operations       platform/operations     database, queue and integration readings
 *
 * ================== WHY THE ASSERTION IS A SENTENCE AND NOT A STATUS CODE ==================
 *
 * CLAUDE.md records the trap that makes a status code worthless here: an `ActivatedModule()` gate
 * answers `redirect()->route('dashboard')`, so a screen the principal cannot open **renders the
 * dashboard with HTTP 200** and a status-code assertion photographs the wrong page. The same shape
 * has been measured six times in a row on this repository's own operator screens by the live
 * harness. So every arm here asserts on a SENTENCE ONLY THAT VIEW'S BODY CAN PRODUCE — the dashboard
 * does not contain it, and neither does any other screen in the group.
 *
 * AND THE ARABIC ARM ASSERTS ARABIC TEXT, NOT `dir="rtl"`. The direction attribute comes from the
 * LAYOUT (`layouts/app.blade.php` derives it from `users.language`), so it is `rtl` on an Arabic
 * page whose body printed nothing but English — which is exactly what an undeclared `__()` key
 * produces. Asserting both separates "the chrome flipped" from "the screen speaks Arabic".
 *
 * NO PHYSICAL SIDE IS PINNED, which is the project's own rule for RTL: the layout and the design
 * system use logical properties, so a test that asserted `ml-3` or `text-left` would be pinning
 * the defect rather than the behaviour. `test_the_four_views_use_logical_and_not_physical_spacing`
 * asserts the converse — that the views contain no physical-side utility at all — and it is keyed
 * on the SAFE set (`ms-`, `me-`, `ps-`, `pe-`, `text-start`, `text-end`, and the axis and
 * single-side-vertical forms) rather than on a list of bad spellings, per CLAUDE.md: *a list of
 * dangerous spellings is always short by the one nobody thought of.*
 *
 * ========================== THE REFUSAL, AND WHY IT IS ASSERTED HERE ==========================
 *
 * The four navigation entries carry `'permission' => ''`, which is correct — `isAbleTo()` refuses
 * the platform operator for any name they have not been granted, so a permission name on one of
 * these entries could HIDE a screen the operator may certainly open. The thing that makes it safe
 * is that the EVENT is the gate. `OperatorPlatformNavigationTest` asserts the menu half of that in
 * both directions; this file asserts the REQUEST half, which is a different claim: a merchant who
 * learns one of these uris must be refused by the route, not merely left without a link to it.
 */
final class OperatorPlatformScreensAreBilingualTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * route name => [a sentence only the EN body prints, a sentence only the AR body prints].
     *
     * Each pair is taken from the view's own `@section('content')`, verbatim, and chosen to be
     * absent from the dashboard — which is the page a silent `redirect()->back()` or an
     * entitlement gate would substitute at HTTP 200.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function screenProvider(): array
    {
        return [
            'verification queue' => [
                'kyc.queue',
                'Each row is one kind of verification for one store',
                'كل صف هو نوع توثيق واحد لمتجر واحد',
            ],
            'approval queue' => [
                'platform.approvals.index',
                'takes effect only when a different operator approves it',
                'ولا يصبح ساري المفعول إلا بموافقة مشغّل آخر مختلف',
            ],
            'incident log' => [
                'platform.incidents.index',
                'One row per thing that went wrong with the platform itself',
                'صفٌّ لكل خطأ أصاب المنصة نفسها',
            ],
            /*
             * NOT `nothing here is judged`, WHICH WAS THE FIRST CHOICE AND IS WRONG BY A HAIR:
             * `platform_resources.blade.php` carries a near-identical sentence ("What this machine
             * reports about itself ... nothing here is judged"), so that marker would also be
             * satisfied by the RESOURCES screen -- which is in the same navigation group, one
             * entry above, and is the single most likely page for a mis-wired link to land on.
             * A marker has to separate the screen from its neighbours, not only from the
             * dashboard.
             */
            'operations' => [
                'platform.operations',
                'queues and integrations report about themselves',
                'وطوابيره وتكاملاته عن نفسها',
            ],
        ];
    }

    private function operator(string $language): User
    {
        $operator = $this->makePlatformAdmin(['language' => $language]);
        $this->makeStore($operator, ['default_language' => $language]);
        (new User)->flushCache();
        Cache::forget('admin_details');

        return User::find($operator->id);
    }

    /**
     * The response body with whitespace collapsed.
     *
     * NOT `strip_tags()`. CLAUDE.md: the merchant layout inlines scripts containing bare `<`
     * comparisons, and `strip_tags()` reads each as a tag opening and discards everything to the
     * next `>` — measured at 80,007 bytes becoming 18,679 on `/finance`, taking a whole rendered
     * block with it. It silently inverts an ABSENCE assertion.
     */
    private function body(User $principal, string $uri): string
    {
        config(['app.debug' => false]);

        $response = $this->actingAs($principal)->get($uri);

        $this->assertSame(200, $response->getStatusCode(),
            $uri . ' did not render for the platform operator (HTTP '
            . $response->getStatusCode() . ').');

        return (string) preg_replace('/\s+/', ' ', (string) $response->getContent());
    }

    /**
     * PREMISE: THE ENGLISH SENTENCES ARE REALLY ABSENT FROM THE DASHBOARD.
     *
     * Everything below distinguishes "the screen rendered" from "the dashboard rendered at HTTP
     * 200 instead" by looking for a sentence. If one of those sentences were ALSO on the
     * dashboard, every arm would pass against the substituted page and this file would assert
     * nothing at all. So the separation is measured rather than assumed.
     */
    #[Test]
    public function test_premise_the_marker_sentences_are_not_on_the_dashboard(): void
    {
        $body = $this->body($this->operator('en'), '/dashboard');

        foreach (self::screenProvider() as $label => [$route, $english]) {
            $this->assertStringNotContainsString($english, $body,
                'the marker sentence for the ' . $label . ' is also on the operator dashboard, so '
                . 'it cannot tell a rendered screen from a redirect to the dashboard. Pick a '
                . 'sentence only that view prints.');
        }

        /* And the dashboard really did render, so the absence above is not the absence of a page. */
        $this->assertStringContainsString('Across the whole platform', $body,
            'the operator dashboard did not render, so this premise measured nothing.');

        /*
         * AND THE MARKERS SEPARATE THE FOUR SCREENS FROM EACH OTHER, which the dashboard check
         * alone does not: the likeliest wrong page for a navigation entry to land on is its
         * SIBLING, not the dashboard. Measured here rather than reasoned about -- the first
         * `operations` marker ("nothing here is judged") appears verbatim on
         * `platform/resources`, one entry above it in the same submenu.
         */
        $pages = [];

        foreach (self::screenProvider() as $label => [$route]) {
            $pages[$label] = $this->body($this->operator('en'), route($route, [], false));
        }

        foreach (self::screenProvider() as $label => [, $english]) {
            foreach ($pages as $other => $page) {
                if ($other === $label) {
                    $this->assertStringContainsString($english, $page,
                        'the marker for the ' . $label . ' is not on its own screen.');

                    continue;
                }

                $this->assertStringNotContainsString($english, $page,
                    'the marker sentence for the ' . $label . ' also appears on the ' . $other
                    . ' screen, so it cannot tell a correct link from one pointing at its '
                    . 'sibling. Pick a sentence only that view prints.');
            }
        }
    }

    #[DataProvider('screenProvider')]
    #[Test]
    public function test_the_screen_renders_its_own_content_in_english(string $route, string $english): void
    {
        $body = $this->body($this->operator('en'), route($route, [], false));

        $this->assertStringContainsString('lang="en"', $body,
            $route . ' did not render in English for an operator whose language is en.');
        $this->assertStringContainsString('dir="ltr"', $body,
            $route . ' did not render left-to-right for an English operator.');
        $this->assertStringContainsString($english, $body,
            $route . ' answered 200 without printing its own content. An entitlement gate or a '
            . 'redirect()->back() renders ANOTHER page at HTTP 200, so the status code cannot '
            . 'tell the two apart and this sentence can.');
    }

    #[DataProvider('screenProvider')]
    #[Test]
    public function test_the_screen_renders_its_own_content_in_arabic_and_right_to_left(
        string $route,
        string $english,
        string $arabic
    ): void {
        $body = $this->body($this->operator('ar'), route($route, [], false));

        $this->assertStringContainsString('lang="ar"', $body,
            $route . ' did not declare Arabic for an operator whose language is ar.');
        $this->assertStringContainsString('dir="rtl"', $body,
            $route . ' did not render right-to-left for an Arabic operator. The layout derives '
            . 'direction from users.language, so this is the chrome, not the body.');

        /* THE BODY, not the chrome. `dir="rtl"` above is true of an Arabic page that printed
           nothing but English — which is what an undeclared `__()` key produces. */
        $this->assertStringContainsString($arabic, $body,
            $route . ' rendered right-to-left with its own content still in English. `__()` '
            . 'returns its KEY when nothing is declared, so the direction flipping is not '
            . 'evidence that the screen speaks Arabic.');

        $this->assertStringNotContainsString($english, $body,
            $route . ' printed its ENGLISH marker sentence on the Arabic page, so at least one '
            . 'string on it is undeclared in ar.json.');
    }

    /**
     * A MERCHANT WHO LEARNS THE URI IS REFUSED BY THE ROUTE, not merely left without a link.
     *
     * 403 AND NEVER 401: CLAUDE.md records that the shipped Flutter app forces a logout on any
     * 401, so a new 401 on a path a client reaches is a user-visible regression. And never a
     * silent 200: the assertion names the status rather than asking "is it not 200", because a
     * `redirect()->back()` to the merchant dashboard would be a 302 and a dead end presented as a
     * screen.
     */
    #[DataProvider('screenProvider')]
    #[Test]
    public function test_a_merchant_is_refused_each_screen(string $route): void
    {
        [$merchant] = $this->makeTenant();

        $response = $this->actingAs(User::find($merchant->id))->get(route($route, [], false));

        $this->assertSame(403, $response->getStatusCode(),
            $route . ' answered HTTP ' . $response->getStatusCode() . ' for a merchant. These '
            . "screens read every tenant at once or the platform itself; `PlatformOperatorOnly` "
            . 'is the gate and 403 is the answer.');
    }

    /**
     * THE PREMISE FOR THE REFUSAL ABOVE, and it is the failure mode CLAUDE.md names first: a 403
     * for everyone is also what a broken fixture produces. The operator must be admitted to the
     * same uri in the same test class, or "the merchant was refused" is not evidence of a gate.
     */
    #[DataProvider('screenProvider')]
    #[Test]
    public function test_premise_the_operator_is_admitted_where_the_merchant_is_refused(string $route): void
    {
        $response = $this->actingAs($this->operator('en'))->get(route($route, [], false));

        $this->assertSame(200, $response->getStatusCode(),
            $route . ' refused the platform OPERATOR too (HTTP ' . $response->getStatusCode()
            . '), so the merchant refusal next door proves nothing about the gate.');
    }

    /**
     * THE VIEWS CARRY NO PHYSICAL-SIDE SPACING, so they are correct in both directions by
     * construction rather than by a pinned side.
     *
     * KEYED ON THE SAFE SET. The census reads every Bootstrap margin/padding utility the four
     * views use and requires each to be a LOGICAL or AXIS form — `ms`/`me` (start/end),
     * `ps`/`pe`, `mx`/`px`, `my`/`py`, `mt`/`mb`, `pt`/`pb` — plus `text-start`/`text-end` for
     * alignment. Anything else is physical BY DEFAULT, so `mr-3`, a `float-left`, a
     * `border-right` or a spelling nobody thought of all fail without being listed. CLAUDE.md
     * records a dangerous-spelling census being short by `sprintf`, `implode` and a bare variable
     * and reporting one site where there were fifteen.
     *
     * COMMENTS STRIPPED: three of these four views explain in a `{{-- --}}` block why they use
     * logical properties, and one of those explanations would otherwise be read as a violation.
     */
    #[Test]
    public function test_the_four_views_use_logical_and_not_physical_spacing(): void
    {
        $views = [
            'resources/views/kyc/queue.blade.php',
            'resources/views/platform/approvals.blade.php',
            'resources/views/superadmin/platform_incidents/index.blade.php',
            'resources/views/superadmin/platform_incidents/_table.blade.php',
            'resources/views/superadmin/platform_operations.blade.php',
        ];

        $physical = [];
        $utilities = 0;

        foreach ($views as $relative) {
            $path = dirname(__DIR__, 3) . '/' . $relative;

            $this->assertFileExists($path, $relative . ' is gone, so this census is vacuous.');

            $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '',
                (string) file_get_contents($path));

            /*
             * WHOLE CLASS TOKENS, NOT A `\b`-ANCHORED SUBSTRING, AND THE FIRST VERSION OF THIS
             * CENSUS GOT IT WRONG IN THE DIRECTION THAT PRODUCES A FALSE FINDING. `\b([mp])` is
             * satisfied by the `md-3` INSIDE `col-md-3`, because the hyphen before it is a word
             * boundary -- so a responsive grid column was reported three times as a physical
             * margin. Splitting the `class` attribute on whitespace asks about the class the
             * browser actually sees.
             */
            preg_match_all('/class\s*=\s*"([^"]*)"/', $source, $attributes);

            foreach ($attributes[1] as $value) {
                foreach (preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY) as $class) {
                    /* A token carrying a Blade expression is not a class; it is whatever the
                       expression evaluates to, and no source census can read it. */
                    if (str_contains($class, '{') || str_contains($class, '$')) {
                        continue;
                    }

                    /* Spacing: m/p, an optional side or axis letter, an optional breakpoint,
                       a size. The SAFE sides are named; anything else is physical by default. */
                    if (preg_match('/^([mp])([a-z]?)-(?:[a-z]{2}-)?(?:n?\d+|auto)$/', $class, $m) === 1) {
                        $utilities++;

                        if (! in_array($m[2], ['', 's', 'e', 'x', 'y', 't', 'b'], true)) {
                            $physical[] = $relative . ': ' . $class;
                        }

                        continue;
                    }

                    /* Alignment, floats and single-side borders and corners: same inversion. */
                    if (preg_match('/^(?:text|float|border|rounded)-/', $class) === 1) {
                        $utilities++;

                        if (preg_match('/-(left|right)(-|$)/', $class) === 1) {
                            $physical[] = $relative . ': ' . $class;
                        }
                    }
                }
            }
        }

        /* THE PREMISE. `assertSame([], $physical)` is satisfied just as well by a census that read
           nothing, and this one strips comments and runs two regular expressions over five files. */
        $this->assertGreaterThan(25, $utilities, sprintf(
            'only %d spacing or directional utilities were read across %d views; 38 were '
            . 'measured. The extraction has broken and this assertion is vacuous.',
            $utilities,
            count($views)
        ));

        sort($physical);

        $this->assertSame([], $physical,
            "a physical-side utility is used on one of the operator's newly-navigable screens. "
            . "Bootstrap 5's `ms`/`me`/`ps`/`pe` and `text-start`/`text-end` follow the writing "
            . "direction; `ml`/`mr`/`text-left`/`float-right` do not, so one of these screens is "
            . "laid out for Arabic as though it were English:\n  " . implode("\n  ", $physical));
    }
}
