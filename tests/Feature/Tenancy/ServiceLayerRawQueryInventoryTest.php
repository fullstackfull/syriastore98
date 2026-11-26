<?php

namespace Tests\Feature\Tenancy;

use Tests\TestCase;

/**
 * THE SERVICE LAYER'S RAW QUERIES, WHICH NO EXISTING RATCHET COULD SEE.
 *
 * HOW THIS WAS FOUND, because the route matters more than the result. F216 added four raw
 * statements to `app/Services/GatewayReference.php` and `RawQueryTenancyInventoryTest`'s
 * per-directory count moved, as designed. But `OwnedPathRawQueryInventoryTest` — the one that
 * actually asks "does this statement name `store_id`, and if not, why not" — stayed green, because
 * its scan does not reach `app/Services`. So a money-bearing service could be added with an
 * unscoped raw write and only a COUNT would move; nothing would ask the question.
 *
 * Measuring the directory for the first time found **25 raw statements across 10 service files,
 * 8 of which name `store_id` nowhere**, five of them in `OrderFinancialService` writing
 * `store_order_financials` and moving `store_wallets.total_income`.
 *
 * **NONE OF THE EIGHT IS A LIVE DEFECT, and that conclusion is traced rather than assumed.** The
 * five money writes key on a PRIMARY KEY that their only caller has already ownership-verified:
 * `StoreFinanceController::updateFinancialPaymentStatus` gates on
 * `canFinance('Edit Finance Payment Status')`, then loads the row with
 * `->where('id', $id)->where('store_id', $storeId)` where `$storeId` is `getCurrentStore()` — the
 * authenticated principal's store, NOT request input — refuses when it does not match, and passes
 * `$financial->id`. That is derived ownership and it holds today.
 *
 * **WHAT DOES NOT HOLD IS THAT IT WILL STAY TRUE.** `markFinancialAsPaid(int $financialId)` is a
 * public method taking a bare integer. A second caller written next month that passes
 * `$request->financial_id` would move another store's wallet balance, and this codebase's own
 * record says that is not hypothetical: "a tenant predicate built from caller-supplied input is
 * not a tenant check — this has produced four separate defects". So the containment here is a
 * ratchet on the CALLER SET, not a change to the service: the service is correct, its safety is
 * just conditional on something no test was asserting.
 *
 * Production code is deliberately untouched by this file.
 */
class ServiceLayerRawQueryInventoryTest extends TestCase
{
    /** `DB::table(` or `\DB::table(`, not preceded by a word character. */
    private const RAW_PATTERN = '/(?<!\w)(?:\\\\)?DB::table\(/';

