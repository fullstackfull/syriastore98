<?php

namespace App\Http\Controllers;

use App\Models\PlatformIncident;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\PlatformIncidentLog;
use App\Services\SaasPlatformOperations;
use App\Services\SaasPlatformResources;
use App\Support\Operations\IncidentSeverity;
use App\Support\Operations\IncidentState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * OPS-003 — THE PLATFORM OPERATOR'S INCIDENT LOG.
 * =================================================================================================
 *
 * "إدارة الحوادث والتنبيهات والتشخيص" — incident, alert and diagnostics management. This is the
 * RECORD half: five actions over `platform_incidents`, each of them going through
 * `PlatformIncidentLog`, which is the only writer and which writes an audit event for every one.
 *
 * THE ALERT HALF IS NOT HERE AND IS NOT COMING BY ACCIDENT. Nothing in this controller reads a
 * figure, compares it with a threshold or notifies anybody, and `PlatformIncidentPolicy` records
 * the five decisions that would be needed before it could — thresholds, recipients, response
 * targets, merchant visibility, retention. The screens say so on their face.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────────
 * THE GATE IS IDENTITY, NOT A PERMISSION, AND EVERY ACTION CHECKS AGAIN
 * ──────────────────────────────────────────────────────────────────────────────────────────────────
 *
 * The reasoning is `SuperAdminResourcesController`'s verbatim, because the situation is identical: a
 * merchant creates their own roles (`RoleController::store`, reachable with the `Create Role`
 * permission the shared `admin` role carries for every self-registered merchant), so "holds
 * permission X" is substantially self-assignable, while `users.type` is not. And there is no
 * super-admin bypass in Laratrust here, so `isAbleTo()` alone would REFUSE the platform operator —
 * a permission gate would be wrong in both directions at once.
 *
 * SO WHY REPEAT THE TEST IN EVERY ACTION, five times, when the routes carry
 * `PlatformOperatorOnly`? Because `CLAUDE.md` records exactly how a route gate disappears:
 * `RouteCollection::addToCollections()` keys on method+domain+uri, so a later declaration of the
 * same verb and URI REPLACES the earlier one and silently drops its middleware — and `GAP-268`
 * found a real duplicated declaration in `routes/web.php`. On a read-only screen that would be a
 * disclosure; on `store`, `transition` and `note` it would be a stranger writing the platform's
 * operational history, which is the one record that must not be forgeable.
 *
 * `instanceof User` FIRST, AND THE ORDER IS THE CONTROL. `UserPolicy::isOperator()` takes an
 * untyped actor and reads `->type`, and `customers` HAS a `type` column with `'type'` in
 * `Customer::$fillable` — so a `Customer` row typed `super admin` satisfies `isOperator()`.
 * `instanceof` short-circuits, so the helper never reads `->type` off a non-`User` at all.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT IS READ FROM THE REQUEST, AND WHAT IS NOT
 * ──────────────────────────────────────────────────────────────────────────────────────────────────
 *
 * This is the first screen in this workstream that WRITES, so unlike its two read-only neighbours
 * it does read request input — and every field is either validated against a declared set or is
 * free text that is stored and escaped, never interpreted.
 *
 * THE THREE THINGS IT POINTEDLY DOES NOT TAKE FROM THE REQUEST:
 *
 *   - the ACTOR. `opened_by` and `actor_id` come from `auth()->id()`. A request-supplied actor on
 *     an audit trail is a forged signature, and `CLAUDE.md` records that a predicate built from
 *     caller-supplied input has produced four separate defects here.
 *   - the CURRENT state. `transition()` reads it off the stored row; a `from` in the request would
 *     let a caller claim a move the incident was never in a position to make.
 *   - the SNAPSHOT. Taken here, by calling the two measurement producers, not accepted as a
 *     payload. An operator-supplied diagnostics blob would be an unverified claim stored as a
 *     measurement, which is the opposite of what OPS-002's one load-bearing word asks for.
 *
 * `detected_at` IS TAKEN from the request, because it is the one thing only the operator knows: an
 * incident is nearly always recorded after it began. It is validated as a date no later than now —
 * an incident that began in the future is a typo, and storing it would make every duration
 * computed from it negative.
 */
