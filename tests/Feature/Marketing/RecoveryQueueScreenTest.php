<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingRecoveryCandidate;
use App\Models\Setting;
use App\Support\Marketing\RecoveryCandidates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * MKT-007 — THE QUEUE SCREEN: who may read it, whose rows it shows, and what it promises.
 *
 * THE PROMISE IS THE PART WORTH TESTING. A screen that lists recoverable baskets reads as "these
 * have been chased" unless it says otherwise, and a merchant who believed that would stop chasing
 * them by hand. So the screen is asserted to state, in words, that nothing is sent automatically —
 * and to offer no control that could send.
 *
 * `Abandon Cart` IS THE GATE, reused from the existing one-click recovery rather than invented: the
 * same people, the same activity, seen as a list. A new permission name would be worse than reusing
 * this one, because a name absent from `Utility::addNewData()` cannot exist as a row and evaluates
 * false for everybody, the tenant owner included.
 */
final class RecoveryQueueScreenTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const URI = '/recovery-queue';

    private const POST = '/recovery-queue/ages';

    private const PERMISSION = 'Abandon Cart';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
    }

    private function refused($response): bool
    {
        return $response->status() === 403 || ($response->isRedirect() && session()->has('error'));
    }

    private function merchantOn($store, $merchant)
    {
        $merchant->current_store = $store->id;
        $merchant->save();

        return $merchant->fresh();
    }

    private function candidate($store, string $kind = RecoveryCandidates::KIND_BASKET, int $subjectId = 7): MarketingRecoveryCandidate
    {
        $row = new MarketingRecoveryCandidate();
        $row->store_id = $store->id;
        $row->kind = $kind;
        $row->subject_type = RecoveryCandidates::KINDS[$kind]['subject_type'];
        $row->subject_id = $subjectId;
        $row->customer_id = null;
        $row->detected_at = now()->subDay();
        $row->send_state = MarketingRecoveryCandidate::STATE_HELD;
        $row->held_reason = MarketingRecoveryCandidate::HELD_CONSENT_OFF;
        $row->save();

        return $row;
    }

    private function stored(int $storeId, string $name): ?string
    {
        $value = Setting::withoutGlobalScopes()
            ->where('store_id', $storeId)
            ->where('name', $name)
            ->value('value');

        return $value === null ? null : (string) $value;
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The promise
     * ---------------------------------------------------------------------------------------------
     */

    public function test_the_screen_says_nothing_is_sent_automatically(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->candidate($store);

        $response = $this->actingAs($this->merchantOn($store, $merchant))->get(self::URI);

        $response->assertOk();
        $response->assertSee(__('Nothing is sent automatically'), false);
        $response->assertSee(__('Consent enforcement is off, so no promotional message may be sent automatically.'), false);
    }

    public function test_the_screen_offers_no_control_that_could_send(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->candidate($store);

        $response = $this->actingAs($this->merchantOn($store, $merchant))->get(self::URI);

        $response->assertOk();

        /*
         * THE RATCHET ON THE OWNER'S INSTRUCTION. Automatic promotional sending stays disabled until
         * consent enforcement is approved and active; a "send all" button added to this screen would
         * route around the gate AND around the deliberate backlog release, and it would look like a
         * feature.
         */
        foreach (['carts.emailsend', 'wishlist.message.send', 'recovery-queue/send', 'release'] as $sender) {
            $response->assertDontSee($sender, false);
        }
    }

    public function test_a_held_row_shows_the_reason_it_was_held(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->candidate($store);

        $response = $this->actingAs($this->merchantOn($store, $merchant))->get(self::URI);

        $response->assertOk();

        /*
         * ASSERTED INSIDE THE TABLE BODY, and that is not fussiness -- it is the difference between
         * this test working and not. A plain `assertSee('Consent enforcement is off')` passed with
         * the ROW's reason mutated away, because the card at the top of the page says "Consent
         * enforcement is off, so no promotional message may be sent automatically" and the row's
         * text is a prefix of it. The page-wide assertion was answering with the card.
         */
        $body = $response->getContent();
        $start = strpos($body, '<tbody>');
        $end = strpos($body, '</tbody>');

        $this->assertNotFalse($start, 'the queue table has no body, so the row assertion below would be vacuous');
        $this->assertNotFalse($end);

        $rows = substr($body, $start, $end - $start);

        $this->assertStringContainsString(__('Not sent'), $rows);
        $this->assertStringContainsString(__('Consent enforcement is off'), $rows,
            'the ROW does not say why it was held, so a merchant cannot tell a held candidate from '
            .'one that was simply never looked at');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The door
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_signed_out_visitor_cannot_open_it(): void
    {
        $response = $this->get(self::URI);

        $this->assertTrue($response->isRedirect());
        $this->assertNotSame(401, $response->status(),
            'a 401 forces a logout in the shipped Flutter client; this codebase redirects or answers 403');
    }

    public function test_a_staff_member_without_the_permission_is_refused(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);
        $staff->current_store = $store->id;
        $staff->save();

        $this->assertFalse($staff->fresh()->isAbleTo(self::PERMISSION),
            'Premise: the staff member holds no abandoned-cart permission.');

        $this->assertTrue($this->refused($this->actingAs($staff->fresh())->get(self::URI)));
    }

    public function test_the_seeded_permission_admits_a_staff_member(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $holder = $this->makeStaff($merchant);
        $this->grantPermission($holder, self::PERMISSION);
        $holder->current_store = $store->id;
        $holder->save();

        $this->actingAs($holder->fresh())->get(self::URI)->assertOk();
    }

    public function test_an_operator_who_has_selected_no_store_is_refused(): void
    {
        $operator = $this->makePlatformAdmin(['current_store' => null]);

        $this->assertTrue($this->refused($this->actingAs($operator)->get(self::URI)),
            'the platform operator was shown a store\'s recovery queue with no store selected');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Whose rows
     * ---------------------------------------------------------------------------------------------
     */

    public function test_another_stores_candidates_are_not_listed(): void
    {
        [$merchant, $mine] = $this->makeTenant();
        [, $theirs] = $this->makeTenant();

        $this->candidate($theirs, RecoveryCandidates::KIND_PAYMENT, 999999);

        $response = $this->actingAs($this->merchantOn($mine, $merchant))->get(self::URI);

        $response->assertOk();
        $response->assertSee(__('Nothing is waiting for follow-up.'), false);
        $response->assertDontSee('999999', false);
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The ages
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_merchant_sets_an_age_and_the_detector_reads_it(): void
    {
        [$merchant, $mine] = $this->makeTenant();
        [, $theirs] = $this->makeTenant();

        $response = $this->actingAs($this->merchantOn($mine, $merchant))->post(self::POST, [
            // Posted and ignored: the store comes from the session, never the body.
            'store_id' => $theirs->id,
            RecoveryCandidates::KIND_BASKET => '48',
            RecoveryCandidates::KIND_PAYMENT => '',
        ]);

        $this->assertFalse($this->refused($response));

        $configured = RecoveryCandidates::configuredFor((int) $mine->id);

        $this->assertSame([RecoveryCandidates::KIND_BASKET => 48], $configured);

        $this->assertNull(
            $this->stored((int) $theirs->id, RecoveryCandidates::KINDS[RecoveryCandidates::KIND_BASKET]['setting']),
            'a `store_id` in the request body wrote the age into another tenant'
        );
    }

    public function test_clearing_an_age_switches_that_kind_off(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $signedIn = $this->merchantOn($store, $merchant);

        $this->actingAs($signedIn)->post(self::POST, [RecoveryCandidates::KIND_BASKET => '48']);
        $this->actingAs($signedIn)->post(self::POST, [RecoveryCandidates::KIND_BASKET => '']);

        $this->assertSame([], RecoveryCandidates::configuredFor((int) $store->id),
            'clearing the field left detection on, so a merchant cannot turn it off');
    }

    public function test_an_absurd_or_zero_age_is_refused(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $signedIn = $this->merchantOn($store, $merchant);

        foreach (['0', '-1', '100000', 'soon'] as $bad) {
            $response = $this->actingAs($signedIn)->post(self::POST, [RecoveryCandidates::KIND_BASKET => $bad]);

            $this->assertTrue($this->refused($response),
                'an age of '.var_export($bad, true).' was accepted');
        }

        $this->assertSame([], RecoveryCandidates::configuredFor((int) $store->id));
    }

    public function test_a_staff_member_without_the_permission_cannot_set_an_age(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);
        $staff->current_store = $store->id;
        $staff->save();

        $response = $this->actingAs($staff->fresh())->post(self::POST, [
            RecoveryCandidates::KIND_BASKET => '48',
        ]);

        $this->assertTrue($this->refused($response));
        $this->assertSame([], RecoveryCandidates::configuredFor((int) $store->id));
    }

    public function test_the_form_shows_the_age_the_merchant_already_set(): void
    {
        [$merchant, $store] = $this->makeTenant();

        Setting::putForStore(
            RecoveryCandidates::KINDS[RecoveryCandidates::KIND_BASKET]['setting'],
            '72',
            (int) $store->id,
            (int) $merchant->id
        );

        $response = $this->actingAs($this->merchantOn($store, $merchant))->get(self::URI);

        $response->assertOk();
        $response->assertSee('value="72"', false);
    }
}