    /**
     * The store-less statements, keyed by a FRAGMENT that identifies the statement rather than by
     * line number, so an edit above does not invalidate the declaration.
     *
     * Ratcheted in BOTH directions below: a declaration cannot outlive its statement, and a new
     * store-less statement cannot hide behind one.
     */
    private const STORE_LESS_BY_DESIGN = [
        /* ---- P0 (2026-11-10): the USER-DELETION SAFETY GUARD. These two statements are
           store-less for the opposite of the usual reason, and that is why they are declared
           together with the argument rather than waved through.

           `UserDeletionSafety::blockers()` answers one question before a `users` row is deleted:
           does anything still depend on it? A `store_id` predicate here would not NARROW an
           exposure -- it would make the guard MISS dependants that are really there, and a missed
           dependant is a store, a wallet or an order ledger destroyed. The failure direction of a
           scoped count is a destructive delete, so the count has to be table-wide.

           It discloses nothing. Both statements are `->count()`, both are keyed on the id of the
           user being deleted, and the only thing either can produce is a REFUSAL naming a number.
           There is no path by which a caller reads another tenant's row through them, and
           authorization has already happened: `UserPolicy::delete()` runs first at both doors,
           operator-first, and refuses a cross-tenant target before this service is reached
           (`UserDeletionAuthorizationTest`, 15 cases).

           The `users` table is not in `config/tenancy.php`'s `scoped_models` either, so there is
           no scope to be consistent with -- and `stores` is keyed on `created_by`, a user id, not
           on a `store_id` of its own. */
        "DB::table('stores')->where('created_by', \$user->id)" =>
            'The store-ownership half of the user-deletion guard. It must see EVERY store this '
            . 'user owns, because a store it fails to see is a store the delete destroys. The '
            . 'statement is a count keyed on the target user id and can only produce a refusal; '
            . 'UserPolicy::delete() has already refused a cross-tenant target before it runs.',

        "DB::table(\$table)->where(\$column, \$userId)" =>
            'The dependency half of the same guard, over the 15 pinned cascade tables and the 5 '
            . 'orphan tables. Same argument and the same failure direction: a tenant predicate '
            . 'would UNDERCOUNT dependants and let a destructive delete through. Count only, '
            . 'keyed on the target user id, behind the same policy check.',

        /* ---- F229 / D-31: the PLAN purchase intent. A SaaS plan sale is not a tenant event.
           `plan_purchase_intents` carries no `store_id` COLUMN at all, and that is the design
           rather than an omission: a plan is bought by a USER from the platform, not by one of a
           merchant's stores from another. `plans` has no `store_id` either, and `plan_orders` --
           the row these intents produce -- keys on `user_id`. The isolation this table needs is
           PER-PURCHASER, which is requirement 5's "cross-user reference reuse", and it is
           enforced: `verifyAndConsume()` refuses an intent whose `user_id` is not the
           authenticated principal, BEFORE it compares the amount, so a stranger probing a
           reference cannot even learn what that purchase was worth.
           `PlanPurchaseIntentTest::test_another_account_cannot_consume_someone_elses_intent`
           ratchets that, and `verify-plan-purchase-intent-on-mysql.php` drives it on a real engine
           together with the two-connection race. Scoping these statements by `store_id` would
           require inventing a column whose value no plan purchase has. */
        "DB::table(self::TABLE)->insert" =>
            'Creating a plan purchase intent. A plan sale belongs to a USER and the platform, not '
            . 'to a store, so there is no store_id to predicate on. The purchaser is the isolation '
            . 'boundary and is written here from the authenticated principal.',

        "DB::table(self::TABLE) ->where('provider', \$provider)" =>
            'Reading or consuming an intent by provider + reference. The reference is 128 bits of '
            . 'random minted by the server, so it is not guessable, and every consuming path '
            . 'checks `user_id` against the authenticated principal before it discloses or '
            . 'changes anything. Per-purchaser isolation, not per-tenant -- a plan has no tenant.',

        "DB::table(self::TABLE) ->where('id', \$intent->id)" =>
            'The one conditional UPDATE that consumes an intent, keyed on the row the reads above '
            . 'already resolved and already checked the purchaser of. `where status = pending` is '
            . 'what makes two concurrent callbacks produce exactly one activation; a store_id '
            . 'predicate would add nothing a plan purchase has.',

        "DB::table(self::TABLE) ->where(function (\$query)" =>
            'The unreconciled-intents review query: captured payments that activated nothing. It '
            . 'is deliberately platform-wide, because that is the population an operator needs to '
            . 'see -- narrowing it to one tenant would hide exactly the rows it exists to surface. '
            . 'Read-only, called by no request path, and it decides nothing (requirement 9 forbids '
            . 'acting on it).',

        "DB::table(self::TABLE) ->where('status', self::PENDING)" =>
            'The two reconciliation reads (#204): the `pending` intents an operator-run '
            . 'reconciliation may ask a provider about, and a count of the ones older than the '
            . 'window it examined. Platform-wide for the same reason as the unreconciled query '
            . 'above -- a plan purchase has no tenant, and the operator reconciling the platform\'s '
            . 'own receipts must see all of them. Read-only, reached only from the '
            . '`payments:reconcile` console command and never from a request, and what the '
            . 'reconciliation then does with a row is bound to that row\'s own purchaser.',

        /* ---- #204: the MODULE payment initiation record. An initiation belongs to a payer's
           SESSION, not to a store. `module_payment_initiations` does carry `store_id`, and both of
           its INSERTs write it -- but as a fact about the route a person will read later, never as
           the thing a row is found by. The reason is in what the record is for: a payer returning
           from a gateway to a switched-off application must show that THIS BROWSER started the
           payment, and the only thing a browser holds is the 128-bit reference the server minted
           into its session. A store id is in the URL and anybody can type one. And a Campaigns
           initiation has no store at all: a merchant pays the platform.
           `DisabledModulePaymentBoundaryTest` drives the isolation that matters -- another session
           cannot spend a payer's initiation, and one module's reference cannot open another's
           writer. */
        "DB::table(self::TABLE) ->where('reference', \$" =>
            'Finding or spending one module payment initiation by the reference held in the '
            . 'payer\'s own session (supersede, abandon, the open-initiation check and the one '
            . 'conditional UPDATE that completes it). The reference is 128 bits of server-minted '
            . 'random and is the isolation boundary; every statement also names the module, so a '
            . 'reference for one module cannot be spent on another. A store_id predicate would add '
            . 'a value the caller supplies in the URL to a check that must not depend on one.',

        "DB::table(self::TABLE) ->where('module', \$module)" =>
            'Refusal bookkeeping for a switched-off module: has this exact refused request been '
            . 'seen before, and how many distinct refusals has the module gained this hour. A '
            . 'module is switched off for the WHOLE platform (`add_on_managers.is_enable` is one '
            . 'row per module), so both the dedupe and the bound are per module by design -- a '
            . 'per-store bound would let one caller multiply it by the number of stores. Neither '
            . 'returns a row to any request.',

        "DB::table(self::TABLE)->where('id', \$repeat->id)" =>
            'Counting a repeated refusal on the row the dedupe read above just resolved. Keyed on '
            . 'that row\'s primary key and nothing a caller supplied.',

        "DB::table(self::TABLE) ->where('status', self::REFUSED)" =>
            'The unacknowledged-refusals list. Platform-wide for the operator, like the '
            . 'unreconciled plan intents: it is about applications switched off for the whole '
            . 'platform. Read-only and reached only from the `payments:reconcile` console command.',

        "DB::table(self::TABLE) ->whereIn('module', \$modules)" =>
            'A COUNT of initiations still open for the modules that are currently switched off. '
            . 'Console-only, discloses no row, and is keyed on module names this application '
            . 'chooses.',

        /*
         * `->where('status', self::REFUSED)` IS PART OF THIS FRAGMENT ON PURPOSE, and leaving it out
         * let two statements about a DIFFERENT table satisfy this ratchet by accident.
         *
         * Matching is `str_contains(normalise($statement), normalise($fragment))`, so the shorter
         * `DB::table(self::TABLE) ->where('id', $id)` also matched `PlanPurchaseIntent`'s new
         * acknowledgement statements -- which meant the declaration ratchet, whose whole purpose is
         * to make an author write down why a store-less statement is store-less, passed without
         * anybody writing anything. Worse, the reason it lent them was FALSE: it says "both
         * statements require the row to be a refusal", and the plan acknowledgement does not.
         *
         * Both module statements really do carry that clause (`ModulePaymentLedger:458`, `:481`), so
         * narrowing the fragment to the thing the reason actually claims costs nothing and stops it
         * being lent out. Found by the independent review of the acknowledgement change; it also
         * strengthens `test_no_declaration_outlives_the_statement_it_describes`, which would have
         * kept this row "live" on the strength of statements it does not describe.
         */
        "DB::table(self::TABLE) ->where('id', \$id) ->where('status', self::REFUSED)" =>
            'Reading or acknowledging ONE refusal by its id, from the console. The id is typed by '
            . 'the operator who has a shell on the server, both statements require the row to be '
            . 'a refusal, and the acknowledgement is a conditional UPDATE that can happen once.',

        "DB::table(self::TABLE) ->where('id', \$id) ->whereNull('acknowledged_at')" =>
            'The one predicate for "this plan intent is answerable by a person" -- read by '
            . 'needingAPerson() and carried INSIDE acknowledge()\'s conditional UPDATE, which is why '
            . 'it is one builder and not two. plan_purchase_intents has no store_id and could not: a '
            . 'plan is bought by a User from the platform, so the isolation that applies is the '
            . 'purchaser\'s and it travels on the row as user_id. The row is addressed by primary '
            . 'key from the console plus server-written state, never by anything a caller supplied.',

        "DB::table(self::TABLE) ->where('id', \$id) ->first()" =>
            'ONE plan intent by its id, whatever state it is in, so the console\'s audit row can '
            . 'describe the row as it is at the moment the acknowledgement won rather than as the '
            . 'caller last saw it -- the financial review found the record naming a status a '
            . 'concurrent settlement had already changed. Read inside the same transaction as the '
            . 'write. Same table with no store_id, addressed by primary key from the console.',

        "DB::table(self::TABLE) ->where('id', \$id) ->whereNotNull('acknowledged_at')" =>
            'Reading or REOPENING one acknowledged plan intent, from the console. Same table with no '
            . 'store_id, same primary-key-plus-server-state addressing. The reopen only clears the '
            . 'three acknowledgement columns, so the worst it can do is put the row back in front of '
            . 'a person -- the reconciliation command must still obtain an authoritative capture '
            . 'from the provider before anything is settled.',

        "DB::table(self::TABLE)->distinct()->pluck('provider')" =>
            'The providers any plan intent has ever been recorded for, so the reconciliation command '
            . 'can refuse a mistyped `--provider` instead of examining nothing and reporting success. '
            . 'A `DISTINCT` over one column of a table with no `store_id`; it discloses no row, and '
            . 'the only caller is the console command.',

        // ---- the tenant RESOLVER. It cannot be tenant-scoped; it is what produces the tenant.
        "DB::table('users')->whereNotNull('deliverysyria_secret')" =>
            'This IS the tenant resolution step for the DeliverySyria integration: given a bearer '
            . 'secret and nothing else, find which users hold it. A `store_id` predicate would '
            . 'require knowing the answer first. The comparison is `hash_equals` against each '
            . 'candidate rather than a WHERE on the secret, so the query cannot be used as an '
            . 'oracle, and the result is narrowed to that owner\'s stores by the next statement.',

        "DB::table('stores')->whereIn('created_by', \$owners)" =>
            'The second half of the same resolution: owner ids -> their store ids. `$owners` comes '
            . 'from the hash_equals match above, never from request input. Scoping this by '
            . '`store_id` would be circular.',

        // ---- a row already reached through a store-scoped parent.
        "DB::table('store_devices') ->where('id', \$device->id)" =>
            'Deactivates ONE device by its own primary key, after a push to it returned an '
            . 'invalid-token error. `$device` comes from the loop over `$notification`\'s own '
            . 'store devices, and `$notification->store_id` is logged on the line above, so the '
            . 'row is already that store\'s. A `store_id` predicate here would be redundant and '
            . 'would hide that the parent loop is where the scoping lives.',

        // ---- the money writes. Derived ownership, ratcheted by the caller test below.
        "->update([ 'payment_status' => \$orderStatus," =>
            'syncFromOrder(): `$financial` was loaded earlier in the same method FROM THE ORDER '
            . 'passed in, so the row is that order\'s own. The Order is the ownership-bearing '
            . 'object here, and the callers are the order observer and the gateway handlers, none '
            . 'of which take a financial id from request input.',

        "->where('id', \$financialId) ->lockForUpdate() ->first();" =>
            'ONE DECLARATION FOR TWO STATEMENTS, because they are byte-identical: the guarded read '
            . 'at the top of markFinancialAsPaid() (:289) and of markFinancialAsUnpaid() (:402). '
            . 'Both key on the primary key their ONE caller has already ownership-verified — see '
            . 'the class docblock and the caller ratchet below. `lockForUpdate()` is the real '
            . 'point of the statement: it serialises two concurrent paid/unpaid flips on the same '
            . 'row, which is why it is raw and inside a transaction rather than an Eloquent read. '
            . 'Each method then refuses a row already in the target state, so neither a '
            . 'double-paid nor a double-unpaid can move wallet income twice.',

        "->update([ 'payment_status' => 'paid'," =>
            'The paid write inside markFinancialAsPaid\'s transaction, on the row the '
            . 'lockForUpdate above returned and the status check accepted.',

        "->update([ 'payment_status' => 'unpaid'," =>
            'The unpaid write inside markFinancialAsUnpaid\'s transaction, on the row the '
            . 'lockForUpdate above returned and the status check accepted.',

        /* -------------------------------------------------------------------------------------
           OPS-001 (2026-11-26): `App\Services\SaasOperationsProbe`, THIRTEEN STATEMENTS, AND THEY
           ARE STORE-LESS FOR A REASON THIS LIST HAS NOT SEEN BEFORE — the subject is the SHARED
           INFRASTRUCTURE, so one tenant's figure would be the wrong answer rather than a narrower
           one.

           The recovered requirement is "مراقبة الخوادم وقواعد البيانات والطوابير والتكاملات" —
           monitor servers, databases, queues and integrations. A queue depth of one store's jobs
           is not a queue depth; the queue is one queue, shared, and an operator asking how deep
           it is is asking about the machine every tenant is waiting on. A `store_id` predicate
           here would not narrow an exposure, it would produce a figure that is wrong by however
           many jobs belong to everybody else.

           THE DISCLOSURE SURFACE IS A COUNT AND THE DOOR IS IDENTITY. Every one of these thirteen
           is a `count()`, a `min()`, a `max()` or a `distinct()->pluck()` of queue NAMES; none
           returns a row of anybody's data. Their one caller is `SaasPlatformOperations`, whose one
           caller is `SuperAdminOperationsController`, gated by `PlatformOperatorOnly` on the route
           AND by the same test again inside the handler — `PlatformOperationsScreenTest` drives a
           merchant, a staff member, a staff member holding `Manage Reports`, and the handler
           directly with the route gate removed. Nothing takes an id from request input; the handler
           reads nothing from the request at all, which `test_a_query_string_changes_nothing`
           asserts rather than leaves to be read off the code.

           FOUR OF THESE TABLES HAVE NO `store_id` COLUMN AT ALL. `jobs`, `failed_jobs`, `cache`
           and `sessions` are the framework's own, created by `0001_01_01_000002` and
           `2019_08_19_000000`; there is no predicate available to omit. `otp_integrations` and
           `settings` DO carry one, which is why their rows in the by-table map are the ones to
           read carefully — see the note there.

           WHY RAW AND NOT ELOQUENT, per table. `jobs`, `failed_jobs`, `cache` and `sessions` have
           no model in `app/Models/`. `OtpIntegration` has one and casts `api_secret` to
           `encrypted`, so an Eloquent read would decrypt every merchant's provider secret into the
           request's memory to answer "how many are active". `Setting` is `Cachable`, and a cached
           figure on an operations screen is read as the current one — which is the exact failure
           these figures exist to catch.
           ------------------------------------------------------------------------------------- */

        "DB::table('jobs')->whereNull('reserved_at')->count()" =>
            'The queue depth: jobs handed over and not yet taken by a worker. The queue is one '
            . 'shared queue and `jobs` has no store_id column, so there is no predicate to omit. '
            . 'A count, behind the operator-only gate.',

        "DB::table('jobs')->whereNotNull('reserved_at')->count()" =>
            'Jobs a worker has taken and not finished — counted apart from the waiting ones '
            . 'because held-and-not-shrinking is a dead worker rather than a backlog. Same table, '
            . 'same absence of a store_id column, same gate.',

        "DB::table('jobs')->whereNull('reserved_at')->min('created_at')" =>
            'When the oldest job still waiting was dispatched. `created_at` and not '
            . '`available_at`, because a delayed job has a due time in the future and has not been '
            . 'waiting at all. One timestamp, no row data, same gate.',

        "DB::table('failed_jobs')->count()" =>
            'Every failure still in the table. `failed_jobs` has no store_id column; it is the '
            . 'framework\'s own table and the only durable failure record this product keeps '
            . 'anywhere, which is why the screen says in words that the figure covers queued work '
            . 'only.',

        "DB::table('failed_jobs') ->where('failed_at', '>=', now()->subHours(\$sinceHours))" =>
            'The same count inside a stated window. The window is a constant on the controller and '
            . 'is never read from the request: a failure count with no window is the commonest way '
            . 'an operations page misleads, and a caller-chosen one would be the one input on a '
            . 'screen that has none.',

        "DB::table('failed_jobs')->max('failed_at')" =>
            'When the newest recorded failure happened. One timestamp.',

        "DB::table('failed_jobs')->distinct()->pluck('queue')" =>
            'The distinct QUEUE NAMES that hold failures — configuration-level strings such as '
            . '`default` or `mail`, not data. Asked for so the per-queue counts below can be taken '
            . 'without a `GROUP BY`: an aggregate would have to be `selectRaw`, which '
            . 'BuilderRawQueryVisibilityTest records as invisible to this very census.',

        "DB::table('failed_jobs') ->where('queue', \$queue)" =>
            'The failure count for one queue, keyed on a name that came from the distinct list '
            . 'immediately above and never from a caller. A handful of queries on any deployment, '
            . 'and each one a count.',

        "DB::table('cache')->count()" =>
            'Rows in the database-backed cache table. It has no store_id column, and the figure is '
            . 'on this screen because those rows share the disk and the connections with the '
            . 'orders. The table NAME is compared against a literal rather than taken from '
            . 'configuration, so a renamed table is a stated refusal instead of a count from '
            . 'somewhere else.',

        "DB::table('sessions')->count()" =>
            'Rows in the database-backed session table — not a count of people signed in. It is '
            . 'worth a row because a sessions table that stops growing is a session store that '
            . 'stopped accepting writes, which signs every principal on the platform out. Same '
            . 'literal-name comparison as the cache table.',

        "DB::table('settings') ->whereIn('name', array_keys(\$enableNames)) ->whereNotNull('value') ->whereNotIn('value', ['on', 'off'])" =>
            'THE ONLY ONE OF THE THIRTEEN ON A TABLE THAT REALLY HAS A `store_id`, and it is '
            . 'platform-wide on purpose: it counts gateway on/off rows holding a value that is '
            . 'neither `on` nor `off`, which is a disagreement between the settings WRITER (which '
            . 'stores only those two literals) and most of the product\'s READERS (which test the '
            . 'row for emptiness and therefore read anything else as switched ON). The figure is '
            . 'that disagreement existing in the data across the platform; per-store it would say '
            . 'nothing. It selects NO value column — `on`, `off` and the emptiness test are '
            . 'literals in the predicate — so no credential is read, which '
            . 'PlatformOperationsMeasurementTest asserts against the SQL actually issued rather '
            . 'than against the source.',

        "DB::table('otp_integrations')->count()" =>
            'How many stores have configured an SMS or WhatsApp provider, platform-wide. The table '
            . 'has a store_id and this count deliberately spans every one of them: the screen\'s '
            . 'subject is what the platform\'s integrations are, not one merchant\'s. Raw rather '
            . 'than Eloquent because `OtpIntegration::$casts` makes `api_secret` `encrypted`, so a '
            . 'model read would decrypt every merchant\'s provider secret to answer a count.',

        "DB::table('otp_integrations')->where('is_active', true)->count()" =>
            'The same population, narrowed to the ones switched on. The difference between the two '
            . 'figures is a provider a merchant set up and then turned off. Same reason for raw, '
            . 'same gate, and still no secret read.',
    ];

