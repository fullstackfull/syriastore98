<?php

namespace Tests\Feature\Security;

use App\DataTables\SafeHelpdeskData;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\Security\EnablesWorkdoModules;
use Workdo\Helpdesk\DataTables\HelpdeskData;

/**
 * P1-SEC-75 -- A MERCHANT'S HELPDESK TICKET IS PRINTED RAW ON THE PLATFORM OPERATOR'S PAGES.
 * ===========================================================================================
 *
 * `packages/workdo/Helpdesk` (vendored, not edited) stores a ticket's description, subject and name and every reply verbatim, and prints them unescaped in two places:
 *
 *   the two views   `ticket_reply.blade.php` (:46 the description, :77 each reply) and `show.blade.php` (:28, :59) print them with `{!! !!}`, because the editor produces markup;
 *   the ticket list `DataTables/HelpdeskData.php` builds the cells for `name`, `subject`, `created_by` (the USER'S profile name), `category_id` and `status_id` as a string of markup
 *                   and declares them `rawColumns` -- so what a merchant types into a ticket's name or subject, or into their own profile name, is HTML in the first page the operator opens.
 *
 * The operator has to open tickets, so this is the shortest path from a registered merchant to a platform-operator session. (The project's `XSS` middleware is an opt-in alias, is on none
 * of these routes, and is a substring blocklist.) `GET helpdesk-ticket-show/{ticketId}` has no `auth` at all, so the same text also reaches whoever follows a ticket link.
 *
 * THE CONTROL IS HOST-SIDE, because the module is vendored: `resources/views/vendor/helpdesk/helpdesk_ticket/{show,ticket_reply}.blade.php` override the two views (the namespace is
 * `helpdesk`, and `loadViewsFrom()` looks in `resources/views/vendor/<namespace>` first) and print the rich text through `App\Support\Html\RichText::clean()`;
 * `App\DataTables\SafeHelpdeskData` is bound over the module's list and escapes the cells.
 *
 * EVERY CASE DRIVES THE REAL ROUTE with the module loaded by the production loader (no module loads in a test run otherwise: a test that "passes" against these routes without it never
 * reached them -- `enable()` asserts the premise). THE ORACLE IS THE DOM OF THE RESPONSE, not a substring: the page legitimately contains scripts of its own, and an escaped payload legitimately
 * contains the characters of a handler. A premise case puts the VENDORED views back (`replaceNamespace`) and shows that the same detector finds the payload there: a check that cannot see the
 * defect proves nothing about its absence.
 */
class HelpdeskStoredXssTest extends SecurityTestCase
{
    use EnablesWorkdoModules;
    use RefreshDatabase;

    private const PERMISSIONS = ['Manage Helpdesk Ticket', 'Create Helpdesk Ticket', 'Edit Helpdesk Ticket', 'Reply Helpdesk Ticket', 'Delete Helpdesk Ticket'];

    private const DESCRIPTION = '<p>Hello <b>operator</b></p><img src=x onerror="alert(\'XSS-D1\')"><script>alert("XSS-D2")</script><a href="javascript:alert(\'XSS-D3\')">click here</a><div style="background:url(javascript:alert(1))">styled</div>';
    private const REPLY = '<i>reply text</i><svg onload=alert("XSS-R1")></svg><iframe src="javascript:alert(\'XSS-R2\')"></iframe>';
    private const NAME = '<img src=x onerror=alert("XSS-N")>';
    private const SUBJECT = '"><script>alert("XSS-S")</script>';
    private const USER_NAME = '<script>alert("XSS-U")</script>';

    private int $ticketNumber = 1800000042;

    // ---- fixtures -------------------------------------------------------------------------------

    private function enable(): void
    {
        $failed = $this->enableWorkdoModules(['Helpdesk']);

        $this->assertNotEmpty(
            array_filter(Route::getRoutes()->getRoutes(), fn ($r) => str_starts_with(ltrim($r->getActionName(), '\\'), 'Workdo\\Helpdesk\\')),
            'Premise: Helpdesk is not loaded, so this test would reach nothing. Failed migrations: ' . implode('; ', $failed)
        );
    }

