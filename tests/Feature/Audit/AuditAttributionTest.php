<?php

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use App\Support\Audit\AuditAttribution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\StripsSourceComments;

/**
 * THE OPERATOR'S AUDIT ROWS WERE ALL FILED UNDER STORE 1, WHOEVER THEY WERE TAKEN AGAINST.
 *
 * `AuditLog::record()` took `store_id` from `getCurrentStore()`, and that helper FABRICATES a
 * tenant for the platform operator -- `app/Helper/helper.php:888-889` is
 * `if ($user->type == 'super admin') { return 1; // Default store for super admin }`. So:
 *
 *   - auditing merchant Y missed every operator action against Y, and
 *   - store 1, a real store, accumulated rows describing other tenants' data.
 *
 * NOT a cross-tenant DISCLOSURE today, and that distinction is measured rather than assumed: there
 * is no merchant-facing audit reader. `AuditLog` appears in three controllers
 * (`BankTransferController`, `PlanController`, `UserController`) and every reference is a WRITE. The
 * trail is wrong; nobody can read the wrong part of it yet. `test_no_reader_exists_without_a_gate`
 * below is the ratchet that keeps it that way.
 *
 * `audit_logs.store_id` is `->nullable()` (migration line 87), which is what makes an honest
 * "this concerns no single store" representable instead of forcing a tenant to be invented.
 */
class AuditAttributionTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    /**
     * THE SWEEP'S TRIPWIRE: what counts as an authorization check somewhere in the same FILE as an
     * audit read. Unchanged in content — hoisted out of the sweep so that it has a name, and so
     * that `GATE_IS_AUTHORIZATION` below can state what it is deliberately NOT.
     */
    private const GATE_TOKENS = '/isAbleTo\(|super admin|Gate::|authorize\(/';

    /**
     * What makes a NAMED GATE an authorization check — a wider list than `GATE_TOKENS`, asked of
     * one METHOD BODY rather than of a whole file.
     *
     * TWO DIFFERENT QUESTIONS, AND MEASURING THEM WITH ONE PATTERN WAS WRONG. `GATE_TOKENS` asks
     * "does this FILE contain an authorization check anywhere" — a tripwire. This asks "is the
     * method I have just named as the gate really one", which is answerable precisely because the
     * subject is one method.
     *
     * AND THE FOUR TRIPWIRE TOKENS DO NOT COVER THIS CODEBASE'S OWN IDIOM. Measured:
     * `app/Services/Kyc/KycAccess.php` matches NONE of them with comments stripped. It gates with
     * `UserPolicy::isOperator()` and `UserPolicy::merchantTenantRoot()` — which is not a gap in the
     * gate, it is CLAUDE.md §1's operator-first rule written the way that document asks for, and
     * `P1-SEC-11` criterion 3 asks for the operator to be defined ONCE rather than spelled a fifth
     * time as `'super admin'`.
     *
     * `GATE_TOKENS` IS DELIBERATELY NOT WIDENED TO MATCH. It is another slice's tripwire and
     * widening what counts as "gated" makes a tripwire weaker, not stronger. Measured, so the
     * decision is not a guess: the only non-write `AuditLog::` calls in `app/` are the two in
     * `KycAccessLog`, so adding these spellings to the sweep would change nothing it reports today
     * and would only lower the bar for whatever is added next.
     */
    private const GATE_IS_AUTHORIZATION = '/UserPolicy::isOperator\(|isPlatformAdmin\(|merchantTenantRoot\(|isAbleTo\(|super admin|Gate::|authorize\(/';

    /**
     * READERS WHOSE GATE IS ONE CLASS AWAY — each EARNED by
     * `test_every_reader_gated_by_a_caller_really_is_gated_by_that_caller`, not merely declared.
     *
     * THE SWEEP BELOW ASKS WHETHER A GATE TOKEN APPEARS IN THE SAME FILE AS THE READ. That is a
     * fair question of a controller and a structurally impossible one of a SERVICE that returns a
     * query builder: `KycAccessLog::forDocument()` cannot gate anybody, because it is handed a
     * `KycDocument` whose caller has already proved the principal may see it. Its own docblock
     * says exactly that — *"WHO may call this is `KycAccess`'s decision, made before the query is
     * built: a query is not a gate"* — and both of its reads carry an explicit
     * `where('store_id', …)` as defence in depth.
     *
     * SO THE FIX IS THE CENSUS'S POPULATION, NOT THE CODE, AND THE ENTRY IS NOT A SUPPRESSION.
     * Simply skipping the file would leave the thing this ratchet exists to catch — an UNGATED
     * CALLER — undetected forever. The companion test asserts the whole chain instead: the named
     * gate exists, it really is an authorization check, each reader narrows by `store_id` in its
     * own body, and EVERY first-party file that calls one of those readers names the gate. A new
     * `accessLog`-shaped action that forgets `KycAccess` therefore fails — which the same-file
     * heuristic could never see, because it would be satisfied by any `authorize()` anywhere in
     * the controller.
     *
     * @var array<string, array{gate: string, readers: list<string>}>
     */
    private const READERS_GATED_BY_A_CALLER = [
        'app/Services/Kyc/KycAccessLog.php' => [
            'gate' => \App\Services\Kyc\KycAccess::class . '::permitsOwnDocuments',
            'readers' => ['forDocument', 'forVerification'],
        ],
    ];

    private function storeOwnedBy(User $owner, string $name): Store
    {
        // `stores.email`, `.theme_id`, `.name`, `.slug` and `.created_by` are all NOT NULL
        // (measured in the create_stores_table migration); the first draft supplied three of five
        // and died on an integrity constraint rather than on anything this test is about.
        return Store::create([
            'name' => $name,
            'slug' => $name,
            'email' => $name . '@example.test',
            'theme_id' => 'stylique',
            'created_by' => $owner->id,
        ]);
    }

    // ---------------------------------------------------------------------------------------------
    // the defect
    // ---------------------------------------------------------------------------------------------

    /**
     * THE DEFECT: an operator acting with no store-bearing subject must not be filed under store 1.
     */
    #[Test]
    public function test_an_operator_action_is_not_filed_under_store_one(): void
    {
        $operator = $this->makePlatformAdmin();

        /*
         * THE OPERATOR MUST OWN A STORE, AND A MUTATION PROVED WHY.
         *
         * Deleting the operator check from `AuditAttribution` SURVIVED the first version of this
         * test: `makePlatformAdmin()` leaves `current_store` empty and owns nothing, so the
         * merchant branch answered null too and the two outcomes were identical. Giving the
         * operator a store of their own makes the branches differ -- without the check the
         * resolver would return THIS store, which is not what the action concerned.
         *
         * `getCurrentStore()` still answers 1, because its `super admin` branch fires before it
         * ever looks for an owned store, so the premise below is unaffected.
         */
        $ownedByOperator = $this->storeOwnedBy($operator, 'zz9-operator-own');

        $this->assertSame(
            1,
            getCurrentStore($operator->id),
            'premise: getCurrentStore() still fabricates store 1 for an operator. If this fails the '
            . 'helper has changed and this test is measuring the wrong thing.'
        );

        $this->actingAs($operator);
        $row = AuditLog::record('operator_action_with_no_subject');

        $this->assertNull(
            $row->store_id,
            'an operator action was attributed to store ' . $row->store_id . ', a tenant it has '
            . 'nothing to do with'
        );
        $this->assertNotSame(
            $ownedByOperator->id,
            $row->store_id,
            "the operator's own store is not what a platform-wide action concerns either"
        );
        $this->assertSame($operator->id, $row->user_id, 'the acting user must still be recorded');
    }

    /**
     * AND THE SUBJECT'S OWN STORE WINS OVER THE ACTOR'S SESSION.
     *
     * The owner's rule, verbatim: "Do not infer the affected tenant solely from an operator's
     * current UI selection when the modified resource identifies a different store." An operator
     * whose session points at store A, acting on a resource belonging to store B, must file against
     * **B**.
     */
    #[Test]
    public function test_the_subjects_own_store_beats_the_actors_selection(): void
    {
        /*
         * STORE B MUST NOT BE STORE 1, AND THE FIRST DRAFT OF THIS TEST MADE IT STORE 1.
         *
         * In a fresh RefreshDatabase the first store created takes id 1 -- which is exactly the id
         * `getCurrentStore()` fabricates for the operator, so the assertion below could not tell
         * "filed against the subject's store" from "filed against the operator's fabricated
         * store". A decoy store is created first so B's id is 2 and the two answers differ.
         */
        $decoyOwner = $this->makeMerchant();
        $decoy = $this->storeOwnedBy($decoyOwner, 'zz9-decoy');

        $merchantB = $this->makeMerchant();
        $storeB = $this->storeOwnedBy($merchantB, 'zz9-store-b');

        $this->assertSame(1, $decoy->id, 'premise: the decoy takes store 1');
        $this->assertNotSame(1, $storeB->id, 'premise: store B must differ from the fabricated 1');

        $operator = $this->makePlatformAdmin();
        $operator->current_store = $decoy->id;
        $operator->save();

        /*
         * `plan_orders` has SIX NOT NULL columns beyond the obvious ones -- `order_id` (unique),
         * `plan_name`, `plan_id`, `price_currency`, `txn_id`, `payment_status`. Found by the
         * integrity constraint one at a time before the migration was read; recorded so the next
         * fixture reads the migration first.
         */
        $subject = \App\Models\PlanOrder::create([
            'order_id' => 'zz9-audit-' . uniqid(),
            'plan_id' => 1,
            'plan_name' => 'zz9 audit plan',
            'user_id' => $merchantB->id,
            'store_id' => $storeB->id,
            'price' => 0,
            'price_currency' => 'USD',
            'txn_id' => 'zz9-txn-' . uniqid(),
            'payment_type' => 'manual',
            'payment_status' => 'pending',
        ]);

        $this->actingAs($operator);
        $row = AuditLog::record('plan_order_approved', $subject);

        $this->assertSame(
            $storeB->id,
            $row->store_id,
            'the row was filed under ' . var_export($row->store_id, true) . ' rather than the '
            . "store the modified resource itself names"
        );
    }

    // ---------------------------------------------------------------------------------------------
    // two stores, and the merchant path that already worked
    // ---------------------------------------------------------------------------------------------

    /** A merchant's own action keeps the attribution it already had. */
    #[Test]
    public function test_a_merchants_action_is_filed_under_their_own_store(): void
    {
        /*
         * TWO STORES, AND THE SELECTED ONE IS NOT THE ONE THE FALLBACK WOULD FIND -- ALSO BECAUSE A
         * MUTATION SURVIVED. With `current_store` equal to the merchant's only store, deleting the
         * `current_store` branch broke nothing: the `Store::where('created_by', ...)` fallback
         * returned the same id. A merchant who owns two stores and has selected the SECOND makes
         * that branch load-bearing, because `->first()` returns the first.
         */
        $merchant = $this->makeMerchant();
        $first = $this->storeOwnedBy($merchant, 'zz9-store-first');
        $selected = $this->storeOwnedBy($merchant, 'zz9-store-selected');
        $merchant->current_store = $selected->id;
        $merchant->save();

        $this->assertNotSame($first->id, $selected->id, 'premise: the two stores must differ');

        $this->actingAs($merchant);
        $row = AuditLog::record('merchant_action');

        $this->assertSame(
            $selected->id,
            $row->store_id,
            'the row was filed against store ' . var_export($row->store_id, true)
            . ' rather than the store the merchant has selected'
        );
    }

    /** TWO STORES: one merchant's action never lands on the other's trail. */
    #[Test]
    public function test_two_merchants_actions_do_not_cross(): void
    {
        $a = $this->makeMerchant();
        $storeA = $this->storeOwnedBy($a, 'zz9-two-a');
        $a->current_store = $storeA->id;
        $a->save();

        $b = $this->makeMerchant();
        $storeB = $this->storeOwnedBy($b, 'zz9-two-b');
        $b->current_store = $storeB->id;
        $b->save();

        $this->actingAs($a);
        AuditLog::record('action_by_a');

        $this->actingAs($b);
        AuditLog::record('action_by_b');

        $this->assertSame(
            [$storeA->id],
            AuditLog::where('action', 'action_by_a')->pluck('store_id')->unique()->values()->all()
        );
        $this->assertSame(
            [$storeB->id],
            AuditLog::where('action', 'action_by_b')->pluck('store_id')->unique()->values()->all()
        );
    }

    /** A merchant with no store at all is null, not 1 and not an error. */
    #[Test]
    public function test_a_merchant_with_no_store_is_not_attributed(): void
    {
        $merchant = $this->makeMerchant();

        $this->actingAs($merchant);
        $row = AuditLog::record('merchant_with_no_store');

        $this->assertNull($row->store_id);
    }

    // ---------------------------------------------------------------------------------------------
    // background contexts
    // ---------------------------------------------------------------------------------------------

    /**
     * A BACKGROUND CONTEXT -- console, queue, or a storefront request with no panel session.
     *
     * Nobody is on the `web` guard, so there is no store to attribute and no user either. Both
     * columns are nullable for exactly this case.
     */
    #[Test]
    public function test_a_background_context_attributes_neither_user_nor_store(): void
    {
        $row = AuditLog::record('background_action');

        $this->assertNull($row->store_id);
        $this->assertNull($row->user_id);
    }

    /**
     * AND A SHOPPER ON THE `customers` GUARD IS NOT A STORE ATTRIBUTION.
     *
     * CLAUDE.md: `CustomAuth` logs a token's owner into whichever guard matches its class, so the
     * DEFAULT guard can be `customers` — and a `Customer` is not a `User`. The resolver reads
     * `auth('web')` explicitly for that reason.
     */
    #[Test]
    public function test_a_customer_is_not_a_store_attribution(): void
    {
        $this->assertNull(AuditAttribution::storeFor(null, new \App\Models\Customer));
        $this->assertNull(AuditAttribution::storeFor(null, 'not a model at all'));
        $this->assertNull(AuditAttribution::storeFor(null, null));
    }

    // ---------------------------------------------------------------------------------------------
    // the reader ratchet
    // ---------------------------------------------------------------------------------------------

    /**
     * NO AUDIT READER EXISTS, AND IF ONE IS ADDED IT MUST CARRY A GATE.
     *
     * The owner's instruction: "Ensure any future audit reader enforces proper authorization." There
     * is nothing to gate today -- every `AuditLog` reference in `app/` is a write -- so the
     * honest way to honour that is a ratchet rather than a reader nobody asked for.
     *
     * This fails the moment a read appears without an authorization check beside it, which is the
     * moment the store-1 contamination described in this file's docblock would turn from a trail
     * defect into a cross-tenant disclosure.
     */
    #[Test]
    public function test_no_audit_reader_exists_without_an_authorization_gate(): void
    {
        $readers = [];
        $writesSeen = 0;

        foreach ($this->firstPartyPhpSources() as $relative => $source) {
            $touches = preg_match_all('/AuditLog::(\w+)\(/', $source, $m);

            if ($touches === 0) {
                continue;
            }

            /*
             * WRITES ARE NOT READS, AND THE FIRST DRAFT OF THIS LIST GOT THAT WRONG. It treated
             * anything but `record` as a read and so flagged `ThemeVersionController`'s own
             * `AuditLog::create([...])` call -- an Eloquent INSERT, not a read. That controller writes
             * directly ON PURPOSE, and its own docblock says why: `record()` used to set
             * `getCurrentStore()`, so a refusal by an operator who named no store was audited
             * against a merchant's store. Phase 03 M4 found this same defect and routed around it;
             * this slice fixes it at the source. The bypass stays, because it passes the store the
             * GATE resolved, which is more precise than any rule `record()` can apply.
             */
            $writes = ['record', 'create', 'insert', 'forceCreate', 'updateOrCreate', 'firstOrCreate'];

            foreach ($m[1] as $method) {
                if (in_array($method, $writes, true)) {
                    $writesSeen++;

                    continue;
                }

                /*
                 * A READER WHOSE GATE IS IN ANOTHER CLASS, declared at the top of this class and
                 * EARNED by `test_every_reader_gated_by_a_caller_really_is_gated_by_that_caller`.
                 * Skipped here rather than tolerated: that test asserts the gate exists, is an
                 * authorization check, and is named by every file that calls the reader — which
                 * this same-file question cannot express at all.
                 */
                if (array_key_exists($relative, self::READERS_GATED_BY_A_CALLER)) {
                    continue;
                }

                $gated = preg_match(self::GATE_TOKENS, $source) === 1;

                if (! $gated) {
                    $readers[] = basename($relative) . '::' . $method . '()';
                }
            }
        }

        /*
         * THE PREMISE AN ABSENCE-RATCHET NEEDS, or it passes by seeing nothing.
         *
         * This test asserts that a list is EMPTY, which a broken regex satisfies perfectly. The
         * known writes -- `AuditLog::record()` in three controllers plus
         * `ThemeVersionController`'s direct `create()` -- must therefore be FOUND, or the sweep is
         * not looking at anything and its empty answer means nothing.
         */
        $this->assertGreaterThanOrEqual(
            4,
            $writesSeen,
            'the sweep found ' . $writesSeen . ' AuditLog writes. It should see at least four, so '
            . 'the regex is not matching and the empty reader list below proves nothing.'
        );

        $this->assertSame(
            [],
            $readers,
            "An audit READ was added with no authorization check in the same file. Audit rows for\n"
            . "operator actions are attributed per-store, and historical rows are all filed under\n"
            . "store 1 -- so an ungated reader is a cross-tenant disclosure, not just a missing\n"
            . "permission. Found: " . implode(', ', $readers)
        );
    }

    /**
     * EVERY DECLARED "GATED BY A CALLER" ENTRY IS EARNED, NOT ASSERTED.
     *
     * Four properties, because the declaration above removes a file from the sweep and an unearned
     * removal is exactly the suppression this file's ratchet exists to prevent:
     *
     *   1. the named gate exists and is callable;
     *   2. the gate's OWN source carries one of the tokens the sweep looks for, so "the gate" is a
     *      gate rather than a helper somebody called one;
     *   3. each declared reader narrows by `store_id` in its own body. Every row for a subject
     *      already carries the subject's store, so this changes no result today; it is what stops
     *      a row written with a different attribution — by a future caller, or by a bug — being
     *      shown on a merchant's screen, and this file's own docblock explains why that matters
     *      (the historical rows are ALL filed under store 1);
     *   4. every first-party file that CALLS one of those readers also names the gate's class.
     *      This is the property the same-file heuristic cannot express and the only one that
     *      really protects anything: a new action that reads the trail and forgets `KycAccess`
     *      fails here.
     */
    #[Test]
    public function test_every_reader_gated_by_a_caller_really_is_gated_by_that_caller(): void
    {
        $this->assertNotSame([], self::READERS_GATED_BY_A_CALLER,
            'premise: nothing is declared, so this test asserts nothing. If the last entry went '
            . 'away because its reader did, delete this test with it — do not leave it passing '
            . 'vacuously beside a sweep it is meant to keep honest.');

        $sources = $this->firstPartyPhpSources();

        foreach (self::READERS_GATED_BY_A_CALLER as $relative => $entry) {
            [$gateClass, $gateMethod] = explode('::', $entry['gate']);

            $this->assertTrue(method_exists($gateClass, $gateMethod),
                $relative . ' names ' . $entry['gate'] . ' as its gate, and that method does not '
                . 'exist. The entry removes a file from the reader sweep, so a dangling gate name '
                . 'is a silent hole.');

            /* The gate's own file, derived from its class rather than typed again, so renaming the
               class moves this with it. */
            $gateRelative = 'app/' . str_replace('\\', '/', substr($gateClass, strlen('App\\'))) . '.php';

            $this->assertArrayHasKey($gateRelative, $sources,
                $entry['gate'] . ' does not live at ' . $gateRelative . ', so this test is not '
                . 'reading the gate it thinks it is.');

            $gateBody = $this->methodBody($sources[$gateRelative], $gateMethod);

            $this->assertNotSame('', $gateBody,
                'this test cannot find the body of ' . $entry['gate'] . ', so it cannot establish '
                . 'that the gate is one.');

            $this->assertMatchesRegularExpression(self::GATE_IS_AUTHORIZATION, $gateBody,
                $entry['gate'] . ' decides nothing about the principal, so calling it a gate is a '
                . 'claim rather than a measurement. Asked of the METHOD BODY, with comments '
                . 'stripped, so prose about authorization cannot satisfy it.');

            $this->assertArrayHasKey($relative, $sources, 'premise: ' . $relative . ' is not in the sweep.');

            foreach ($entry['readers'] as $reader) {
                $body = $this->methodBody($sources[$relative], $reader);

                $this->assertNotSame('', $body,
                    $relative . ' declares reader ' . $reader . '(), which this test cannot find. '
                    . 'An entry naming a method that is gone exempts a file for nothing.');

                $this->assertStringContainsString("'store_id'", $body,
                    $relative . '::' . $reader . '() no longer narrows by store_id. Historical '
                    . 'audit rows are all filed under store 1, so a reader without that predicate '
                    . "can put another tenant's row on a merchant's screen.");
            }

            $ungatedCallers = [];
            $callersSeen = 0;

            foreach ($sources as $candidate => $source) {
                if ($candidate === $relative) {
                    continue;
                }

                foreach ($entry['readers'] as $reader) {
                    if (! str_contains($source, '::' . $reader . '(')) {
                        continue;
                    }

                    $callersSeen++;

                    /* The short name matches an import and an inline FQN alike. It cannot be
                       satisfied by `KycAccessLog::`, whose next characters are `Log`. */
                    if (! str_contains($source, class_basename($gateClass) . '::')) {
                        $ungatedCallers[] = $candidate . ' calls ' . $reader . '()';
                    }
                }
            }

            $this->assertSame([], $ungatedCallers,
                "An audit reader declared as gated by its CALLER is called from a file that does\n"
                . 'not name ' . $gateClass . ". The reader cannot gate anybody — it is handed a\n"
                . "subject its caller has already authorised — so a caller without the gate is an\n"
                . "ungated audit read, and audit rows for operator actions are attributed\n"
                . "per-store while the historical ones are all under store 1. Found: "
                . implode(', ', $ungatedCallers));

            $this->assertGreaterThan(0, $callersSeen,
                'premise: nothing in app/ calls any of ' . $relative . "'s declared readers, so "
                . 'the caller assertion above is vacuous. Either the reader is dead and the entry '
                . 'should go, or the search stopped working.');
        }
    }

    /**
     * One method's body, brace-matched, from source whose comments are already gone.
     *
     * NOT A PHP PARSER, and the limitation is stated rather than hidden: it finds the FIRST
     * declaration of that name in the file, which is sufficient here because the subject is one
     * class per file. It returns `''` rather than guessing, and the caller asserts on that.
     */
    private function methodBody(string $source, string $method): string
    {
        if (! preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $source, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $open = strpos($source, '{', (int) $m[0][1]);

        if ($open === false) {
            return '';
        }

        $depth = 0;
        $length = strlen($source);

        for ($i = $open; $i < $length; $i++) {
            $depth += $source[$i] === '{' ? 1 : ($source[$i] === '}' ? -1 : 0);

            if ($depth === 0) {
                return substr($source, $open, $i - $open + 1);
            }
        }

        return '';
    }

    /**
     * Every first-party PHP file under `app/`, as "relative path => CODE".
     *
     * COMMENTS ARE STRIPPED, AND THAT IS NOT TIDINESS. CLAUDE.md §3 records this census trap eight
     * times over: a sweep that reads comments can be satisfied — or broken — by prose about the
     * thing it measures. Here it was satisfying the WRITE PREMISE. MEASURED over `app/`:
     * **47 "writes" on raw source, 36 on code — eleven of them in comments, spread over NINE
     * files** that each describe an `AuditLog` write while explaining an attribution defect they
     * were repaired of. `app/Support/Audit/AuditAttribution.php` is the clearest: raw 1, code 0, so
     * every write the sweep saw there was prose.
     *
     * THE READER SET IS IDENTICAL EITHER WAY — both answers name the same two `AuditLog::query()`
     * builders inside `KycAccessLog` — so stripping changes nothing the census reports today. What
     * it stops is a future file satisfying this premise, or losing its gate token, by writing about
     * one.
     *
     * (`KycAccessLog` declares no `query()` of its own, so naming those builders as that class's
     * would be a citation of a call the class MAKES rather than a method it HAS.
     * `tests/Unit/Governance/CitedMethodsExistTest` catches exactly that, and did, on the first
     * draft of the paragraph above.)
     *
     * @return array<string, string>
     */
    private function firstPartyPhpSources(): array
    {
        static $sources = null;

        if ($sources !== null) {
            /* The premise is re-asserted on every call rather than only on the one that paid for
               the tokenising, so a test that reads the cache still states what it is reading. */
            $this->assertGreaterThan(300, count($sources), 'premise: the sweep found almost no files');

            return $sources;
        }

        $found = [];

        foreach ([base_path('app')] as $root) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($it as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $found[ltrim(str_replace(base_path(), '', $file->getPathname()), '/')] = null;
                }
            }
        }

        $this->assertGreaterThan(300, count($found), 'premise: the sweep found almost no files');

        ksort($found);

        foreach (array_keys($found) as $relative) {
            $found[$relative] = $this->sourceWithoutComments($relative);
        }

        return $sources = $found;
    }
}