class SuperAdminIncidentsController extends Controller
{
    public function __construct(private readonly PlatformIncidentLog $log = new PlatformIncidentLog())
    {
    }

    /** The log: live incidents worst-first, then the history. */
    public function index(Request $request)
    {
        $this->refuseAnyoneButTheOperator();

        /*
         * `openedBy` IS EAGER-LOADED because the table prints the operator's name on every row,
         * and `PlatformIncident::openedByName()` resolves it through the relation. Without this
         * the list is one query plus one per incident — the shape that is invisible on a screen
         * with three rows and is the whole page on a platform with a year of history.
         */
        $all = PlatformIncident::query()
            ->with('openedBy')
            ->orderByDesc('detected_at')
            ->orderByDesc('id')
            ->get();

        /*
         * SORTED BY `IncidentSeverity::rank()` AND NOT BY THE COLUMN. A string sort puts
         * `critical` after `major` alphabetically, which would list the worst live incident
         * second. The rank is derived from the declared order, so this cannot drift from the
         * vocabulary.
         */
        $live = $all->filter(static fn (PlatformIncident $i): bool => $i->isLive())
            ->sortBy([
                static fn (PlatformIncident $a, PlatformIncident $b): int
                    => IncidentSeverity::rank((string) $a->severity) <=> IncidentSeverity::rank((string) $b->severity),
            ])
            ->values();

        return view('superadmin.platform_incidents.index', [
            'live' => $live,
            'closed' => $all->reject(static fn (PlatformIncident $i): bool => $i->isLive())->values(),
            'severities' => IncidentSeverity::all(),
        ]);
    }

    /** One incident and its whole trail. */
    public function show(Request $request, $id)
    {
        $this->refuseAnyoneButTheOperator();

        $incident = PlatformIncident::with(['openedBy', 'events.actor'])->find($id);

        if ($incident === null) {
            return redirect()->route('platform.incidents.index')
                ->with('error', __('That incident does not exist.'));
        }

        return view('superadmin.platform_incidents.show', [
            'incident' => $incident,
            'moves' => $incident->availableMoves(),
        ]);
    }

