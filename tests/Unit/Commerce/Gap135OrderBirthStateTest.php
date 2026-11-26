<?php

namespace Tests\Unit\Commerce;

use App\Support\Orders\FulfilmentStateMachine as SM;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * AN ORDER MUST NOT BE BORN DELIVERED, AND THE RULE MUST HAVE A HOME.  GAP-135 / `P04-P4-03`.
 * =================================================================================================
 *
 * The gap register's clause is "order can still be born Delivered", from finding `P4-F043`:
 * `Api\ApiController::ordersave` assigned `orders.delivered_status` straight from request input, so
 * a store's own shopper could POST `delivered_status=1` and have their order arrive in the state
 * `Order::product_return()` requires before it CREDITS MERCHANT STOCK and REDUCES `final_price`.
 *
 * THAT DOOR IS CLOSED (`GAP-258`, 2026-11-25: the handler assigns the literal `'-'` and does not
 * read the request at all) AND THIS FILE IS NOT A SECOND COPY OF ITS TEST. What was missing is the
 * two halves either side of that one handler:
 *
 *   1. THE RULE HAD NO HOME. `FulfilmentStateMachine` governed TRANSITIONS only -- `refusalFor()`
 *      needs a from-state and a new order has none -- so "which states may an order be created in"
 *      was a convention spread across fourteen files rather than a rule anybody could ask.
 *      {@see \App\Support\Orders\FulfilmentStateMachine::refusalForBirth()} is that rule.
 *   2. NOTHING STOPPED THE *NEXT* WRITER. `GAP-258`'s test drives one route with one hostile body.
 *      It cannot see a new order writer that hardcodes `delivered_status = 1`, or one that reads the
 *      column from the request again somewhere else. This file asks the machine about EVERY order
 *      creation in `app/`, so that writer fails a test instead of shipping.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT THE CENSUS READS, AND WHAT IT DOES NOT
 * -------------------------------------------------------------------------------------------------
 *
 * KEYED ON THE CREATION, NOT ON A SPELLING. CLAUDE.md §7 records four completeness claims that
 * missed what they claimed to cover, each keyed on a word rather than on the thing. So this does not
 * grep for `delivered_status`: it reads the CREATION and then asks what that creation assigns. A
 * creation that assigns nothing takes the column's own default and is reported as such; a creation
 * that assigns something has that value put to the machine.
 *
 * THE CLAIM IS TWO SHAPES, NOT "EVERY CREATION", and the wording here said "every" until the
 * independent review measured the extractor against it. What is actually matched is
 * `Order::create(` followed by a LITERAL array, and `$var = new Order()` up to that variable's
 * first `->save()`. Invisible to it, and named so nobody reads this as exhaustive:
 * `Order::create($data)` with a variable, `updateOrCreate`, `firstOrCreate`, `Order::insert` and
 * `DB::table('orders')->insert()`. `MIN_WINDOWS` and `MUST_FIND` protect against the extractor
 * collapsing to nothing; they do NOT protect against a new writer arriving in an unmatched shape,
 * which is the very failure this file's own opening paragraph warns about. Add the shapes when one
 * appears -- do not lower the number.
 *
 * COMMENTS ARE STRIPPED. CLAUDE.md records a census satisfied -- and one broken -- by prose about
 * the thing it measures, eight times now. Every file here goes through
 * `StripsSourceComments::sourceWithoutComments()`, which uses PHP's own tokenizer and preserves
 * line numbers so a failure can point at the real line. This matters immediately: the one handler
 * this defect was found on now carries a 40-line comment QUOTING `$request->delivered_status` as
 * the thing it no longer does, and a naive scan would read that as the defect.
 *
 * `app/` ONLY, AND THE TWO VENDORED CREATORS ARE NAMED RATHER THAN SCANNED.
 * `packages/workdo/AutomaticOrderPrinting` and `packages/workdo/LotteryPrizes` each create a host
 * `orders` row; both were read for this file and NEITHER assigns `delivered_status`, so both take
 * the column default. They are excluded from the scan because a red test over vendored code could
 * not be fixed -- `packages/workdo/` must not be edited -- and a containment would be the answer
 * instead. If a module update ever makes one of them write a fulfilment state, this file will not
 * see it; that is stated here rather than left as an unexamined boundary.
 *
 * THE DETECTOR IS PROVED BEFORE IT IS TRUSTED. Three planted sources drive the same extractor: a
 * born-Delivered literal, the pre-`GAP-258` request-driven shape, and a ternary of two literals.
 * Without those, a rename anywhere in the extractor would make this census pass by finding nothing,
 * which reads exactly like finding nothing wrong.
 */