    /**
     * The ONE caller of each wallet-moving method, and the ownership check it must carry.
     *
     * This is the containment. The methods are safe because of what their caller does; this
     * asserts the caller still does it and that no second caller has appeared.
     */
    private const WALLET_MOVING_METHODS = ['markFinancialAsPaid', 'markFinancialAsUnpaid'];

    public function test_the_service_layer_raw_query_census_is_exactly_this(): void
    {
        [$total, $scoped, $storeLess] = $this->census();

        /*
         * 25 -> 31 on 2026-10-31: `app/Services/StockMovement.php` (E13's stock-evidence record)
         * adds SIX raw statements, and every one of them names `store_id` -- so the store-less
         * count below is unchanged at eight. The table has no model and therefore no global scope,
         * which is exactly why each read and write carries the predicate at the call site rather
         * than relying on one: `record()` refuses a movement with no store at all,
         * `movedOutFor()` and its observed-fallback both predicate on it, and `attributeToOrder()`,
         * `unattributed()` and `forOrder()` do too.
         *
         * 31 -> 33 on 2026-10-31 [F237]: `StockMovement::attributionStateFor()` adds TWO more,
         * and both name `store_id`, so the store-less count below is still eight. They exist
         * because `attributeToOrder()` answers with a row count, and a count of zero conflates
         * "already attributed" (success) with "rows exist but did not move" (a failure that must
         * not be read as success) — so the state is asked as its own question. Same reasoning for
         * raw-over-Eloquent as the six above: this table is written on the checkout path and read
         * immediately afterwards, and a `Cachable` model would serve a pre-write row.
         */
        /*
         * 33 -> 39 on 2026-10-31 [F229 / D-31 req 1]: `PlanPurchaseIntent` adds SIX, and -- unlike
         * every previous delta in this file -- **none of them names `store_id`, so the store-less
         * count moves too (8 -> 14)**. That is the design and not an oversight: the table has no
         * `store_id` column, because a SaaS plan is bought by a USER from the platform. The
         * isolation is per-purchaser and is enforced before any disclosure. All six are declared
         * with their reason in STORE_LESS_BY_DESIGN above, which is what
         * `test_every_store_less_service_statement_is_declared_with_its_reason` requires.
         *
         * 39 -> 40 on 2026-10-31 [F229 round 3]: `recordRecoverableRefusal()` adds ONE, and it is
         * store-less for the same reason as the other six (14 -> 15). It exists because round 2's
         * `REFUSED_RECOVERABLE` left the row `pending` having written nothing, which
         * `unreconciled()` could not match -- a captured payment became indistinguishable from an
         * abandoned checkout. It writes only `failure_reason`, deliberately NOT `provider_txn_id`:
         * the column the query treats as evidence of capture must not be filled on the one path
         * where nothing about the payment was established. Already covered by the
         * `->where('provider', $provider)` declaration above, which is why the
         * every-statement-declared test did not move.
         *
         * 40 -> 41 on 2026-10-31 [F229, Cashfree + Mollie]: `consumeForPlanBinding()` adds ONE,
         * store-less for the same reason as the rest (15 -> 16). It is the plan binding WITHOUT
         * the amount comparison -- the two controls are independent, and binding the plan to the
         * intent needs nothing about how a provider reports money, which is what let Cashfree and
         * Mollie be protected while their amount contracts stay BLOCKED under requirement 8.
         * Covered by the `->where('provider', $provider)` declaration above.
         *
         * 41 -> 42 on 2026-11-01 [E13 / D-15 condition 8]: `OrderReplacement::cancelAndReplace()`
         * adds ONE — the `lockForUpdate()` re-read of the original order that serialises two
         * concurrent replacements. **AND THIS CENSUS WAS ALREADY RED AT THAT POINT, which is the
         * part worth recording:** the statement landed with the E13 commit and the count was not
         * moved with it, so `app/Services` really held 42 while this file asserted 41. Measured by
         * running the same scan over `git archive HEAD app/Services` — HEAD total 42 — rather than
         * inferred from the failure, because the whole value of a census is that its number is a
         * measurement.
         *
         * **THE STORE-LESS COUNT DOES NOT MOVE (stays 16), and that is the other half of the fix.**
         * The statement now carries `->where('store_id', $original->store_id)` at the call site, so
         * it needs no STORE_LESS_BY_DESIGN entry at all. It was added that way deliberately rather
         * than declared: the caller does authorise the order, but this file's rule — scope at the
         * call site, or declare with a reason, and *"the caller checks"* is the weaker of the two —
         * is the right one for a raw statement that no global scope can see. A row whose `store_id`
         * is NULL now fails to lock and the replacement is refused, which is fail-closed and
         * deliberate: `where('store_id', null)` compiles to `IS NULL` and would match any other
         * orphan.
         *
         * 42 -> 44 on 2026-11-10 [P0, the user-deletion safety guard]: `UserDeletionSafety` adds
         * TWO, and both are store-less (16 -> 18). They are the two halves of the guard that
         * decides whether a `users` row can be hard-deleted at all: a count of the stores the user
         * owns, and a count of their rows in the 15 pinned cascade tables and 5 orphan tables.
         *
         * **THEY ARE DECLARED RATHER THAN SCOPED, AND THE DIRECTION OF FAILURE IS THE ARGUMENT.**
         * Everywhere else in this file a missing `store_id` predicate means a statement can SEE
         * too much. Here a predicate would mean it sees too LITTLE: a dependant it fails to count
         * is a dependant the delete destroys, so a scoped count turns a refusal into a silent
         * cascade. Both statements are counts keyed on the target user id, both can produce only a
         * refusal naming a number, and `UserPolicy::delete()` has already refused a cross-tenant
         * target before either runs. See their entries in STORE_LESS_BY_DESIGN.
         */
        /*
         * 44 -> 46 on 2026-11-16 [P4-F124]: `App\Services\OrderEntitlementReversal` adds the
         * locked read of an order's `store_order_financials` row and the update that records the
         * reversal of entitlement.
         *
         * BOTH NAME `store_id`, so the store-less list this assertion prints on failure is
         * UNCHANGED -- that list, not this count, is the part that matters.
         *
         * They are raw rather than Eloquent for the reason every statement against this table is:
         * the row is read, decided on and written inside one `DB::transaction` under
         * `lockForUpdate()`, and a `Cachable` model would serve a pre-write row to the next read
         * in the same request.
         */
        /*
         * 46 -> 48 on 2026-11-25 [#204, the payment reconciliation surface]: `PlanPurchaseIntent`
         * adds TWO -- `awaitingReconciliation()` and `outsideReconciliationWindow()`.
         *
         * BOTH ARE STORE-LESS (18 -> 20), like every other statement against that table and for
         * the same reason: it has no `store_id` column. They are declared in STORE_LESS_BY_DESIGN
         * under their own fragment rather than riding on an existing one, because they are the
         * first statements in that file that select MANY purchasers' rows for something that then
         * ACTS -- the unreconciled query is also platform-wide but nothing acts on it. What makes
         * that safe is not the query: it is that the only caller is a console command, and that
         * `PlanPaymentReconciler` settles each row for that row's own purchaser and plan and
         * takes nothing from any caller.
         */
        /*
         * 48 -> 61 on 2026-11-25 [#204, the disabled-application payment boundary]:
         * `App\Services\ModulePaymentLedger` adds THIRTEEN.
         *
         * TWO NAME `store_id` (28 -> 30) AND ELEVEN DO NOT (20 -> 31), and this is the one delta in
         * this file where the split is NOT the evidence of anything, so it is said rather than
         * left to be read the usual way. The two that name it are INSERTs that record the store a
         * route belonged to; the eleven that do not are every statement that FINDS a row -- by the
         * session-held reference, by module, or by primary key from the console. The isolation is
         * per payer session, and STORE_LESS_BY_DESIGN has the argument for each fragment.
         *
         * AND THE CLASS WAS MOVED HERE SO THAT THIS CENSUS WOULD SEE IT. It was first written at
         * `app/Services/Payments/ModulePaymentLedger.php`, and this file's scan is
         * `glob('app/Services/*.php')` -- not recursive -- so thirteen new raw statements against a
         * payment record sat outside the ratchet and it stayed green. They belong beside
         * `GatewayReference`, `PlanPurchaseIntent` and `StockMovement`, which is where they now
         * are. The blind spot itself is real and pre-existing (`Marketing/NudgeLedger`,
         * `Theme/ThemeConfigurationService` and `Theme/ThemePublishingService` hold raw statements
         * this census has never counted) and is recorded as a gap rather than widened here, because
         * widening it means reading and declaring statements this change did not write.
         */
        /*
         * 61 -> 65 on 2026-11-25 [the independent financial review of #204]: FOUR more, all in
         * `PlanPurchaseIntent` and all added in response to a measured finding.
         *
         *   `findForUpdate()`    a LOCKING re-read. Under REPEATABLE READ a plain `find()` inside the
         *                        settlement's transaction answered from that transaction's own
         *                        snapshot, so the loser of a real race read the intent as still
         *                        `pending` and a lost race was reported AND AUDITED as a mismatching
         *                        AMOUNT. Measured on MariaDB; invisible to SQLite, where
         *                        `lockForUpdate()` compiles to nothing.
         *   `reopenInitiation()` / `initiationStore()`   the module ledger's two halves of
         *                        put-the-claim-back and complete-in-the-store-it-started-in.
         *   `recordedProviders()`  a DISTINCT so a mistyped `--provider` is refused rather than
         *                        reported as a clean run.
         *
         * Three name no `store_id` (31 -> 34) for the reason every statement against these two
         * tables does: neither has the column. `recordedProviders()` is declared in
         * STORE_LESS_BY_DESIGN; the other three are covered by the reference-keyed and
         * `(reference, module)` declarations already there.
         */
        /*
         * 65 -> 67 on 2026-11-25 [the acknowledgement for plan intents, the same review's finding 9]:
         * TWO more, both in `PlanPurchaseIntent`, both about the row a person has answered.
         *
         *   `needingAPerson()`  the one intent an acknowledgement may name. Deliberately NARROWER
         *                       than `unreconciled()`: a `pending` intent qualifies only once
         *                       something has been RECORDED against it, because acknowledging a
         *                       clean pending payment would make a capture permanently unsettleable
         *                       with one console command.
         *   `acknowledge()`     the conditional `UPDATE ... WHERE acknowledged_at IS NULL`, so two
         *                       people racing produce one record and the first reason survives.
         *
         * Both name no `store_id` (34 -> 36), for the reason every statement against this table
         * does: `plan_purchase_intents` has no such column, and it could not -- a plan purchase is
         * between the platform and a merchant's ACCOUNT, not inside one of their stores. The
         * isolation that matters here is the purchaser's, and it is `user_id`, which both carry
         * through the row they read rather than from any caller's input.
         *
         * 67 -> 68 THE SAME DAY [the two independent reviews of that change]: ONE more, net, and the
         * arithmetic is worth writing out because it is three changes rather than one addition.
         *
         *   -2 +1  `needingAPerson()`'s builder and `acknowledge()`'s UPDATE became ONE shared
         *          statement, `answerableById()`. The financial review measured why they must not be
         *          two: the state was tested by a separate NON-locking read, so a settlement that
         *          committed while the acknowledgement waited for the row lock left the payment
         *          granted AND recorded as "will never be settled" -- and once `plan_order_id` is
         *          set the row matches no `unreconciled()` clause, so the contradiction never
         *          surfaces again. The predicate now lives inside the conditional UPDATE, which is
         *          the shape `ModulePaymentLedger::acknowledge()` already had.
         *   +2     `reopen()` and `acknowledged()`, so a mistyped id is correctable ON THE RECORD.
         *          Both reviews raised the irreversibility: the ids are small sequential integers
         *          printed beside other purchasers' findings, and before this the only way back was
         *          a hand-written UPDATE -- same shell access, no audit row.
         *
         * Store-less goes 36 -> 37 and `$scoped` stays at 31, which is the evidence that none of it
         * widened a tenant's reach.
         *
         * AND ALL THREE NOW CARRY THEIR OWN DECLARED REASONS, which they did not before. The same
         * review found them satisfying the declaration ratchet by INHERITING a fragment written for
         * `ModulePaymentLedger`, whose stated reason ("both statements require the row to be a
         * refusal") is false for them. That fragment is narrowed above so it can no longer be lent
         * out -- which is this file's own failure mode, not theirs.
         */
        /* 68 -> 69, same day: `byId()`. The console had a `DB::table('plan_purchase_intents')` of
           its own for the audit re-read, which broke the invariant the entry-point census records
           for this table -- every statement against it belongs to the service that owns it, so the
           table has exactly ONE class that queries it -- and put the table into the per-table
           exposure map as a literal for the first time. Moved here instead of declared there. */
        /* 69 -> 70, AND THE WHOLE OF THE +1 IS SCOPED -- `app/Services/RefundPolicy.php:164`,
           added by GAP-140 (`cb2282588`/`68153d34b`) and NOT noticed then, because that package's
           blast radius was chosen by subject and this census is keyed on a DIRECTORY. The same root
           cause as GAP-334 one commit earlier in this branch, which is why it is worth saying twice:
           a census over `app/Services` is reached by any change to any file in it, whatever the
           change was about.

           The statement is
               DB::table('order_refund_settings')->where('store_id', $storeId)->where('name', $name)
           so it lands in `$scoped` (31 -> 32) and `$storeLess` is UNCHANGED at 38. That split is the
           evidence this added no cross-tenant exposure: the totals would have moved either way, and
           the store-less count is the one that would have risen if the predicate were missing. */
        /* 70 -> 85 on 2026-11-26 [OPS-001]: `app/Services/SaasOperationsProbe.php` adds FIFTEEN,
           and -- unlike most deltas in this file -- THIRTEEN of them land in `$storeLess`
           (38 -> 51). That is the design and not an oversight, and the full argument is beside the
           thirteen declarations in STORE_LESS_BY_DESIGN above: the subject of that class is the
           SHARED INFRASTRUCTURE, so a `store_id` predicate would not narrow an exposure, it would
           produce a figure that is wrong by however much belongs to everybody else. A queue depth
           of one store's jobs is not a queue depth.

           THE OTHER TWO LAND IN `$scoped` (32 -> 34) AND THAT NEEDS SAYING PLAINLY, because the
           census's rule and the statements' behaviour differ here. Both are
           `DB::table('settings')->select('name', 'store_id')...`, and they are counted as scoped
           because the string `store_id` appears in them -- but they SELECT the column, they do not
           PREDICATE on it. They are as platform-wide as the other thirteen. Nothing is being
           smuggled through: the column is selected precisely so the figures can be computed PER
           STORE in PHP ("how many stores have this gateway switched on, and how many of those have
           every required credential"), which is what the screen shows. Recorded here so that
           nobody later reads a `$scoped` count as a tenant guarantee it is not. */
        $this->assertSame(
            85,
            $total,
            "The number of raw query-builder statements in app/Services moved. Every one is "
            . "invisible to every Eloquent global scope, so a new one needs a `store_id` "
            . "predicate written at the call site or a declaration in STORE_LESS_BY_DESIGN.\n  "
            . implode("\n  ", array_keys($storeLess))
        );

        /* 26 -> 28 [P4-F124]: both of OrderEntitlementReversal's statements, and BOTH name
           `store_id` -- so the whole of the +2 lands here and the store-less count below is
           unchanged at 18. That split is the evidence that the change added no cross-tenant
           exposure; the totals above would move either way. */
        /* 34 -> 36 -> 37 on 2026-11-25: `needingAPerson()` and `acknowledge()`, then the reviews'
           net +1 (`answerableById()` replacing those two, plus `reopen()` and `acknowledged()`).
           `$scoped` is UNCHANGED at 31 through all of it, which is the evidence that none of this
           added cross-tenant exposure -- every one of these statements reads or writes
           `plan_purchase_intents`, which has no `store_id` and could not have one, and every one
           addresses its row by primary key or the server-minted reference rather than by anything a
           caller supplied. */
        $this->assertSame(34, $scoped, 'statements naming store_id');
        $this->assertCount(51, $storeLess, 'statements naming store_id nowhere');
    }