    /**
     * RECORD AN INCIDENT, with the measurement snapshot taken here.
     *
     * The snapshot is BOTH producers' output, because the two answer different questions and an
     * incident can be about either: `SaasPlatformResources` is this host's load, memory and disk;
     * `SaasPlatformOperations` is the shared database, the queues and the integrations. Taking only
     * one would mean an incident recorded during a disk outage carried no disk figures.
     *
     * A SNAPSHOT THAT CANNOT BE TAKEN IS NOT A REASON TO REFUSE THE INCIDENT. If a producer throws
     * — a host in a state nobody anticipated is exactly when an incident is being recorded — the
     * incident is still written, with no snapshot. Losing the record of an outage because the
     * machine was too broken to measure would be the worst possible failure mode for this feature.
     */
    public function store(Request $request)
    {
        $this->refuseAnyoneButTheOperator();

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:160',
            'summary' => 'nullable|string|max:5000',
            'severity' => 'required|string|in:' . implode(',', IncidentSeverity::all()),
            'detected_at' => 'nullable|date|before_or_equal:now',
        ]);

        if ($validator->fails()) {
            /*
             * `only()` AND NOT A BARE `withInput()`, which is this repository's own rule and which
             * `CredentialSinksTest::test_the_bare_with_input_census_is_what_the_middleware_was
             * _written_for` holds as a closed set: a bare call flashes the WHOLE request body into
             * the session, and the census's message says in terms that "a new HOST-APP site should
             * exclude its credential fields at source (the middleware is the net for the vendored
             * ones, not a licence to stop doing that)".
             *
             * This form has no credential field, so nothing secret would have been flashed today --
             * and naming the four fields is still the right call, for a reason this screen has of
             * its own: a caller can post anything, and the one key this handler deliberately
             * IGNORES is `diagnostics` (the snapshot is measured here, never submitted). A bare
             * `withInput()` would put a submitted `diagnostics` blob back into the session and
             * then into `old()`, which is the beginning of the path where it reaches a page.
             */
            return redirect()->route('platform.incidents.index')
                ->withInput($request->only(['title', 'summary', 'severity', 'detected_at']))
                ->with('error', $validator->errors()->first());
        }

        $incident = $this->log->open(
            (string) $request->input('title'),
            (string) $request->input('severity'),
            $request->filled('summary') ? (string) $request->input('summary') : null,
            $request->filled('detected_at')
                ? \Carbon\CarbonImmutable::parse((string) $request->input('detected_at'))
                : null,
            auth()->id(),
            $this->snapshot(),
        );

        return redirect()->route('platform.incidents.show', $incident->id)
            ->with('success', __('Incident :reference recorded.', ['reference' => $incident->reference]));
    }

    /** Move an incident, if the state machine allows it. */
    public function transition(Request $request, $id)
    {
        $this->refuseAnyoneButTheOperator();

        $incident = PlatformIncident::find($id);

        if ($incident === null) {
            return redirect()->route('platform.incidents.index')
                ->with('error', __('That incident does not exist.'));
        }

        $validator = Validator::make($request->all(), [
            'to' => 'required|string|in:' . implode(',', IncidentState::all()),
            'note' => 'nullable|string|max:5000',
        ]);

        if ($validator->fails()) {
            return redirect()->route('platform.incidents.show', $incident->id)
                ->with('error', $validator->errors()->first());
        }

        /*
         * THE VALIDATOR ONLY PROVES THE TARGET IS A REAL STATE. Whether this incident may move
         * there is the state machine's question and is asked by `transition()`, which refuses and
         * writes nothing. The refusal is caught and shown rather than being a 500, because a
         * second click on a button that was correct a moment ago is an ordinary thing for a person
         * to do — and because two operators working the same incident will race.
         */
        try {
            $this->log->transition(
                $incident,
                (string) $request->input('to'),
                $request->filled('note') ? (string) $request->input('note') : null,
                auth()->id(),
            );
        } catch (RuntimeException $e) {
            return redirect()->route('platform.incidents.show', $incident->id)
                ->with('error', __('That is not a move this incident can make. It is :status now.', [
                    'status' => $incident->fresh()->statusLabel(),
                ]));
        }

        return redirect()->route('platform.incidents.show', $incident->id)
            ->with('success', __('Incident updated.'));
    }

    /** Add to an incident without moving it. */
    public function note(Request $request, $id)
    {
        $this->refuseAnyoneButTheOperator();

        $incident = PlatformIncident::find($id);

        if ($incident === null) {
            return redirect()->route('platform.incidents.index')
                ->with('error', __('That incident does not exist.'));
        }

        $validator = Validator::make($request->all(), [
            'note' => 'required|string|max:5000',
        ]);

        if ($validator->fails()) {
            return redirect()->route('platform.incidents.show', $incident->id)
                ->with('error', $validator->errors()->first());
        }

        $this->log->note($incident, (string) $request->input('note'), auth()->id());

        return redirect()->route('platform.incidents.show', $incident->id)
            ->with('success', __('Note added.'));
    }

    /**
     * WHAT WAS MEASURED AT THE MOMENT THE INCIDENT WAS RECORDED.
     *
     * Both producers, and a failure in either loses the snapshot rather than the incident. See
     * `store()`'s docblock.
     *
     * @return array<string, mixed>|null
     */
    private function snapshot(): ?array
    {
        try {
            return [
                'machine' => SaasPlatformResources::measure(),
                'operations' => SaasPlatformOperations::measure(),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * DEFENCE IN DEPTH, AND NOT A SECOND SPELLING OF THE ROUTE GATE.
     *
     * The predicate is copied from `SuperAdminResourcesController::refuseAnyoneButTheOperator()`
     * character-for-character rather than rewritten: a second spelling of an operator test is how
     * `PlatformOperatorOnly` came to admit `' super admin'` while every other check refused it
     * (`trim` strips a leading space; `UserPolicy::isOperator()` does `rtrim($type, ' ')`, matching
     * MySQL's PAD SPACE). The full reasoning, including why the class clause comes first, is in
     * this class's own docblock.
     */
    private function refuseAnyoneButTheOperator(): void
    {
        $user = auth()->user();

        if (! $user instanceof User || ! UserPolicy::isOperator($user)) {
            abort(403);
        }
    }
}
