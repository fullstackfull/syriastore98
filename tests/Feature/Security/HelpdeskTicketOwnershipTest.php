<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Support\ModuleRouteManifest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Security\EnablesWorkdoModules;

/**
 * P1-SEC-68 -- THE HELPDESK MODULE'S AUTHENTICATED TICKET ROUTES TRUST A PERMISSION AND NOTHING ELSE.
 * =====================================================================================================
 *
 * `packages/workdo/Helpdesk` (vendored, not edited) declares nine routes that take a ticket and seven that are signed-in only. P1-SEC-04 classified the two
 * that carry no `auth` (`GET helpdesk-ticket-show/{ticketId}`, `POST helpdesk-ticket/{ticketId}`). The other seven sit in the module's own
 * `['auth','web','PlanModuleCheck:Helpdesk']` group and were never looked at, because the census only classifies routes with no authentication:
 *
 *   GET    edit-ticket/{ticketId}              the edit form                 `Edit Helpdesk Ticket`    ticket NUMBER, encrypted
 *   POST   update-ticket/{ticketId}            REWRITES the row, incl. user_id `Edit Helpdesk Ticket`  ticket NUMBER, encrypted
 *   GET    ticket-reply/{ticketId}             the conversation              `Reply Helpdesk Ticket`   ticket NUMBER, encrypted
 *   DELETE delete-ticket/{ticketId}            deletes the row + its folder  `Delete Helpdesk Ticket`  ROW id
 *   POST   ticket-conversion-store/{ticketId}  adds a reply                  `Reply Helpdesk Ticket`   ROW id
 *   POST   ticket-note-store/{ticketId}        overwrites the note           NONE                      ROW id
 *   POST   get-user-data                       name + e-mail of ANY user     NONE                      body `user_id`
 *                                              (called only by the OPERATOR's company select: the two forms render it `@if hasRole('super admin')`)
 *
 * Every handler looks the ticket up by the identifier alone: `HelpdeskTicket::where('ticket_id', Decrypt($id))`, `::find($id)`, `::where('id', $id)`. None
 * compares the row's `created_by` / `user_id` with the caller. The three that take the NUMBER take it ENCRYPTED, which is no control: the number is `time()`
 * at creation (a guessable second), and APP_KEY is in git history, so an attacker mints the segment themselves. The permissions are no barrier to a
 * merchant either: they are granted to the global `admin` role every self-registered merchant holds.
 *
 * So a signed-in merchant on a plan that lists the module can, for any other tenant's ticket: read it (the form and the conversation, which render the
 * description, the replies and the internal note), rewrite it AND TAKE IT -- `update()` sets `user_id` to the CALLER, and `index()` and the DataTable list a
 * non-operator's tickets by `where('user_id', <caller>)`, so the victim loses it from their own list -- delete it with its attachment folder, add replies to
 * it, overwrite its note, and read the name and e-mail of any user id.
 *
 * THE CONTROL IS HOST-SIDE: `ModuleAdministrativeRouteGuard` rules `owner:helpdesk-ticket-link` (the encrypted number, decoded WITHOUT unserialize, and
 * refused for EVERYBODY -- the operator included -- unless it is exactly a ticket number), `owner:helpdesk-ticket-row` (the row id) and, for the user
 * lookup, the existing `operator` rule. The module is vendored, so a rule on its routes is the control; a rule is ownership only -- WHAT a signed-in user may
 * do (the permission) stays the handler's and is P1-SEC-31's to decide.
 *
 * THE SEGMENT IS ALSO A DESERIALIZATION DOOR. The three number handlers `decrypt()` it WITH unserialize(), APP_KEY is public, and a handler that is handed
 * a forged segment instantiates whatever it carries. `HelpdeskWakeProbe` (at the end of this file, harmless: it counts its own `__wakeup`) shows it without
 * exploiting anything: an object nobody asked for is built on the unguarded tree, for a merchant and for the operator alike.
 *
 * EVERY CASE DRIVES THE REAL ROUTE with the module loaded by the production loader (no module loads in a test run otherwise, so a test that "passes" against
 * these routes without it never reached them -- `enable()` asserts the premise). Five principals: the operator (user id 1, as in production), the ticket's
 * tenant owner, that owner's staff, a stranger (another merchant) and the stranger's staff. The oracle is the ROW (`DB::table`), because `HelpdeskTicket` is a
 * `Cachable` model and an Eloquent read in a test answers for whatever the cache holds.
 */