    public function test_every_store_less_service_statement_is_declared_with_its_reason(): void
    {
        [, , $storeLess] = $this->census();

        $undeclared = [];

        foreach ($storeLess as $site => $statement) {
            $matched = false;

            foreach (array_keys(self::STORE_LESS_BY_DESIGN) as $fragment) {
                if (str_contains($this->normalise($statement), $this->normalise($fragment))) {
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                $undeclared[] = $site;
            }
        }

        $this->assertSame(
            [],
            $undeclared,
            "A raw statement in app/Services names `store_id` nowhere and is not declared in "
            . "STORE_LESS_BY_DESIGN. Either scope it, or declare it there WITH THE REASON it must "
            . "be wider than one tenant — and if the reason is 'the caller checks', add a caller "
            . "ratchet like the one for the wallet-moving methods, because an unratcheted caller "
            . "is the whole shape of this risk.\n  " . implode("\n  ", $undeclared)
        );
    }

    public function test_no_declaration_outlives_the_statement_it_describes(): void
    {
        [, , $storeLess] = $this->census();

        $found = array_map(fn ($s) => $this->normalise($s), array_values($storeLess));
        $stale = [];

        foreach (array_keys(self::STORE_LESS_BY_DESIGN) as $fragment) {
            $normalised = $this->normalise($fragment);
            $hit = false;

            foreach ($found as $statement) {
                if (str_contains($statement, $normalised)) {
                    $hit = true;
                    break;
                }
            }

            if (! $hit) {
                $stale[] = $fragment;
            }
        }

        $this->assertSame(
            [],
            $stale,
            'A STORE_LESS_BY_DESIGN declaration no longer matches any statement. If the statement '
            . 'was scoped or deleted, delete its declaration too — a stale exemption is how the '
            . "next unscoped statement hides.\n  " . implode("\n  ", $stale)
        );
    }

    /**
     * A file's PHP source with every comment and docblock removed.
     *
     * Same shape as `DeliveryBoyScopeTest`'s and `OrderWriteCensus`'s, so the three agree about
     * what "the code says" means.
     */
    private function sourceWithoutComments(string $file): string
    {
        $out = '';

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }

        return $out;
    }