class Gap135OrderBirthStateTest extends TestCase
{
    use StripsSourceComments;

    /**
     * The creation sites this census must still find. A reachability assertion: if `Order::create`
     * or `new Order()` is renamed, wrapped in a factory or moved, the count collapses and the scan
     * would otherwise pass vacuously.
     *
     * Measured 2026-11-26: 16 creation sites in 14 files, plus two `->query(new Order())`
     * DataTable forms that create nothing and are classified as such.
     */
    private const MUST_FIND = [
        'app/Services/PaymentService.php',
        'app/Services/OrderReplacement.php',
        'app/Http/Controllers/Api/ApiController.php',
        'app/Http/Controllers/Api/DashboardController.php',
        'app/Http/Controllers/OrderController.php',
        'app/Http/Controllers/PosController.php',
        'app/Http/Controllers/PaymentController.php',
    ];

    private const MIN_WINDOWS = 16;

    // ---------------------------------------------------------------------------------------------
    //  THE RULE ITSELF
    // ---------------------------------------------------------------------------------------------

    /**
     * THE TWO LEGAL BIRTH STATES, AND THE SEVEN ILLEGAL ONES, ENUMERATED IN BOTH DIRECTIONS.
     *
     * A test that only asserted the refusals would pass just as well if the machine refused every
     * birth state, which would stop every order writer in the platform.
     */
    public function test_the_machine_permits_exactly_pending_and_pre_order_at_birth(): void
    {
        $this->assertSame([SM::PENDING, SM::STATE_PRE_ORDER], SM::BIRTH_PERMITTED);

        foreach ([SM::PENDING, SM::STATE_PRE_ORDER] as $state) {
            $this->assertNull(SM::refusalForBirth($state),
                'An order may be created at delivered_status ' . $state . '. Pending is what twelve '
                . 'of the fourteen writers assign and is the column default; 8 (Pre-Order) is what '
                . 'every gateway controller assigns for a basket holding a pre-order product.');
        }

        foreach ([
            SM::STATE_DELIVERED, SM::STATE_CANCELLED, SM::STATE_RETURN, SM::STATE_CONFIRMED,
            SM::STATE_PICKED_UP, SM::STATE_SHIPPED, SM::STATE_PARTIALLY_PAID,
        ] as $state) {
            $this->assertNotNull(SM::refusalForBirth($state),
                'An order may be created at delivered_status ' . $state . '. Nothing in the '
                . 'repository creates an order in that state: 1 is the state the return and refund '
                . 'workflows are gated on, 2 and 3 are TERMINAL (nothing leaves them), 4/5/6 are '
                . 'assertions about goods that have moved, and 7 is a PartialPayments payment state '
                . 'written by a transition arm.');
        }
    }

    /**
     * AN UNRECOGNISED VALUE IS PERMITTED AT BIRTH, and that is a preservation, not an oversight.
     *
     * `Api\ApiController::ordersave` writes the literal `'-'` on purpose -- it is what every request
     * that posts nothing has always received -- `stateOf()` answers null for it, and `GAP-254`
     * settled that such a row keeps its own board bucket. Refusing it here would turn a documented
     * default into a defect.
     */
    public function test_the_shipped_unrecognised_default_is_still_a_legal_birth(): void
    {
        foreach (['-', '', 'wat', null] as $raw) {
            $this->assertNull(SM::refusalForBirth($raw),
                'delivered_status ' . var_export($raw, true) . ' was refused at birth. The literal '
                . "'-' is written by a shipped handler, and stranding the rows shipped code creates "
                . 'would be a new defect in place of a fix.');
        }
    }

    /**
     * THE PREDICATE DOES NOT REWRITE ANYTHING, which is the whole reason it is a predicate.
     *
     * A normalising `creating` hook was considered and refused: it would rewrite the fulfilment
     * state of every order the gateways and the fixtures create (86 fixture rows in this suite are
     * born non-Pending deliberately, to test returns and refunds), which is a behaviour change
     * dressed as a guard. The machine answers the question; the caller decides.
     */
    public function test_the_birth_rule_is_a_question_and_not_a_mutation(): void
    {
        $order = new \App\Models\Order();
        $order->delivered_status = SM::STATE_DELIVERED;

        SM::refusalForBirth($order->delivered_status);

        $this->assertSame(SM::STATE_DELIVERED, $order->delivered_status,
            'Asking the machine about a birth state changed the model. Nothing in '
            . 'FulfilmentStateMachine may write.');
    }