    /** @return array{operator: User, merchant: User, ticketId: int} the operator (user 1), a merchant whose profile name is hostile, and that merchant's hostile ticket with a hostile reply */
    private function world(): array
    {
        $operator = $this->makePlatformAdmin();      // user id 1, as in production
        [$merchant] = $this->makeTenant();
        $merchant->forceFill(['name' => self::USER_NAME])->save();

        foreach ([$operator, $merchant] as $user) {
            foreach (self::PERMISSIONS as $permission) {
                $this->grantPermission($user, $permission);
            }
        }

        DB::table('user_active_modules')->insert(['user_id' => $merchant->id, 'module' => 'Helpdesk', 'created_at' => now(), 'updated_at' => now()]);

        $ticketId = (int) DB::table('helpdesk_tickets')->insertGetId([
            'ticket_id' => $this->ticketNumber, 'name' => self::NAME, 'email' => 'merchant@example.test', 'subject' => self::SUBJECT, 'description' => self::DESCRIPTION,
            'note' => null, 'attachments' => '[]', 'user_id' => $merchant->id, 'created_by' => $merchant->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('helpdesk_conversions')->insert(['ticket_id' => $ticketId, 'description' => self::REPLY, 'attachments' => '[]', 'sender' => (string) $merchant->id, 'created_at' => now(), 'updated_at' => now()]);

        return compact('operator', 'merchant', 'ticketId');
    }

    /** What a browser would build from the response, asked of the DOM: nothing that runs, nothing that requests, nothing that navigates, from the PAYLOAD. */
    private function offences(string $html): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);

        $found = [];
        $queries = [
            'an image with a handler'      => '//img[@onerror]',
            'an svg that loads'            => '//svg[@onload]',
            'a frame with a script URL'    => '//iframe[contains(@src, "javascript")]',
            'a script of the payload'      => '//script[contains(., "XSS-")]',
            'a script URL'                 => '//a[starts-with(translate(normalize-space(@href), "JAVSCRIPT", "javscript"), "javascript:")]',
            'a handler on any element'     => '//*[@*[starts-with(name(), "on") and contains(., "XSS-")]]',
            'a style with a script URL'    => '//*[contains(@style, "javascript")]',
        ];

        foreach ($queries as $what => $query) {
            if ($xpath->query($query)->length > 0) {
                $found[] = $what;
            }
        }

        return $found;
    }

    private function conversationUrl(): string
    {
        return '/ticket-reply/' . Crypt::encrypt($this->ticketNumber);
    }

    // ---- the premise ----------------------------------------------------------------------------

    public function test_premise_the_vendored_views_print_the_text_raw_and_the_detector_sees_it(): void
    {
        $this->enable();
        $w = $this->world();

        $vendored = base_path('packages/workdo/Helpdesk/src/resources/views');
        $this->assertStringContainsString('{!! $ticket->description !!}', file_get_contents($vendored . '/helpdesk_ticket/ticket_reply.blade.php'), 'Premise: the vendored view prints the description raw.');
        $this->assertStringContainsString('{!! $conversion->description !!}', file_get_contents($vendored . '/helpdesk_ticket/ticket_reply.blade.php'));
        $this->assertStringContainsString('{!! $ticket->description !!}', file_get_contents($vendored . '/helpdesk_ticket/show.blade.php'));

        // put the VENDORED views back for this one request, as they were before the override
        app('view')->getFinder()->replaceNamespace('helpdesk', [$vendored]);
        app('view')->flushFinderCache();

        $response = $this->actingAs($w['operator'])->get($this->conversationUrl());

        $this->assertSame(200, $response->status(), 'Premise: the operator is shown the conversation.');
        $this->assertContains('an image with a handler', $this->offences($response->getContent()), 'Premise: the detector sees the payload on the vendored page, or it proves nothing about its absence.');
        $this->assertContains('a script of the payload', $this->offences($response->getContent()));
    }

    public function test_premise_the_override_is_what_the_module_resolves(): void
    {
        $this->enable();

        $finder = app('view')->getFinder();

        foreach (['helpdesk_ticket.ticket_reply', 'helpdesk_ticket.show'] as $name) {
            $this->assertSame(realpath(resource_path('views/vendor/helpdesk/' . str_replace('.', '/', $name) . '.blade.php')), realpath($finder->find('helpdesk::' . $name)), "{$name} must resolve to the host's override.");
        }

        $this->assertInstanceOf(SafeHelpdeskData::class, app(HelpdeskData::class), 'The ticket list the module injects must be the escaping subclass.');
    }

