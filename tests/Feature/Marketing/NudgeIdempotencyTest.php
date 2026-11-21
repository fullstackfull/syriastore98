<?php

namespace Tests\Feature\Marketing;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Customer;
use App\Models\MarketingNudgeSend;
use App\Models\Product;
use App\Models\WhatsappMessage;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-219 — A DOUBLE-CLICK MUST NOT SEND TWO BILLABLE MESSAGES.
 * =================================================================================================
 *
 * Measured before this slice: `POST abandon-carts-messagesend`, `POST abandon-carts-emailsend`,
 * `POST abandon-wishlist-message-send` and `POST abandon-wish-emailsend` carry
 * `web, Authenticate, XSS, SetLocate, EmailIsVerified` and NO `throttle`, and nothing recorded that
 * a nudge had gone out. `AbandonedMessageDispatch::blockedReason()` asks five questions and none of
 * them is "have we already sent this". So the second click sent again, and every WhatsApp message
 * is billable.
 *
 * WHAT THIS FILE PROVES, and what it deliberately does not:
 *
 *  1. THE SECOND IDENTICAL CLICK SENDS NOTHING, and says so. Driven through the real HTTP endpoint
 *     with the dispatcher's send leg replaced, so the count of real hand-overs is observable.
 *  2. A DIFFERENT ACTION IS STILL ALLOWED. A second cart, or the same shopper's wishlist, is a
 *     different nudge -- a guard that collapsed them would stop a merchant nudging about anything
 *     else, which is worse than the defect.
 *  3. ANOTHER STORE IS UNAFFECTED. `marketing_nudge_sends` is NOT in `config/tenancy.php`'s
 *     `scoped_models` and `null_tenant => 'open'` means a global scope adds no predicate at all
 *     when no tenant resolves -- so if the ledger ever leaned on the scope, store A's nudge would
 *     block store B's. Driven with the SAME phone number in two stores.
 *  4. NO CADENCE IS ENFORCED BY DEFAULT, and that is asserted rather than assumed. How often a
 *     shopper may be nudged is a marketing policy and the owner's to set; a test that only checked
 *     the duplicate guard would not notice a frequency quietly shipping with it.
 *
 * THE SEND LEG IS REPLACED IN EVERY CASE THAT CAN REACH IT. An independent review of
 * `AbandonedMessageReportingTest` found that its configured cases drove the real
 * `Utility::SendMsgs()` into `curl_exec` against graph.facebook.com four times per Feature run,
 * invisibly, because `SendMsgs()` discards the response. The same double is used here, for the same
 * reason, from the start.
 */