class HelpdeskTicketOwnershipTest extends SecurityTestCase
{
    use EnablesWorkdoModules;
    use RefreshDatabase;

    private const PERMISSIONS = ['Manage Helpdesk Ticket', 'Create Helpdesk Ticket', 'Edit Helpdesk Ticket', 'Reply Helpdesk Ticket', 'Delete Helpdesk Ticket'];

    /** The seven routes of this defect, and the rule each is given. */
    private const RULES = [
        'GET edit-ticket/{ticketId}' => 'owner:helpdesk-ticket-link',
        'POST update-ticket/{ticketId}' => 'owner:helpdesk-ticket-link',
        'GET ticket-reply/{ticketId}' => 'owner:helpdesk-ticket-link',
        'DELETE delete-ticket/{ticketId}' => 'owner:helpdesk-ticket-row',
        'POST ticket-conversion-store/{ticketId}' => 'owner:helpdesk-ticket-row',
        'POST ticket-note-store/{ticketId}' => 'owner:helpdesk-ticket-row',
        'POST get-user-data' => 'operator',
    ];

    private int $sequence = 0;

    // ---- fixtures -------------------------------------------------------------------------------

    private function enable(): void
    {
        $failed = $this->enableWorkdoModules(['Helpdesk']);

        $this->assertNotEmpty(
            array_filter(Route::getRoutes()->getRoutes(), fn ($r) => str_starts_with(ltrim($r->getActionName(), '\\'), 'Workdo\\Helpdesk\\')),
            'Premise: Helpdesk is not loaded, so this test would reach nothing. Failed migrations: ' . implode('; ', $failed)
        );
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('helpdesk_tickets'), 'Premise: the ticket table exists.');
    }

    /** Plan entitlement: PlanModuleCheck refuses a merchant whose plan lacks the module (the operator bypasses it). */
    private function entitle(User $owner): void
    {
        DB::table('user_active_modules')->insert(['user_id' => $owner->id, 'module' => 'Helpdesk', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertContains('Helpdesk', ActivatedModule($owner->id), 'The entitlement fixture did not take.');
    }

    /**
     * @return array{operator: User, owner: User, staff: User, stranger: User, strangerStaff: User}
     */
    private function world(): array
    {
        $operator = $this->makePlatformAdmin();                // created FIRST: user id 1 is the operator, and every merchant has created_by = 1
        [$owner] = $this->makeTenant();
        [$stranger] = $this->makeTenant();
        $staff = $this->makeStaff($owner);
        $strangerStaff = $this->makeStaff($stranger);

        foreach ([$operator, $owner, $staff, $stranger, $strangerStaff] as $user) {
            foreach (self::PERMISSIONS as $permission) {
                $this->grantPermission($user, $permission);
            }
        }

        $this->entitle($owner);
        $this->entitle($stranger);

        return compact('operator', 'owner', 'staff', 'stranger', 'strangerStaff');
    }

    /** @return array{0: int, 1: int, 2: string} row id, ticket number, tag */
    private function ticket(int $createdBy, ?int $userId = null): array
    {
        $tag = 'T' . (++$this->sequence);
        $number = 1800000000 + $this->sequence;

        $id = (int) DB::table('helpdesk_tickets')->insertGetId([
            'ticket_id' => $number, 'name' => 'FIXTURE-NAME-' . $tag, 'email' => 'ticket-' . $tag . '@example.test',
            'subject' => 'FIXTURE-SUBJECT-' . $tag, 'description' => 'FIXTURE-DESCRIPTION-' . $tag, 'note' => null, 'attachments' => '[]',
            'user_id' => $userId ?? $createdBy, 'created_by' => $createdBy, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$id, $number, $tag];
    }

    private function row(int $id): array
    {
        $row = DB::table('helpdesk_tickets')->where('id', $id)->first();

        return $row === null ? [] : (array) $row;
    }

    private function signedInAs(User $user): static
    {
        return $this->actingAs($user->fresh());
    }

    private function refusedByTheGuard($response, string $what): void
    {
        $this->assertSame(302, $response->status(), $what . ': expected the guard\'s redirect, got ' . $response->status());
        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('error', __('Permission denied'));
    }

    // ---- the premise: the seven routes are the module's, and the table classifies them ----------

    public function test_every_route_of_the_module_that_names_a_ticket_or_reads_a_user_is_classified_and_carries_the_guard(): void
    {
        $this->enable();
        $seen = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with(ltrim($route->getActionName(), '\\'), 'Workdo\\Helpdesk\\')) {
                continue;
            }

            if (! in_array('ticketId', $route->parameterNames(), true) && $route->uri() !== 'get-user-data') {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $key = $method . ' ' . $route->uri();
                $seen[$key] = ModuleRouteManifest::administrativeRule($method, $route->uri());

                $this->assertNotNull($seen[$key], "{$key} names a ticket (or reads a user) and has no rule: a signed-in user of any tenant reaches it.");

                if ($key === 'POST get-user-data') {
                    $this->assertSame('operator', $seen[$key], 'The user lookup answers with any user\'s name and e-mail: only the operator\'s form calls it.');
                } else {
                    $this->assertStringStartsWith('owner:helpdesk-', $seen[$key], "{$key}: a ticket route needs an OWNERSHIP rule, not a bare `auth`.");
                }

                $this->assertContains(\App\Http\Middleware\ModuleAdministrativeRouteGuard::class, $this->app['router']->gatherRouteMiddleware($route), "{$key} does not carry the guard, so its rule protects nothing.");
            }
        }

        // the two unauthenticated routes P1-SEC-04 classified, and the seven of this defect: nine in all
        $this->assertCount(9, $seen, 'The module declares a different number of ticket routes than this test was written against: ' . implode(', ', array_keys($seen)));

        foreach (self::RULES as $key => $rule) {
            $this->assertSame($rule, $seen[$key] ?? null, "{$key} has the wrong rule: the row-id routes and the encrypted-number routes name DIFFERENT identifiers.");
        }
    }

    // ---- the two read screens -------------------------------------------------------------------

    public static function theTwoReadScreens(): array
    {
        return ['the edit form' => ['edit-ticket/'], 'the conversation' => ['ticket-reply/']];
    }

    #[DataProvider('theTwoReadScreens')]
    public function test_a_ticket_is_shown_to_its_tenant_and_the_operator_only(string $screen): void
    {
        $this->enable();
        $w = $this->world();
        [, $number, $tag] = $this->ticket($w['owner']->id);
        $url = '/' . $screen . Crypt::encrypt($number);

        foreach (['stranger' => $w['stranger'], 'the stranger\'s staff' => $w['strangerStaff']] as $who => $user) {
            $response = $this->signedInAs($user)->get($url);

            $this->assertStringNotContainsString('FIXTURE-DESCRIPTION-' . $tag, (string) $response->getContent(), "{$who} was shown another tenant's ticket on {$screen}.");
            $this->refusedByTheGuard($response, "{$who} on {$screen}");
        }

        foreach (['the owner' => $w['owner'], 'the owner\'s staff' => $w['staff'], 'the operator' => $w['operator']] as $who => $user) {
            $response = $this->signedInAs($user)->get($url);

            $this->assertSame(200, $response->status(), "Premise: {$who} must still be shown the ticket on {$screen}.");
            $this->assertStringContainsString('FIXTURE-', (string) $response->getContent(), "Premise: {$who} must still be shown the ticket on {$screen}.");
        }
    }

    // ---- the update: it rewrites the row and takes it -------------------------------------------

    public function test_a_stranger_cannot_rewrite_another_tenants_ticket_or_take_it_over(): void
    {
        $this->enable();
        $w = $this->world();
        [$id, $number] = $this->ticket($w['owner']->id);
        $before = $this->row($id);
        $url = '/update-ticket/' . Crypt::encrypt($number);
        $body = ['category' => '7', 'subject' => 'STRANGER-SUBJECT', 'description' => 'STRANGER-DESCRIPTION'];

        foreach (['stranger' => $w['stranger'], 'the stranger\'s staff' => $w['strangerStaff']] as $who => $user) {
            $response = $this->signedInAs($user)->post($url, $body);

            $this->assertSame($before, $this->row($id), "{$who} changed another tenant's ticket (user_id is the column `index()` and the list filter on).");
            $this->refusedByTheGuard($response, "{$who} updating the ticket");
        }

        // premise: the owner can, and it stays the owner's
        $this->signedInAs($w['owner'])->post($url, ['category' => '7', 'subject' => 'OWNER-SUBJECT', 'description' => 'OWNER-DESCRIPTION'])->assertRedirect(route('helpdesk.ticket.index'));
        $after = $this->row($id);
        $this->assertSame('OWNER-SUBJECT', $after['subject'], 'Premise: the owner can still edit their ticket.');
        $this->assertSame((int) $w['owner']->id, (int) $after['user_id']);

        // the owner's staff edits it: user_id becomes the staff member's, which is still the same tenant (documented, unchanged)
        $this->signedInAs($w['staff'])->post($url, ['category' => '7', 'subject' => 'STAFF-SUBJECT', 'description' => 'STAFF-DESCRIPTION'])->assertRedirect(route('helpdesk.ticket.index'));
        $this->assertSame('STAFF-SUBJECT', $this->row($id)['subject'], 'Premise: the owner\'s staff can still edit it.');
        $this->assertSame((int) $w['staff']->id, (int) $this->row($id)['user_id']);

        // the operator assigns it to a company
        $this->signedInAs($w['operator'])->post($url, [
            'company_id' => $w['owner']->id, 'email' => $w['owner']->email, 'category' => '7', 'subject' => 'OPERATOR-SUBJECT', 'status' => '1', 'description' => 'OPERATOR-DESCRIPTION',
        ])->assertRedirect(route('helpdesk.ticket.index'));
        $this->assertSame('OPERATOR-SUBJECT', $this->row($id)['subject'], 'Premise: the operator can still edit any ticket.');
        $this->assertSame((int) $w['owner']->id, (int) $this->row($id)['user_id']);
    }

    // ---- the three row-id routes ----------------------------------------------------------------

    public function test_a_stranger_cannot_delete_another_tenants_ticket(): void
    {
        $this->enable();
        $w = $this->world();
        [$victim] = $this->ticket($w['owner']->id);
        [$mine] = $this->ticket($w['stranger']->id);
        DB::table('helpdesk_conversions')->insert(['ticket_id' => $victim, 'description' => 'kept', 'attachments' => '[]', 'sender' => 'user', 'created_at' => now(), 'updated_at' => now()]);

        foreach (['stranger' => $w['stranger'], 'the stranger\'s staff' => $w['strangerStaff']] as $who => $user) {
            $response = $this->signedInAs($user)->delete('/delete-ticket/' . $victim);

            $this->assertNotSame([], $this->row($victim), "{$who} deleted another tenant's ticket.");
            $this->assertSame(1, DB::table('helpdesk_conversions')->where('ticket_id', $victim)->count(), "{$who} took the ticket's conversation with it.");
            $this->refusedByTheGuard($response, "{$who} deleting the ticket");
        }

        // premises: the stranger still deletes their own, and the owner, the owner's staff and the operator delete the victim's
        $this->signedInAs($w['stranger'])->delete('/delete-ticket/' . $mine);
        $this->assertSame([], $this->row($mine), 'Premise: a merchant can still delete their own ticket.');
        $this->assertNotSame([], $this->row($victim));

        $this->signedInAs($w['staff'])->delete('/delete-ticket/' . $victim);
        $this->assertSame([], $this->row($victim), 'Premise: the owner\'s staff can still delete the tenant\'s ticket.');

        [$second] = $this->ticket($w['owner']->id);
        $this->signedInAs($w['operator'])->delete('/delete-ticket/' . $second);
        $this->assertSame([], $this->row($second), 'Premise: the operator can still delete any ticket.');
    }

    public function test_a_stranger_cannot_overwrite_another_tenants_ticket_note(): void
    {
        $this->enable();
        $w = $this->world();
        [$victim] = $this->ticket($w['owner']->id);

        foreach (['stranger' => $w['stranger'], 'the stranger\'s staff' => $w['strangerStaff']] as $who => $user) {
            $response = $this->signedInAs($user)->post('/ticket-note-store/' . $victim, ['note' => 'STRANGER-NOTE']);

            $this->assertNull($this->row($victim)['note'], "{$who} overwrote another tenant's internal note.");
            $this->refusedByTheGuard($response, "{$who} writing the note");
        }

        $this->signedInAs($w['owner'])->post('/ticket-note-store/' . $victim, ['note' => 'OWNER-NOTE']);
        $this->assertSame('OWNER-NOTE', $this->row($victim)['note'], 'Premise: the owner can still write their ticket\'s note.');

        $this->signedInAs($w['operator'])->post('/ticket-note-store/' . $victim, ['note' => 'OPERATOR-NOTE']);
        $this->assertSame('OPERATOR-NOTE', $this->row($victim)['note'], 'Premise: the operator can still write any note.');
    }

    public function test_a_stranger_cannot_add_a_reply_to_another_tenants_ticket(): void
    {
        $this->enable();
        $w = $this->world();
        [$victim] = $this->ticket($w['owner']->id);

        foreach (['stranger' => $w['stranger'], 'the stranger\'s staff' => $w['strangerStaff']] as $who => $user) {
            $response = $this->signedInAs($user)->post('/ticket-conversion-store/' . $victim, ['reply_description' => 'STRANGER-REPLY']);

            $this->assertSame(0, DB::table('helpdesk_conversions')->where('ticket_id', $victim)->count(), "{$who} added a reply to another tenant's ticket.");
            $this->refusedByTheGuard($response, "{$who} replying");
        }

        $this->signedInAs($w['owner'])->post('/ticket-conversion-store/' . $victim, ['reply_description' => 'OWNER-REPLY']);
        $this->signedInAs($w['operator'])->post('/ticket-conversion-store/' . $victim, ['reply_description' => 'OPERATOR-REPLY']);

        $this->assertEqualsCanonicalizing(
            ['OWNER-REPLY', 'OPERATOR-REPLY'],
            DB::table('helpdesk_conversions')->where('ticket_id', $victim)->pluck('description')->all(),
            'Premise: the owner and the operator can still reply.'
        );
    }

    public static function theThreeRowIdRoutes(): array
    {
        return [
            'delete' => ['DELETE', '/delete-ticket/', []],
            'note' => ['POST', '/ticket-note-store/', ['note' => 'X']],
            'reply' => ['POST', '/ticket-conversion-store/', ['reply_description' => 'X']],
        ];
    }

    /**
     * The guard reads the ROUTE parameter, which is what the handler takes as its method argument. A body key of the same name must not move the
     * check onto the caller's own row while the handler acts on somebody else's.
     */
    #[DataProvider('theThreeRowIdRoutes')]
    public function test_the_row_routes_are_judged_by_the_route_parameter_not_by_the_body(string $method, string $prefix, array $extra): void
    {
        $this->enable();
        $w = $this->world();
        [$victim] = $this->ticket($w['owner']->id);
        [$mine] = $this->ticket($w['stranger']->id);

        $response = $this->signedInAs($w['stranger'])->call($method, $prefix . $victim . '?ticketId=' . $mine . '&id=' . $mine, array_merge($extra, ['ticketId' => $mine, 'id' => $mine]));

        $this->assertNotSame([], $this->row($victim), 'A body key named the caller\'s own ticket and the handler acted on the victim\'s.');
        $this->assertNull($this->row($victim)['note']);
        $this->assertSame(0, DB::table('helpdesk_conversions')->where('ticket_id', $victim)->count());
        $this->refusedByTheGuard($response, 'the row route with a body key naming the caller\'s own ticket');
    }

    // ---- the identifier: what the encrypted segment decodes to ----------------------------------

    public static function theTwoEncodings(): array
    {
        // the module encrypts `$ticket->ticket_id`; PDO returns an integer on one driver and a numeric string on another
        return ['as an integer' => [false], 'as a string' => [true]];
    }

    #[DataProvider('theTwoEncodings')]
    public function test_the_number_is_judged_in_both_spellings_the_module_may_encrypt_it_in(bool $asString): void
    {
        $this->enable();
        $w = $this->world();
        [$id, $number, $tag] = $this->ticket($w['owner']->id);
        $segment = Crypt::encrypt($asString ? (string) $number : $number);

        $stranger = $this->signedInAs($w['stranger'])->get('/edit-ticket/' . $segment);
        $this->assertStringNotContainsString('FIXTURE-DESCRIPTION-' . $tag, (string) $stranger->getContent());
        $this->refusedByTheGuard($stranger, 'the stranger');
        $this->refusedByTheGuard($this->signedInAs($w['stranger'])->post('/update-ticket/' . $segment, ['category' => '1', 'subject' => 'X', 'description' => 'X']), 'the stranger updating');

        $owner = $this->signedInAs($w['owner'])->get('/edit-ticket/' . $segment);
        $this->assertSame(200, $owner->status(), 'Premise: the owner is still shown their ticket.');
        $this->assertStringContainsString('FIXTURE-SUBJECT-' . $tag, (string) $owner->getContent());
    }

    public function test_a_segment_that_is_not_exactly_a_ticket_number_is_refused_before_the_handler_for_everybody(): void
    {
        $this->enable();
        $w = $this->world();
        [$id, $number] = $this->ticket($w['owner']->id);
        $before = $this->row($id);

        $segments = [
            'not encrypted' => 'not-encrypted',
            'a plain number' => (string) $number,
            'an array' => Crypt::encrypt(['x' => 1]),
            'a word' => Crypt::encryptString('abc'),
            'a number with a tail' => Crypt::encrypt($number . '; DROP'),
            'a number that is no ticket' => Crypt::encrypt(1234),
            'a negative number' => Crypt::encrypt(-5),
            // A REAL ticket number inside something that is not exactly the serialised number: an unanchored pattern would find it, and the handler's
            // unserialize() would then be handed bytes the guard never judged
            'an array holding the number' => Crypt::encrypt([$number]),
            'an object holding the number' => Crypt::encrypt((object) ['n' => $number]),
            'a string with the number inside' => Crypt::encryptString("x i:{$number};"),
            'the number and a trailing newline' => Crypt::encryptString("i:{$number};\n"),
            'a declared length shorter than the number\'s' => Crypt::encryptString('s:2:"' . $number . '";'),
            'a declared length longer than the number\'s' => Crypt::encryptString('s:11:"' . $number . '";'),
            'a number of nineteen digits' => Crypt::encrypt(PHP_INT_MAX),
        ];

        // the owner AND the operator: the operator used to be admitted before the segment was read
        foreach (['the owner' => $w['owner'], 'the operator' => $w['operator']] as $who => $user) {
            foreach ($segments as $what => $segment) {
                // a WELL-FORMED number that names no ticket is the operator's "Ticket Not Found" from the handler: the operator has no ownership to prove
                if ($who === 'the operator' && $what === 'a number that is no ticket') {
                    continue;
                }

                foreach (['get' => ['/edit-ticket/', []], 'post' => ['/update-ticket/', ['category' => '1', 'subject' => 'X', 'description' => 'X']], 'reply' => ['/ticket-reply/', []]] as $verb => [$prefix, $body]) {
                    $response = $verb === 'post' ? $this->signedInAs($user)->post($prefix . $segment, $body) : $this->signedInAs($user)->get($prefix . $segment);

                    $this->assertLessThan(500, $response->status(), "{$what} for {$who} on {$prefix} caused a server error.");
                    $this->refusedByTheGuard($response, "{$what} for {$who} on {$prefix}");
                }
            }
        }

        $this->assertSame($before, $this->row($id));
    }

    /**
     * Two layers hold this, either alone is enough, and this case cannot tell them apart: the guard refuses a segment that is not exactly a ticket number
     * before the handler runs (the case above asserts THAT, for the owner and the operator), and the container's encrypter refuses a payload that is not plain data even if it were
     * handed one (P1-SEC-77). It is the end-to-end statement: no object nobody asked for is built through these routes, for anybody.
     */
    public function test_a_forged_segment_never_builds_an_object_through_these_routes_for_anybody(): void
    {
        $this->enable();
        $w = $this->world();
        [, $number] = $this->ticket($w['owner']->id);

        foreach (['/edit-ticket/', '/ticket-reply/'] as $prefix) {
            foreach (['the stranger' => $w['stranger'], 'the owner' => $w['owner'], 'the operator' => $w['operator']] as $who => $user) {
                HelpdeskWakeProbe::$woken = 0;

                $this->signedInAs($user)->get($prefix . Crypt::encrypt(new HelpdeskWakeProbe));
                $this->signedInAs($user)->post('/update-ticket/' . Crypt::encrypt(new HelpdeskWakeProbe), ['category' => '1', 'subject' => 'X', 'description' => 'X']);

                $this->assertSame(0, HelpdeskWakeProbe::$woken, "A forged segment was unserialized by a handler for {$who} on {$prefix}: an object nobody asked for was built.");
            }
        }

        // Premise: the probe really is built when a ciphertext IS unserialized -- by the FRAMEWORK's encrypter, not the container's, which since P1-SEC-77 refuses
        // anything that is not plain data. So a 0 above is the guard (this file) and the object-free encrypter (ObjectFreeEncrypterTest) together, and not a probe that cannot fire.
        $stock = new \Illuminate\Encryption\Encrypter(random_bytes(32), 'aes-256-cbc');
        HelpdeskWakeProbe::$woken = 0;
        $stock->decrypt($stock->encrypt(new HelpdeskWakeProbe));
        $this->assertSame(1, HelpdeskWakeProbe::$woken, 'Premise: the probe counts its own wake-up.');
    }

    public function test_head_is_judged_like_get(): void
    {
        $this->enable();
        $w = $this->world();
        [, $number] = $this->ticket($w['owner']->id);

        $this->refusedByTheGuard($this->signedInAs($w['stranger'])->call('HEAD', '/edit-ticket/' . Crypt::encrypt($number)), 'the stranger, by HEAD');
        $this->assertSame(200, $this->signedInAs($w['owner'])->call('HEAD', '/edit-ticket/' . Crypt::encrypt($number))->status(), 'Premise: the owner is answered by HEAD.');
    }

    #[DataProvider('theThreeRowIdRoutes')]
    public function test_a_row_id_with_a_tail_is_refused_by_the_guard_even_for_the_owner(string $method, string $prefix, array $extra): void
    {
        $this->enable();
        $w = $this->world();
        [$mine] = $this->ticket($w['owner']->id);

        // `12abc` is row 12 to MySQL: the guard is stricter than the engine, so the two can never name different rows
        $response = $this->signedInAs($w['owner'])->call($method, $prefix . $mine . 'abc', $extra);

        $this->refusedByTheGuard($response, 'a row id with a tail');
        $this->assertNotSame([], $this->row($mine));
    }

    // ---- whose a ticket is ----------------------------------------------------------------------

    public function test_a_ticket_the_operator_raised_for_a_merchant_is_the_merchants_to_read_reply_to_and_delete(): void
    {
        $this->enable();
        $w = $this->world();
        // created_by = the operator, user_id = the merchant: only the user_id limb lets the merchant in
        [$id, $number, $tag] = $this->ticket($w['operator']->id, $w['owner']->id);
        $segment = Crypt::encrypt($number);

        $this->assertStringContainsString('FIXTURE-SUBJECT-' . $tag, (string) $this->signedInAs($w['owner'])->get('/edit-ticket/' . $segment)->getContent(), 'The merchant was refused a ticket the operator raised for them.');
        // the conversation screen prints the name, e-mail and DESCRIPTION; the edit form prints the subject
        $this->assertStringContainsString('FIXTURE-DESCRIPTION-' . $tag, (string) $this->signedInAs($w['staff'])->get('/ticket-reply/' . $segment)->getContent(), 'The merchant\'s staff were refused a ticket the operator raised for the tenant.');
        $this->refusedByTheGuard($this->signedInAs($w['stranger'])->get('/edit-ticket/' . $segment), 'the stranger');

        $this->signedInAs($w['owner'])->delete('/delete-ticket/' . $id);
        $this->assertSame([], $this->row($id), 'Premise: the merchant can still delete a ticket raised for them.');
    }

    public function test_the_creator_of_a_ticket_keeps_it_when_its_user_id_is_someone_else(): void
    {
        $this->enable();
        $w = $this->world();
        // created_by = the owner, user_id = the operator: only the created_by limb lets the owner in
        [, $number, $tag] = $this->ticket($w['owner']->id, $w['operator']->id);

        $this->assertStringContainsString('FIXTURE-SUBJECT-' . $tag, (string) $this->signedInAs($w['owner'])->get('/edit-ticket/' . Crypt::encrypt($number))->getContent(), 'The creator of a ticket was refused it because its user_id is someone else.');
        $this->refusedByTheGuard($this->signedInAs($w['stranger'])->get('/edit-ticket/' . Crypt::encrypt($number)), 'the stranger');
    }

    public function test_a_user_rooted_at_the_operator_is_nobodys_colleague_on_these_routes_either(): void
    {
        $this->enable();
        $w = $this->world();
        // the operator's OWN ticket. A merchant who demoted themselves (or staff the operator created) has creatorId() = 1, which is the operator's tenant
        $demoted = $this->makeStaff($w['operator']);
        foreach (self::PERMISSIONS as $permission) {
            $this->grantPermission($demoted, $permission);
        }
        // The operator holds no `user_active_modules` rows (its own modules come from its type), so such a user is stopped EARLIER, by PlanModuleCheck, and
        // the ownership rule is never asked. A row is written so that it is: without this premise the test passes against the unguarded routes too.
        DB::table('user_active_modules')->insert(['user_id' => $w['operator']->id, 'module' => 'Helpdesk', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertContains('Helpdesk', ActivatedModule($demoted->id), 'Premise: the demoted user passes the plan check, so only the ownership rule can refuse them.');
        [$id, $number, $tag] = $this->ticket($w['operator']->id);
        $segment = Crypt::encrypt($number);

        $this->assertStringNotContainsString('FIXTURE-DESCRIPTION-' . $tag, (string) $this->signedInAs($demoted)->get('/ticket-reply/' . $segment)->getContent(), 'A user rooted at the operator was shown the operator\'s own ticket.');
        $this->signedInAs($demoted)->post('/update-ticket/' . $segment, ['category' => '1', 'subject' => 'DEMOTED', 'description' => 'DEMOTED']);
        $this->signedInAs($demoted)->delete('/delete-ticket/' . $id);
        $this->signedInAs($demoted)->post('/ticket-note-store/' . $id, ['note' => 'DEMOTED']);

        $row = $this->row($id);
        $this->assertNotSame([], $row, 'A user rooted at the operator deleted the operator\'s own ticket.');
        $this->assertSame('FIXTURE-SUBJECT-' . $tag, $row['subject']);
        $this->assertNull($row['note']);
    }

    // ---- the user lookup ------------------------------------------------------------------------

    public function test_the_user_lookup_is_for_the_operator_alone(): void
    {
        $this->enable();
        $w = $this->world();
        $everybody = ['the owner' => $w['owner'], 'the owner\'s staff' => $w['staff'], 'the stranger' => $w['stranger'], 'the stranger\'s staff' => $w['strangerStaff'], 'the operator' => $w['operator']];

        $ask = fn (User $caller, mixed $userId) => $this->signedInAs($caller)->postJson('/get-user-data', ['user_id' => $userId]);

        // a merchant, or their staff, is told nothing about anybody: another tenant's owner or staff, a colleague, themself, the operator
        foreach (['a stranger' => $w['stranger'], 'the owner\'s staff' => $w['staff']] as $caller => $callerUser) {
            foreach ($everybody as $target => $targetUser) {
                $response = $ask($callerUser, $targetUser->id);

                $this->assertSame(403, $response->status(), "{$caller} was answered about {$target}.");
                $this->assertStringNotContainsString($targetUser->email, (string) $response->getContent(), "{$caller} was told {$target}'s e-mail.");
            }
        }

        // the form-encoded spelling (what the operator's form sends) is refused for them with the guard's redirect, never answered
        $form = $this->signedInAs($w['stranger'])->post('/get-user-data', ['user_id' => (string) $w['owner']->id]);
        $this->assertStringNotContainsString($w['owner']->email, (string) $form->getContent(), 'A form-encoded lookup was answered for a merchant.');
        $this->refusedByTheGuard($form, 'a form-encoded lookup by a merchant');

        // premise: the operator -- the one caller the forms have -- is answered for any user, and for the blank "Select User" option with "not found"
        foreach (['a merchant' => $w['owner'], 'a merchant\'s staff' => $w['staff']] as $what => $target) {
            $this->assertSame($target->email, $ask($w['operator'], $target->id)->json('email'), "Premise: the operator can still look {$what} up.");
        }
        $this->assertSame($w['owner']->email, $this->signedInAs($w['operator'])->post('/get-user-data', ['user_id' => (string) $w['owner']->id])->json('email'), 'Premise: the operator\'s own form request (the id as a string) is answered.');

        $blank = $ask($w['operator'], '');
        $this->assertSame(200, $blank->status(), 'The blank option of the operator\'s form must still be answered, or the e-mail field keeps the previous company\'s address.');
        $this->assertArrayHasKey('error', $blank->json());
    }

    public function test_the_user_lookup_is_no_oracle_for_which_ids_exist_and_refuses_anything_that_is_not_an_id_to_a_merchant(): void
    {
        $this->enable();
        $w = $this->world();

        $existing = $this->signedInAs($w['stranger'])->postJson('/get-user-data', ['user_id' => $w['owner']->id]);
        $missing = $this->signedInAs($w['stranger'])->postJson('/get-user-data', ['user_id' => 987654]);

        $this->assertSame(403, $existing->status());
        $this->assertSame(403, $missing->status(), 'An id that does not exist answered differently from one that exists but is not the caller\'s: an oracle.');
        $this->assertSame($existing->getContent(), $missing->getContent());

        foreach (['an array' => [$w['owner']->id], 'a negative id' => '-1', 'a tail' => $w['owner']->id . 'abc', 'a decimal' => $w['owner']->id . '.0', 'a huge number' => str_repeat('9', 30), 'blank' => ''] as $what => $value) {
            $response = $this->signedInAs($w['stranger'])->postJson('/get-user-data', ['user_id' => $value]);

            $this->assertSame(403, $response->status(), "{$what} was not refused.");
            $this->assertStringNotContainsString($w['owner']->email, (string) $response->getContent());
        }
    }
}

/**
 * A harmless stand-in for "whatever a forged segment carries": it does nothing but count its own `__wakeup`, which `unserialize()` calls on a class it is
 * allowed to instantiate. It lets the test show that an object nobody asked for is BUILT without exploiting anything.
 */
class HelpdeskWakeProbe
{
    public static int $woken = 0;

    public function __wakeup(): void
    {
        self::$woken++;
    }
}