    // ---- the screens ----------------------------------------------------------------------------

    public function test_the_conversation_the_operator_opens_prints_the_ticket_and_its_replies_cleaned(): void
    {
        $this->enable();
        $w = $this->world();

        $response = $this->actingAs($w['operator'])->get($this->conversationUrl());
        $body = $response->getContent();

        $this->assertSame(200, $response->status());
        $this->assertSame([], $this->offences($body), 'The operator was handed a ticket that runs, requests or navigates.');
        $this->assertStringContainsString('<p>Hello <b>operator</b></p>', $body, 'The formatting the editor produced is kept.');
        $this->assertStringContainsString('<i>reply text</i>', $body, 'So is the reply\'s.');
        $this->assertStringContainsString('click here', $body, 'The text of a link that was refused is kept.');
        $this->assertStringContainsString('styled', $body);
    }

    public function test_the_ticket_page_the_route_declares_without_auth_prints_it_cleaned_too(): void
    {
        $this->enable();
        $w = $this->world();
        $url = '/helpdesk-ticket-show/' . Crypt::encrypt($this->ticketNumber);

        // `GET helpdesk-ticket-show/{ticketId}` is declared with no `auth`, but a request with no session is sent to the login page (executed): its reader is a signed-in user
        $this->get($url)->assertRedirect('/login');

        $response = $this->actingAs($w['operator'])->get($url);

        $this->assertSame(200, $response->status(), 'Premise: the page renders the ticket for a signed-in user.');
        $this->assertSame([], $this->offences($response->getContent()));
        $this->assertStringContainsString('<p>Hello <b>operator</b></p>', $response->getContent());
    }

