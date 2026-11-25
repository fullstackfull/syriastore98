<?php

namespace Tests\Feature\Support;

use App\Models\Store;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\StripsSourceComments;

/**
 * THE STATUS CONTROL ON THE MERCHANT TICKET SCREEN, IN THE STATE WHERE IT WAS DEAD.
 * =================================================================================================
 *
 * `resources/views/support/edit.blade.php` decided three things separately and the three disagreed,
 * measured on that file before this change:
 *
 *     the `dropdown-toggle` CLASS    in_array($status, ['open', 'In Progress', 'solved'])
 *     the `data-bs-toggle` ATTRIBUTE in_array($status, ['Solved'])
 *     whether the MENU is rendered   in_array($status, ['open', 'In Progress', 'solved'])
 *
 * `SupportTicket::ticket_status_change()` writes `'Solved'` with a capital S, so the lowercase
 * `'solved'` in two of those lists matches NOTHING this application ever writes. The consequence
 * for a solved ticket: no menu, but a button still carrying `data-bs-toggle="dropdown"` from the
 * unconditional copy on the next line, `aria-haspopup="true"` and a hard-coded
 * `aria-expanded="true"`. A screen reader announced an already-expanded menu that was not on the
 * page, and a mouse user got a button that opened nothing.
 *
 * A SECOND, INDEPENDENT DEFECT IN THE SAME FILE'S SCRIPT. The success toast lived in the AJAX
 * `complete:` handler, which jQuery fires after success AND after failure. So a refusal produced two
 * toasts -- the error, then "Ticket status updated successfully" -- and an HTTP failure skipped
 * `success:` entirely and produced ONLY the success toast. The merchant was told the status had
 * changed when nothing had been written. Same class as ORD-12.
 *
 * A THIRD DEFECT, FOUND BY THIS TEST FAILING FOR A REASON THAT WAS NOT THE DROPDOWN. The first run
 * of the cases below returned `Attempt to read property "name" on null (View:
 * resources/views/support/edit.blade.php)` -- HTTP 500 -- because the fixture ticket had no
 * `customer_id` and the view read `$ticket->UserData->name` unguarded. `customers` is BOTH
 * soft-deleted and store-scoped, so that relation answers null for a shopper who deleted their
 * account and for one the resolved scope cannot see; either made the merchant's own ticket screen a
 * 500. The same shape sits on each reply: `SupportConversion::replyBy()` ends in `->first()` and so
 * returns null when the sender's row is gone, and it runs once per reply -- one orphaned reply broke
 * the entire conversation rather than hiding one name. Both are null-safe now, falling back to the
 * `Unknown` key that already exists in both language files.
 *
 * WHY THE SCRIPT HALF IS ASSERTED STRUCTURALLY AND NOT BY DRIVING IT. The toast is jQuery running
 * in a browser; a Feature test gets the HTML. So the assertion is on the SOURCE -- with comments
 * stripped, because CLAUDE.md records a census being satisfied by prose about the thing it measures
 * four times in one day, and the block above mentions both `complete:` and the success string. It is
 * scoped to the `$.ajax` call, not the whole file, for the same reason.
 */
class TicketStatusControlTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    private const VIEW = 'resources/views/support/edit.blade.php';

    private User $actor;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = $this->makeMerchant();
        $this->store = $this->makeStore($owner, ['default_language' => 'en']);
        $owner->current_store = $this->store->id;
        $owner->save();
        (new User)->flushCache();

        $this->grantPermission(User::find($owner->id), 'Replay Support Ticket');
        $this->actor = User::find($owner->id);

        $this->assertTrue($this->actor->isAbleTo('Replay Support Ticket'),
            'PREMISE: the actor cannot open the edit screen, so every case here is vacuous.');
    }

    private function screenFor(string $status): string
    {
        $ticket = SupportTicket::forceCreate([
            'ticket_id' => 'T-' . bin2hex(random_bytes(4)),
            'title' => 'Damascus delivery',
            'description' => 'x',
            'status' => $status,
            'store_id' => $this->store->id,
            'created_by' => (int) $this->actor->id,
        ]);

        $response = $this->actingAs($this->actor)->get(route('support_ticket.edit', $ticket->id));
        $response->assertOk();

        return (string) $response->getContent();
    }

    /**
     * The heading block that carries the ticket's own identity: the customer name and the age.
     *
     * Anchored on the age marker rather than on a class name, because the name and the age are
     * printed by the same line and the age is the only text unique to it.
     */
    private function ticketIdentityBlock(string $body): string
    {
        $at = strpos($body, 'ago');

        $this->assertNotFalse($at,
            'the ticket identity block (name + age) is not on the page, so this assertion has no '
            . 'block to scope to');

        $open = strrpos(substr($body, 0, $at), '<div');

        $this->assertNotFalse($open, 'the identity block is not inside an element');

        return substr($body, $open, ($at - $open) + 40);
    }

    /** Everything inside the status button's own element, so a match elsewhere cannot satisfy this. */
    private function statusButton(string $body): string
    {
        $at = strpos($body, 'order_status_btn');

        $this->assertNotFalse($at, 'the status button is not on the page at all');

        $open = strrpos(substr($body, 0, $at), '<button');
        $this->assertNotFalse($open, 'the status button is not inside a <button>');

        $close = strpos($body, '</button>', $open);

        return substr($body, $open, $close - $open);
    }

    // ---------------------------------------------------------------------------------------
    //  A SOLVED TICKET: NO MENU, SO NO CONTROL THAT CLAIMS ONE
    // ---------------------------------------------------------------------------------------

    public function test_a_solved_ticket_offers_no_menu_and_does_not_claim_to_have_one(): void
    {
        $body = $this->screenFor('Solved');
        $button = $this->statusButton($body);

        $this->assertStringContainsString('Status', $button,
            'PREMISE: this is not the status button, so the assertions below prove nothing.');

        $this->assertStringNotContainsString('data-bs-toggle', $button,
            'a solved ticket renders a dropdown toggle with no menu to open');
        $this->assertStringNotContainsString('dropdown-toggle', $button,
            'a solved ticket still looks like a dropdown');
        $this->assertStringNotContainsString('aria-haspopup', $button,
            'a screen reader is still told this button opens a menu');
        $this->assertStringNotContainsString('aria-expanded', $button,
            'a screen reader is still told the state of a menu that does not exist');

        $this->assertStringNotContainsString('Set Ticket status', $body,
            'the menu is rendered for a status with no transitions');
    }

    // ---------------------------------------------------------------------------------------
    //  THE TWO STATES THAT DO HAVE TRANSITIONS
    // ---------------------------------------------------------------------------------------

    public function test_an_open_ticket_offers_both_transitions_and_one_working_toggle(): void
    {
        $body = $this->screenFor('open');
        $button = $this->statusButton($body);

        $this->assertSame(1, substr_count($button, 'data-bs-toggle'),
            'the toggle attribute appears ' . substr_count($button, 'data-bs-toggle')
            . ' times on one button; duplicated attributes are what the two disagreeing conditions produced');
        $this->assertStringContainsString('dropdown-toggle', $button);
        $this->assertStringContainsString('aria-expanded="false"', $button,
            'a closed menu is still announced as expanded');

        $this->assertStringContainsString('data-value="In Progress"', $body);
        $this->assertStringContainsString('data-value="Solved"', $body);
    }

    public function test_an_in_progress_ticket_offers_only_the_solved_transition(): void
    {
        $body = $this->screenFor('In Progress');
        $button = $this->statusButton($body);

        $this->assertSame(1, substr_count($button, 'data-bs-toggle'));
        $this->assertStringContainsString('data-value="Solved"', $body);
        $this->assertStringNotContainsString('data-value="In Progress"', $body,
            'the screen offers a transition to the status the ticket is already in');
    }

    /**
     * A STATUS THE DROPDOWN HAS NO TRANSITIONS FOR IS NOT A BROKEN CONTROL EITHER.
     *
     * `support_tickets.status` is a nullable string with no constraint, and the storefront writes
     * 'New Ticket' among others, so a value outside the three this screen knows is ordinary rather
     * than hypothetical. It gets a plain button, not a toggle with nothing behind it.
     */
    public function test_an_unknown_status_renders_a_plain_button_rather_than_a_dead_toggle(): void
    {
        $button = $this->statusButton($this->screenFor('New Ticket'));

        $this->assertStringNotContainsString('data-bs-toggle', $button);
        $this->assertStringNotContainsString('aria-haspopup', $button);
    }

    // ---------------------------------------------------------------------------------------
    //  A TICKET WHOSE PEOPLE ARE GONE
    // ---------------------------------------------------------------------------------------

    /** Found by this file's own first run: an unguarded `->name` was a 500, not a blank. */
    public function test_a_ticket_with_no_customer_renders_rather_than_answering_500(): void
    {
        $body = $this->screenFor('open');

        /*
         * SCOPED TO THE BLOCK THAT HOLDS THE NAME, not the whole page. A page-wide
         * `assertStringContainsString('Unknown', $body)` answers "does this word appear anywhere",
         * which any other `Unknown` in the chrome would satisfy -- the hazard CLAUDE.md records for
         * censuses, here in an ordinary assertion. Independent review flagged it. The case could not
         * pass before the fix either way, because the unguarded dereference was a 500 caught by
         * `screenFor()`'s `assertOk()`; scoping makes the assertion mean what it says.
         */
        $card = $this->ticketIdentityBlock($body);

        $this->assertStringContainsString('Unknown', $card,
            'the customer name is neither shown nor replaced; a ticket with no customer row renders '
            . 'a blank where a reader expects a name');
    }

    public function test_a_reply_whose_sender_is_gone_renders_rather_than_answering_500(): void
    {
        $ticket = SupportTicket::forceCreate([
            'ticket_id' => 'T-' . bin2hex(random_bytes(4)),
            'title' => 'Aleppo returns', 'description' => 'x', 'status' => 'open',
            'store_id' => $this->store->id, 'created_by' => (int) $this->actor->id,
        ]);

        /*
         * THE MERCHANT-SIDE BRANCH, chosen for a reason worth recording. `replyBy()` branches on
         * `sender`: 'user' reads `customer_id`, anything else reads `sender` as a `users` id. The
         * customer branch CANNOT be driven with a dangling id -- `support_conversions.customer_id`
         * is a foreign key to `customers` with ON DELETE CASCADE, so SQLite refuses the insert (my
         * first attempt at this fixture did exactly that). The merchant branch reads an id out of a
         * plain text column with no constraint, so a deleted staff account leaves precisely this
         * row behind, and it reaches the identical `->first()` -> null -> `->name` path.
         *
         * `attachments` is `text` NOT NULL with no default -- the migration declares no
         * `->nullable()` -- so an omitted value is a constraint violation rather than an empty one.
         */
        \App\Models\SupportConversion::forceCreate([
            'ticket_id' => $ticket->id,
            'description' => 'A reply from an account that no longer exists.',
            'sender' => '99999999',
            'customer_id' => null,
            'store_id' => $this->store->id,
            'attachments' => '',
        ]);

        $response = $this->actingAs($this->actor)->get(route('support_ticket.edit', $ticket->id));

        $response->assertOk();
        $this->assertStringContainsString('A reply from an account that no longer exists.',
            (string) $response->getContent(),
            'the reply body is missing, so the page rendered without the conversation it exists to show');
    }

    // ---------------------------------------------------------------------------------------
    //  THE TOAST, ASSERTED ON THE SOURCE
    // ---------------------------------------------------------------------------------------

    /** The `$.ajax` call's own text, comments stripped — not the whole file. */
    private function ajaxCall(): string
    {
        $source = $this->sourceWithoutComments(self::VIEW);
        $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source);

        $at = strpos($source, "route('support_ticket.status.change'");
        $this->assertNotFalse($at, 'the status-change XHR is no longer in this view');

        $open = strrpos(substr($source, 0, $at), '$.ajax(');
        $this->assertNotFalse($open, 'the route is not inside an $.ajax call');

        $call = substr($source, $open);
        $end = strpos($call, '});');

        return substr($call, 0, $end === false ? strlen($call) : $end);
    }

    public function test_the_success_toast_is_not_fired_from_the_complete_handler(): void
    {
        $call = $this->ajaxCall();

        $completeAt = strpos($call, 'complete:');
        $this->assertNotFalse($completeAt, 'PREMISE: there is no complete handler, so this cannot regress');

        $complete = substr($call, $completeAt);

        $this->assertStringNotContainsString('Ticket status updated successfully', $complete,
            'the success toast is fired from `complete:`, which runs after a FAILURE too -- so a '
            . 'refused or failed status change still tells the merchant it worked');
        $this->assertStringNotContainsString("'success'", $complete,
            'a success toast is still raised unconditionally after the request completes');
    }

    public function test_the_success_toast_is_fired_only_from_the_success_path(): void
    {
        $call = $this->ajaxCall();

        $this->assertSame(1, substr_count($call, 'Ticket status updated successfully'),
            'the success message appears ' . substr_count($call, 'Ticket status updated successfully')
            . ' times in this call; it belongs in exactly one place');

        $successAt = strpos($call, 'success: function');
        $errorAt = strpos($call, 'error: function');

        $this->assertNotFalse($successAt);
        $this->assertNotFalse($errorAt, 'a transport failure (403, 422, 500) is still handled by nothing');

        $messageAt = strpos($call, 'Ticket status updated successfully');

        $this->assertGreaterThan($successAt, $messageAt,
            'the success message is raised before the success handler');
        $this->assertLessThan($errorAt, $messageAt,
            'the success message sits in or after the error handler');
    }
}