final class NudgeIdempotencyTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const CART_WHATSAPP = '/abandon-carts-messagesend';

    private const WISH_WHATSAPP = '/abandon-wishlist-message-send';

    private const CART_EMAIL = '/abandon-carts-emailsend';

    private const WISH_EMAIL = '/abandon-wish-emailsend';

    /** @return array{0: \App\Models\User, 1: \App\Models\Store, 2: Customer} */
    private function tenantWithShopper(string $mobile = '963900000001'): array
    {
        [$owner, $store] = $this->makeTenant();

        $customer = Customer::forceCreate([
            'first_name' => 'Nour', 'last_name' => 'Saleh',
            'email' => 'shopper' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'store_id' => $store->id,
            'mobile' => $mobile,
        ]);

        $this->grantPermission($owner, 'Abandon Cart');

        return [$owner, $store, $customer];
    }

    private function makeProductIn(int $storeId): Product
    {
        $name = 'Nudge Item ' . strtoupper(bin2hex(random_bytes(4)));
        $catName = 'Nudge Cat ' . uniqid();

        $category = Category::forceCreate([
            'name' => $catName,
            'slug' => \Illuminate\Support\Str::slug($catName) . '-' . uniqid(),
            'parent_id' => 0, 'trending' => 0, 'status' => 1, 'store_id' => $storeId,
        ]);

        return Product::forceCreate([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(),
            'description' => 'x', 'price' => 10, 'track_stock' => 0, 'trending' => 0,
            'stock_status' => 'in_stock', 'product_stock' => 5, 'product_type' => null,
            'status' => 1, 'variant_product' => 0,
            'category_id' => $category->id,
            'store_id' => $storeId, 'created_by' => 1,
        ]);
    }

    private function cartFor(Customer $customer): Cart
    {
        return Cart::forceCreate([
            'customer_id' => $customer->id,
            'product_id' => $this->makeProductIn((int) $customer->store_id)->id,
            'variant_id' => 0, 'qty' => 1, 'price' => 5, 'store_id' => $customer->store_id,
        ]);
    }

    private function wishlistFor(Customer $customer): Wishlist
    {
        return Wishlist::forceCreate([
            'customer_id' => $customer->id,
            'product_id' => $this->makeProductIn((int) $customer->store_id)->id,
            'store_id' => $customer->store_id,
        ]);
    }

    private function configureWhatsapp(\App\Models\User $owner, \App\Models\Store $store, string $template): void
    {
        WhatsappMessage::forceCreate([
            'name' => $template, 'from' => 'StoreSyria', 'user_id' => $owner->id,
            'is_active' => 1, 'store_id' => $store->id, 'created_by' => $owner->id,
        ]);

        DB::table('settings')->insert([
            ['name' => 'whatsapp_phone_number_id', 'value' => '1234567890',
             'store_id' => $store->id, 'created_by' => $owner->id],
            ['name' => 'whatsapp_access_token', 'value' => 'FAKE-TOKEN-NEVER-REAL',
             'store_id' => $store->id, 'created_by' => $owner->id],
        ]);

        \Cache::flush();
    }

    /** `handOver` records instead of sending; `blockedReason` stays the real one under test. */
    private function recorder(): \App\Services\AbandonedMessageDispatch
    {
        return new class extends \App\Services\AbandonedMessageDispatch
        {
            /** @var array<int, array{template: string, to: string, message: string}> */
            public array $recorded = [];

            public function handOver(string $template, string $mobile, string $message): void
            {
                $this->recorded[] = ['template' => $template, 'to' => $mobile, 'message' => $message];
            }
        };
    }

    private ?\App\Services\AbandonedMessageDispatch $lastRecorder = null;

    private function withoutSending(callable $run)
    {
        $recorder = $this->recorder();
        $this->lastRecorder = $recorder;
        $this->app->instance(\App\Services\AbandonedMessageDispatch::class, $recorder);

        try {
            return $run();
        } finally {
            $this->app->forgetInstance(\App\Services\AbandonedMessageDispatch::class);
        }
    }

    /** @return array<int, MarketingNudgeSend> every ledger row, unscoped. */
    private function ledgerRows(): array
    {
        return MarketingNudgeSend::withoutGlobalScopes()->orderBy('id')->get()->all();
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The premise: without the guard, two clicks were two messages
     * ---------------------------------------------------------------------------------------------
     */

    public function test_the_premise_that_the_nudge_endpoints_still_carry_no_throttle_middleware(): void
    {
        $routes = app('router')->getRoutes();

        foreach ([
            'abandon-carts-messagesend',
            'abandon-carts-emailsend',
            'abandon-wishlist-message-send',
            'abandon-wish-emailsend',
        ] as $uri) {
            $route = collect($routes)->first(fn ($r) => $r->uri() === $uri);

            $this->assertNotNull($route, "The nudge route {$uri} is gone.");

            $throttles = array_filter(
                $route->gatherMiddleware(),
                fn ($m) => is_string($m) && str_starts_with($m, 'throttle')
            );

            $this->assertSame([], array_values($throttles), sprintf(
                'The route %s now carries throttle middleware. That is not wrong, but the ledger '
                . 'guard was built because it did not -- re-read whether both are wanted.',
                $uri
            ));
        }
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * 1 — the second identical click
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_second_identical_whatsapp_nudge_sends_nothing_and_says_why(): void
    {
        [$owner, $store, $customer] = $this->tenantWithShopper();
        $this->configureWhatsapp($owner, $store, 'Abandon Cart');
        $cart = $this->cartFor($customer);

        $this->withoutSending(function () use ($owner, $cart) {
            $first = $this->actingAs($owner->fresh())
                ->post(self::CART_WHATSAPP, ['cart_id' => $cart->id]);

            $first->assertStatus(200);
            $this->assertTrue($first->json('is_success'),
                'The first nudge did not go out, so this test cannot say anything about the second. '
                . 'Message was: ' . (string) $first->json('message'));

            $second = $this->actingAs($owner->fresh())
                ->post(self::CART_WHATSAPP, ['cart_id' => $cart->id]);

            $second->assertStatus(200);
            $this->assertFalse($second->json('is_success'),
                'The second identical click was accepted, so the merchant has been charged twice.');
            $this->assertStringContainsString('already sent', (string) $second->json('message'),
                'The refusal does not tell the merchant the nudge had already been sent. A button '
                . 'that silently does nothing is indistinguishable from a broken one and gets '
                . 'clicked a third time. Message was: ' . (string) $second->json('message'));
        });

        $this->assertCount(1, $this->lastRecorder->recorded,
            'The dispatcher was handed ' . count($this->lastRecorder->recorded) . ' messages for two '
            . 'clicks on one cart. Exactly one may leave the building.');

        $sent = array_filter($this->ledgerRows(), fn ($r) => $r->outcome === MarketingNudgeSend::OUTCOME_SENT);

        $this->assertCount(1, $sent, 'The ledger holds more than one send for one logical action.');
    }

    /**
     * THE LEDGER ROW IS OWNED BY THE CALLER'S STORE AND HOLDS NO PLAINTEXT CONTACT.
     *
     * Both halves matter. The store id is the tenancy; the hash is the reason this table is not a
     * second copy of the customer contact list in a table built to be read often and cheaply.
     */
    public function test_the_ledger_row_carries_the_callers_store_and_hashes_the_contact(): void
    {
        [$owner, $store, $customer] = $this->tenantWithShopper('963900000777');
        $this->configureWhatsapp($owner, $store, 'Abandon Cart');
        $cart = $this->cartFor($customer);

        $this->withoutSending(fn () => $this->actingAs($owner->fresh())
            ->post(self::CART_WHATSAPP, ['cart_id' => $cart->id]));

        $rows = $this->ledgerRows();

        $this->assertCount(1, $rows);
        $row = $rows[0];

        $this->assertSame((int) $store->id, (int) $row->store_id);
        $this->assertSame($owner->id, (int) $row->created_by);
        $this->assertSame('whatsapp', $row->channel);
        $this->assertSame('cart', $row->subject_type);
        $this->assertSame((int) $cart->id, (int) $row->subject_id);
        $this->assertSame(MarketingNudgeSend::OUTCOME_SENT, $row->outcome);
        $this->assertNotNull($row->sent_at);

        $this->assertSame(64, strlen((string) $row->contact_hash),
            'The contact column is not a SHA-256 hash.');
        $this->assertStringNotContainsString('963900000777', (string) $row->contact_hash,
            'The shopper\'s phone number is in the ledger in plaintext.');

        $this->assertSame(
            hash('sha256', '963900000777'),
            (string) $row->contact_hash,
            'The hash is not over the digits-only normalisation, so the same person typed two ways '
            . 'would get two nudges.'
        );
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * 1b — the two E-MAIL endpoints, which an independent review found driven by nothing
     * ---------------------------------------------------------------------------------------------
     */

    /**
     * THE E-MAIL ENDPOINTS RESERVE TOO, and before this test they did not have to.
     *
     * An independent review found that every behavioural case in this file posted to one of the two
     * WhatsApp endpoints, so **deleting the `$nudge` block from either e-mail handler left the whole
     * suite green** -- and those two are the ones carrying the worse form of the defect, because
     * they reserve with no precondition check at all and only discover an SMTP failure afterwards.
     *
     * THIS ASSERTS AT THE LEDGER RATHER THAN AT THE RESPONSE, deliberately.
     * `Utility::sendEmailTemplate()` needs a vendored `EmailTemplate` fixture to succeed, and
     * building one would make this test about the mail stack. What S6 actually asks is "would
     * deleting the guard fail a test", and a reservation row is the guard's only observable: with
     * the block removed, NO row is written and this fails. The send's own outcome is irrelevant to
     * that question, and the failure path has its own test below.
     */
    public function test_the_email_endpoints_record_their_nudge_in_the_ledger(): void
    {
        [$owner, $store, $customer] = $this->tenantWithShopper();
        $this->grantPermission($owner->fresh(), 'Abandon Wishlist');

        $cart = $this->cartFor($customer);
        $wish = $this->wishlistFor($customer);

        $this->actingAs($owner->fresh())->post(self::CART_EMAIL, ['cart_id' => $cart->id]);
        $this->actingAs($owner->fresh())->post(self::WISH_EMAIL, ['wish_id' => $wish->id]);

        $rows = $this->ledgerRows();

        $this->assertCount(2, $rows, sprintf(
            'The two e-mail endpoints wrote %d ledger rows instead of 2. Each must reserve through '
            . 'NudgeGate, or a double-click sends two e-mails and nothing records either.',
            count($rows)
        ));

        foreach ($rows as $row) {
            $this->assertSame('email', $row->channel,
                'An e-mail nudge was recorded on the wrong channel, so the two legs would '
                . 'deduplicate against each other.');
            $this->assertSame((int) $store->id, (int) $row->store_id);
        }

        $subjects = array_map(fn ($r) => $r->subject_type, $rows);
        sort($subjects);

        $this->assertSame(['cart', 'wishlist'], $subjects,
            'The cart and wishlist e-mail nudges are not recorded against different subjects.');
    }

    /**
     * A FAILED SEND MUST NOT BLOCK THE RETRY — the defect this guard would otherwise have CREATED,
     * and it is worse than the one it fixes.
     *
     * `reserve()` writes its row BEFORE the send, which is what makes it a guard rather than a log:
     * a row written only after a successful send leaves the race window open for as long as an HTTP
     * call to a gateway takes. The cost is that a failed send leaves a claim behind, and the key has
     * no clock in it, so nothing expires it. An independent review traced the consequence: with
     * SMTP misconfigured, the merchant's first click claims the nudge and fails; they fix SMTP,
     * click again, and are told *"this nudge was already sent"* -- for ever. The only escapes were
     * the cart's lowest line id changing or a DBA deleting the row.
     *
     * THE FIXTURE NEEDS NO SABOTAGE. A fresh tenant has no `Abandon Cart` e-mail template, so
     * `Utility::sendEmailTemplate()` genuinely answers `is_success => false` with
     * "Mail not send, email not found". That is a real failure on a real path, not a stubbed one.
     *
     * The second click must reach the send again and fail for the SAME real reason -- not be
     * refused as a duplicate of a message that never existed.
     */
    public function test_a_failed_email_send_does_not_block_the_retry(): void
    {
        [$owner, $store, $customer] = $this->tenantWithShopper();
        $cart = $this->cartFor($customer);

        $first = $this->actingAs($owner->fresh())->post(self::CART_EMAIL, ['cart_id' => $cart->id]);

        $first->assertStatus(200);
        $this->assertFalse($first->json('is_success'),
            'Premise: with no e-mail template this send must fail, or this test proves nothing.');

        $afterFirst = $this->ledgerRows();

        $this->assertCount(1, $afterFirst);
        $this->assertSame('blocked', $afterFirst[0]->outcome, sprintf(
            'The failed send left its claim recorded as "%s". It must be released: the key has no '
            . 'clock in it, so an unreleased claim blocks this nudge permanently.',
            $afterFirst[0]->outcome
        ));
        $this->assertNull($afterFirst[0]->sent_at,
            'A released claim still carries a sent_at, so once a cadence is configured a failed '
            . 'send would extend the shopper\'s quiet period.');

        $second = $this->actingAs($owner->fresh())->post(self::CART_EMAIL, ['cart_id' => $cart->id]);

        $second->assertStatus(200);
        $this->assertStringNotContainsString('already sent', (string) $second->json('message'), sprintf(
            'After a FAILED send the retry was refused as a duplicate. The guard has become a '
            . 'permanent block on a nudge that was never delivered, which is strictly worse than '
            . 'the double-send it replaces. Message was: %s',
            (string) $second->json('message')
        ));
        $this->assertSame('Mail not send, email not found', (string) $second->json('message'),
            'The retry did not reach the send leg again.');

        $this->assertCount(2, $this->ledgerRows(),
            'The retry left no record of its own. Two attempts, neither delivered, is two facts.');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * 2 — a different action is still allowed
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_second_cart_for_the_same_shopper_is_a_different_nudge(): void
    {
        [$owner, $store, $customer] = $this->tenantWithShopper();
        $this->configureWhatsapp($owner, $store, 'Abandon Cart');

        $cartA = $this->cartFor($customer);
        $cartB = $this->cartFor($customer);

        $this->withoutSending(function () use ($owner, $cartA, $cartB) {
            $this->actingAs($owner->fresh())->post(self::CART_WHATSAPP, ['cart_id' => $cartA->id]);
            $second = $this->actingAs($owner->fresh())->post(self::CART_WHATSAPP, ['cart_id' => $cartB->id]);

            $this->assertTrue($second->json('is_success'),
                'A nudge about a DIFFERENT basket was refused as a duplicate. The guard has '
                . 'collapsed two distinct actions, which stops the merchant nudging about anything '
                . 'else -- worse than the defect it replaces. Message: ' . (string) $second->json('message'));
        });

        $this->assertCount(2, $this->lastRecorder->recorded);
    }

    /**
     * A WISHLIST NUDGE AND A CART NUDGE ARE DIFFERENT ACTIONS even though the shipped wishlist
     * handler uses the `Abandon Cart` TEMPLATE name. The subject is what keeps them apart, and that
     * template name is deliberately left alone: it is what a merchant has configured.
     */
    public function test_a_wishlist_nudge_is_not_deduplicated_against_a_cart_nudge(): void
    {
        [$owner, $store, $customer] = $this->tenantWithShopper();
        $this->configureWhatsapp($owner, $store, 'Abandon Cart');

        $cart = $this->cartFor($customer);
        $wish = $this->wishlistFor($customer);

        $this->grantPermission($owner->fresh(), 'Abandon Wishlist');

        $this->withoutSending(function () use ($owner, $cart, $wish) {
            $this->actingAs($owner->fresh())->post(self::CART_WHATSAPP, ['cart_id' => $cart->id]);
            $second = $this->actingAs($owner->fresh())->post(self::WISH_WHATSAPP, ['wish_id' => $wish->id]);

            $this->assertTrue($second->json('is_success'),
                'A wishlist nudge was refused because a cart nudge had gone to the same shopper. '
                . 'Message: ' . (string) $second->json('message'));
        });

        $this->assertCount(2, $this->lastRecorder->recorded);

        $subjects = array_map(fn ($r) => $r->subject_type, $this->ledgerRows());
        sort($subjects);

        $this->assertSame(['cart', 'wishlist'], $subjects,
            'The two nudges are not recorded against different subjects.');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * 3 — another store is unaffected
     * ---------------------------------------------------------------------------------------------
     */

    /**
     * THE SAME PHONE NUMBER IN TWO STORES IS TWO SHOPPERS as far as this guard is concerned, and a
     * reservation by one must not refuse the other.
     *
     * This is the tenancy assertion. `marketing_nudge_sends` is not in `scoped_models`, so if any
     * query in `NudgeLedger` omitted its `store_id` predicate, store B's merchant would be told
     * their nudge was "already sent" because store A's merchant had nudged the same number.
     */
    public function test_one_stores_reservation_does_not_refuse_another_stores_nudge(): void
    {
        $shared = '963900001234';

        [$ownerA, $storeA, $customerA] = $this->tenantWithShopper($shared);
        [$ownerB, $storeB, $customerB] = $this->tenantWithShopper($shared);

        $this->configureWhatsapp($ownerA, $storeA, 'Abandon Cart');
        $this->configureWhatsapp($ownerB, $storeB, 'Abandon Cart');

        $cartA = $this->cartFor($customerA);
        $cartB = $this->cartFor($customerB);

        $this->withoutSending(function () use ($ownerA, $ownerB, $cartA, $cartB) {
            $first = $this->actingAs($ownerA->fresh())->post(self::CART_WHATSAPP, ['cart_id' => $cartA->id]);
            $this->assertTrue($first->json('is_success'),
                'Premise: store A\'s nudge must succeed. Message: ' . (string) $first->json('message'));

            $second = $this->actingAs($ownerB->fresh())->post(self::CART_WHATSAPP, ['cart_id' => $cartB->id]);

            $this->assertTrue($second->json('is_success'),
                'Store B was refused because store A had nudged the same phone number. A query in '
                . 'NudgeLedger is missing its store_id predicate, and marketing_nudge_sends is not '
                . 'in scoped_models so nothing adds one. Message: ' . (string) $second->json('message'));
        });

        $stores = array_map(fn ($r) => (int) $r->store_id, $this->ledgerRows());
        sort($stores);

        $this->assertSame([(int) $storeA->id, (int) $storeB->id], $stores,
            'The two sends are not recorded against the two different stores.');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * 4 — no cadence is enforced unless one is configured
     * ---------------------------------------------------------------------------------------------
     */

    /**
     * NO SENDING FREQUENCY SHIPS BY DEFAULT, asserted rather than assumed.
     *
     * The duplicate guard is correctness; a cadence is marketing policy, and the standing
     * instruction is not to invent an approved sending frequency. Without this assertion a default
     * interval could be added later and every other test here would still pass -- the duplicate
     * guard would mask it, because the second click is refused either way.
     */
    public function test_no_sending_frequency_is_configured_by_default(): void
    {
        $this->assertNull(config('marketing.nudge_min_interval_seconds'),
            'A minimum interval between nudges has been given a default value. How often a shopper '
            . 'may be messaged is the owner\'s decision; a quiet default is that decision taken by '
            . 'another route.');

        $this->assertFalse(app(\App\Services\Marketing\NudgeThrottle::class)->enabled(),
            'The cadence limb reports itself enabled with no interval configured.');
    }

    /**
     * AND WHEN AN INTERVAL IS CONFIGURED, IT REFUSES A DIFFERENT BASKET TOO -- which is the whole
     * difference between a cadence and a duplicate guard, and the reason the owner has to choose it.
     *
     * The interval here is a TEST value, not a recommendation: the capability is proved to work so
     * that setting it later is one config value and no code change.
     */
    public function test_a_configured_interval_refuses_a_different_basket_for_the_same_shopper(): void
    {
        config(['marketing.nudge_min_interval_seconds' => 3600]);

        [$owner, $store, $customer] = $this->tenantWithShopper();
        $this->configureWhatsapp($owner, $store, 'Abandon Cart');

        $cartA = $this->cartFor($customer);
        $cartB = $this->cartFor($customer);

        $this->withoutSending(function () use ($owner, $cartA, $cartB) {
            $this->actingAs($owner->fresh())->post(self::CART_WHATSAPP, ['cart_id' => $cartA->id]);

            $second = $this->actingAs($owner->fresh())->post(self::CART_WHATSAPP, ['cart_id' => $cartB->id]);

            $this->assertFalse($second->json('is_success'),
                'A cadence of one hour was configured and a second nudge to the same shopper about '
                . 'a different basket still went out, so the interval does nothing.');
            $this->assertStringContainsString('messaged recently', (string) $second->json('message'),
                'The cadence refusal does not say why. Message: ' . (string) $second->json('message'));
        });

        $this->assertCount(1, $this->lastRecorder->recorded);

        $outcomes = array_map(fn ($r) => $r->outcome, $this->ledgerRows());
        sort($outcomes);

        $this->assertSame(
            [MarketingNudgeSend::OUTCOME_SENT, MarketingNudgeSend::OUTCOME_THROTTLED],
            $outcomes,
            'The throttled nudge was not recorded. A ledger holding only successes cannot answer a '
            . 'merchant asking why their shopper never got a message.'
        );
    }

    /**
     * A THROTTLED NUDGE MUST NOT BURN THE IDEMPOTENCY KEY, or the throttle silently becomes a
     * permanent block: the quiet period ends and the merchant's next click is refused as a
     * duplicate of a send that never happened.
     *
     * This is why `NudgeGate` asks the cadence BEFORE reserving, and nothing else here would catch
     * the wrong order.
     */
    public function test_a_throttled_nudge_can_be_sent_once_the_interval_has_passed(): void
    {
        config(['marketing.nudge_min_interval_seconds' => 3600]);

        [$owner, $store, $customer] = $this->tenantWithShopper();
        $this->configureWhatsapp($owner, $store, 'Abandon Cart');

        $cartA = $this->cartFor($customer);
        $cartB = $this->cartFor($customer);

        $this->withoutSending(function () use ($owner, $cartA, $cartB) {
            $this->actingAs($owner->fresh())->post(self::CART_WHATSAPP, ['cart_id' => $cartA->id]);
            $this->actingAs($owner->fresh())->post(self::CART_WHATSAPP, ['cart_id' => $cartB->id]);
        });

        // The quiet period passes: push the recorded send back beyond the interval.
        MarketingNudgeSend::withoutGlobalScopes()
            ->where('outcome', MarketingNudgeSend::OUTCOME_SENT)
            ->update(['sent_at' => now()->subHours(3)]);

        $this->withoutSending(function () use ($owner, $cartB) {
            $retry = $this->actingAs($owner->fresh())->post(self::CART_WHATSAPP, ['cart_id' => $cartB->id]);

            $this->assertTrue($retry->json('is_success'),
                'After the interval passed, the nudge was still refused. The earlier THROTTLED '
                . 'refusal consumed the idempotency key, so the throttle has become a permanent '
                . 'block -- which is what asking the cadence before reserving exists to prevent. '
                . 'Message: ' . (string) $retry->json('message'));
        });

        $this->assertCount(1, $this->lastRecorder->recorded);
    }
}