    /** the list answers the DataTable's AJAX call with JSON, and renders the page otherwise */
    private function listRows(User $viewer): array
    {
        $response = $this->actingAs($viewer)->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])->get('/tickets?draw=1&start=0&length=10');

        $this->assertSame(200, $response->status(), 'Premise: the list answers the DataTable call.');
        $this->assertNotNull($response->json('data.0'), 'Premise: the list holds the ticket.');

        return $response->json('data.0');
    }

    public function test_the_ticket_list_prints_a_merchants_name_subject_and_profile_name_as_text(): void
    {
        $this->enable();
        $w = $this->world();

        $row = $this->listRows($w['operator']);

        $this->assertSame('<span class="white-space">' . e(self::NAME) . '</span>', $row['name']);
        $this->assertSame('<span class="white-space">' . e(self::SUBJECT) . '</span>', $row['subject']);
        $this->assertSame('<span class="text-primary">' . e(self::USER_NAME) . '</span>', $row['created_by']);

        foreach (['name', 'subject', 'created_by', 'category_id', 'status_id', 'ticket_id'] as $column) {
            $this->assertSame([], $this->offences((string) $row[$column]), "The {$column} cell of the operator's ticket list runs, requests or navigates.");
        }
    }

    public function test_a_colour_that_is_not_a_colour_does_not_reach_the_style_of_a_badge(): void
    {
        $this->enable();
        $w = $this->world();

        $category = (int) DB::table('helpdesk_categories')->insertGetId(['name' => '<b>cat</b>', 'color' => 'red;" onmouseover="alert(\'XSS-C\')', 'created_by' => $w['merchant']->id, 'created_at' => now(), 'updated_at' => now()]);
        $status = (int) DB::table('helpdesk_statuses')->insertGetId(['status_name' => '<i>st</i>', 'status_color' => '#fff;" onfocus="alert(\'XSS-T\')', 'created_by' => $w['merchant']->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('helpdesk_tickets')->where('id', $w['ticketId'])->update(['category_id' => $category, 'status_id' => $status]);

        $row = $this->listRows($w['operator']);

        $this->assertSame([], $this->offences($row['category_id'] . $row['status_id']));
        $this->assertStringContainsString('&lt;b&gt;cat&lt;/b&gt;', $row['category_id']);
        $this->assertStringNotContainsString('onmouseover="', $row['category_id']);
        $this->assertStringNotContainsString('onfocus="', $row['status_id']);
    }

    // ---- the overrides are verbatim copies -------------------------------------------------------

    /**
     * The two override views are exempt from the design ratchets (BreadcrumbContractTest, IconFamilyCoherenceTest) because they are the vendored module's own markup. That is only honest while it
     * is TRUE: each is the vendored view with the two expressions that print the text changed, and one header comment. If the module's view changes upstream this fails, which is the prompt to
     * re-diff the override; if somebody edits the override, it fails too.
     */
    public function test_the_overrides_are_the_vendored_views_with_only_the_printed_text_changed(): void
    {
        foreach (['show', 'ticket_reply'] as $view) {
            $vendored = file_get_contents(base_path("packages/workdo/Helpdesk/src/resources/views/helpdesk_ticket/{$view}.blade.php"));
            $override = file_get_contents(resource_path("views/vendor/helpdesk/helpdesk_ticket/{$view}.blade.php"));

            $override = preg_replace('/\A\{\{-- HOST OVERRIDE .*? --\}\}\n/s', '', $override, 1, $headers);
            $this->assertSame(1, $headers, "{$view}: the override carries its header comment.");

            $restored = str_replace(
                ['\App\Support\Html\RichText::clean($ticket->description)', '\App\Support\Html\RichText::clean($conversion->description)'],
                ['$ticket->description', '$conversion->description'],
                $override
            );

            $this->assertSame($vendored, $restored, "{$view}: the override differs from the vendored view in more than the two expressions that print the text.");
            $this->assertSame(substr_count($vendored, '{!! $ticket->description !!}') + substr_count($vendored, '{!! $conversion->description !!}'), substr_count($override, 'RichText::clean('), "{$view}: every raw print of the text is cleaned.");
        }
    }

    // ---- nothing that worked stops working ------------------------------------------------------

    private function plainWorld(): array
    {
        $w = $this->world();
        DB::table('helpdesk_tickets')->where('id', $w['ticketId'])->update(['name' => 'Alice', 'subject' => 'Printer on fire', 'description' => '<p dir="rtl" style="text-align:right">مرحبا <b>بالعالم</b></p><ul><li>one</li></ul><a href="https://example.com/x">link</a>']);
        DB::table('users')->where('id', $w['merchant']->id)->update(['name' => 'Bob']);
        DB::table('helpdesk_conversions')->where('ticket_id', $w['ticketId'])->update(['description' => '<p>Thanks</p>']);

        return $w;
    }

    /** a CONTROL: passes against the vendored views and against the overrides */
    public function test_a_plain_ticket_reads_as_before(): void
    {
        $this->enable();
        $w = $this->plainWorld();

        $page = $this->actingAs($w['operator'])->get($this->conversationUrl())->getContent();

        $this->assertStringContainsString('<b>بالعالم</b>', $page);
        $this->assertStringContainsString('<ul><li>one</li></ul>', $page);
        $this->assertStringContainsString('href="https://example.com/x"', $page);
        $this->assertStringContainsString('<p>Thanks</p>', $page);
        $this->assertStringContainsString('dir="rtl"', $page);

        $row = $this->listRows($w['operator']);
        $this->assertSame('<span class="white-space">Alice</span>', $row['name']);
        $this->assertSame('<span class="white-space">Printer on fire</span>', $row['subject']);
        $this->assertSame('<span class="text-primary">Bob</span>', $row['created_by']);
        $this->assertStringContainsString('#' . $this->ticketNumber, $row['ticket_id']);
        $this->assertStringContainsString('/ticket-reply/', $row['ticket_id'], 'The ticket number is still a link to the conversation for a user who may reply.');
        $this->assertStringStartsWith('<a class="btn btn-badge btn-outline-primary" href="', $row['ticket_id']);
    }

    /** what the sanitizer writes: the markup is re-written, not copied */
    public function test_the_text_is_rewritten_by_the_sanitizer_not_copied(): void
    {
        $this->enable();
        $w = $this->plainWorld();

        $page = $this->actingAs($w['operator'])->get($this->conversationUrl())->getContent();

        $this->assertStringContainsString('<p dir="rtl" style="text-align: right">مرحبا <b>بالعالم</b></p>', $page);
        $this->assertStringContainsString('<a href="https://example.com/x" rel="noopener noreferrer nofollow" target="_blank">link</a>', $page);
    }
}
