<?php

namespace Tests\Feature\Operations;

use App\Http\Controllers\SuperAdminIncidentsController;
use App\Models\Customer;
use App\Models\PlatformIncident;
use App\Models\User;
use App\Services\PlatformIncidentLog;
use App\Support\Operations\IncidentSeverity;
use App\Support\Operations\IncidentState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Security\SecurityTestCase;

/**
 * OPS-003 — THE INCIDENT LOG'S DOOR, AND WHAT GETS WRITTEN THROUGH IT.
 * =================================================================================================
 *
 * THIS IS THE FIRST WRITING SURFACE IN THIS WORKSTREAM, and that changes what the gate protects.
 * `/platform/resources` and `/platform/operations` disclose; these three POST routes let a caller
 * WRITE the platform's operational history, which is the one record that must not be forgeable. So
 * every action is driven against every principal, and the handler is driven DIRECTLY as well —
 * `CLAUDE.md`: a handler-level check behind an `abort()`ing middleware is INVISIBLE to an HTTP
 * test, and `PlanController::planDisable` admitted a `Customer` typed `super admin` behind a gate
 * whose HTTP tests were green (`GAP-307`).
 *
 * AND THE REFUSAL ASSERTIONS ARE KEYED ON THE REASON, NOT THE STATUS, where a status would be
 * ambiguous. `CLAUDE.md`: a gateless handler's own "not found" branch is also a 302, so a 302 from
 * a missing row and a 302 from a gate are the same status and opposite facts. The write cases
 * therefore assert the TABLE — a refused write must have written nothing — which cannot be
 * satisfied by the wrong branch.
 *
 * `config(['app.debug' => false])` before any body is read: an APP_DEBUG query log was once nearly
 * mis-filed as a disclosure in this repository.
 */
final class PlatformIncidentsScreenTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const URI = '/platform/incidents';

    private const PERMISSION = 'Manage Reports';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
    }

    private function anIncident(): PlatformIncident
    {
        return (new PlatformIncidentLog())->open('Queue stalled', IncidentSeverity::MAJOR);
    }

    private function incidentCount(): int
    {
        return (int) DB::table('platform_incidents')->count();
    }

    private function eventCount(): int
    {
        return (int) DB::table('platform_incident_events')->count();
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The door — reading
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_signed_out_visitor_cannot_open_the_log(): void
    {
        $response = $this->get(self::URI);

        $this->assertTrue($response->isRedirect(),
            'the platform incident log was served to a guest (status ' . $response->status() . ')');
        $this->assertNotSame(401, $response->status(),
            'a 401 forces a logout in the shipped Flutter client; `auth` runs first and redirects');
    }

    public function test_a_merchant_cannot_open_the_log(): void
    {
        [$merchant] = $this->makeTenant();

        $this->actingAs($merchant->fresh())->get(self::URI)->assertStatus(403);
    }

    public function test_a_staff_member_cannot_open_the_log(): void
    {
        [$merchant] = $this->makeTenant();

        $this->actingAs($this->makeStaff($merchant)->fresh())->get(self::URI)->assertStatus(403);
    }

    /** A self-assignable permission must not open the platform's own operational history. */
    public function test_a_staff_member_holding_manage_reports_is_still_refused(): void
    {
        [$merchant] = $this->makeTenant();
        $holder = $this->makeStaff($merchant);
        $this->grantPermission($holder, self::PERMISSION);

        $holder = $holder->fresh();

        $this->assertTrue($holder->isAbleTo(self::PERMISSION),
            'Premise: this principal really does hold ' . self::PERMISSION . '.');
        $this->actingAs($holder)->get('/analytics')->assertStatus(200);

        $this->actingAs($holder)->get(self::URI)->assertStatus(403);
    }

    public function test_the_platform_operator_can_open_the_log(): void
    {
        $this->actingAs($this->makePlatformAdmin()->fresh())->get(self::URI)->assertStatus(200);
    }

    public function test_a_merchant_cannot_read_one_incident(): void
    {
        $incident = $this->anIncident();
        [$merchant] = $this->makeTenant();

        $this->actingAs($merchant->fresh())
            ->get(self::URI . '/' . $incident->id)
            ->assertStatus(403);
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The door — writing. The table is the assertion, because a status is ambiguous here.
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_merchant_cannot_record_an_incident(): void
    {
        [$merchant] = $this->makeTenant();

        $before = $this->incidentCount();

        $this->actingAs($merchant->fresh())->post(self::URI, [
            'title' => 'Written by a merchant',
            'severity' => IncidentSeverity::CRITICAL,
        ])->assertStatus(403);

        $this->assertSame($before, $this->incidentCount(),
            'a merchant wrote a row into the platform\'s operational history');
    }

    public function test_a_merchant_cannot_move_an_incident(): void
    {
        $incident = $this->anIncident();
        [$merchant] = $this->makeTenant();

        $events = $this->eventCount();

        $this->actingAs($merchant->fresh())
            ->post(self::URI . '/' . $incident->id . '/transition', ['to' => IncidentState::RESOLVED])
            ->assertStatus(403);

        $this->assertSame(IncidentState::OPEN, DB::table('platform_incidents')->where('id', $incident->id)->value('status'),
            'a merchant resolved a platform incident');
        $this->assertSame($events, $this->eventCount(),
            'a refused move still wrote an event into the audit trail');
    }

    public function test_a_merchant_cannot_add_a_note(): void
    {
        $incident = $this->anIncident();
        [$merchant] = $this->makeTenant();

        $events = $this->eventCount();

        $this->actingAs($merchant->fresh())
            ->post(self::URI . '/' . $incident->id . '/note', ['note' => 'Written by a merchant'])
            ->assertStatus(403);

        $this->assertSame($events, $this->eventCount(),
            'a merchant wrote into a platform incident\'s audit trail');
    }

    /**
     * EVERY ACTION REFUSES ON ITS OWN, WITH THE ROUTE GATE REMOVED.
     *
     * The HTTP cases above pass whether or not the handlers check anything, because
     * `PlatformOperatorOnly` answers 403 and the handler is never reached. So each of the five
     * actions is called directly, with no middleware in the way, against three principals.
     *
     * EXACTLY 403 is asserted, and the `Customer` case is why. `customers` has a `type` column with
     * `'type'` in `Customer::$fillable`, and `UserPolicy::isOperator()` takes an untyped actor and
     * reads `->type` — so a Customer row typed `super admin` satisfies it. Without the `instanceof`
     * clause that Customer reaches the handler and raises a 500, and a 500 is not a refusal.
     */
    public function test_every_action_refuses_a_non_operator_with_the_route_gate_removed(): void
    {
        $incident = $this->anIncident();
        [$merchant] = $this->makeTenant();

        $controller = new SuperAdminIncidentsController();

        $principals = [
            'a merchant' => $merchant->fresh(),
            'a customer typed super admin' => $this->customerTypedSuperAdmin(),
            'nobody' => null,
        ];

        $calls = [
            'index' => fn () => $controller->index(Request::create(self::URI, 'GET')),
            'show' => fn () => $controller->show(Request::create(self::URI . '/' . $incident->id, 'GET'), $incident->id),
            'store' => fn () => $controller->store(Request::create(self::URI, 'POST', [
                'title' => 'Forged', 'severity' => IncidentSeverity::CRITICAL,
            ])),
            'transition' => fn () => $controller->transition(
                Request::create(self::URI . '/' . $incident->id . '/transition', 'POST', ['to' => IncidentState::RESOLVED]),
                $incident->id
            ),
            'note' => fn () => $controller->note(
                Request::create(self::URI . '/' . $incident->id . '/note', 'POST', ['note' => 'Forged']),
                $incident->id
            ),
        ];

        $incidents = $this->incidentCount();
        $events = $this->eventCount();

        foreach ($calls as $action => $call) {
            foreach ($principals as $who => $principal) {
                if ($principal === null) {
                    auth()->logout();
                } else {
                    auth()->setUser($principal);
                }

                try {
                    $call();

                    $this->fail("{$action}() served {$who} with the route gate removed");
                } catch (HttpException $e) {
                    $this->assertSame(403, $e->getStatusCode(),
                        "{$action}() answered {$e->getStatusCode()} rather than 403 for {$who}");
                }
            }
        }

        $this->assertSame($incidents, $this->incidentCount(),
            'a refused call still wrote an incident');
        $this->assertSame($events, $this->eventCount(),
            'a refused call still wrote into the audit trail');
        $this->assertSame(IncidentState::OPEN, DB::table('platform_incidents')->where('id', $incident->id)->value('status'),
            'a refused call still moved an incident');
    }

    /** A `Customer` row typed `super admin`: `type` is in `Customer::$fillable`, so it is reachable. */
    private function customerTypedSuperAdmin(): Customer
    {
        [$merchant, $store] = $this->makeTenant();

        $customer = new Customer();
        $customer->forceFill([
            'first_name' => 'Impostor',
            'last_name' => 'Impostor',
            'email' => 'impostor-' . uniqid() . '@example.test',
            'password' => bcrypt('x'),
            'type' => 'super admin',
            'store_id' => $store->id,
            'created_by' => $merchant->id,
        ])->save();

        return $customer;
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * What the operator can actually do
     * ---------------------------------------------------------------------------------------------
     */

    /** Recording one writes the row, attributes it to the operator, and lands on its own page. */
    public function test_the_operator_can_record_an_incident_and_it_is_attributed_to_them(): void
    {
        $operator = $this->makePlatformAdmin();

        $response = $this->actingAs($operator->fresh())->post(self::URI, [
            'title' => 'Card payments failing',
            'severity' => IncidentSeverity::CRITICAL,
            'summary' => 'Every card payment returned a provider error.',
        ]);

        $this->assertSame(1, $this->incidentCount());

        $row = (array) DB::table('platform_incidents')->first();

        $this->assertSame('Card payments failing', $row['title']);
        $this->assertSame(IncidentSeverity::CRITICAL, $row['severity']);
        $this->assertSame(IncidentState::OPEN, $row['status']);
        $this->assertSame((int) $operator->id, (int) $row['opened_by'],
            'the incident was not attributed to the operator who recorded it');

        $response->assertRedirect(route('platform.incidents.show', $row['id']));
    }

    /**
     * THE ACTOR IS NEVER TAKEN FROM THE REQUEST, which on an audit trail is a forged signature.
     *
     * A hostile `opened_by` and `actor_id` are sent and must be ignored in favour of the
     * authenticated operator.
     */
    public function test_a_request_supplied_actor_is_ignored(): void
    {
        $operator = $this->makePlatformAdmin();
        [$merchant] = $this->makeTenant();

        $this->actingAs($operator->fresh())->post(self::URI, [
            'title' => 'Something',
            'severity' => IncidentSeverity::MINOR,
            'opened_by' => $merchant->id,
            'actor_id' => $merchant->id,
        ]);

        $this->assertSame((int) $operator->id, (int) DB::table('platform_incidents')->value('opened_by'),
            'an incident was attributed to an id supplied in the request, so the audit trail can '
            . 'be signed with somebody else\'s name');
        $this->assertSame((int) $operator->id, (int) DB::table('platform_incident_events')->value('actor_id'),
            'an audit event was attributed to an id supplied in the request');
    }

    /**
     * THE SNAPSHOT IS TAKEN HERE AND NOT ACCEPTED AS A PAYLOAD.
     *
     * An operator-supplied diagnostics blob would be an unverified claim stored as a measurement,
     * which is the opposite of what OPS-002's one load-bearing word asks for. A hostile one is
     * sent, and what is stored must be the real measurement instead.
     */
    public function test_the_snapshot_is_measured_rather_than_submitted(): void
    {
        $operator = $this->makePlatformAdmin();

        $this->actingAs($operator->fresh())->post(self::URI, [
            'title' => 'Something',
            'severity' => IncidentSeverity::MINOR,
            'diagnostics' => ['machine' => ['processor' => [['key' => 'load_1', 'value' => 'FORGED']]]],
        ]);

        $stored = PlatformIncident::first()->diagnostics;

        $this->assertIsArray($stored, 'no snapshot was taken at all, so the incident records nothing about the system');
        $this->assertArrayHasKey('machine', $stored);
        $this->assertArrayHasKey('operations', $stored,
            'only one of the two producers was captured, so an incident during a disk outage '
            . 'carries no disk figures or an incident during a queue backlog carries no queue ones');
        $this->assertStringNotContainsString('FORGED', json_encode($stored),
            'a diagnostics payload from the request was stored as a measurement');

        /*
         * AND IT IS A REAL READING, not an empty shell: the engine the queries really go to has to
         * be in it, re-derived here.
         */
        $this->assertStringContainsString(DB::connection()->getDriverName(), json_encode($stored),
            'the stored snapshot does not contain the engine the queries go to, so it is not a '
            . 'measurement of this system');
    }

    /** An undeclared severity is refused by the request validation, and nothing is written. */
    public function test_an_undeclared_severity_is_refused_without_writing(): void
    {
        $operator = $this->makePlatformAdmin();

        $this->actingAs($operator->fresh())->post(self::URI, [
            'title' => 'Something',
            'severity' => 'catastrophic',
        ])->assertRedirect(route('platform.incidents.index'));

        $this->assertSame(0, $this->incidentCount(),
            'an incident with an invented severity was written');
    }

    /** An incident cannot have begun in the future: that is a typo, and it breaks every duration. */
    public function test_an_incident_cannot_have_begun_in_the_future(): void
    {
        $operator = $this->makePlatformAdmin();

        $this->actingAs($operator->fresh())->post(self::URI, [
            'title' => 'Something',
            'severity' => IncidentSeverity::MINOR,
            'detected_at' => CarbonImmutable::now()->addDays(3)->toDateTimeString(),
        ]);

        $this->assertSame(0, $this->incidentCount(),
            'an incident was recorded as having begun in the future, so every duration computed '
            . 'from it is negative');
    }

    /** An operator can move an incident, and the trail records who. */
    public function test_the_operator_can_move_an_incident(): void
    {
        $incident = $this->anIncident();
        $operator = $this->makePlatformAdmin();

        $this->actingAs($operator->fresh())
            ->post(self::URI . '/' . $incident->id . '/transition', [
                'to' => IncidentState::ACKNOWLEDGED,
                'note' => 'On it.',
            ])
            ->assertRedirect(route('platform.incidents.show', $incident->id));

        $row = (array) DB::table('platform_incidents')->where('id', $incident->id)->first();

        $this->assertSame(IncidentState::ACKNOWLEDGED, $row['status']);
        $this->assertNotNull($row['acknowledged_at']);

        $event = (array) DB::table('platform_incident_events')->orderByDesc('id')->first();

        $this->assertSame(IncidentState::ACKNOWLEDGED, $event['to_status']);
        $this->assertSame((int) $operator->id, (int) $event['actor_id']);
        $this->assertSame('On it.', $event['note']);
    }

    /**
     * A MOVE THE MACHINE REFUSES IS A MESSAGE, NOT A 500 AND NOT A SILENT SUCCESS.
     *
     * A second click on a button that was correct a moment ago is an ordinary thing for a person to
     * do, and two operators working the same incident will race. So the refusal is shown and
     * NOTHING is written — asserted on the table, because the redirect alone is the same status as
     * a success.
     */
    public function test_a_move_the_machine_refuses_is_reported_and_writes_nothing(): void
    {
        $incident = $this->anIncident();
        $operator = $this->makePlatformAdmin();

        $this->actingAs($operator->fresh())
            ->post(self::URI . '/' . $incident->id . '/transition', ['to' => IncidentState::RESOLVED]);

        $events = $this->eventCount();
        $stamp = DB::table('platform_incidents')->where('id', $incident->id)->value('resolved_at');

        $response = $this->actingAs($operator->fresh())
            ->post(self::URI . '/' . $incident->id . '/transition', ['to' => IncidentState::RESOLVED]);

        $response->assertRedirect(route('platform.incidents.show', $incident->id));
        $response->assertSessionHas('error');

        $this->assertSame($events, $this->eventCount(),
            'a refused repeat wrote a second resolution event');
        $this->assertSame($stamp, DB::table('platform_incidents')->where('id', $incident->id)->value('resolved_at'),
            'a refused repeat moved the resolution time');
    }

    /** An id that is not an incident is an error message, not a 404 page and not a 500. */
    public function test_an_unknown_incident_is_an_error_message_on_the_log(): void
    {
        $operator = $this->makePlatformAdmin();

        $response = $this->actingAs($operator->fresh())->get(self::URI . '/987654');

        $response->assertRedirect(route('platform.incidents.index'));
        $response->assertSessionHas('error');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * What the page says
     * ---------------------------------------------------------------------------------------------
     */

    /** The log lists an incident with its reference, and links to its own history. */
    public function test_the_log_lists_an_incident_and_links_to_its_history(): void
    {
        $incident = $this->anIncident();
        $operator = $this->makePlatformAdmin();

        $html = $this->actingAs($operator->fresh())->get(self::URI)->assertOk()->getContent();

        $this->assertStringContainsString($incident->reference, $html);
        $this->assertStringContainsString('href="' . route('platform.incidents.show', $incident->id) . '"', $html);
    }

    /**
     * THE DETAIL PAGE SHOWS THE WHOLE TRAIL, and the assertion is on the SEQUENCE rather than on
     * the words being present somewhere: a page that printed only the latest event would satisfy a
     * "contains" assertion for the last note.
     */
    public function test_the_detail_page_shows_every_event_in_order(): void
    {
        $log = new PlatformIncidentLog();
        $incident = $log->open('Worker dying', IncidentSeverity::MAJOR);
        $log->transition($incident, IncidentState::ACKNOWLEDGED, 'FIRST-NOTE');
        $log->note($incident, 'SECOND-NOTE');
        $log->transition($incident->refresh(), IncidentState::MITIGATED, 'THIRD-NOTE');

        $operator = $this->makePlatformAdmin();

        $html = $this->actingAs($operator->fresh())
            ->get(self::URI . '/' . $incident->id)->assertOk()->getContent();

        $positions = [];

        foreach (['FIRST-NOTE', 'SECOND-NOTE', 'THIRD-NOTE'] as $marker) {
            $at = strpos($html, $marker);

            $this->assertNotFalse($at, "the trail does not show '{$marker}'");

            $positions[] = $at;
        }

        $sorted = $positions;
        sort($sorted);

        $this->assertSame($sorted, $positions,
            'the trail is not in the order the events happened, so the sequence — which is the '
            . 'whole value of the log — cannot be read off it');
    }

    /**
     * ONLY THE MOVES THE MACHINE ALLOWS HAVE A BUTTON, and the ones it refuses have none.
     *
     * Both directions, because a page that offered every move would pass the first half.
     */
    public function test_only_the_allowed_moves_are_offered(): void
    {
        $log = new PlatformIncidentLog();
        $incident = $log->open('Something', IncidentSeverity::MINOR);
        $incident = $log->transition($incident, IncidentState::MITIGATED);

        $operator = $this->makePlatformAdmin();

        $html = $this->actingAs($operator->fresh())
            ->get(self::URI . '/' . $incident->id)->assertOk()->getContent();

        $this->assertStringContainsString('value="' . IncidentState::RESOLVED . '"', $html,
            'a mitigated incident cannot be resolved from its own page');

        foreach ([IncidentState::OPEN, IncidentState::ACKNOWLEDGED] as $refused) {
            $this->assertStringNotContainsString('name="to" value="' . $refused . '"', $html,
                "a mitigated incident offers a move to {$refused}, which the state machine refuses");
        }
    }

    /**
     * THE SNAPSHOT ON THE PAGE IS THE ONE THAT WAS TAKEN, not a fresh reading.
     *
     * Three jobs are queued AFTER the incident is recorded and the page must still show the figure
     * as it was. A page that re-measured would show three.
     */
    public function test_the_detail_page_shows_the_snapshot_as_it_was_taken(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.driver' => 'database',
            'queue.connections.database.table' => 'jobs',
        ]);

        $operator = $this->makePlatformAdmin();

        $this->actingAs($operator->fresh())->post(self::URI, [
            'title' => 'Queue stalled',
            'severity' => IncidentSeverity::MAJOR,
        ]);

        $id = (int) DB::table('platform_incidents')->value('id');

        for ($i = 0; $i < 3; $i++) {
            DB::table('jobs')->insert([
                'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
                'reserved_at' => null, 'available_at' => time(), 'created_at' => time(),
            ]);
        }

        $html = $this->actingAs($operator->fresh())->get(self::URI . '/' . $id)->assertOk()->getContent();

        $from = strpos($html, 'ss-incident-detail-start');
        $to = strpos($html, 'ss-incident-detail-end', (int) $from);
        $detail = substr($html, (int) $from, (int) $to - (int) $from);

        $row = $this->figureCell($detail, __('Jobs waiting'));

        $this->assertSame('0', $row,
            'the saved snapshot shows the queue as it is now rather than as it was when the '
            . 'incident was recorded, so it is not a snapshot');
    }

    /** An incident with no snapshot says so rather than showing an empty table. */
    public function test_an_incident_with_no_snapshot_says_so(): void
    {
        $incident = $this->anIncident();      // opened through the log, with no diagnostics
        $operator = $this->makePlatformAdmin();

        $html = $this->actingAs($operator->fresh())
            ->get(self::URI . '/' . $incident->id)->assertOk()->getContent();

        $this->assertStringContainsString('No measurements were saved with this incident', $html,
            'an incident with no snapshot shows an empty measurement area with no explanation');
    }

    /** The log states that nothing opens an incident and nothing alerts anybody. */
    public function test_the_log_says_that_nothing_opens_an_incident_by_itself(): void
    {
        $operator = $this->makePlatformAdmin();

        $html = $this->actingAs($operator->fresh())->get(self::URI)->assertOk()->getContent();

        foreach ([
            'Nothing opens an incident by itself' => 'the log does not say that every row was written by a person',
            'sends an alert' => 'the log does not say that nothing on this platform alerts anybody',
            'has not been decided' => 'the log does not say that whether a merchant ever sees an incident is undecided',
        ] as $needle => $why) {
            $this->assertStringContainsString($needle, $html, $why);
        }
    }

    /**
     * AN EMPTY LOG DOES NOT CLAIM THE PLATFORM IS HEALTHY, which is the honesty point on this
     * screen: nothing watches the figures, so "no incident is open" means only that nobody has
     * written one.
     */
    public function test_an_empty_log_does_not_claim_the_platform_is_healthy(): void
    {
        $operator = $this->makePlatformAdmin();

        $html = $this->actingAs($operator->fresh())->get(self::URI)->assertOk()->getContent();

        $this->assertStringContainsString('No incident is open', $html);
        $this->assertStringContainsString('not a statement that the platform is healthy', $html,
            'an empty incident log reads as a clean bill of health, and nothing on this platform '
            . 'watches anything');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Both languages
     * ---------------------------------------------------------------------------------------------
     */

    public function test_the_log_renders_in_arabic_and_right_to_left(): void
    {
        $this->anIncident();

        $operator = $this->makePlatformAdmin();
        $operator->language = 'ar';
        $operator->save();

        $html = $this->actingAs($operator->fresh())->get(self::URI)->assertOk()->getContent();

        $this->assertStringContainsString('dir="rtl"', $html,
            'premise failed: the document is not right-to-left for an Arabic operator');
        $this->assertStringContainsString('حوادث المنصة', $this->between($html, 'ss-page-title', '</h1>'),
            'the page heading rendered in English for an Arabic operator');
        $this->assertStringContainsString('مفتوحة الآن', $html,
            'the live-incident heading rendered in English for an Arabic operator');

        /*
         * THE STORED VALUE IS A MACHINE KEY AND THE WORD IS RESOLVED ON READ, which is the reason
         * `IncidentState` keeps the two apart. An incident recorded by an English-speaking operator
         * has to read correctly in Arabic, and this is the assertion that proves it does.
         */
        $this->assertStringContainsString('كبير', $html,
            'the severity rendered in English for an Arabic operator, so the label was stored '
            . 'rather than resolved');
    }

    public function test_the_log_renders_in_english_and_left_to_right(): void
    {
        $this->anIncident();

        $operator = $this->makePlatformAdmin(['language' => 'en']);

        $html = $this->actingAs($operator->fresh())->get(self::URI)->assertOk()->getContent();

        $this->assertStringContainsString('dir="ltr"', $html,
            'premise failed: the document is not left-to-right for an English operator');
        $this->assertStringContainsString('Platform incidents', $this->between($html, 'ss-page-title', '</h1>'));
        $this->assertStringContainsString('Open now', $html);
        $this->assertStringContainsString('Major', $html);
    }

    public function test_the_detail_page_renders_in_arabic(): void
    {
        $incident = $this->anIncident();

        $operator = $this->makePlatformAdmin();
        $operator->language = 'ar';
        $operator->save();

        $html = $this->actingAs($operator->fresh())
            ->get(self::URI . '/' . $incident->id)->assertOk()->getContent();

        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('السجل', $html,
            'the history heading rendered in English for an Arabic operator');
        $this->assertMatchesRegularExpression('/\p{Arabic}/u',
            $this->between($html, 'ss-incident-detail-start', 'ss-incident-detail-end'),
            'the incident detail rendered with no Arabic in it for an Arabic operator');
    }

    /** The screen is reachable from the operator dashboard, not only by typing its URL. */
    public function test_the_log_is_linked_from_the_operator_dashboard(): void
    {
        $operator = $this->makePlatformAdmin();
        $this->makeStore($operator, ['default_language' => 'en']);
        (new User)->flushCache();
        Cache::forget('admin_details');

        $html = $this->actingAs(User::find($operator->id))->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('href="' . route('platform.incidents.index') . '"', $html,
            'a built screen nobody can find is not delivered: the operator dashboard does not link here');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * helpers
     * ---------------------------------------------------------------------------------------------
     */

    /** The figure printed against one label, exactly — see `PlatformOperationsScreenTest`'s note. */
    private function figureCell(string $html, string $label): string
    {
        $from = strpos($html, '>' . $label . '<');

        if ($from === false) {
            return '';
        }

        $to = strpos($html, '</tr>', $from);

        if ($to === false) {
            return '';
        }

        if (preg_match('#<td>(.*?)</td>#s', substr($html, $from, $to - $from), $matches) !== 1) {
            return '';
        }

        return trim($matches[1]);
    }

    private function between(string $html, string $open, string $close): string
    {
        $from = strpos($html, $open);

        if ($from === false) {
            return '';
        }

        $to = strpos($html, $close, $from);

        return $to === false ? '' : substr($html, $from, $to - $from);
    }
}
