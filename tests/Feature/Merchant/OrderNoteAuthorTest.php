<?php

namespace Tests\Feature\Merchant;

use App\Models\Order;
use App\Models\OrderNote;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2-04-6 / WS E — `order_notes.created_by`: WHO WROTE THIS NOTE, and who did not.
 * ============================================================================================
 *
 * The owner approved an **optional** author on NEW notes (§1, 2026-10-28) with one condition
 * stated twice: *"old notes must not be attributed to the current employee or to a presumed
 * author"*. That condition, not the feature, is what most of this file tests.
 *
 * THE DEFECT THIS ROW CLOSES WAS THE OPPOSITE OF A MISSING FEATURE. The notes list rendered
 * `{{ __(' by ') }}{{ Auth::user()->name }}` — the name of whoever was LOOKING at the page — so
 * one note read "by Amina" to Amina and "by Khalid" to Khalid. It was removed in the P2-04-6
 * correctness pass and `OrderDetailTest::test_a_note_is_not_attributed_to_the_person_reading_the_page`
 * has guarded its return since. **That test must keep passing after this change**, and it does:
 * naming the AUTHOR is not naming the READER, and the two cases are only distinguishable by a
 * test where the author and the reader are different people. Every positive test below uses two
 * different users for exactly that reason.
 *
 * FOUR KINDS OF ROW MUST SHOW NO AUTHOR, and each is a real case rather than a hypothetical:
 *   1. a note written before the column existed;
 *   2. a SYSTEM note — `OrderNote::order_note_data()` composes "Stock levels reduced…" from
 *      event data at 30+ call sites, most inside payment-gateway callbacks with no
 *      authenticated user at all;
 *   3. the two generated "Order created" notes the manual-order writers emit, whose text is
 *      composed rather than typed;
 *   4. a note whose recorded author can no longer be named — the row is gone, or they are
 *      outside the viewer's tenant.
 */
class OrderNoteAuthorTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $author;
    private User $reader;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->makeMerchant();
        $this->store = $this->makeStore($this->owner, ['default_language' => 'en']);

        // TWO DIFFERENT PEOPLE, always. A test where the author and the reader are the same
        // user cannot tell "the note names its author" from "the note names whoever is
        // looking", which is the defect this row closes.
        $this->author = $this->makeStaff($this->owner, ['name' => 'Amina Author']);
        $this->reader = $this->makeStaff($this->owner, ['name' => 'Zzz Readerson']);

        foreach ([$this->owner, $this->author, $this->reader] as $u) {
            $u->current_store = $this->store->id;
            $u->save();

            foreach (['Show Order', 'Manage Order', 'Manage Order Note', 'Create Order Note'] as $p) {
                $this->grantPermission(User::find($u->id), $p);
            }
        }

        $this->owner = User::find($this->owner->id);
        $this->author = User::find($this->author->id);
        $this->reader = User::find($this->reader->id);
    }

    // =======================================================================================
    //  THE PREMISE
    // =======================================================================================

    public function test_the_column_is_nullable_with_no_default_and_no_cascade(): void
    {
        $this->assertTrue(Schema::hasColumn('order_notes', 'created_by'),
            'premise: the migration has run');

        $column = collect(Schema::getColumns('order_notes'))->firstWhere('name', 'created_by');

        $this->assertNotNull($column);
        $this->assertTrue($column['nullable'], 'a note with no recorded author must be legal');
        $this->assertNull($column['default'],
            'A DEFAULT WOULD ATTRIBUTE EVERY SYSTEM NOTE TO SOMEBODY. `order_note_data()` writes '
            . 'from payment-gateway callbacks where no user is authenticated at all.');

        $authorFk = collect(Schema::getForeignKeys('order_notes'))
            ->first(fn ($fk) => in_array('created_by', $fk['columns'], true));

        $this->assertNull($authorFk,
            'NO FOREIGN KEY, DELIBERATELY. `order_notes` already cascades from `customers` and '
            . '`orders`; a third cascade from `users` would DELETE a merchant\'s order notes when '
            . 'an employee account is removed — destroying commercial records to tidy up a user '
            . 'row. `test_removing_the_author_does_not_remove_the_note` is the behavioural half.');
    }

    // =======================================================================================
    //  WHAT IS ATTRIBUTED
    // =======================================================================================

    public function test_a_typed_note_records_its_author(): void
    {
        $order = $this->makeOrder($this->store, ['product_order_id' => 'N-1']);

        $response = $this->actingAs($this->author)->post('order-note', [
            'order_id' => $order->id,
            'note' => 'Customer asked us to hold delivery until Friday',
            'note_type' => 'private_note',
        ]);

        $note = OrderNote::withoutGlobalScopes()->where('order_id', $order->id)->first();

        $this->assertNotNull($note,
            'premise: the note was written. Status was ' . $response->status());
        $this->assertSame((int) $this->author->id, (int) $note->created_by);
    }

    public function test_a_typed_note_through_the_admin_app_records_its_author(): void
    {
        $order = $this->makeOrder($this->store, ['product_order_id' => 'N-2']);

        $token = $this->author->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/orders/notes/create?store_id=' . $this->store->id, [
                'order_id' => $order->id,
                'note' => 'Rang the customer, no answer',
                'note_type' => 'private_note',
            ]);

        $note = OrderNote::withoutGlobalScopes()->where('order_id', $order->id)->first();

        $this->assertNotNull($note,
            'premise: the api wrote a note. ' . $response->status() . ' ' . $response->getContent());
        $this->assertSame((int) $this->author->id, (int) $note->created_by);
    }

    /**
     * THE API RESPONSE IS NOT WIDENED. It lists its keys explicitly, so adding `created_by`
     * would change the payload the shipped app parses. Recorded as parity gap G9 rather than
     * absorbed here.
     */
    public function test_the_admin_app_note_response_is_not_widened(): void
    {
        $order = $this->makeOrder($this->store, ['product_order_id' => 'N-3']);
        $token = $this->author->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/orders/notes/create?store_id=' . $this->store->id, [
                'order_id' => $order->id,
                'note' => 'x',
                'note_type' => 'private_note',
            ]);

        $note = $response->json('data.note') ?? $response->json('note') ?? [];

        $this->assertNotEmpty($note, 'premise: the response carries a note object');
        $this->assertArrayNotHasKey('created_by', $note);
        $this->assertSame(
            ['id', 'order_id', 'customer_id', 'note', 'note_type', 'status', 'created_at'],
            array_keys($note),
            'the order-note payload keeps exactly the keys the shipped app parses'
        );
    }

    // =======================================================================================
    //  WHAT IS NOT ATTRIBUTED — the owner's condition
    // =======================================================================================

    /**
     * AN AUTHOR CANNOT BE MASS-ASSIGNED, which is why `created_by` is not in `$fillable`.
     *
     * This property was discovered by a bite test rather than designed: removing the key from
     * `$fillable` left every other test green, because both human writers assign the property
     * directly. That made the key unnecessary -- and once unnecessary, keeping it would have been
     * a pure forgery surface, since four `OrderNote::create` sites write system notes by mass
     * assignment and `order_note_data()` composes more from gateway callbacks.
     */
    public function test_an_author_cannot_be_mass_assigned(): void
    {
        $order = $this->makeOrder($this->store, ['product_order_id' => 'MASS-1']);

        $note = OrderNote::create([
            'order_id' => $order->id,
            'customer_id' => null,
            'notes' => 'A note whose create() call tried to name an author',
            'note_type' => 'private_note',
            'status' => '',
            'store_id' => $this->store->id,
            'created_by' => $this->author->id,
        ]);

        $this->assertNotNull(OrderNote::withoutGlobalScopes()->find($note->id),
            'premise: the note itself was written, so the absence below is about the key only');

        $this->assertNull(
            DB::table('order_notes')->where('id', $note->id)->value('created_by'),
            'MASS ASSIGNMENT MUST NOT BE ABLE TO NAME AN AUTHOR. The only way to record one is '
            . 'the explicit property assignment the two human writers make, in handlers that have '
            . 'already established who the caller is.'
        );
    }

    /**
     * A SYSTEM note. `order_note_data()` is the composer used by every gateway callback; it
     * never sees a user, and nothing may supply one on its behalf.
     */
    public function test_a_system_note_has_no_author(): void
    {
        $order = $this->makeOrder($this->store, ['product_order_id' => 'N-4']);

        $this->actingAs($this->author);

        OrderNote::order_note_data([
            'order_id' => $order->id,
            'customer_id' => null,
            'status' => 'Stock Manage',
            'variant_product' => 0,
            'product_name' => 'Scarf',
            'product_stock' => 2,
            'store_id' => $this->store->id,
        ]);

        $note = OrderNote::withoutGlobalScopes()->where('order_id', $order->id)->first();

        $this->assertNotNull($note, 'premise: the system note was written');
        $this->assertNull($note->created_by,
            'A SYSTEM NOTE HAS NO AUTHOR EVEN WHEN SOMEBODY IS SIGNED IN. This test signs a user '
            . 'in on purpose: the risk is not a missing value, it is a `?? auth()->id()` added '
            . 'later by somebody making the column "complete".');
    }

    /** The generated "Order created" note the merchant web form emits alongside the order. */
    public function test_the_generated_order_created_note_has_no_author(): void
    {
        $this->actingAs($this->author)->post('order', $this->validOrderPayload());

        $notes = OrderNote::withoutGlobalScopes()
            ->where('store_id', $this->store->id)->get();

        $this->assertNotEmpty($notes, 'premise: the order form emitted its note');

        foreach ($notes as $note) {
            $this->assertNull($note->created_by,
                'its text is composed ("Order created manually by admin"), not typed, so it '
                . 'describes an event rather than stating somebody\'s words');
        }
    }

    /**
     * THE OWNER'S CONDITION, ASSERTED ON THE RENDERED PAGE. A note written before the column
     * existed must not acquire a name — and above all not the reader's.
     */
    public function test_an_old_note_is_not_attributed_to_anyone(): void
    {
        $order = $this->makeOrder($this->store, ['product_order_id' => 'N-5']);

        $note = OrderNote::forceCreate([
            'order_id' => $order->id,
            'customer_id' => null,
            'notes' => 'A note nobody named Readerson wrote',
            'note_type' => 'private_note',
            'status' => '',
            'store_id' => $this->store->id,
        ]);

        DB::table('order_notes')->where('id', $note->id)->update(['created_by' => null]);

        $panel = $this->notesPanel($this->detailHtml($this->reader, $order->id));

        $this->assertStringContainsString('A note nobody named Readerson wrote', $panel,
            'premise: the note is inside the panel this assertion looks at');
        $this->assertStringNotContainsString('Zzz Readerson', $panel,
            'the reader\'s own name is the original defect and must never come back');
        $this->assertStringNotContainsString('Amina Author', $panel);

        /*
         * SCOPED TO THIS NOTE'S OWN CARD. Asserting `' by '` against the whole panel -- 734 lines
         * of it -- matched prose elsewhere on the page and failed. A generic word checked against
         * a whole document answers a question about the document; this file made the same mistake
         * as the Source row did with "older" matching "placeholder", on the same day.
         */
        $card = $this->noteCardFor($panel, 'A note nobody named Readerson wrote');

        $this->assertStringNotContainsString(' by ', $card,
            'and no author line at all on this note: there is nothing to name');
    }

    public function test_the_author_is_named_on_the_detail_page(): void
    {
        $order = $this->makeOrder($this->store, ['product_order_id' => 'N-6']);

        $this->actingAs($this->author)->post('order-note', [
            'order_id' => $order->id,
            'note' => 'Held for Friday',
            'note_type' => 'private_note',
        ]);

        $panel = $this->notesPanel($this->detailHtml($this->reader, $order->id));

        $this->assertStringContainsString('Held for Friday', $panel, 'premise: the note rendered');
        $this->assertStringContainsString('Amina Author', $panel,
            'the AUTHOR is named...');
        $this->assertStringNotContainsString('Zzz Readerson', $panel,
            '...and the READER is not, which is the whole distinction');
    }

    /**
     * No foreign key was added, so removing a user cannot remove the note. If a CASCADE were
     * ever added this test fails, and it should: order notes are commercial records.
     */
    public function test_removing_the_author_does_not_remove_the_note(): void
    {
        $order = $this->makeOrder($this->store, ['product_order_id' => 'N-7']);

        $this->actingAs($this->author)->post('order-note', [
            'order_id' => $order->id,
            'note' => 'Written by someone since departed',
            'note_type' => 'private_note',
        ]);

        $noteId = OrderNote::withoutGlobalScopes()->where('order_id', $order->id)->value('id');
        $this->assertNotNull($noteId, 'premise: the note exists');

        User::find($this->author->id)->delete();

        $note = OrderNote::withoutGlobalScopes()->find($noteId);

        $this->assertNotNull($note,
            'THE NOTE SURVIVES ITS AUTHOR. Adding a cascading foreign key to `users` would '
            . 'delete a merchant\'s order notes to tidy up a user row.');

        $panel = $this->notesPanel($this->detailHtml($this->reader, $order->id));

        $this->assertStringContainsString('Written by someone since departed', $panel);
        $this->assertStringNotContainsString('Amina Author', $panel,
            'and an unnameable author is shown as nothing, not guessed at');
    }

    /** A name must not cross a tenant boundary, whatever put the id in the column. */
    public function test_a_foreign_authors_name_is_not_shown(): void
    {
        $otherOwner = $this->makeMerchant(['name' => 'Foreign Person']);
        $this->makeStore($otherOwner);

        $order = $this->makeOrder($this->store, ['product_order_id' => 'N-8']);

        $note = OrderNote::forceCreate([
            'order_id' => $order->id,
            'customer_id' => null,
            'notes' => 'A note carrying a foreign author id',
            'note_type' => 'private_note',
            'status' => '',
            'store_id' => $this->store->id,
        ]);

        DB::table('order_notes')->where('id', $note->id)
            ->update(['created_by' => $otherOwner->id]);

        $panel = $this->notesPanel($this->detailHtml($this->reader, $order->id));

        $this->assertStringContainsString('A note carrying a foreign author id', $panel,
            'premise: the note rendered');
        $this->assertStringNotContainsString('Foreign Person', $panel,
            'the author lookup carries its own tenant predicate rather than trusting that a '
            . 'foreign id can never land in the column');
    }

    // =======================================================================================
    //  HELPERS
    // =======================================================================================

    private function detailHtml(User $viewer, int $orderId): string
    {
        $response = $this->actingAs($viewer)->get('/order-view/' . Crypt::encrypt($orderId));

        $this->assertSame(200, $response->status(),
            'premise: the detail page rendered rather than redirecting on a permission or '
            . 'ownership refusal, which would make every assertion below vacuous');

        return $response->getContent();
    }

    /**
     * The notes panel only. NOT the whole page: the layout greets the signed-in user by name in
     * the header, so a body-wide search for the reader's name answers a different question —
     * the mistake `OrderDetailTest` records making first.
     */
    /** One note's card, from its own text to the end of its block. */
    private function noteCardFor(string $panel, string $noteText): string
    {
        $start = strpos($panel, $noteText);

        $this->assertNotFalse($start, 'premise: the note is in the panel');

        // The author line and the timestamp both sit in this note's `cart-time-wrapper`, which
        // closes well inside 900 characters of the note's text.
        return substr($panel, $start, 900);
    }

    private function notesPanel(string $body): string
    {
        $start = strpos($body, 'Order Notes');

        $this->assertNotFalse($start, 'premise: the notes panel heading was found');

        return substr($body, $start);
    }

    private function validOrderPayload(): array
    {
        $product = \App\Models\Product::forceCreate([
            'name' => 'note', 'slug' => 'note-' . uniqid(),
            'price' => 10, 'sale_price' => 10, 'cost_price' => 4,
            'product_stock' => 9, 'track_stock' => 1, 'variant_product' => 0,
            'stock_status' => 'in_stock', 'status' => 1,
            'store_id' => $this->store->id, 'created_by' => $this->store->created_by,
        ]);

        $customer = \App\Models\Customer::forceCreate([
            'first_name' => 'Note', 'last_name' => 'Buyer',
            'email' => 'note' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'store_id' => $this->store->id,
        ]);

        return [
            'customer_id' => $customer->id,
            'products' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 10]],
            'payment_type' => 'cod', 'payment_status' => 'Unpaid', 'final_price' => 10,
            'first_name' => 'Note', 'last_name' => 'Buyer',
            'email' => 'note@example.test', 'telephone' => '1',
            'address' => 'x', 'city' => 'y', 'state' => 'z', 'country' => 'q',
        ];
    }
}
