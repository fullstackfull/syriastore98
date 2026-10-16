<?php

namespace Tests\Feature\Lynomia;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Models\LynomiaPlatformNotice;
use Workdo\LynomiaChat\app\Services\AccountProvisioner;
use Workdo\LynomiaChat\app\Services\PlatformNotifier;
use Workdo\LynomiaChat\app\Services\PlatformRecipients;
use Workdo\LynomiaChat\app\Support\LinkState;

/**
 * P6E. ONE OPERATIONAL NOTICE TO ONE MERCHANT, ONCE.
 *
 * Two properties carry the weight here.
 *
 * SAY IT ONCE. There is no idempotency mechanism anywhere on Lynomia's send path -- no key
 * on message create, no unique index on messages.source_id, and the campaign guard's own
 * docblock records that re-running a half-sent campaign messages people twice. So the
 * guarantee is made on this side, by a unique index, and these examples are what say it
 * actually holds.
 *
 * REFUSE RATHER THAN GUESS AT AN ADDRESS. Store Syria has no verified merchant phone number:
 * users.mobile is nullable, unindexed, has no format rule, and is seeded with placeholders --
 * self-registration writes an empty string and Super Admin store creation hardcodes
 * 7878787878. A WhatsApp message to a number like that reaches whoever holds it, who is a
 * stranger, about a merchant's account.
 */
