<?php

namespace Tests\Feature\Ui;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\StripsSourceComments;

/**
 * EVERY DELIVERED SCREEN IS OFFERED BY SOME SURFACE A PRINCIPAL CAN REACH.
 * =================================================================================================
 *
 * THE OWNER'S STANDARD, VERBATIM: *"Every implemented feature must survive integration and be
 * accessible through its intended user journey. Backend code, an unreachable screen, or a passing
 * isolated test is not complete product delivery."*
 *
 * It has been broken twice in the measurable way. `OperatorPlatformNavigationTest` records the
 * first: five operator `platform/*` screens were built, gated and tested, and
 * `SuperAdminMenuListener` named none of them, so the only way to open one was to type its uri.
 * That file catches the recurrence for ONE shape of route — a parameterless `platform/<x>` GET
 * behind `PlatformOperatorOnly` — and it catches it by asking whether the NAVIGATION names it.
 *
 * THIS FILE ASKS THE MORE GENERAL QUESTION, over every screen the host application serves, and it
 * asks it of every surface rather than of the sidebar alone: a nav entry, a button, a link, a form
 * action, an ajax url and a controller redirect are all ways to arrive somewhere, and a screen
 * reached from the screen above it is reached.
 *
 * -------------------------------------------------------------------------------------------------
 * THE SUBJECT SET IS COMPUTED FROM THE BOOTED ROUTE TABLE
 * -------------------------------------------------------------------------------------------------
 *
 * Not by reading `routes/*.php`: `CLAUDE.md` records that a later declaration **replaces** an
 * earlier one for the same method+uri, silently dropping the earlier middleware, so the files and
 * the table disagree. `RouteFacade::getRoutes()` is the table the dispatcher uses.
 *
 * A route is in the subject set when **all** of these hold:
 *
 *   1. it answers `GET` — a writer is reached from the screen that posts to it, so it is the
 *      screen that has to be findable;
 *   2. it has a NAME — an unnamed route cannot be `route()`d, and every unnamed host web route in
 *      this application is a third-party callback (`zid/oauth/callback` and its two siblings);
 *   3. its action is in `App\Http\Controllers\` and NOT `App\Http\Controllers\Api\` — the api
 *      controllers' client is the Flutter admin app, which lives in another repository, so this
 *      repository cannot answer the question for them;
 *   4. its middleware includes the `web` group — the storefront and the panel;
 *   5. **its action METHOD'S OWN SOURCE renders a view.** Read through `ReflectionMethod` and
 *      sliced to the method's own line range, not the whole controller file: a controller holding
 *      twenty screens and one json endpoint must not make the json endpoint a screen.
 *
 * -------------------------------------------------------------------------------------------------
 * "OFFERED" ENUMERATES THE SAFE SET, BECAUSE A LIST OF SPELLINGS IS ALWAYS SHORT BY ONE
 * -------------------------------------------------------------------------------------------------
 *
 * `CLAUDE.md` §3: *WHEN A CENSUS HUNTS FOR SOMETHING DANGEROUS, ENUMERATE THE SAFE SET INSTEAD. A
 * list of dangerous spellings is always short by the one nobody thought of* — a concatenation
 * census keyed on `'literal' . $var` reported ONE dynamic raw-SQL site where there were FIFTEEN.
 *
 * So this census does not look for `route(`, `action(`, `url(`, `href=`, `$.ajax`, `fetch(` or any
 * other spelling. Laravel has exactly **three** ways to name a route's address, and they are
 * properties of the ROUTE rather than of the call:
 *
 *   | key | what is searched for | covers |
 *   |---|---|---|
 *   | name | the route's name as a quoted literal | `route('x')`, `redirect()->route('x')`, `Form::open(['route' => 'x'])`, a `Menu::add(['route' => 'x'])` entry, anything at all that passes the name |
 *   | uri | the route's static uri prefix as a quoted path | `url('/x')`, `href="/x"`, `'/x/' + id`, `$.ajax({url: '/x'})`, `<form action="/x">`, a hand-written link |
 *   | action | `Controller` and `'method'` in one file, or `Controller@method` | `action([C::class, 'm'])`, `action('C@m')` |
 *
 * A fourth spelling of any of those three still contains the name, the path or the pair, so an
 * unthought-of spelling is caught by default. What is NOT covered is a url assembled from pieces
 * that spell none of them (`'/' . $segment . '-list'`), and that is stated rather than papered
 * over.
 *
 * EVERY FILE IS READ WITH ITS COMMENTS REMOVED, and that is load-bearing rather than tidy.
 * `CLAUDE.md`: *A CENSUS THAT READS COMMENTS CAN BE SATISFIED — OR BROKEN — BY PROSE ABOUT THE
 * THING IT MEASURES*, hit eight times here. Measured for this census specifically:
 * `StoreController.php` contains the sentence *"nothing in the UI links `stores.edit` or
 * `stores.update` at all"*, and `OrderDocumentAccessGuard` contains the literal
 * `'order-packing-list/{id}'`. Both are findings of this census WRITTEN DOWN, and reading comments
 * would have let each one satisfy the question it answers.
 *
 * -------------------------------------------------------------------------------------------------
 * TWO QUESTIONS, BECAUSE "SOMETHING NAMES IT" IS NOT "SOMEBODY CAN GET THERE"
 * -------------------------------------------------------------------------------------------------
 *
 * **(a) Is the screen named at all?** A screen no file in the application names can only be
 * reached by typing its uri. This is the strong question and it has no false positives.
 *
 * **(b) Is the FEATURE entered from outside itself?** Measured on the KYC package: `kyc.index` is
 * named by `resources/views/kyc/access-log.blade.php` and `kyc.queue` by
 * `resources/views/kyc/queue.blade.php` — its own filter form. Every reference is inside the
 * feature, so the three screens link to each other and nothing links to any of them. Question (a)
 * cannot see that; this one can.
 *
 * It is asked per CONTROLLER rather than per route, and that matters in the other direction too:
 * `countries.create` is named only by `country/index.blade.php`, which `CountryController` itself
 * renders — and that is CORRECT, because the index is in the merchant navigation and carries the
 * "Add" button. A per-route rule would call it unreachable. What makes a feature unreachable is
 * that NONE of its screens has an entry from outside.
 *
 * A feature's "own surfaces" are its controller file plus the views it renders, transitively
 * through `@include`/`@component`/`@each` and `<x-...>` CHILDREN — never through `@extends`,
 * which points at the shared layout. Including the layout would make a navigation entry count as
 * self-reference and invert the whole test.
 *
 * -------------------------------------------------------------------------------------------------
 * THE ALLOWANCE IS BOUNDED, EACH ENTRY CARRIES ITS REASON, AND IT IS ASSERTED IN BOTH DIRECTIONS
 * -------------------------------------------------------------------------------------------------
 *
 * Two kinds of entry, and the distinction is the point:
 *
 *   `DELIBERATE` — unreachable by design. A framework redirect target, a superseded duplicate, an
 *   alias. Nothing is owed.
 *   `OPEN` — a real omission, measured, with the gap or workstream it belongs to. These are debts,
 *   not exemptions, and they are listed here so a NEW one cannot hide among them.
 *
 * ONE DEBT WAS PAID RATHER THAN DECLARED, as the first thing this census found:
 * `order.packing_list` — ORD-008's picking and packing document, which had a route, a handler, a
 * view, its own `OrderDocumentAccessGuard` entry and `OrderPackingListScreenTest` driving all
 * three of its answers, and which no screen in the application named. The link is now beside its
 * two siblings on the order detail screen, so it is absent from the allowance below and
 * `CommerceJourneyTest` asserts the link in the rendered page.
 *
 * BOTH DIRECTIONS: a subject that is no longer flagged but still carries an entry **fails this
 * file**, so closing one of these requires deleting its line.
 *
 * -------------------------------------------------------------------------------------------------
 * MUTATIONS RUN AGAINST THIS FILE — eight, all caught (`tests/Support/bin/mutate.py`)
 * -------------------------------------------------------------------------------------------------
 *
 * | mutation | result |
 * |---|---|
 * | drop the packing-list link from `order/view.blade.php` | CAUGHT (`named_by_some_file`) |
 * | let `app/Http/Middleware/` count as an offer | CAUGHT — `DemoModeMiddleware` names `'stores.edit'` |
 * | stop stripping comments | CAUGHT — `StoreController`'s own sentence names `stores.edit` |
 * | count a uri literal in `app/` as an offer | CAUGHT by `named_by_some_file` |
 * | the same, plus letting middleware offer | CAUGHT by `entry_point_from_outside_itself` |
 * | treat a controller's own file as outside its feature | CAUGHT |
 * | drop the `->render('x.y')` detector | CAUGHT by the premise |
 * | drop the url-shape rule on path literals | CAUGHT |
 *
 * The fourth row is worth reading twice: counting a uri literal in `app/` survived the
 * self-containment question and was caught only by the naming question. So the `app/` exclusion
 * on the uri key is a live control, but only one of the two questions exercises it — which is the
 * kind of thing that reads as "untested" if nobody writes down which lens caught it. That is the same ratchet
 * `.github/known-test-outcomes.json` uses and the reason this is a ratchet rather than a
 * suppression list. A new unreachable screen fails it too, which is the whole mechanism.
 */
final class DeliveredScreenReachabilityTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    /**
     * SCREENS THAT NO FILE NAMES, with the reason each one is tolerated.
     *
     * Measured 2026-11-27 against the booted route table of `int/checkpoint`.
     *
     * @var array<string, string> route name => kind + reason
     */
    private const UNNAMED_ALLOWANCE = [
        'currency-rates.index' => 'OPEN — the merchant web screen for currency rates has no link '
            . 'anywhere and no navigation entry. The feature is reachable only through the admin '
            . 'API (`api/admin/get-currency-rates`, `api/admin/update-currency-rate`), i.e. from '
            . 'the shipped Flutter app. Pre-dates this project\'s phases.',

        'customer.filter.data' => 'DELIBERATE (dead) — the endpoint writes its answer into '
            . '`#service-filter-data`, and `resources/views/customer/grid.blade.php` already '
            . 'records that THAT ELEMENT DOES NOT EXIST IN THIS VIEW. A previously documented '
            . 'dead fragment; nothing calls it and nothing should.',

        'pos.product.variant' => 'DELIBERATE (dead) — a POS variant fragment. Nothing in the till '
            . 'markup or its javascript names it; `public/assets/js/front-theme.js` uses a '
            . 'storefront endpoint of a different name for the same job.',

        'stock.report' => 'DELIBERATE (alias) — `GET stock-report` and `GET stock-reports` are TWO '
            . 'routes on ONE handler, `ReportController@StockReport`. The merchant navigation '
            . 'names `reports.stock_report` (`/stock-reports`), which is reachable; this is the '
            . 'unlinked twin, not an unreachable feature.',

        'stores.edit' => 'OPEN (already documented) — `StoreController` itself states "nothing in '
            . 'the UI links `stores.edit` or `stores.update` at all". The operator edits a store '
            . 'through `platform.stores.show` / the store listing instead.',

        'stripe.plan' => 'DELIBERATE (gateway entry) — `GET stripe/{code}` is a plan-payment '
            . 'hand-off reached from the gateway side, not from a page of this application.',
    ];

    /**
     * FEATURES WITH NO ENTRY POINT FROM OUTSIDE THEMSELVES, with the reason each one is tolerated.
     *
     * @var array<string, string> controller class => kind + reason
     */
    private const SELF_CONTAINED_ALLOWANCE = [
        \App\Http\Controllers\AddonController::class => 'DELIBERATE (superseded) — the operator\'s '
            . 'add-on screen in the navigation is `module.index` (`SuperAdminMenuListener`\'s '
            . '"Add-on Manager" entry points there), served by `ModuleController`. '
            . '`AddonController`\'s `/addon`, `/addon-apps` and `/addon/create` are the earlier '
            . 'parallel set, superseded rather than lost.',

        \App\Http\Controllers\Auth\ConfirmablePasswordController::class => 'DELIBERATE (framework '
            . 'redirect target) — `confirm-password` is where Laravel\'s own `password.confirm` '
            . 'middleware sends a request, never somewhere a user clicks to. A link to it would '
            . 'be wrong.',

        \App\Http\Controllers\CurrencyRateController::class => 'OPEN — same finding as '
            . '`currency-rates.index` above, stated once per question.',

        \App\Http\Controllers\KycVerificationController::class => 'OPEN — KYC-001..003. The '
            . 'merchant\'s own verification screen and the operator\'s verification queue are '
            . 'named only from inside the KYC views themselves, so nothing leads to either. The '
            . 'package\'s own author recorded it as "reachable only by URL". The operator\'s '
            . 'navigation is owned by the workstream that owns `SuperAdminMenuListener`, and the '
            . 'merchant\'s entry belongs with `MerchantPageDirectory`; neither is this file\'s to '
            . 'add.',

        \App\Http\Controllers\OperatorApprovalController::class => 'OPEN — Phase 06 IAM\'s '
            . 'two-person approval queue. `platform.approvals.index` is named ONLY by its own '
            . 'controller\'s redirects, so an operator who has not been handed the url cannot '
            . 'find the queue they are supposed to act in. Navigation for the platform screens is '
            . 'owned by another workstream.',

        \App\Http\Controllers\OtpIntegrationController::class => 'OPEN — the OTP integration '
            . 'settings screen (`/otp-integration`) has no navigation entry and no link. '
            . 'Pre-dates this project\'s phases.',
    ];

    // ---------------------------------------------------------------------------------------------
    // the census
    // ---------------------------------------------------------------------------------------------

    /** @var array<string, string>|null relative path => comment-stripped source */
    private static ?array $corpus = null;

    /**
     * Every file that could offer a route, read once per process with comments removed.
     *
     * `routes/` IS INCLUDED, but the name key ignores it: `->name('x')` names every route and
     * would satisfy this census for all of them. A redirect inside a route closure is still
     * found, through the uri and action keys.
     *
     * @return array<string, string>
     */
    private function corpus(): array
    {
        if (self::$corpus !== null) {
            return self::$corpus;
        }

        $root = base_path();
        $files = [];

        foreach ([
            ['resources/views', ['blade.php']],
            ['resources/js', ['js']],
            ['themes', ['blade.php', 'js']],
            ['packages/workdo', ['blade.php']],
            ['public', ['js']],
            ['app', ['php']],
            ['routes', ['php']],
        ] as [$dir, $extensions]) {
            $path = $root . '/' . $dir;

            if (! is_dir($path)) {
                continue;
            }

            $walk = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($walk as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                foreach ($extensions as $extension) {
                    if (str_ends_with($file->getFilename(), '.' . $extension)) {
                        $files[] = substr($file->getPathname(), strlen($root) + 1);

                        break;
                    }
                }
            }
        }

        $corpus = [];

        foreach ($files as $relative) {
            $corpus[$relative] = $this->strippedSource($root . '/' . $relative, $relative);
        }

        return self::$corpus = $corpus;
    }

    /**
     * A file's code, comments removed — the same two mechanisms `StripsSourceComments` uses, and
     * pinned equal to it by `test_premise_the_corpus_strips_comments_the_way_the_shared_trait_does`.
     *
     * WHY NOT THE TRAIT ITSELF, over the corpus. The trait asserts that stripping did not remove
     * the WHOLE file, which is exactly right for a test that asserts about ONE named file: a
     * stripper that ate it would turn every absence assertion into a free pass. Over a 2,600-file
     * corpus an empty answer is ordinary and legitimate -- measured, 10 such files, among them
     * `front_end/hooks/cart_sidebar.blade.php` (0 bytes, a hook placeholder) and
     * `front_end/hooks/product_rating.blade.php` (344 bytes, every one of them inside a single
     * `{{-- --}}`). Neither can offer a route either way.
     *
     * So the guard is replaced rather than removed: the COUNT of empty answers is ratcheted in
     * the premise below, and the stripper's agreement with the shared trait is asserted on a real
     * PHP file and a real Blade file. A stripper that started eating code fails both.
     */
    private function strippedSource(string $absolute, string $relative): string
    {
        $raw = (string) file_get_contents($absolute);

        /* Blade first, always: `{{-- --}}` may legally contain PHP comment syntax. */
        $stripped = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $raw);

        if (str_ends_with($relative, '.php') && ! str_ends_with($relative, '.blade.php')) {
            $code = '';

            foreach (token_get_all($stripped) as $token) {
                if (! is_array($token)) {
                    $code .= $token;

                    continue;
                }

                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    $code .= str_repeat("\n", substr_count($token[1], "\n"));

                    continue;
                }

                $code .= $token[1];
            }

            return $code;
        }

        $stripped = (string) preg_replace('#(?<![\w"\'/])/\*.*?\*/#s', '', $stripped);

        return (string) preg_replace('#^\s*//.*$#m', '', $stripped);
    }

    /**
     * The subject set: every host-application screen, from the booted route table.
     *
     * @return array<string, array{uri: string, action: string}> route name => shape
     */
    private function screens(): array
    {
        $screens = [];

        foreach (RouteFacade::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $name = (string) $route->getName();
            $action = (string) $route->getActionName();

            if ($name === '') {
                continue;
            }

            if (! str_starts_with($action, 'App\\Http\\Controllers\\')
                || str_starts_with($action, 'App\\Http\\Controllers\\Api\\')) {
                continue;
            }

            if (! in_array('web', array_filter($route->gatherMiddleware(), 'is_string'), true)) {
                continue;
            }

            if (! $this->methodRendersAView($action)) {
                continue;
            }

            $screens[$name] = ['uri' => $route->uri(), 'action' => $action];
        }

        ksort($screens);

        return $screens;
    }

    /**
     * Does the action's OWN METHOD render a view?
     *
     * Sliced to the method's line range through reflection. Reading the whole controller file
     * would make every json endpoint in a controller that also serves screens into a screen.
     */
    private function methodRendersAView(string $action): bool
    {
        if (! str_contains($action, '@')) {
            return false;
        }

        [$class, $method] = explode('@', $action, 2);

        if (! class_exists($class)) {
            return false;
        }

        try {
            $reflection = new \ReflectionMethod($class, $method);
        } catch (\Throwable) {
            return false;
        }

        $file = $reflection->getFileName();

        if ($file === false) {
            return false;
        }

        $lines = file($file);
        $source = implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));

        /*
         * `->render('x.y')` COUNTS, AND LEAVING IT OUT COST THE TWO BIGGEST SCREENS IN THE
         * PRODUCT. Measured: `order.index` and `product.index` were both absent from the subject
         * set, because `OrderController::index()` ends
         * `return $dataTable->render('order.index', ...)` -- the yajra DataTable pattern every
         * WorkDo list screen uses -- and the word `view` never appears. A census that silently
         * omitted the order list and the product list would be the exact failure CLAUDE.md names:
         * "a census that stops looking at part of its subject reads exactly like one that looked
         * and found nothing."
         */
        return (bool) preg_match('/\bview\s*\(|->render\s*\(\s*[\'"]/', $source);
    }

    /** The static part of a uri, up to the first route parameter. */
    private function staticPrefix(string $uri): string
    {
        $parts = [];

        foreach (explode('/', $uri) as $segment) {
            if (str_contains($segment, '{')) {
                break;
            }

            $parts[] = $segment;
        }

        return implode('/', $parts);
    }

    /** @var array{literal: array<string, list<string>>, path: array<string, list<array{0: string, 1: string}>>}|null */
    private static ?array $index = null;

    /**
     * THE INVERTED INDEX, built once. Without it this file asks 269 questions of 2,600 files and
     * takes 42 seconds (measured); with it, under four.
     *
     *  - `literal` maps every quoted literal to the files holding it. Used by the NAME key and by
     *    the ACTION key's method half.
     *  - `path` maps a literal's FIRST path segment to the (file, literal) pairs, with one leading
     *    slash removed. Used by the URI key.
     *
     * A literal is anything between matching single quotes, double quotes or backticks with no
     * whitespace in it, capped at 200 characters. Route names, uri paths and method names are all
     * of that shape; a sentence is not, and this index is built from comment-stripped source
     * anyway.
     *
     * @return array{literal: array<string, list<string>>, path: array<string, list<array{0: string, 1: string}>>}
     */
    private function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }

        $literal = [];
        $path = [];

        foreach ($this->corpus() as $file => $source) {
            if (! preg_match_all('/[\'"`]([^\'"`\s]{1,200})[\'"`]/', $source, $matches)) {
                continue;
            }

            foreach (array_unique($matches[1]) as $value) {
                $literal[$value][$file] = true;

                $normalised = ltrim($value, '/');

                if ($normalised === '') {
                    continue;
                }

                /*
                 * ONLY LITERALS THAT LOOK LIKE A URL PATH. A bare single-segment literal with no
                 * slash at all is far more often an html id, class or field name than a url, and
                 * two measured false credits came from exactly that: `"confirm-password"` is the
                 * id of a password field in `front_end/Auth/register.blade.php`, and `'addon'`
                 * appears in a LandingPage asset string. Both would have credited a screen with
                 * an entry point it does not have.
                 *
                 * `href="/addon"`, `url('/addon')` and `'/addon/' + id` all carry the slash; the
                 * one spelling lost is `url('addon')` with neither a slash nor a further segment,
                 * and `route('addon.index')` — the normal spelling — is the NAME key's business
                 * anyway.
                 */
                if ($value[0] !== '/' && ! str_contains($normalised, '/')) {
                    continue;
                }

                $path[explode('/', $normalised)[0]][] = [$file, $normalised];
            }
        }

        return self::$index = [
            'literal' => array_map('array_keys', $literal),
            'path' => $path,
        ];
    }

    /**
     * Can this file OFFER a route at all?
     *
     * Two exclusions, each measured rather than assumed.
     *
     * `routes/` — `->name()` names every route, so the declaration would satisfy this census for
     * all of them. The one thing lost is a `redirect()` inside a route closure, and there are ten
     * closure routes in the whole table.
     *
     * `app/Http/Middleware/` — A MIDDLEWARE NEVER OFFERS A ROUTE. It passes, refuses or forces a
     * redirect, and a forced redirect is an arrival rather than something a user can find. Two
     * measured false credits, one on each key: `DemoModeMiddleware` holds the literal
     * `'stores.edit'` in its list of names blocked in demo mode (which credited the NAME key for
     * a screen `StoreController`'s own source says "nothing in the UI links"), and
     * `PlatformOperatorOnly` holds `'platform/verification'` to refuse requests to it (which
     * credited the KYC queue). This also corrects a claim the first draft of this docblock made:
     * a route name is NOT only ever used to build a url — `$request->route()->getName()` is how a
     * gate matches one.
     */
    private function canOffer(string $file): bool
    {
        return ! str_starts_with($file, 'routes/')
            && ! str_starts_with($file, 'app/Http/Middleware/');
    }

    /**
     * Every file that OFFERS this route, by any of the three keys.
     *
     * WHICH KEY COUNTS IN WHICH LAYER, and this is the one judgement in the census rather than a
     * mechanism. The NAME key counts everywhere, because a route NAME has exactly one use in
     * Laravel: generating that route's url. Something that names it is sending somebody there.
     *
     * The URI key counts in VIEWS and JAVASCRIPT only. In a view or a script a path literal is a
     * link, a form action or a fetch — an offer. In `app/` it is as likely to be a gate's map or a
     * filesystem path, and both were measured here: `PlatformOperatorOnly` holds
     * `'platform/verification'` to REFUSE requests to it, which would have credited the KYC queue
     * with an entry point it does not have, and `Utility.php` holds
     * `'...workdo.io/cronjob/addon.json'`, a remote url that merely contains `/addon`. Neither
     * sends anybody anywhere. A `redirect()` in `app/` that really does send somebody somewhere
     * uses the NAME, which is counted.
     *
     * THE LITERAL MUST START WITH THE PREFIX, at a path boundary. `'../../addon/mode/overlay'`
     * (in a minified editor plugin) contains `/addon` and is not a reference to `GET /addon`;
     * `'/addon'`, `"/addon/"` and `'addon'` are. One leading slash is removed before comparing, so
     * `url('x')` and `href="/x"` are the same question.
     *
     * `routes/` and `app/Http/Middleware/` are excluded from all three keys — see `canOffer()`.
     *
     * @return list<string>
     */
    private function filesOffering(string $name, string $uri, string $action): array
    {
        $index = $this->index();
        $found = [];

        /* KEY 1 — the route's NAME as a literal, anywhere but routes/. */
        foreach ($index['literal'][$name] ?? [] as $file) {
            if ($this->canOffer($file)) {
                $found[$file] = true;
            }
        }

        /* KEY 2 — the uri's static prefix, as a path literal in a view or a script. */
        $prefix = $this->staticPrefix($uri);

        if ($prefix !== '') {
            foreach ($index['path'][explode('/', $prefix)[0]] ?? [] as [$file, $value]) {
                if (str_starts_with($file, 'app/') || ! $this->canOffer($file)) {
                    continue;
                }

                if ($value === $prefix) {
                    $found[$file] = true;

                    continue;
                }

                if (str_starts_with($value, $prefix . '/')
                    || str_starts_with($value, $prefix . '?')
                    || str_starts_with($value, $prefix . '#')) {
                    $found[$file] = true;
                }
            }
        }

        /* KEY 3 — action([Controller::class, 'method']) / 'Controller@method'. */
        [$class, $method] = explode('@', $action, 2);
        $shortClass = substr($class, strrpos($class, '\\') + 1);

        $byMethod = $index['literal'][$method] ?? [];
        $pair = $index['literal'][$shortClass . '@' . $method] ?? [];

        foreach (array_merge($byMethod, $pair) as $file) {
            if (! $this->canOffer($file)) {
                continue;
            }

            if (str_contains($this->corpus()[$file] ?? '', $shortClass)) {
                $found[$file] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * The views a file renders or includes, by name — CHILDREN only.
     *
     * @return list<string>
     */
    private function childViewNames(string $source, bool $isBlade): array
    {
        $names = [];

        preg_match_all(
            '/(?:\bview|View::make|->view|@include|@includeIf|@includeWhen|@includeUnless'
            . '|@includeFirst|@each|@component)\s*\(([^)]{0,400})/',
            $source,
            $calls
        );

        foreach ($calls[1] as $arguments) {
            preg_match_all('/[\'"]([A-Za-z0-9_:.\-]+)[\'"]/', $arguments, $literals);

            foreach ($literals[1] as $literal) {
                $names[] = $literal;
            }
        }

        if ($isBlade) {
            preg_match_all('/<x-([a-z0-9_.\-]+)/', $source, $components);

            foreach ($components[1] as $component) {
                $names[] = 'components.' . $component;
            }
        }

        return $names;
    }

    /** @var array<string, list<string>>|null view name => files */
    private static ?array $viewFiles = null;

    /** @return array<string, list<string>> */
    private function viewFiles(): array
    {
        if (self::$viewFiles !== null) {
            return self::$viewFiles;
        }

        $map = [];

        foreach (array_keys($this->corpus()) as $path) {
            if (! str_ends_with($path, '.blade.php')) {
                continue;
            }

            if (str_starts_with($path, 'resources/views/')) {
                $name = str_replace('/', '.', substr($path, 16, -10));
                $map[$name][] = $path;

                continue;
            }

            if (str_starts_with($path, 'themes/')) {
                $segments = explode('/', $path);
                $index = array_search('views', $segments, true);

                if ($index !== false) {
                    $name = substr(implode('.', array_slice($segments, $index + 1)), 0, -10);
                    $map[$name][] = $path;
                }

                continue;
            }

            if (preg_match('#^packages/workdo/([^/]+)/.*?resources/views/(.*)\.blade\.php$#', $path, $m)) {
                $map[strtolower($m[1]) . '::' . str_replace('/', '.', $m[2])][] = $path;
            }
        }

        return self::$viewFiles = $map;
    }

    /**
     * A feature's own surfaces: its controller file plus the views it renders, transitively
     * through children.
     *
     * @return list<string>
     */
    private function ownSurfaces(string $action): array
    {
        $class = explode('@', $action, 2)[0];
        $file = 'app/' . str_replace('\\', '/', substr($class, strlen('App\\'))) . '.php';

        $corpus = $this->corpus();
        $views = $this->viewFiles();

        $seen = [$file => true];
        $frontier = [$file];

        while ($frontier !== []) {
            $next = [];

            foreach ($frontier as $current) {
                if (! isset($corpus[$current])) {
                    continue;
                }

                foreach ($this->childViewNames($corpus[$current], str_ends_with($current, '.blade.php')) as $name) {
                    foreach ($views[$name] ?? [] as $path) {
                        if (! isset($seen[$path])) {
                            $seen[$path] = true;
                            $next[] = $path;
                        }
                    }
                }
            }

            $frontier = $next;
        }

        return array_keys($seen);
    }

    // ---------------------------------------------------------------------------------------------
    // premises
    // ---------------------------------------------------------------------------------------------

    /**
     * PREMISE: the census really reads a corpus and really finds screens.
     *
     * Without this every assertion below is vacuously green on an empty set — the failure mode
     * `CLAUDE.md` names first. The floors are deliberately far below the measured figures
     * (**2,651 corpus files, 10 of them empty after stripping, 307 screens across 99 features**,
     * on 2026-11-27) so that ordinary growth does not move them while a collapse to nothing
     * still fails.
     */
    #[Test]
    public function test_premise_the_census_reads_the_application_and_finds_its_screens(): void
    {
        $corpus = $this->corpus();

        $empty = array_keys(array_filter($corpus, fn ($source) => trim($source) === ''));

        $this->assertLessThan(40, count($empty),
            count($empty) . ' corpus files are empty after stripping. Measured on 2026-11-27 '
            . 'there were 10 zero-byte or comment-only hook placeholders. A jump means the '
            . 'stripper started eating real files, which would make every absence assertion '
            . "below a free pass. Empty: " . implode(', ', array_slice($empty, 0, 20)));

        $this->assertGreaterThan(1500, count($corpus),
            'the corpus collapsed to ' . count($corpus) . ' files, so "nothing names this route" '
            . 'would be true of everything.');

        $screens = $this->screens();

        $this->assertGreaterThan(150, count($screens),
            'the census found only ' . count($screens) . ' screens.');

        /* Known-good members, so a rename cannot make the scan pass vacuously. */
        foreach (['order.index', 'product.index', 'dashboard', 'setting.index'] as $expected) {
            $this->assertArrayHasKey($expected, $screens,
                $expected . ' is no longer in the subject set. If the shape moved, re-derive it; '
                . 'do not narrow it.');
        }

        /* And the exclusions, so the rule cannot quietly widen. */
        $this->assertArrayNotHasKey('order.status.change', $screens,
            'a POST writer entered the screen census.');

        foreach (array_keys($screens) as $name) {
            $this->assertStringStartsNotWith('api/', $screens[$name]['uri'],
                $name . ' is an api route and this repository cannot answer whether the Flutter '
                . 'app offers it.');
        }
    }

    /**
     * PREMISE: comments really are stripped, and the corpus really can see code.
     *
     * Both directions, because a stripper that ate everything would make the absence questions
     * free passes and a stripper that ate nothing would let prose answer them. Measured subjects:
     * `StoreController` names `stores.edit` ONLY in a sentence about it, and `order/view.blade.php`
     * names `order.receipt` in real markup.
     */
    /**
     * PREMISE: this file's stripper answers exactly what the shared trait answers.
     *
     * `StripsSourceComments`' own docblock lists five ratchets that were satisfied by prose and
     * the measured case where a pattern-based PHP stripper ate seven code tokens out of
     * `XSS.php`. The trait is the reference implementation; this asserts the copy has not drifted
     * from it, on a real PHP file and a real Blade file.
     */
    #[Test]
    public function test_premise_the_corpus_strips_comments_the_way_the_shared_trait_does(): void
    {
        foreach ([
            'app/Http/Controllers/StoreController.php',
            'resources/views/order/view.blade.php',
        ] as $relative) {
            $this->assertSame(
                $this->sourceWithoutComments($relative),
                $this->strippedSource(base_path() . '/' . $relative, $relative),
                $relative . ': this file\'s stripper has drifted from StripsSourceComments. The '
                . 'trait is the reference; fix the copy, not the assertion.'
            );
        }
    }

    #[Test]
    public function test_premise_the_corpus_strips_comments_without_eating_code(): void
    {
        $corpus = $this->corpus();

        $this->assertArrayHasKey('app/Http/Controllers/StoreController.php', $corpus);
        $this->assertStringNotContainsString(
            "nothing in the UI links `stores.edit`",
            $corpus['app/Http/Controllers/StoreController.php'],
            'the comment that spells out this census\'s own finding survived stripping, so prose '
            . 'about an unreachable route can satisfy the question about it.'
        );

        $this->assertArrayHasKey('resources/views/order/view.blade.php', $corpus);
        $this->assertStringContainsString(
            "route('order.receipt'",
            $corpus['resources/views/order/view.blade.php'],
            'stripping removed real markup, so every absence assertion in this file is a free pass.'
        );
    }

    // ---------------------------------------------------------------------------------------------
    // the two questions
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function test_every_delivered_screen_is_named_by_some_file(): void
    {
        $unnamed = [];

        foreach ($this->screens() as $name => $shape) {
            if ($this->filesOffering($name, $shape['uri'], $shape['action']) === []) {
                $unnamed[$name] = $shape['uri'];
            }
        }

        $undeclared = array_diff_key($unnamed, self::UNNAMED_ALLOWANCE);

        $lines = [];

        foreach ($undeclared as $name => $uri) {
            $lines[] = $name . '  (GET /' . $uri . ')';
        }

        $this->assertSame([], $lines,
            "These screens exist and NO file in this application names them, so the only way to "
            . "open one is to type its uri:\n  " . implode("\n  ", $lines)
            . "\n\nThe owner's standard: \"Backend code, an unreachable screen, or a passing "
            . 'isolated test is not complete product delivery.\" Add the link, button or '
            . 'navigation entry that leads to it. If it is genuinely unreachable by design — a '
            . 'gateway callback, a superseded duplicate, an alias — add it to UNNAMED_ALLOWANCE '
            . 'with that reason stated.');

        /* THE OTHER DIRECTION: a closed debt must lose its line. */
        $stale = array_diff(array_keys(self::UNNAMED_ALLOWANCE), array_keys($unnamed));

        $this->assertSame([], array_values($stale),
            "These routes are in UNNAMED_ALLOWANCE and are no longer unnamed:\n  "
            . implode("\n  ", $stale)
            . "\n\nEither the link was added — delete the entry, it is a debt and the debt is "
            . 'paid — or the route was removed, in which case delete it too.');
    }

    #[Test]
    public function test_every_delivered_feature_has_an_entry_point_from_outside_itself(): void
    {
        $byFeature = [];

        foreach ($this->screens() as $name => $shape) {
            $byFeature[explode('@', $shape['action'], 2)[0]][$name] = $shape;
        }

        $selfContained = [];

        foreach ($byFeature as $class => $screens) {
            $own = $this->ownSurfaces((string) array_values($screens)[0]['action']);
            $entered = false;
            $detail = [];

            foreach ($screens as $name => $shape) {
                $naming = $this->filesOffering($name, $shape['uri'], $shape['action']);
                $outside = array_values(array_diff($naming, $own));

                if ($outside !== []) {
                    $entered = true;
                }

                $detail[] = $name . ' <- ' . ($naming === [] ? 'nothing' : implode(', ', $naming));
            }

            if (! $entered) {
                $selfContained[$class] = $detail;
            }
        }

        $undeclared = array_diff_key($selfContained, self::SELF_CONTAINED_ALLOWANCE);

        $lines = [];

        foreach ($undeclared as $class => $detail) {
            $lines[] = $class . "\n        " . implode("\n        ", $detail);
        }

        $this->assertSame([], $lines,
            "Every screen of these features is named ONLY from inside the feature itself, so the "
            . "screens link to each other and nothing leads to any of them:\n  "
            . implode("\n  ", $lines)
            . "\n\nThis is the KYC shape: three screens, each named by a sibling KYC view, and no "
            . 'navigation entry anywhere. Add one entry point — a navigation item, or a link from '
            . 'a screen that already has one — or declare the feature in '
            . 'SELF_CONTAINED_ALLOWANCE with the reason it is unreachable by design.');

        /* THE OTHER DIRECTION. */
        $stale = array_diff(array_keys(self::SELF_CONTAINED_ALLOWANCE), array_keys($selfContained));

        $this->assertSame([], array_values($stale),
            "These features are in SELF_CONTAINED_ALLOWANCE and now have an entry point:\n  "
            . implode("\n  ", $stale) . "\n\nDelete the entry — the debt is paid.");
    }
}