    /**
     * THE CONTAINMENT. `markFinancialAsPaid`/`markFinancialAsUnpaid` move
     * `store_wallets.total_income`, and they take a bare `int`. They are safe only because their
     * single caller verifies the row's ownership first.
     */
    public function test_each_wallet_moving_method_has_exactly_one_ownership_verified_caller(): void
    {
        foreach (self::WALLET_MOVING_METHODS as $method) {
            $callers = [];

            foreach ($this->firstPartyPhpFiles() as $file) {
                if (str_ends_with($file, 'app/Services/OrderFinancialService.php')) {
                    continue; // its own declaration and internal call
                }

                /*
                 * COMMENTS STRIPPED BEFORE MATCHING, because a comment cannot call a method.
                 *
                 * This read `str_contains(file_get_contents($file), $method . '(')` and therefore
                 * counted any file that so much as NAMED one of these methods in prose. That is
                 * not a hypothetical: `App\Services\OrderEntitlementReversal` was reported as a new
                 * caller of `markFinancialAsUnpaid()` purely because its docblock explains why it
                 * deliberately does NOT use that method — i.e. the ratchet fired on documentation
                 * of the very containment it exists to protect.
                 *
                 * A false positive here is not harmless. It trains a reader to resync the expected
                 * list without looking, which is exactly how a REAL new caller would later slip
                 * through. Narrowing the detector to code makes the assertion mean what its own
                 * message says.
                 *
                 * THE SAME TECHNIQUE IS ALREADY USED IN THIS REPOSITORY for the same reason --
                 * `DeliveryBoyScopeTest.php:508` and `tests/Support/Commerce/OrderWriteCensus.php:504`
                 * both drop `T_COMMENT` / `T_DOC_COMMENT` before matching, and the CI scripts strip
                 * XML comments before grepping `phpunit.xml` because "a commented-out override
                 * satisfies a naive grep while doing nothing".
                 *
                 * THIS DOES NOT WEAKEN THE RATCHET. A real call site is code and survives the
                 * strip; only prose is removed. A caller that tried to hide inside a comment would
                 * not execute.
                 */
                $source = $this->sourceWithoutComments($file);

                if (str_contains($source, $method . '(')) {
                    $callers[] = str_replace(base_path() . '/', '', $file);
                }
            }

            $this->assertSame(
                ['app/Http/Controllers/StoreFinanceController.php'],
                $callers,
                $method . '() moves store_wallets.total_income and takes a bare int financial id. '
                . 'A NEW CALLER HAS APPEARED. Before this test goes green again, prove that caller '
                . 'resolves the financial row from the AUTHENTICATED principal\'s store — '
                . '`->where(\'store_id\', getCurrentStore())` or equivalent — and not from request '
                . 'input. A predicate built from `$request->store_id` is not a tenant check; it '
                . 'only compares the caller\'s own two values, and that pattern has produced four '
                . 'separate defects in this codebase.'
            );
        }

        // And the caller still does what makes the five raw statements safe.
        $caller = (string) file_get_contents(base_path('app/Http/Controllers/StoreFinanceController.php'));

        $this->assertStringContainsString(
            "canFinance('Edit Finance Payment Status')",
            $caller,
            'the permission gate in front of the wallet-moving path is gone'
        );

        $this->assertMatchesRegularExpression(
            "/DB::table\('store_order_financials'\)\s*->where\('id', \\\$id\)\s*->where\('store_id', \\\$storeId\)/",
            $caller,
            'StoreFinanceController::updateFinancialPaymentStatus no longer loads the financial '
            . 'row scoped to the caller\'s own store. THIS IS THE CHECK THE WHOLE SERVICE DEPENDS '
            . 'ON: without it, the id reaching markFinancialAsPaid() is unverified and a merchant '
            . 'can move another store\'s wallet balance.'
        );

        $this->assertMatchesRegularExpression(
            '/\$storeId\s*=\s*getCurrentStore\(\)/',
            $caller,
            'the store used in that predicate must come from the authenticated principal '
            . '(getCurrentStore()), never from request input'
        );
    }