    // ---------------------------------------------------------------------------------------------
    //  THE CENSUS
    // ---------------------------------------------------------------------------------------------

    /**
     * EVERY `new Order()` IN `app/` IS EITHER A CREATION THIS CENSUS READS OR A NAMED NON-CREATION.
     *
     * "The scan did not recognise this site" and "there is nothing wrong at this site" read
     * identically in a green test, which is how CLAUDE.md's four failed censuses failed. So an
     * occurrence that is neither `$var = new Order()` nor `->query(new Order())` (the DataTable
     * query-builder form, which creates nothing) is a failure with the site named, not a skip.
     */
    public function test_every_order_instantiation_is_classified_rather_than_skipped(): void
    {
        $this->assertSame([], $this->censusOfOrderCreations()['unclassified'],
            'A `new Order()` was found in a shape this census does not read, so whatever it assigns '
            . 'to delivered_status is unmeasured. Either it is a creation -- give it the '
            . '`$var = new Order()` shape, or teach the extractor the new one -- or it creates '
            . 'nothing, in which case name that form in extract() the way `->query(new Order())` is '
            . 'named.');
    }

    /**
     * EVERY ORDER CREATION IN `app/` IS BORN IN A STATE THE MACHINE PERMITS.
     */
    public function test_every_order_creation_in_the_app_is_born_in_a_permitted_state(): void
    {
        $census = $this->censusOfOrderCreations();

        $this->assertGreaterThanOrEqual(self::MIN_WINDOWS, count($census['windows']),
            'The census found only ' . count($census['windows']) . ' order-creation sites in app/, '
            . 'against ' . self::MIN_WINDOWS . ' measured on 2026-11-26. A scan that stopped '
            . 'matching reads exactly like a scan that found nothing wrong, so this is a failure. '
            . 'If order creation was legitimately refactored -- a factory, a service -- re-point '
            . 'the extractor at the new shape rather than lowering this number.');

        foreach (self::MUST_FIND as $path) {
            $this->assertArrayHasKey($path, $census['files'],
                $path . ' creates an order and the census no longer sees it. Either the writer '
                . 'moved (re-point MUST_FIND and say so) or the extractor stopped matching.');
        }

        $illegal = array_filter($census['assignments'], fn ($a) => $a['refusal'] !== null);

        $this->assertSame([], array_values(array_map(
            fn ($a) => $a['file'] . ':' . $a['line'] . ' -> ' . $a['value'] . '  (' . $a['refusal'] . ')',
            $illegal
        )), 'An order is created in a fulfilment state the machine refuses at birth. That is '
            . 'finding P4-F043: an order born DELIVERED arrives in the state Order::product_return() '
            . 'requires before it credits merchant stock and reduces final_price. A new order '
            . 'belongs at FulfilmentStateMachine::PENDING, or at STATE_PRE_ORDER for a pre-order '
            . 'basket; if a real workflow needs another, that is an owner decision about what an '
            . 'order may be, not a value to assign.');
    }

    /**
     * AND NO ORDER CREATION TAKES ITS BIRTH STATE FROM THE REQUEST -- the exact shape of `P4-F043`.
     *
     * Separate from the case above because the two fail for different reasons and a reader needs to
     * know which: that one says "this literal is the wrong state", this one says "the state is
     * whatever the caller posted", which is unjudgeable at any state and was the defect.
     */
    public function test_no_order_creation_reads_its_birth_state_from_request_input(): void
    {
        $census = $this->censusOfOrderCreations();

        $fromInput = array_filter($census['assignments'], fn ($a) => $a['readsInput']);

        $this->assertSame([], array_values(array_map(
            fn ($a) => $a['file'] . ':' . $a['line'] . ' -> ' . $a['value'],
            $fromInput
        )), 'An order creation reads orders.delivered_status from request input. This is P4-F043 '
            . 'coming back: Api\ApiController::ordersave assigned '
            . '`!empty($request->delivered_status) ? $request->delivered_status : \'-\'`, and a '
            . 'store\'s own shopper had their order born DELIVERED. No legitimate client names a '
            . 'fulfilment state at checkout -- it is in no documented request body and in neither '
            . 'shipped app -- so the value is not to be validated, it is not to be read.');
    }

