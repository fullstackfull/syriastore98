<?php

namespace App\Services\Marketing;

use App\Support\Marketing\NudgeDecision;
use Carbon\CarbonImmutable;

/**
 * GAP-219 — THE CADENCE LIMB, BUILT AND SWITCHED OFF, because the number is not engineering's.
 * =================================================================================================
 *
 * GAP-219 has two halves and they are not the same kind of question:
 *
 *   1. "Did the merchant just send this exact nudge twice?" is a CORRECTNESS question. It has one
 *      right answer, needs no business input, and costs real money when wrong. `NudgeLedger`
 *      answers it by identity, with no clock involved, and it is ON.
 *   2. "How often may a shopper be messaged?" is a MARKETING POLICY. Seven days is a defensible
 *      answer; so is 24 hours; so is "never twice about the same basket". Each is a different
 *      product, and picking one here would be inventing an approved sending frequency — which the
 *      standing instruction forbids in those words.
 *
 * SO THE CAPABILITY IS COMPLETE AND THE NUMBER IS ABSENT. `minIntervalSeconds()` returns null
 * unless `config('marketing.nudge_min_interval_seconds')` is set, and with null this class returns
 * `allow()` without so much as a query. Setting it is one config value and no code change; until
 * somebody sets it, no shopper is refused for recency and no figure anywhere implies one was.
 *
 * WHY NOT A DEFAULT OF SEVEN DAYS "TO BE SAFE". Because a quiet default is still a decision, and a
 * conservative one is not automatically the safe one: a merchant whose cart-recovery nudges stop
 * working loses sales and has no way to see why. An absent rule that is documented as absent is
 * honest; a rule nobody chose, enforced silently, is the thing this project keeps finding and
 * fixing.
 *
 * A ZERO OR NEGATIVE VALUE IS TREATED AS UNSET rather than as "no interval", because
 * `MARKETING_NUDGE_MIN_INTERVAL_SECONDS=0` in an `.env` almost certainly means "off" and reading it
 * as "allow everything" would be the same answer by accident.
 */
final class NudgeThrottle
{
    public function __construct(private readonly NudgeLedger $ledger) {}

    /**
     * The configured minimum interval in seconds, or null when no cadence has been approved.
     *
     * @return positive-int|null
     */
    public function minIntervalSeconds(): ?int
    {
        $raw = config('marketing.nudge_min_interval_seconds');

        if ($raw === null || $raw === '' || $raw === false) {
            return null;
        }

        $seconds = (int) $raw;

        return $seconds > 0 ? $seconds : null;
    }

    /** True when a cadence rule is in force. Exposed so a surface can say so rather than guess. */
    public function enabled(): bool
    {
        return $this->minIntervalSeconds() !== null;
    }

    /**
     * Would a cadence rule refuse this nudge right now?
     *
     * RETURNS `allow()` WHEN NO INTERVAL IS CONFIGURED, and does not touch the database in that
     * case. That ordering matters: a disabled rule must cost nothing, or somebody will disable the
     * whole class for performance and take the duplicate guard with it.
     */
    /*
     * NAMED `check()`, NOT `decide()`, AND THAT IS A SECURITY-CENSUS CONSTRAINT RATHER THAN TASTE.
     *
     * `ConsentLedgerCensusTest::test_the_gate_is_called_only_from_the_listed_seams` treats ANY
     * `->decide(` inside `app/Services/Marketing/` as a possible new caller of
     * `MarketingSendGate`, because a `$this->gate->decide()` there would not name the class. That
     * census is how "the consent gate is wired in exactly two seams" stays a statement about named
     * files instead of an assumption, and this class really did trip it.
     *
     * The wrong fixes were both available and both rejected: adding `NudgeGate` to `GATE_CALLERS`
     * would assert it is a consent seam when it is not, and loosening the census's pattern would
     * weaken a control to accommodate a method name. Renaming costs nothing and `check()` is the
     * better word anyway -- `decide()` is the consent gate's verb.
     */
    public function check(int $storeId, string $channel, string $contactHash, ?CarbonImmutable $now = null): NudgeDecision
    {
        $interval = $this->minIntervalSeconds();

        if ($interval === null) {
            return NudgeDecision::allow();
        }

        $lastSentAt = $this->ledger->lastSentAt($storeId, $channel, $contactHash);

        if ($lastSentAt === null) {
            return NudgeDecision::allow();
        }

        $now = $now ?? CarbonImmutable::now();
        $elapsed = (int) $lastSentAt->diffInSeconds($now, false);

        /*
         * A NEGATIVE ELAPSED MEANS THE LAST SEND IS IN THE FUTURE, which happens when a clock moves
         * or a fixture is written ahead. Refusing then would lock a merchant out for as long as the
         * skew lasts, so it is treated as "not yet elapsed" only up to the interval and never
         * longer: `retryAfter` is clamped at the interval itself.
         */
        if ($elapsed >= $interval) {
            return NudgeDecision::allow();
        }

        return NudgeDecision::throttled($lastSentAt, min($interval, $interval - $elapsed));
    }
}