    public function test_the_owned_path_inventory_still_does_not_reach_this_directory(): void
    {
        /*
         * The premise of this whole file, asserted rather than assumed — and asserted so that the
         * day somebody DOES extend `OwnedPathRawQueryInventoryTest` to `app/Services`, this test
         * fails and tells them these declarations are now duplicated there.
         */
        $owned = (string) file_get_contents(
            base_path('tests/Feature/Tenancy/OwnedPathRawQueryInventoryTest.php')
        );

        $this->assertStringNotContainsString(
            "'app/Services/GatewayReference.php'",
            $owned,
            'OwnedPathRawQueryInventoryTest now classifies a service file. If its scan has been '
            . 'extended to app/Services, this file\'s declarations belong there instead of being '
            . 'maintained twice — move them and delete this test.'
        );
    }

    // ------------------------------------------------------------------ the scanner

    /** @return array{0:int,1:int,2:array<string,string>} total, scoped, store-less by site */
    private function census(): array
    {
        $total = 0;
        $scoped = 0;
        $storeLess = [];

        foreach (glob(base_path('app/Services/*.php')) ?: [] as $file) {
            $lines = file($file) ?: [];

            foreach ($lines as $index => $line) {
                $trimmed = ltrim($line);

                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')
                    || str_starts_with($trimmed, '/*')) {
                    continue;
                }

                if (! preg_match(self::RAW_PATTERN, $line)) {
                    continue;
                }

                $total++;
                $statement = $this->statementFrom($lines, $index);
                $site = str_replace(base_path() . '/', '', $file) . ':' . ($index + 1);

                if (str_contains($statement, 'store_id')) {
                    $scoped++;
                } else {
                    $storeLess[$site] = $statement;
                }
            }
        }

        return [$total, $scoped, $storeLess];
    }

    /** The whole statement a site begins, so a predicate on a later line is not missed. */
    private function statementFrom(array $lines, int $index): string
    {
        $statement = '';

        for ($i = $index; $i < min($index + 25, count($lines)); $i++) {
            $statement .= $lines[$i];

            if (preg_match('/;\s*$/', rtrim($lines[$i]))) {
                break;
            }
        }

        return $statement;
    }

    /** Whitespace-insensitive, so a reformat does not invalidate a declaration. */
    private function normalise(string $text): string
    {
        return (string) preg_replace('/\s+/', ' ', trim($text));
    }

    /** @return string[] */
    private function firstPartyPhpFiles(): array
    {
        $files = [];

        foreach (['app', 'routes', 'database', 'config'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $entry) {
                if ($entry->isFile() && $entry->getExtension() === 'php') {
                    $files[] = $entry->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }
}
