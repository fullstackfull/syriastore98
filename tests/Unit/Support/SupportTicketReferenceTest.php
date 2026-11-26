<?php

namespace Tests\Unit\Support;

use App\Models\SupportTicket;
use Tests\TestCase;

/**
 * TWO SHOPPERS WHO SUBMITTED IN THE SAME SECOND SHARED A TICKET NUMBER.
 * =================================================================================================
 *
 * `AccountProfileController::support_ticket_store()` wrote `$tickets->ticket_id = time();` and
 * `support_tickets.ticket_id` is `string(...)->nullable()` with NO unique index, so the collision was
 * silent: both tickets exist, both print `#1764000000` to the shopper and in the merchant's desk, and
 * the number -- the only handle either party has for a ticket in an e-mail or a phone call -- names
 * two different conversations. The vendored Helpdesk twin writes `time()` into
 * `unsignedBigInteger(...)->unique()`, so there the second insert throws; the first-party copy is the
 * one with the weaker constraint, and it is the editable one.
 *
 * WHY THE GENERATOR IS A NAMED METHOD AND NOT AN EXPRESSION AT THE WRITER. The property that matters
 * ("two tickets in one second get two numbers") cannot be asserted deterministically against the
 * clock: a test that creates two rows and compares them passes for the wrong reason whenever the
 * second happens to tick between them, and fails to kill a `time()` mutant for the same reason. Two
 * hundred draws can distinguish the two implementations in both directions -- `time()` yields at most
 * two distinct values across a fast loop, a reference carrying real entropy yields two hundred -- and
 * that is only expressible against a function.
 *
 * NO MIGRATION. A unique index on `support_tickets.ticket_id` is the structural fix and it is a
 * persistent migration over a column that already holds colliding values, which is an owner decision
 * (CLAUDE.md section 2). This closes the generator; the index is reported, not added.
 */
class SupportTicketReferenceTest extends TestCase
{
    public function test_two_hundred_references_drawn_in_one_loop_are_all_distinct(): void
    {
        $references = [];

        for ($i = 0; $i < 200; $i++) {
            $references[] = SupportTicket::newReference();
        }

        $this->assertCount(200, array_unique($references),
            'two tickets raised in the same second can share a number, which is how the desk came '
            . 'to have two conversations under one reference');
    }

    /**
     * The reference is still safe to print and to put in a file name.
     *
     * `support/edit.blade.php` assigns it to a JavaScript `filename` variable and the storefront
     * prints it as `#{{ $ticket->ticket_id }}`, so a separator, a quote or whitespace in it would be
     * a new sink rather than a fix. Asserted as a closed character class, not as an exact format, so
     * the shape may change without the property being lost.
     */
    public function test_a_reference_is_printable_and_contains_no_separator_quote_or_space(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $reference = SupportTicket::newReference();

            $this->assertMatchesRegularExpression('/\A[A-Za-z0-9-]{1,40}\z/', $reference,
                'a ticket reference carries a character that is not safe in a file name, a URL or '
                . 'an HTML attribute: ' . $reference);
        }
    }

    /**
     * It still STARTS with the clock, so the numbers a desk already holds sort beside the new ones.
     *
     * Not decoration: every existing row's reference is a bare Unix second, and a reference that
     * stopped being clock-led would order a six-month-old ticket after today's in any list sorted by
     * it. This is the one part of the old format that was doing something.
     */
    public function test_a_reference_is_still_led_by_the_clock(): void
    {
        $before = time();
        $reference = SupportTicket::newReference();
        $after = time();

        $leading = (int) explode('-', $reference)[0];

        $this->assertGreaterThanOrEqual($before, $leading,
            'the reference no longer begins with the current Unix second, so new references no '
            . 'longer sort beside the bare-second ones already in the table');
        $this->assertLessThanOrEqual($after, $leading);
    }
}
