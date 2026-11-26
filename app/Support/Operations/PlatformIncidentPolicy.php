<?php

namespace App\Support\Operations;

/**
 * OPS-003's OTHER HALF: THE ALERTING POLICY, WHICH NOBODY HAS DECIDED, STATED AS CODE.
 * =================================================================================================
 *
 * "إدارة الحوادث والتنبيهات والتشخيص" — incident, ALERT and diagnostics management. The incident
 * record is built (`PlatformIncidentLog`, `IncidentState`, `/platform/incidents`) and the
 * diagnostics are measured (`SaasPlatformResources`, `SaasPlatformOperations`). The ALERT is not,
 * and this class is the reason, written down where an engineer looking for it will find it rather
 * than only in a document that may not be read.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────────
 * WHY A CLASS AND NOT A COMMENT
 * ──────────────────────────────────────────────────────────────────────────────────────────────────
 *
 * Three reasons, and the third is the one that decides it:
 *
 *   1. The operator screens SAY on their face that nothing alerts anyone. That sentence has to stay
 *      true, and the only way to keep a sentence true is to make something fail when it stops being
 *      so. `PlatformIncidentPolicyTest` asserts that `isAlertingConfigured()` is false and that no
 *      threshold is declared anywhere — so the day somebody wires an alert, the test that says
 *      "this platform alerts nobody" goes red and the sentence gets changed with the code.
 *
 *   2. The questions are specific, and a specific unanswered question is worth far more than "needs
 *      a decision". They are below, each with what it would take to answer it and what it would
 *      cost to answer it wrongly.
 *
 *   3. THE DEFAULT THAT WOULD OTHERWISE GET PICKED IS THE REAL RISK. A threshold is the easiest
 *      thing in the world to invent — 85% of a disk, 1,000 jobs, five failures an hour — and the
 *      moment one is in the code it is quoted back as the platform's rule, including to merchants.
 *      `SaasPlatformResources`'s docblock already records this workstream's standing instruction to
 *      refuse to pick one. This class is that refusal in a form that cannot be overlooked.
 *
 * NOTHING HERE SENDS ANYTHING, READS A CHANNEL OR HOLDS A RECIPIENT. It has no state and no
 * configuration key, deliberately: a `config('incidents.thresholds')` that is empty today is an
 * invitation to fill it in without a decision, which is the thing being prevented.
 */
final class PlatformIncidentPolicy
{
    /**
     * WHETHER ANYTHING ON THIS PLATFORM ALERTS ANYBODY. Measured, not configured.
     *
     * It answers false because it is false: there is no notification, no channel, no recipient and
     * no scheduled check anywhere in this product that watches an operational figure and tells a
     * human. Two operator screens state that in words, and this is the assertion that keeps those
     * words honest.
     *
     * A HARD-CODED `false` IS THE POINT, not a shortcut. The alternative — reading a config key —
     * would make this method answer "whatever is configured", which is exactly the question nobody
     * has decided. When alerting is built, this method changes along with the sentences on the two
     * screens and the test that pins them, in one deliberate change.
     */
    public static function isAlertingConfigured(): bool
    {
        return false;
    }

    /**
     * THE DECISIONS THIS NEEDS, each one named precisely enough to be answerable in a sentence.
     *
     * Keyed by a short id so a ledger row, a document and a screen can all refer to the same
     * question. Every entry says what the question is, what answering it enables, and what it costs
     * to answer it by default instead of deliberately.
     *
     * @return array<string, array{question: string, enables: string, cost_of_guessing: string}>
     */
    public static function openDecisions(): array
    {
        return [
            'thresholds' => [
                'question' => 'At what measured value does each figure on the operations screens become an incident — queue depth, oldest waiting job, failed jobs per hour, disk used, database connections in use?',
                'enables' => 'An incident opened by the platform itself rather than by an operator noticing. The figures are already measured and already carry their source and period; what is missing is the line.',
                'cost_of_guessing' => 'A threshold in the code is read as the platform\'s rule. Too low and every nightly import is a critical incident, which trains an operator to ignore the log; too high and the first real outage is not in it. Neither is recoverable by changing the number later, because the log already contains the wrong history.',
            ],
            'recipients' => [
                'question' => 'Who is told when an incident is opened, and through what channel — in-product only, email, SMS, or a chat integration?',
                'enables' => 'Notification at all. The incident record and its audit trail are built and are useless to somebody who is not looking at the screen.',
                'cost_of_guessing' => 'A recipient list is personal data and a channel is a cost. Mailing an address somebody typed is also an exfiltration path: the same reasoning `analytics_report_schedules` records about its own recipient column, where the address is taken from the authenticated user and is not fillable.',
            ],
            'response_targets' => [
                'question' => 'Does any severity carry a response commitment, and if so what is it?',
                'enables' => 'A severity that means something operationally rather than descriptively, and a report on whether the platform met it.',
                'cost_of_guessing' => 'A response time is a promise to merchants. `IncidentSeverity` deliberately attaches none, and inventing one would make the product state a commitment the business has not made.',
            ],
            'merchant_visibility' => [
                'question' => 'Does a merchant ever see a platform incident — a status page, a banner, nothing?',
                'enables' => 'Merchants knowing why their store is slow without asking support, which is the commonest reason an incident log exists at all.',
                'cost_of_guessing' => 'Publishing an incident is the platform telling its customers it is broken, in its own words, permanently. Who may write that text, and whether it is published at all, is a business decision and a reputational one. The record built here is OPERATOR-ONLY for exactly that reason, and widening it is not an engineering change.',
            ],
            'retention' => [
                'question' => 'How long is an incident and its audit trail kept?',
                'enables' => 'A retention job. There is none, so the answer today is "for ever", which is a decision by default and is named here as one.',
                'cost_of_guessing' => 'Deleting an operational history is irreversible, and the audit trail has no update or delete path on purpose. A retention rule has to be chosen rather than arrived at.',
            ],
        ];
    }

    /**
     * THE SENTENCE BOTH OPERATOR SCREENS PRINT, in one place so the two cannot drift apart.
     *
     * Not a disclaimer. An operator who reads a page full of real figures and no alerts will
     * reasonably assume something somewhere is watching them, and nothing is.
     */
    public static function noAlertingNotice(): string
    {
        return __('No threshold is applied to any figure here and nothing on this platform sends an alert: what counts as too deep a queue, too full a disk or too many failures, and who should be told, are decisions that have not been taken.');
    }
}
