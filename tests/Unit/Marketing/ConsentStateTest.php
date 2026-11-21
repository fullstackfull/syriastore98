<?php

namespace Tests\Unit\Marketing;

use App\Support\Marketing\ConsentEvent;
use App\Support\Marketing\ConsentState;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * THE CONSENT STATE MACHINE, EXHAUSTIVELY. D8 / MKT-008.
 *
 * Pure -- no database, no framework -- so every one of the sixteen (state, event) pairs is asserted by
 * name rather than sampled. The properties that matter are the three that a refactor could break
 * without any other test noticing:
 *
 *   1. NOTHING EXCEPT AN EXPLICIT GRANT EVER REACHES `granted`. In particular `none` is not `granted`,
 *      and lifting a suppression lands on `withdrawn`, not `granted`.
 *   2. `suppressed` OUTRANKS A GRANT. A checkbox ticked at checkout must not undo a complaint.
 *   3. ONLY `granted` PERMITS MARKETING.
 */
class ConsentStateTest extends TestCase
{
    /** @return array<string, array{0: ConsentState, 1: ConsentEvent, 2: ConsentState}> */
    public static function transitions(): array
    {
        $n = ConsentState::None;
        $g = ConsentState::Granted;
        $w = ConsentState::Withdrawn;
        $s = ConsentState::Suppressed;

        return [
            'none + granted' => [$n, ConsentEvent::Granted, $g],
            'none + withdrawn' => [$n, ConsentEvent::Withdrawn, $w],
            'none + suppressed' => [$n, ConsentEvent::Suppressed, $s],
            'none + lifted' => [$n, ConsentEvent::SuppressionLifted, $n],

            'granted + granted' => [$g, ConsentEvent::Granted, $g],
            'granted + withdrawn' => [$g, ConsentEvent::Withdrawn, $w],
            'granted + suppressed' => [$g, ConsentEvent::Suppressed, $s],
            'granted + lifted' => [$g, ConsentEvent::SuppressionLifted, $g],

            'withdrawn + granted' => [$w, ConsentEvent::Granted, $g],
            'withdrawn + withdrawn' => [$w, ConsentEvent::Withdrawn, $w],
            'withdrawn + suppressed' => [$w, ConsentEvent::Suppressed, $s],
            'withdrawn + lifted' => [$w, ConsentEvent::SuppressionLifted, $w],

            'suppressed + granted' => [$s, ConsentEvent::Granted, $s],
            'suppressed + withdrawn' => [$s, ConsentEvent::Withdrawn, $s],
            'suppressed + suppressed' => [$s, ConsentEvent::Suppressed, $s],
            'suppressed + lifted' => [$s, ConsentEvent::SuppressionLifted, $w],
        ];
    }

    #[DataProvider('transitions')]
    public function test_every_state_event_pair(ConsentState $from, ConsentEvent $event, ConsentState $expected): void
    {
        $this->assertSame($expected, $from->after($event));
    }

    public function test_the_table_covers_every_pair(): void
    {
        $this->assertCount(
            count(ConsentState::cases()) * count(ConsentEvent::cases()),
            self::transitions(),
            'A state or an event was added and the transition table was not extended.'
        );
    }

    public function test_only_an_explicit_grant_can_move_anyone_into_granted(): void
    {
        foreach (ConsentState::cases() as $from) {
            if ($from === ConsentState::Granted) {
                continue; // already granted: nothing to reach
            }

            foreach (ConsentEvent::cases() as $event) {
                if ($event === ConsentEvent::Granted) {
                    continue;
                }

                $this->assertNotSame(
                    ConsentState::Granted,
                    $from->after($event),
                    $from->value.' + '.$event->value.' must not produce granted'
                );
            }
        }
    }

    public function test_a_suppression_outranks_a_grant_and_only_a_lift_clears_it(): void
    {
        $state = ConsentState::Suppressed;

        $this->assertSame(ConsentState::Suppressed, $state->after(ConsentEvent::Granted));
        $this->assertSame(ConsentState::Suppressed, $state->after(ConsentEvent::Withdrawn));
        $this->assertSame(ConsentState::Withdrawn, $state->after(ConsentEvent::SuppressionLifted),
            'lifting a suppression must NOT grant consent; the person must opt in afresh');
    }

    public function test_only_granted_permits_marketing(): void
    {
        foreach (ConsentState::cases() as $state) {
            $this->assertSame($state === ConsentState::Granted, $state->permitsMarketing(), $state->value);
        }
    }
}
