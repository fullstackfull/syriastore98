<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\SupportConversion;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Security\DetectsActiveMarkup;

/**
 * P1-SEC-79 -- A SHOPPER'S SUPPORT TICKET IS PRINTED RAW ON THE MERCHANT'S PAGES.
 * ===========================================================================================
 *
 * Found by the independent review of P1-SEC-75 (the same defect one door over, with a far wider attacker population: ANY shopper, because registration is open). A shopper's
 * `support_ticket_store` and `ticket_reply` (customer routes: no `xss` middleware, which is an opt-in alias and a substring blocklist anyway) store `description` and
 * `reply_description` verbatim. The host's own views then print them with `{!! !!}`:
 *
 *   resources/views/support/edit.blade.php                   :75 the description, :104 each reply   -- the MERCHANT'S admin page for the ticket
 *   resources/views/front_end/pages/reply_support_ticket.blade.php   :16, :54                       -- the storefront page (the shopper's own text, and the merchant's replies)
 *
 * and `SupportTicketDataTable` declares `customer_id` a raw column and fills it with the shopper's NAME (first and last name are free text at registration).
 * A ticket is what a merchant opens for a living, so this was the shortest path from a registered shopper to a script running in a MERCHANT'S panel session.
 *
 * THE CONTROL: the text goes through `App\Support\Html\RichText::clean()` where it is printed (an allowlist that builds a new string from the parse tree), and the name and the
 * status go through `e()`. Stored text stays raw: this is an output filter.
 *
 * EVERY CASE DRIVES THE REAL ROUTE. THE ORACLE IS THE DOM of the response (Tests\Support\Security\DetectsActiveMarkup), not a substring. The premise case feeds the oracle the payload
 * printed raw and shows that it finds it: a check that cannot see the defect proves nothing about its absence.
 */
class SupportTicketStoredXssTest extends SecurityTestCase
{
    use DetectsActiveMarkup;
    use RefreshDatabase;

    private const DESCRIPTION = '<p>Hello <b>merchant</b></p><img src=x onerror="alert(\'XSS-D1\')"><script>alert("XSS-D2")</script><a href="javascript:alert(\'XSS-D3\')">click here</a><div style="background:url(javascript:alert(1))">styled</div>';
    private const REPLY = '<i>reply text</i><svg onload=alert("XSS-R1")></svg><iframe src="javascript:alert(\'XSS-R2\')"></iframe>';
    private const FIRST = '<img src=x onerror=alert("XSS-F")>';
    private const LAST = '<script>alert("XSS-L")</script>';

    /** @return array{merchant: User, store: \App\Models\Store, shopper: Customer, ticket: SupportTicket} a merchant, their store, a shopper with a hostile name, and that shopper's hostile ticket with a hostile reply */
    private function world(): array
    {
        [$merchant, $store] = $this->makeTenant();

        foreach (['Manage Support Ticket', 'Replay Support Ticket'] as $permission) {
            $this->grantPermission($merchant, $permission);
        }

        $shopper = Customer::forceCreate([
            'first_name' => self::FIRST, 'last_name' => self::LAST, 'email' => 'shopper' . $this->uniqueFixtureRef() . '@example.test',
            'password' => bcrypt('not-a-real-credential'), 'store_id' => $store->id, 'status' => 1,
            'billable_id' => $store->created_by, 'billable_type' => User::class,
        ]);

        $ticket = SupportTicket::forceCreate([
            'ticket_id' => 'T-' . $this->uniqueFixtureRef(), 'title' => 'Printer on fire', 'description' => self::DESCRIPTION, 'customer_id' => $shopper->id,
            'status' => 'Open', 'store_id' => $store->id, 'created_by' => $shopper->id,
        ]);

        SupportConversion::forceCreate(['ticket_id' => $ticket->id, 'description' => self::REPLY, 'attachments' => '[]', 'sender' => 'user', 'customer_id' => $shopper->id, 'store_id' => $store->id]);

        return compact('merchant', 'store', 'shopper', 'ticket');
    }

    /** A signed-in SHOPPER as production sees one: the `customers` guard holds the customer and the DEFAULT guard stays `web`, with nobody in it (see PlatformUploaderDoorsTest::asAShopper). */
    private function asAShopper(Customer $customer): self
    {
        $this->actingAs($customer, 'customers');
        $this->app['auth']->shouldUse('web');

        return $this;
    }

    // ---- the premise ----------------------------------------------------------------------------

    public function test_premise_the_detector_sees_the_payload_when_it_is_printed_raw(): void
    {
        $raw = '<div><p>' . self::DESCRIPTION . '</p><div>' . self::REPLY . '</div></div>';

        foreach (['an image with a handler', 'a script of the payload', 'a script URL', 'an svg that loads', 'a frame with a script URL', 'a style with a script URL'] as $what) {
            $this->assertContains($what, $this->activeMarkupIn($raw), "Premise: the detector must see {$what}, or it proves nothing about its absence.");
        }

        $this->assertSame([], $this->activeMarkupIn('<p>Hello <b>merchant</b></p>'), 'Premise: and it must see nothing in plain markup.');
    }

    // ---- the merchant's pages ---------------------------------------------------------------------

