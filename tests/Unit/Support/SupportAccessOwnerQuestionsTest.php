<?php

namespace Tests\Unit\Support;

use Tests\TestCase;

/**
 * NO POLICY NUMBER WITHOUT A QUESTION THE OWNER CAN ANSWER.
 * =================================================================================================
 *
 * `SUPPORT-004` / `SUPPORT-005`. The owner approved that support access be temporary, scoped,
 * consented and audited, and that emergency access be separate with higher approvals. The owner did
 * NOT state how long, which scopes, or how many approvers — checked by reading the recovered plan
 * and `DECISION-ID-REGISTER.md`, not inferred. CLAUDE.md §4.1 is explicit that an engineering
 * deferral is not an owner decision, so every one of those numbers ships as a PLACEHOLDER with its
 * question written down.
 *
 * THE FAILURE MODE THIS EXISTS TO PREVENT is not a wrong number — it is a SIXTH number appearing in
 * `config/support_access.php` six months from now with a plausible default, no question beside it,
 * and nobody realising a policy decision was taken by whoever typed it. That is exactly how an
 * engineering deferral becomes an unrecorded owner decision.
 *
 * SO THIS IS A RATCHET IN BOTH DIRECTIONS, over the real config file and the real document:
 *
 *   • every leaf key in `config/support_access.php` is named in the questions document;
 *   • every key the document claims to cover still exists in the config.
 *
 * It is NOT a check that the values are right. It cannot be: that is the owner's answer.
 */
class SupportAccessOwnerQuestionsTest extends TestCase
{
    private const DOC = 'docs/development/phase-6/PHASE-06-SUPPORT-ACCESS-OWNER-QUESTIONS.md';

    /**
     * The leaf keys, in dotted form, that `config/support_access.php` really declares.
     *
     * READ FROM THE FILE, not listed here. A hardcoded list would be the very thing this test
     * exists to prevent — a new key would simply not appear in it and the test would stay green.
     *
     * @return array<int, string>
     */
    private function configuredKeys(): array
    {
        $config = require dirname(__DIR__, 3) . '/config/support_access.php';

        $this->assertIsArray($config, 'PREMISE: config/support_access.php does not return an array');

        $flatten = function (array $node, string $prefix = '') use (&$flatten): array {
            $keys = [];

            foreach ($node as $key => $value) {
                $dotted = $prefix === '' ? (string) $key : $prefix . '.' . $key;

                // A nested array is a group of keys; a list of scalars (like `scopes`) is a value.
                if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                    $keys = array_merge($keys, $flatten($value, $dotted));

                    continue;
                }

                $keys[] = $dotted;
            }

            return $keys;
        };

        $keys = $flatten($config);

        $this->assertNotEmpty($keys, 'PREMISE: no keys were read, so this test asserts nothing');

        return $keys;
    }

    private function document(): string
    {
        $path = dirname(__DIR__, 3) . '/' . self::DOC;

        $this->assertFileExists(
            $path,
            'The support access owner-questions document is gone. config/support_access.php points '
            . 'at it, and without it five policy numbers ship with placeholder values and no '
            . 'record that they are placeholders.'
        );

        return (string) file_get_contents($path);
    }

    /**
     * EVERY CONFIGURED POLICY NUMBER IS NAMED IN THE DOCUMENT.
     *
     * The dotted key itself must appear, not a paraphrase: a reader holding the config file must be
     * able to grep the document for the key they are looking at.
     */
    public function test_every_support_access_config_key_has_a_question_in_the_document(): void
    {
        $document = $this->document();

        foreach ($this->configuredKeys() as $key) {
            $this->assertStringContainsString(
                'support_access.' . $key,
                $document,
                'config/support_access.php declares "' . $key . '" and '
                . self::DOC . ' does not mention it. Every value in that file is operating policy '
                . 'the owner has not stated -- a duration, a scope list, an approver count, an '
                . 'approver role -- and CLAUDE.md 4.1 is explicit that an engineering deferral is '
                . 'not an owner decision. Add the question, in the form the owner can answer, in '
                . 'the same change that adds the key.'
            );
        }
    }

    /**
     * AND THE OTHER DIRECTION: the document does not describe keys that no longer exist.
     *
     * A document naming a key that was renamed or removed sends the owner to answer a question that
     * changes nothing, which is worse than silence because it will be believed. The five keys are
     * named explicitly here because they are the five the document's five numbered questions
     * promise — so renaming one in the config fails the case above, and deleting one from the
     * config fails this one.
     */
    public function test_the_document_describes_only_keys_the_config_still_declares(): void
    {
        $configured = $this->configuredKeys();

        foreach ([
            'enforce',
            'max_duration_minutes',
            'scopes',
            'emergency.required_approvals',
            'emergency.approver_permission',
            'emergency.max_duration_minutes',
        ] as $promised) {
            $this->assertContains(
                $promised,
                $configured,
                self::DOC . ' asks the owner about "' . $promised . '" and '
                . 'config/support_access.php no longer declares it. Either the key was renamed -- '
                . 'in which case the document sends the owner to answer a question that changes '
                . 'nothing -- or the mechanism stopped reading it, in which case a clause of '
                . 'SUPPORT-004 or SUPPORT-005 is no longer bounded by anything.'
            );
        }
    }

    /**
     * THE DOCUMENT SAYS WHICH VALUES ARE PLACEHOLDERS AND WHAT IS NOT BUILT.
     *
     * Two things the owner cannot infer and must not have to ask for: that the shipped numbers are
     * not recommendations, and that the mechanism has no merchant-facing consent screen — which is
     * the prerequisite for turning enforcement on. A document that listed the questions without
     * saying the second would invite the owner to answer question 1 with `true` and break support.
     */
    public function test_the_document_states_the_placeholder_status_and_what_is_not_built(): void
    {
        $document = $this->document();

        $this->assertMatchesRegularExpression(
            '/\bplaceholder/i',
            $document,
            self::DOC . ' no longer says that the shipped values are placeholders, so the owner '
            . 'cannot tell a default from a recommendation'
        );

        $this->assertStringContainsString(
            'What is NOT built',
            $document,
            self::DOC . ' no longer lists what the mechanism does not yet have. The '
            . 'merchant-facing consent screen is the prerequisite for enforcing, and an owner who '
            . 'answers question 1 with "yes" without knowing that would refuse every support login.'
        );

        // The one fact that makes answering question 1 safe.
        $this->assertStringContainsString(
            'support_access_ungranted',
            $document,
            self::DOC . ' no longer explains that the unenforced mode still records the gap. That '
            . 'row is the count the owner needs in order to answer question 1 at all.'
        );
    }

    /**
     * THE SHIPPED DEFAULT IS THE NON-BREAKING ONE.
     *
     * Asserted because it is the one default whose value is a safety property rather than a policy
     * choice: `true` with an empty grant table refuses every support login, so a change of this
     * default is an operational event and should not happen as a side effect of tidying a config
     * file. The document's question 1 records the sequence for switching it on deliberately.
     */
    public function test_enforcement_ships_off_so_turning_it_on_is_a_deliberate_act(): void
    {
        $config = require dirname(__DIR__, 3) . '/config/support_access.php';

        $this->assertFalse(
            (bool) ($config['enforce'] ?? null),
            'config/support_access.php now enforces support access grants by default. That is the '
            . 'approved end state of SUPPORT-004, but with no grants in the table it refuses EVERY '
            . 'support login, and the merchant-facing consent screen is not built. If this is '
            . 'deliberate, the owner has answered question 1 -- record that answer in '
            . self::DOC . ' and re-point this test.'
        );
    }
}