class LynomiaPlatformNoticeTest extends TestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLynomiaHarness();
        config()->set('lynomia.platform_workspace_account_id', 9001);
        config()->set('lynomia.platform_workspace_sender_user_id', 501);
        config()->set('lynomia.platform_workspace_inbox_id', 31);
    }

    private function connected(string $mobile = '963900111222'): array
    {
        [$owner, $store] = $this->entitledTenant();
        $owner->forceFill(['mobile' => $mobile])->save();
        $this->fakeLynomia(['account_id' => 7001]);
        $link = app(AccountProvisioner::class)->provision($store, $owner)->fresh();

        return [$owner->fresh(), $store, $link];
    }

    private function notifier(): PlatformNotifier
    {
        return app(PlatformNotifier::class);
    }

    private function notify($link, string $key = 'integration-broken:1', ?string $kind = null)
    {
        return $this->notifier()->notify(
            $link, $kind ?? 'integration_recovery', 'integration_broken', $key, ['1' => 'Shop'], $this->operator()
        );
    }

    // ===================================================== say it once

    public function test_an_operational_notice_is_sent_and_recorded(): void
    {
        [, , $link] = $this->connected();

        $notice = $this->notify($link);

        $this->assertSame(LynomiaPlatformNotice::SENT, $notice->status);
        $this->assertSame('963900111222', $notice->recipient_phone);
        $this->assertNotNull($notice->sent_at);
    }

    /**
     * THE SECOND ATTEMPT SENDS NOTHING. The unique index on dedupe_key is the mechanism --
     * not a check with a window in it -- so two operators acting on the same incident
     * cannot both message the merchant.
     */
    public function test_the_same_occasion_is_never_sent_twice(): void
    {
        [, , $link] = $this->connected();
        $this->notify($link);
        $before = count(Http::recorded());

        $again = $this->notify($link);

        $this->assertSame(LynomiaPlatformNotice::SENT, $again->status);
        $this->assertSame(1, LynomiaPlatformNotice::count());
        $this->assertCount($before, Http::recorded(), 'the second attempt must reach Lynomia not at all');
    }

    public function test_a_different_occasion_is_a_different_notice(): void
    {
        [, , $link] = $this->connected();

        $this->notify($link, 'integration-broken:1');
        $this->notify($link, 'integration-broken:2');

        $this->assertSame(2, LynomiaPlatformNotice::count());
    }

    /**
     * A LOST ANSWER IS NOT A FAILURE AND IS NOT RETRIED. The request may have reached Meta;
     * nothing downstream would recognise a repeat. For an operational notice the safe
     * failure is one that might not arrive, not one that arrives twice.
     */
    public function test_a_lost_answer_is_left_for_an_operator_rather_than_retried(): void
    {
        [, , $link] = $this->connected();
        $this->lynomiaScript['fail'] = [
            'POST /api/v1/accounts/9001/conversations/77/messages' => Http::response(['error' => 'gateway'], 503),
        ];

        $notice = $this->notify($link);

        $this->assertSame(LynomiaPlatformNotice::INDETERMINATE, $notice->status);
        $this->assertTrue(LynomiaPlatformNotice::needingAttention()->exists());
    }

    /** A refusal is settled: Lynomia said no, and will say no again. */
    public function test_a_refusal_is_recorded_as_failed(): void
    {
        [, , $link] = $this->connected();
        $this->lynomiaScript['fail'] = [
            'POST /api/v1/accounts/9001/conversations/77/messages' => Http::response(['error' => 'bad template'], 422),
        ];

        $this->assertSame(LynomiaPlatformNotice::FAILED, $this->notify($link)->status);
    }

    // ===================================================== refuse rather than guess

    public function test_a_merchant_with_no_number_on_record_is_not_messaged(): void
    {
        [, , $link] = $this->connected('');
        Http::fake();

        $notice = $this->notify($link);

        $this->assertSame(LynomiaPlatformNotice::FAILED, $notice->status);
        $this->assertSame('no_phone_on_record', $notice->failure_reason);
        Http::assertNothingSent();
    }

    /** Super Admin store creation hardcodes this. It is not a phone number. */
    public function test_the_placeholder_number_is_refused(): void
    {
        [, , $link] = $this->connected('7878787878');
        Http::fake();

        $this->assertSame('placeholder_phone', $this->notify($link)->failure_reason);
        Http::assertNothingSent();
    }

    public function test_a_number_that_is_not_a_number_is_refused(): void
    {
        [, , $link] = $this->connected('12');
        Http::fake();

        $this->assertSame('phone_not_usable', $this->notify($link)->failure_reason);
        Http::assertNothingSent();
    }

    // ===================================================== who may be told

    public function test_a_decommissioned_installation_is_not_messaged(): void
    {
        [, , $link] = $this->connected();
        $link->forceFill(['state' => LinkState::DECOMMISSIONED])->save();
        Http::fake();

        $this->assertSame('installation_not_reachable', $this->notify($link->fresh())->failure_reason);
        Http::assertNothingSent();
    }

    /**
     * A SUSPENDED MERCHANT IS STILL TOLD. The hold is usually why they need to hear from the
     * platform, and refusing to tell them would make it silent.
     */
    public function test_a_suspended_installation_is_still_reachable(): void
    {
        [, , $link] = $this->connected();
        $link->forceFill(['state' => LinkState::SUSPENDED])->save();

        $this->assertSame(LynomiaPlatformNotice::SENT, $this->notify($link->fresh())->status);
    }

    public function test_a_store_that_changed_hands_is_not_messaged(): void
    {
        [, $store, $link] = $this->connected();
        $someoneElse = $this->merchant();
        $store->forceFill(['created_by' => $someoneElse->id])->save();
        Http::fake();

        $this->assertSame('owner_changed', $this->notify($link)->failure_reason);
        Http::assertNothingSent();
    }

    // ===================================================== authorised kinds only

    public function test_only_an_authorised_notice_kind_may_be_sent(): void
    {
        [, , $link] = $this->connected();

        $this->expectException(\InvalidArgumentException::class);
        $this->notify($link, 'promo-blast:1', 'marketing');
    }

    public function test_every_declared_kind_is_one_the_owner_authorised(): void
    {
        $this->assertSame(
            ['onboarding', 'integration_recovery', 'security', 'service', 'support'],
            LynomiaPlatformNotice::KINDS
        );
    }

    // ===================================================== safety when unconfigured

    public function test_nothing_is_sent_when_the_platform_workspace_is_not_set_up(): void
    {
        [, , $link] = $this->connected();
        config()->set('lynomia.platform_workspace_inbox_id', null);
        Http::fake();

        $notice = $this->notify($link);

        // `failed`, not `indeterminate`: an unconfigured workspace is not a lost answer, it
        // is a refusal that will be the same refusal until an operator sets it up.
        $this->assertSame(LynomiaPlatformNotice::FAILED, $notice->status);
        Http::assertNothingSent();
    }

    // ===================================================== the resolver, directly

    public function test_the_resolver_never_takes_an_address_from_a_caller(): void
    {
        $reflection = new \ReflectionClass(PlatformRecipients::class);
        $resolve = $reflection->getMethod('resolve');

        $this->assertCount(1, $resolve->getParameters());
        $this->assertSame('link', $resolve->getParameters()[0]->getName());
    }

    public function test_the_notice_records_who_asked_for_it(): void
    {
        [, , $link] = $this->connected();
        $operator = $this->operator();

        $notice = $this->notifier()->notify(
            $link, 'security', 'integration_broken', 'sec:1', [], $operator
        );

        $this->assertSame((int) $operator->id, (int) $notice->requested_by);
        $this->assertFalse($notice->automated);
    }
}