    public function test_the_ticket_page_the_merchant_opens_prints_the_text_cleaned(): void
    {
        $w = $this->world();

        $response = $this->actingAs($w['merchant'])->get(route('support_ticket.edit', $w['ticket']->id));
        $body = $response->getContent();

        $this->assertSame(200, $response->status(), 'Premise: the merchant is shown the ticket.');
        $this->assertStringContainsString('Printer on fire', $body, 'Premise: it is the ticket page.');
        $this->assertSame([], $this->activeMarkupIn($body), 'The merchant was handed a ticket that runs, requests or navigates.');
        $this->assertStringContainsString('<p>Hello <b>merchant</b></p>', $body, 'The formatting the editor produced is kept.');
        $this->assertStringContainsString('<i>reply text</i>', $body, 'So is the reply\'s.');
        $this->assertStringContainsString('click here', $body, 'The text of a link that was refused is kept.');
    }

    /** the list answers the DataTable's AJAX call with JSON */
    private function listRow(User $viewer): array
    {
        $response = $this->actingAs($viewer)->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])->get('/support_ticket?draw=1&start=0&length=10');

        $this->assertSame(200, $response->status(), 'Premise: the list answers the DataTable call.');
        $this->assertNotNull($response->json('data.0'), 'Premise: the list holds the ticket.');

        return $response->json('data.0');
    }

    public function test_the_ticket_list_prints_a_shoppers_name_as_text(): void
    {
        $w = $this->world();

        $row = $this->listRow($w['merchant']);

        $this->assertSame(e(self::FIRST . ' ' . self::LAST), $row['customer_id'], 'The shopper\'s name is TEXT in the merchant\'s list.');

        $this->assertSame([], $this->activeMarkupIn((string) $row['customer_id']), 'The shopper\'s name, in the merchant\'s support-ticket list, runs, requests or navigates.');
    }

    /**
     * The ticket number is `time()` and the order number is the platform's own, so neither is shopper text TODAY; they go through e() so that a cell of this list is never the place where a
     * value that stops being server-made becomes markup. The fixture can say anything (the column is only a column), and the assertion has to bite on that.
     */
    public function test_the_ticket_number_and_the_order_number_the_list_prints_are_text(): void
    {
        $w = $this->world();
        $order = $this->makeOrder($w['store'], ['product_order_id' => '<u onmouseover=alert("XSS-O")>9</u>']);
        SupportTicket::withoutGlobalScopes()->whereKey($w['ticket']->id)->update(['ticket_id' => '<u onmouseover=alert("XSS-T")>1</u>', 'order_id' => $order->id]);

        $row = $this->listRow($w['merchant']);

        $this->assertStringContainsString('#&lt;u onmouseover=alert(&quot;XSS-T&quot;)&gt;1&lt;/u&gt;</a>', $row['ticket_id']);
        $this->assertStringContainsString('#&lt;u onmouseover=alert(&quot;XSS-O&quot;)&gt;9&lt;/u&gt;</a>', $row['order_id']);
        $this->assertSame([], $this->activeMarkupIn($row['ticket_id'] . $row['order_id']));
    }

    public function test_the_status_the_list_prints_is_text(): void
    {
        $w = $this->world();
        SupportTicket::withoutGlobalScopes()->whereKey($w['ticket']->id)->update(['status' => '<b onclick=alert("XSS-S")>Open</b>']);

        $row = $this->listRow($w['merchant']);

        $this->assertStringContainsString('&lt;b onclick=', $row['status']);
        $this->assertSame([], $this->activeMarkupIn((string) $row['status']));
    }

    // ---- the storefront page ----------------------------------------------------------------------

    public function test_the_storefront_page_prints_the_ticket_cleaned_too(): void
    {
        $w = $this->world();

        $response = $this->asAShopper($w['shopper'])->get('/' . $w['store']->slug . '/reply-support-ticket/' . $w['ticket']->id);
        $html = (string) $response->json('html');

        $this->assertSame(200, $response->status());
        $this->assertNotSame('', $html, 'Premise: the storefront renders the ticket (the reply is a JSON envelope around the page).');
        $this->assertSame([], $this->activeMarkupIn($html), 'The storefront page of the ticket runs, requests or navigates.');
        $this->assertStringContainsString('<p>Hello <b>merchant</b></p>', $html);
        $this->assertStringContainsString('<i>reply text</i>', $html);
    }

    // ---- nothing that worked stops working --------------------------------------------------------

    /** a CONTROL: passes against the old views and against the fixed ones */
    public function test_a_plain_ticket_reads_as_before(): void
    {
        $w = $this->world();
        $w['shopper']->forceFill(['first_name' => 'Alice', 'last_name' => 'Shopper'])->save();
        SupportTicket::withoutGlobalScopes()->whereKey($w['ticket']->id)->update(['description' => '<p dir="rtl" style="text-align:right">مرحبا <b>بالعالم</b></p><ul><li>one</li></ul><a href="https://example.com/x">link</a>']);
        SupportConversion::withoutGlobalScopes()->where('ticket_id', $w['ticket']->id)->update(['description' => '<p>Thanks</p>']);

        $page = $this->actingAs($w['merchant'])->get(route('support_ticket.edit', $w['ticket']->id))->getContent();

        $this->assertStringContainsString('<b>بالعالم</b>', $page);
        $this->assertStringContainsString('<ul><li>one</li></ul>', $page);
        $this->assertStringContainsString('href="https://example.com/x"', $page);
        $this->assertStringContainsString('<p>Thanks</p>', $page);
        $this->assertStringContainsString('dir="rtl"', $page);

        $row = $this->listRow($w['merchant']);
        $this->assertSame('Alice Shopper', $row['customer_id']);
        $this->assertStringContainsString('>Open</span>', $row['status']);
        $this->assertStringContainsString('class="badge fix_badges bg-primary p-2 px-3 rounded-1"', $row['status']);
    }
}
