<?php

namespace App\Support\Operations;

use InvalidArgumentException;

/**
 * OPS-003 — HOW BAD AN INCIDENT IS SAID TO BE, AND NOTHING ABOUT WHAT THAT OBLIGES.
 * =================================================================================================
 *
 * THE WHOLE DESIGN OF THIS CLASS IS A LINE IT DOES NOT CROSS, so it is worth stating before the
 * constants: a severity here is a DESCRIPTION OF IMPACT and carries no response time, no escalation
 * path, no notification and no promise.
 *
 * The reason is not caution, it is that the other thing is somebody else's to decide. "Critical
 * means somebody is woken up within fifteen minutes" is an operational commitment by Store Syria to
 * its merchants; it implies an on-call rota, a contact list and a cost. Engineering can build the
 * mechanism that would carry such a rule, and has. Choosing the rule is the owner's — `CLAUDE.md`
 * section 2 reserves "material changes to prices, payments, stock or business rules" to them, and a
 * response commitment is a business rule.
 *
 * If a response target is attached to any of these later, it is attached BECAUSE SOMEBODY DECIDED,
 * and `PlatformIncidentPolicy` is where that decision is recorded. Until then this vocabulary lets
 * an operator say how bad something is without the product pretending to know what follows.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────────
 * WHY FOUR LEVELS AND NOT A NUMBER AN OPERATOR TYPES
 * ──────────────────────────────────────────────────────────────────────────────────────────────────
 *
 * A free-text severity is unsortable, unfilterable and unanswerable: two operators write "high" and
 * "High" and the log can no longer tell you how many bad weeks the platform has had. A fixed set is
 * the only form in which the question "was this worse than last time" has an answer.
 *
 * The four are the impact distinctions this product can actually tell apart, and each one is
 * described by WHAT IS HAPPENING TO SOMEBODY rather than by how urgent it feels — because "urgent"
 * is the judgement that belongs to the policy, and "merchants cannot take money" is a fact.
 *
 * THE ORDER IS PART OF THE DECLARATION. `rank()` exists so a list can be sorted worst-first without
 * any screen inventing its own ordering, and so the set can gain a level in the middle later without
 * every caller's comparison silently changing meaning.
 */
final class IncidentSeverity
{
    /** Shoppers cannot buy, or merchants cannot take money, across the platform. */
    public const CRITICAL = 'critical';

    /** A whole capability is unavailable or wrong for many tenants, and buying still works. */
    public const MAJOR = 'major';

    /** Something is degraded, slow or failing intermittently, and there is a way around it. */
    public const MINOR = 'minor';

    /**
     * Recorded for the history, with no live impact.
     *
     * THIS LEVEL IS WHY THE SET IS FOUR AND NOT THREE. Most of what an operations log is worth
     * recording has no impact at the time — a near miss, a failed job that retried successfully, a
     * disk that was filling and was extended. Without a level for it, those are either not
     * recorded at all or are recorded as `minor`, which makes `minor` mean nothing.
     */
    public const INFORMATIONAL = 'informational';

    /**
     * Worst first, which is the order a list of live incidents is useful in.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::CRITICAL, self::MAJOR, self::MINOR, self::INFORMATIONAL];
    }

    public static function exists(string $severity): bool
    {
        return in_array($severity, self::all(), true);
    }

    /**
     * 1 is worst. Declared through the order above rather than hard-coded, so adding a level in
     * the middle cannot leave two levels sharing a rank.
     */
    public static function rank(string $severity): int
    {
        $index = array_search($severity, self::all(), true);

        if ($index === false) {
            throw new InvalidArgumentException('Unknown incident severity: ' . $severity);
        }

        return $index + 1;
    }

    public static function label(string $severity): string
    {
        return match ($severity) {
            self::CRITICAL => __('Critical'),
            self::MAJOR => __('Major'),
            self::MINOR => __('Minor'),
            self::INFORMATIONAL => __('Informational'),
            default => throw new InvalidArgumentException('Unknown incident severity: ' . $severity),
        };
    }

    /**
     * WHAT IS HAPPENING TO SOMEBODY, which is the only thing this vocabulary claims to say.
     *
     * Each sentence describes impact. None of them says how quickly anything must be answered or
     * who must be told: see the class docblock, and `PlatformIncidentPolicy` for the questions that
     * would settle that.
     */
    public static function description(string $severity): string
    {
        return match ($severity) {
            self::CRITICAL => __('Shoppers cannot buy, or merchants cannot take money, across the platform.'),
            self::MAJOR => __('A whole capability is unavailable or wrong for many tenants, and buying still works.'),
            self::MINOR => __('Something is degraded, slow or failing intermittently, and there is a way around it.'),
            self::INFORMATIONAL => __('Recorded for the history, with no live impact at the time.'),
            default => throw new InvalidArgumentException('Unknown incident severity: ' . $severity),
        };
    }
}