    /**
     * EVERY BIRTH STATE IS A LITERAL THE MACHINE CAN JUDGE.
     *
     * A value this census cannot evaluate is not "probably fine": it is how the request gets back
     * in, one variable removed. If a writer genuinely needs a computed birth state, the contract is
     * to ask -- `FulfilmentStateMachine::refusalForBirth($value)` -- and refuse, which is what this
     * message says.
     */
    public function test_every_birth_state_in_the_app_is_a_literal_this_census_can_judge(): void
    {
        $census = $this->censusOfOrderCreations();

        $unjudgeable = array_filter(
            $census['assignments'],
            fn ($a) => ! $a['readsInput'] && $a['literals'] === []
        );

        $this->assertSame([], array_values(array_map(
            fn ($a) => $a['file'] . ':' . $a['line'] . ' -> ' . $a['value'],
            $unjudgeable
        )), 'An order creation assigns a birth state this census cannot judge. Assign a literal '
            . '(FulfilmentStateMachine::PENDING, or STATE_PRE_ORDER for a pre-order basket), or ask '
            . 'FulfilmentStateMachine::refusalForBirth() about the value and refuse when it answers '
            . 'a sentence.');
    }

    // ---------------------------------------------------------------------------------------------
    //  THE DETECTOR, PROVED. Three plants; without them the census can pass by finding nothing.
    // ---------------------------------------------------------------------------------------------

    public function test_the_detector_sees_an_order_created_delivered_by_mass_assignment(): void
    {
        $found = $this->assignmentsIn('plant.php', <<<'PHP'
            <?php
            class X {
                public function store() {
                    return Order::create([
                        'product_order_id' => 'ORD-1',
                        'delivered_status' => 1,
                        'store_id' => 7,
                    ]);
                }
            }
            PHP);

        $this->assertCount(1, $found, 'The extractor missed a born-Delivered Order::create().');
        $this->assertNotNull($found[0]['refusal'],
            'The machine was not asked, or it permitted a birth at DELIVERED.');
        $this->assertSame(['1'], $found[0]['literals']);
    }

    public function test_the_detector_sees_the_pre_gap_258_request_driven_shape(): void
    {
        $found = $this->assignmentsIn('plant.php', <<<'PHP'
            <?php
            class X {
                public function ordersave($request) {
                    $order = new Order();
                    $order->product_order_id = 'ORD-2';
                    $order->delivered_status = !empty($request->delivered_status) ? $request->delivered_status : '-';
                    $order->save();
                }
            }
            PHP);

        $this->assertCount(1, $found, 'The extractor missed the request-driven birth state.');
        $this->assertTrue($found[0]['readsInput'],
            'The exact shape finding P4-F043 names was not reported as reading request input.');
    }

    /**
     * AND A TERNARY OF TWO LITERALS IS JUDGED ON BOTH ARMS, not on the first one it happens to see.
     * That is the shape ten gateway controllers use (`8` for a pre-order basket, else `0`), spread
     * over an `if`/`else` -- and a one-line version of it must not be able to hide a `1`.
     */
    public function test_the_detector_judges_both_arms_of_a_ternary(): void
    {
        $found = $this->assignmentsIn('plant.php', <<<'PHP'
            <?php
            class X {
                public function store($isPreOrder) {
                    $order = new Order();
                    $order->delivered_status = $isPreOrder ? 8 : 1;
                    $order->save();
                }
            }
            PHP);

        $this->assertCount(1, $found, 'The extractor missed a ternary birth state.');
        $this->assertSame(['8', '1'], $found[0]['literals']);
        $this->assertNotNull($found[0]['refusal'],
            'A ternary whose second arm is DELIVERED was permitted. Every literal in the '
            . 'expression has to be judged, not the first one.');
    }

