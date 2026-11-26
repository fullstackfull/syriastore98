<?php

namespace Tests\Feature\Support;

use App\Models\Customer;
use App\Models\Store;
use App\Models\SupportConversion;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE MERCHANT'S REPLY DOOR STORED THE CLIENT'S OWN FILE NAME — P1-SEC-71, MERCHANT SIDE.
 * =================================================================================================
 *
 * `SUPPORT-001` / `SUPPORT-003`. P1-SEC-71 fixed exactly this on the STOREFRONT paths and scoped
 * itself there: measured on this branch, `AccountProfileController` calls
 * `PlatformUploadPolicy::storedName()` at SIX upload sites, and
 * `SupportTicketController::conversion_store()` — the merchant's reply — called
 * `$file->getClientOriginalName()` and passed it straight to the uploader. The `SUPPORT-003` review
 * of 2026-11-25 found it, named it in the gap register, and left it for "another workstream".
 *
 * -------------------------------------------------------------------------------------------------
 *  WHY IT MATTERS AFTER THE FOLDER WAS ALREADY FIXED
 * -------------------------------------------------------------------------------------------------
 *
 * The same change that found this had already made the folder `uploads/<TICKET's store>/reply_tickets`
 * instead of the actor's, so the cross-TENANT collision is closed. What remains is a collision
 * INSIDE one store's reply folder, and it is not cosmetic:
 *
 *   • the merchant replies to shopper A's ticket, attaching `invoice.pdf`;
 *   • the merchant replies to shopper B's ticket, attaching a different `invoice.pdf`;
 *   • the second write lands on the first one's path, and shopper A's reply row — which stores the
 *     path — now serves shopper B's document to shopper A.
 *
 * Both parties are customers of the same store, so `StoreScope` cannot help: this is a
 * customer-to-customer disclosure within a tenant, and the shopper reads it through their own
 * ticket thread. `storedName()` appends the time and eight random hex digits, so a name cannot be
 * collided with or guessed in advance.
 *
 * -------------------------------------------------------------------------------------------------
 *  WHAT THIS FILE ASSERTS, AND WHAT IT DELIBERATELY DOES NOT
 * -------------------------------------------------------------------------------------------------
 *
 * It asserts the NAME the door hands the uploader, by driving the real route and reading what
 * landed on a faked disk. It does NOT assert that the uploader refuses to replace — that is
 * `PlatformUploadPolicy::nonReplacingName()`'s job, it applies only to shopper requests by design
 * (a merchant replacing their own gallery image under its own name is the product), and a merchant
 * reply is a panel request. So the control here is the generated NAME, which is the same control
 * the six storefront sites use.
 */
class MerchantReplyAttachmentNameTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->grantPermission($this->merchant, 'Replay Support Ticket');
        (new User)->flushCache();

        /*
         * THE DISK IS `theme`, AND THE FIRST DRAFT OF THIS FILE FAKED THE WRONG ONE.
         *
         * `Utility::keyWiseUpload_file()` writes through `Storage::disk('theme')` on the local
         * storage setting -- not `local` and not `public`. Faking those two left the premise
         * assertion below reading an EMPTY disk while the conversation row happily recorded a path,
         * so every name assertion would have been measuring nothing. That is why the premise is
         * there; it is CLAUDE.md's "a test that passes for the wrong reason" caught by an assertion
         * rather than by review.
         */
        Storage::fake('theme');
    }

    /** A real `customers` row: `support_tickets.customer_id` carries a FOREIGN KEY. */
    private function shopper(string $name): Customer
    {
        return Customer::forceCreate([
            'first_name' => $name, 'last_name' => 'Shopper',
            'email' => uniqid() . '@example.test', 'password' => bcrypt('not-a-real-credential'),
            'store_id' => $this->store->id, 'status' => 1,
            'billable_id' => $this->store->created_by, 'billable_type' => User::class,
        ]);
    }

    private function ticketFor(Customer $customer): SupportTicket
    {
        $ticket = new SupportTicket;
        $ticket->forceFill([
            'ticket_id' => SupportTicket::newReference(),
            'customer_id' => (int) $customer->id,
            'title' => 'Where is my order',
            'description' => 'It has not arrived.',
            'status' => 'open',
            'store_id' => (int) $this->store->id,
            'created_by' => (int) $this->merchant->id,
        ])->save();

        return $ticket;
    }

    /** Every file name anywhere on either faked disk. */
    private function storedNames(): array
    {
        $names = [];

        foreach (Storage::disk('theme')->allFiles() as $path) {
            $names[] = basename($path);
        }

        sort($names);

        return $names;
    }

    private function reply(SupportTicket $ticket, string $clientName): void
    {
        $this->actingAs($this->merchant)->post(
            route('conversion.store', $ticket->id),
            [
                'reply_description' => 'Here is the document.',
                'reply_attachments' => [UploadedFile::fake()->create($clientName, 8, 'application/pdf')],
            ]
        );
    }

    /**
     * THE CORE ASSERTION: the stored name is not the client's.
     *
     * Keyed on the SHAPE rather than on an exact string, because the name carries the time and
     * eight random hex digits and cannot be predicted — which is the property being asserted.
     */
    public function test_the_stored_reply_attachment_is_not_named_by_the_client(): void
    {
        $ticket = $this->ticketFor($this->shopper('Amal'));

        $this->reply($ticket, 'invoice.pdf');

        $names = $this->storedNames();

        $this->assertNotEmpty(
            $names,
            'PREMISE: nothing was stored at all, so this case cannot tell a safe name from an '
            . 'unsafe one. The route, the permission or the uploader refused before writing.'
        );

        $this->assertNotContains(
            'invoice.pdf',
            $names,
            'the merchant reply door stored the client\'s own file name verbatim. Two replies on '
            . 'two different shoppers\' tickets attaching the same name are ONE file, and the first '
            . 'shopper\'s reply row then serves the second shopper\'s document -- a '
            . 'customer-to-customer disclosure inside one store, which StoreScope cannot prevent. '
            . 'The six storefront upload sites in AccountProfileController all use '
            . 'PlatformUploadPolicy::storedName(); this door did not.'
        );

        $this->assertCount(1, $names);
        $this->assertMatchesRegularExpression(
            '/^invoice_\d{10}_[0-9a-f]{8}\.pdf$/',
            $names[0],
            'the stored name does not have PlatformUploadPolicy::storedName()\'s shape '
            . '(base_time_8hex.ext), so it is not the shared helper that produced it'
        );
    }

    /**
     * THE CONSEQUENCE, DRIVEN END TO END: two shoppers, one name, two files.
     *
     * This is the assertion that would have caught the defect without knowing anything about
     * `storedName()`, and it is the one that states the harm.
     */
    public function test_two_replies_attaching_the_same_name_are_two_distinct_files(): void
    {
        $this->reply($this->ticketFor($this->shopper('Amal')), 'invoice.pdf');
        $this->reply($this->ticketFor($this->shopper('Bashir')), 'invoice.pdf');

        $names = $this->storedNames();

        $this->assertCount(
            2,
            $names,
            'two replies on two different shoppers\' tickets, each attaching a file called '
            . 'invoice.pdf, produced ' . count($names) . ' file(s) instead of 2 -- one shopper\'s '
            . 'attachment replaced the other\'s, and the earlier reply row now points at the later '
            . 'shopper\'s document. Stored: ' . implode(', ', $names)
        );

        $this->assertNotSame($names[0], $names[1]);
    }

    /**
     * AND THE ROW RECORDS THE NAME THAT WAS ACTUALLY WRITTEN.
     *
     * A door that generates a safe name and then persists the client's one is no better than the
     * defect: the file is safe and unreachable, and the row points at a path that either does not
     * exist or belongs to somebody else. The old code kept BOTH — `$imageName` was passed to the
     * uploader AND re-used afterwards as `'reply_tickets/'.$imageName` — so this is not
     * hypothetical.
     *
     * `withoutGlobalScopes()` on the read: `SupportConversion` is in `scoped_models` and really
     * filters, so a plain `SupportConversion::first()` answers against whichever tenant happens to
     * be resolved rather than against the table. CLAUDE.md records that this cost a real false
     * reading.
     */
    public function test_the_conversation_row_points_at_the_file_that_was_written(): void
    {
        $ticket = $this->ticketFor($this->shopper('Carine'));

        $this->reply($ticket, 'receipt.pdf');

        $row = SupportConversion::withoutGlobalScopes()
            ->where('ticket_id', $ticket->id)
            ->first();

        $this->assertNotNull($row, 'PREMISE: no conversation row was written at all');

        $recorded = json_decode((string) $row->attachments, true) ?: [];
        $this->assertNotEmpty($recorded, 'the row records no attachment although one was stored');

        $stored = $this->storedNames();

        foreach ($recorded as $path) {
            $this->assertContains(
                basename((string) $path),
                $stored,
                'the conversation row records ' . $path . ' but no such file was written. The door '
                . 'stored the file under a generated name and then persisted the client\'s name, so '
                . 'the shopper\'s thread links to a path that is not there -- or to another '
                . 'shopper\'s file.'
            );
        }
    }
}