    /**
     * THE STRIPPER IS LOAD-BEARING, so it is proved too: the comment the live handler now carries
     * quotes `$request->delivered_status` as the thing it stopped doing, and a scan that read
     * comments would report the defect it documents.
     */
    public function test_a_comment_describing_the_defect_is_not_the_defect(): void
    {
        $found = $this->assignmentsIn('plant.php', <<<'PHP'
            <?php
            class X {
                public function ordersave($request) {
                    $order = new Order();
                    /* NOT `$order->delivered_status = $request->delivered_status;` -- see GAP-258,
                       which is why this reads `'-'` and does not touch the request. */
                    $order->delivered_status = '-';   // and not 1, either
                    $order->save();
                }
            }
            PHP);

        $this->assertCount(1, $found,
            'The extractor read a comment as an assignment, or missed the real one.');
        $this->assertFalse($found[0]['readsInput'],
            'A comment quoting $request->delivered_status was read as code. CLAUDE.md records this '
            . 'failure eight times; the stripper is what prevents it.');
        $this->assertNull($found[0]['refusal']);
    }

    // ---------------------------------------------------------------------------------------------
    //  THE EXTRACTOR
    // ---------------------------------------------------------------------------------------------

    /**
     * Every `App\Models\Order` creation in `app/`, and what each one assigns to `delivered_status`.
     *
     * @return array{windows: list<array{file: string, line: int, kind: string}>, files: array<string, int>, assignments: list<array<string, mixed>>}
     */
    private function censusOfOrderCreations(): array
    {
        $root = dirname(__DIR__, 3) . '/app';
        $windows = [];
        $files = [];
        $assignments = [];
        $unclassified = [];

        /** @var \SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = 'app' . str_replace('\\', '/', substr($file->getPathname(), strlen($root)));
            $source = $this->sourceWithoutComments($relative);
            $found = $this->extract($relative, $source);

            $unclassified = array_merge($unclassified, $found['unclassified']);

            if ($found['windows'] === []) {
                continue;
            }

            $files[$relative] = count($found['windows']);
            $windows = array_merge($windows, $found['windows']);
            $assignments = array_merge($assignments, $found['assignments']);
        }

        return [
            'windows' => $windows,
            'files' => $files,
            'assignments' => $assignments,
            'unclassified' => $unclassified,
        ];
    }

    /**
     * The `delivered_status` assignments inside the order creations of ONE source, for the planted
     * cases above. Takes the source as a string so a plant needs no file on disk.
     *
     * @return list<array<string, mixed>>
     */
    private function assignmentsIn(string $label, string $source): array
    {
        return $this->extract($label, $this->codeOnly($source))['assignments'];
    }

    /**
     * @return array{windows: list<array<string, mixed>>, assignments: list<array<string, mixed>>, unclassified: list<string>}
     */
    private function extract(string $path, string $source): array
    {
        $windows = [];
        $unclassified = [];

        /*
         * `\bOrder::create([` matches `Order::create([` and `\App\Models\Order::create([` and NOT
         * `PlanOrder::create([` or `AuctionProductOrder::create([` -- there is no word boundary
         * between `n`/`t` and `O`. The SaaS plan orders are a different table and are deliberately
         * out of scope.
         */
        if (preg_match_all('/\bOrder::create\s*\(\s*\[/', $source, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $open = strpos($source, '[', $match[1]);
                $windows[] = [
                    'file' => $path,
                    'kind' => 'create',
                    'line' => $this->lineAt($source, $match[1]),
                    'start' => $match[1],
                    'end' => $this->matchingBracket($source, (int) $open),
                ];
            }
        }

        /*
         * `$order = new Order(); ... $order->save();` -- and the window is read for assignments to
         * THAT VARIABLE, not for any `delivered_status` between the two.
         *
         * A WINDOW THAT RAN TO THE NEXT `->save()` AND MATCHED ANY VARIABLE WAS THE FIRST SHAPE OF
         * THIS EXTRACTOR, AND IT WAS FRAGILE IN THE DIRECTION THAT MATTERS LEAST BUT COSTS MOST: a
         * false red. `OrderController:2720` and `:2780` are `->query(new Order())` -- a DataTable
         * query builder, not a creation, with no `->save()` of its own -- so each window ran to
         * whatever `->save()` came next in a 3,000-line file, and a TRANSITION's
         * `$order->delivered_status = 1` inside that span would have been reported as a birth. It
         * does not happen today (measured: both windows contain no assignment at all), which is
         * exactly the kind of accident that gets noticed later by somebody else's red build.
         *
         * SO EVERY `new Order()` IS CLASSIFIED AND NONE IS SKIPPED SILENTLY: assigned to a variable
         * is a creation; `->query(new Order())` is the DataTable form and is named as such; anything
         * else is reported as unclassified rather than assumed harmless, because "the scan did not
         * recognise it" and "there is nothing there" read identically.
         */
        if (preg_match_all('/\bnew\s+(?:\\\\?App\\\\Models\\\\)?Order\s*\(\s*\)/', $source, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $before = substr($source, max(0, $match[1] - 40), min(40, $match[1]));

                if (preg_match('/(\$[A-Za-z_]\w*)\s*=\s*$/', $before, $named)) {
                    $save = strpos($source, $named[1] . '->save()', $match[1]);

                    $windows[] = [
                        'file' => $path,
                        'kind' => 'new',
                        'line' => $this->lineAt($source, $match[1]),
                        'start' => $match[1],
                        'end' => $save === false ? strlen($source) - 1 : $save,
                        'variable' => $named[1],
                    ];

                    continue;
                }

                if (! preg_match('/->query\(\s*$/', $before)) {       /* not the DataTable form */
                    $unclassified[] = $path . ':' . $this->lineAt($source, $match[1]);
                }
            }
        }

        $assignments = [];

        foreach ($windows as $window) {
            $text = substr($source, $window['start'], $window['end'] - $window['start'] + 1);

            /*
             * A `new Order()` window reads assignments to ITS OWN variable only; a `create([...])`
             * window is the array literal, so the key form is the only one it can hold.
             */
            $pattern = isset($window['variable'])
                ? '/(?:' . preg_quote($window['variable'], '/') . '->delivered_status\s*=(?!=))\s*([^,;\n]+)/'
                : '/(?:[\'"]delivered_status[\'"]\s*=>)\s*([^,;\n]+)/';

            if (! preg_match_all($pattern, $text, $hits, PREG_OFFSET_CAPTURE)) {
                continue;   // nothing assigned: the order takes the column's own default, 0.
            }

            foreach ($hits[1] as $hit) {
                $value = trim($hit[0]);

                preg_match_all('/(?:\'([^\']*)\'|"([^"]*)"|\b(\d+)\b)/', $value, $parts, PREG_SET_ORDER);

                $literals = array_map(
                    fn ($p) => $p[3] ?? ($p[1] !== '' ? $p[1] : ($p[2] ?? '')),
                    $parts
                );

                /* `SM::PENDING` and friends read as their own value, not as a literal 0. */
                if (preg_match_all('/\b(?:FulfilmentStateMachine|SM)::([A-Z_]+)\b/', $value, $named)) {
                    foreach ($named[1] as $constant) {
                        $full = SM::class . '::' . $constant;
                        if (defined($full)) {
                            $literals[] = (string) constant($full);
                        }
                    }
                }

                $refusal = null;
                foreach ($literals as $literal) {
                    $refusal = $refusal ?? SM::refusalForBirth($literal);
                }

                $assignments[] = [
                    'file' => $path,
                    'line' => $this->lineAt($source, $window['start'] + $hit[1]),
                    'window' => $window['kind'] . '@' . $window['line'],
                    'value' => $value,
                    'literals' => array_values($literals),
                    'readsInput' => (bool) preg_match(
                        '/\$request\b|\brequest\(\)|\binput\s*\(|->get\s*\(|\ball\s*\(\s*\)/',
                        $value
                    ),
                    'refusal' => $refusal,
                ];
            }
        }

        return [
            'windows' => $windows,
            'assignments' => $assignments,
            'unclassified' => $unclassified,
        ];
    }

    private function matchingBracket(string $source, int $open): int
    {
        $depth = 0;

        for ($i = $open, $n = strlen($source); $i < $n; $i++) {
            if ($source[$i] === '[') {
                $depth++;
            } elseif ($source[$i] === ']') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return $n - 1;
    }

    private function lineAt(string $source, int $offset): int
    {
        return substr_count(substr($source, 0, $offset), "\n") + 1;
    }

    /** The planted sources' own comments, removed the same way the real files' are. */
    private function codeOnly(string $raw): string
    {
        $out = '';

        foreach (token_get_all($raw) as $token) {
            if (is_array($token)) {
                $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                    ? str_repeat("\n", substr_count($token[1], "\n"))
                    : $token[1];
                continue;
            }

            $out .= $token;
        }

        return $out;
    }
}
