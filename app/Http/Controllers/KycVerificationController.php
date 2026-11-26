<?php

namespace App\Http\Controllers;

use App\Models\KycDocument;
use App\Models\KycVerification;
use App\Models\Store;
use App\Models\User;
use App\Services\Kyc\KycAccess;
use App\Services\Kyc\KycAccessLog;
use App\Services\Kyc\KycAuthority;
use App\Services\Kyc\KycDocumentStore;
use App\Services\Kyc\KycRefusal;
use App\Services\Kyc\KycRenewal;
use App\Services\Kyc\KycReview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * KYC-001…KYC-003 — THE DOORS. A MERCHANT'S OWN VERIFICATION, AND THE OPERATOR'S REVIEW QUEUE.
 * =================================================================================================
 *
 * EVERY HANDLER DERIVES ITS TENANT FROM THE PRINCIPAL OR FROM THE ROW, NEVER FROM REQUEST INPUT.
 * There is no `$request->store_id` anywhere in this file, and that is the single most productive
 * defect shape in this codebase: CLAUDE.md records a predicate built from caller-supplied input
 * producing four separate defects directly, and the `custom.auth` variant — a handler shaped
 * `if (! auth('customers')->user()) { $id = $request->some_id; }` — producing thirty-one more.
 *
 *   a merchant's own screens   the store comes from `KycAccess::ownStore(Auth::user())`, which
 *                              reads `current_store` AND VERIFIES THE PRINCIPAL OWNS IT, gives the
 *                              operator null rather than the fabricated store 1 that
 *                              `getCurrentStore()` returns for them, and gives a platform-rooted
 *                              principal null rather than a guess.
 *   a document or a record     the store comes from the ROW, and `KycAccess` is asked whether this
 *                              principal may reach that store. One predicate serves both the
 *                              merchant door and the operator door, so they cannot drift.
 *
 * THE HANDLER CHECKS AGAIN BEHIND THE ROUTE'S MIDDLEWARE, and that is not a second spelling of the
 * same gate. `RouteCollection::addToCollections()` keys on method+domain+uri, so a LATER declaration
 * of the same verb and URI replaces the earlier one and silently drops its middleware —
 * `OrderDocumentAccessGuard`'s docblock has the measured instance, where a vendored module
 * re-declared a host route and `Authenticate` disappeared with the action name unchanged.
 *
 * AND THE TESTS CALL `KycAccess`/`KycReview` DIRECTLY as well as through HTTP, because CLAUDE.md
 * records that *a handler-level check behind an `abort()`ing middleware is invisible to an HTTP
 * test*: the middleware answers 403 and the handler never runs, so the route answers 403 whether or
 * not the handler checks anything. `PlanController::planDisable` admitted a `Customer` typed
 * `super admin` behind a gate whose HTTP tests were green.
 *
 * A CROSS-TENANT DOCUMENT ANSWERS 404 AND STILL WRITES A REFUSAL ROW. Two separate decisions:
 *
 *   404 and not 403   a 403 would confirm that a document with that id exists, which for identity
 *                     papers is itself a disclosure (whose, and that they have one).
 *   a logged refusal  the status code tells the caller nothing and the trail tells the MERCHANT
 *                     everything: somebody asked for their document and was refused. That is
 *                     KYC-002's access log doing the job it exists for, and it is also why the
 *                     tests key their refusal assertions on the AUDIT ROW rather than on the
 *                     status — CLAUDE.md: *"a 302 from a missing row and a 302 from a gate are the
 *                     same status and opposite facts"*.
 *
 * 403 AND NEVER 401 for a signed-in principal who may not: repo B's `lib/network/network.dart`
 * maps any 401 to a forced logout that clears stored preferences.
 *
 * WHAT THIS CONTROLLER DOES NOT HAVE: a delete door. How long identity papers are kept, and what
 * happens to them when a store closes, is an owner decision (`D-P6-46`), and a destructive
 * operation on personal data is an escalation under this project's standing rules. A replaced
 * document is superseded, not removed.
 */
class KycVerificationController extends Controller
{
    /**
     * THE MERCHANT'S OWN VERIFICATION SCREEN — one card per authority, which is KYC-003 on screen.
     */
    public function index(Request $request)
    {
        $user = Auth::guard('web')->user();
        $storeId = KycAccess::ownStore($user);

        if ($storeId === null || ! KycAccess::permitsOwnDocuments($user, $storeId)) {
            abort(403);
        }

        $records = KycVerification::query()
            ->where('store_id', $storeId)
            ->orderBy('authority')
            ->get()
            ->keyBy('authority');

        $documents = KycDocument::query()
            ->where('store_id', $storeId)
            ->orderByDesc('id')
            ->get()
            ->groupBy('authority');

        return view('kyc.index', [
            'storeId' => $storeId,
            'authorities' => KycAuthority::ALL,
            'records' => $records,
            'documents' => $documents,
            'dueForRenewal' => KycRenewal::dueForRenewal($storeId),
            'subjectTypes' => KycVerification::SUBJECT_TYPES,
        ]);
    }

    /**
     * THE MERCHANT UPLOADS ONE DOCUMENT.
     *
     * The authority and the subject type are validated against the closed vocabularies, and the
     * store is the principal's. `document_kind` is checked by `KycDocumentStore` against
     * `config('kyc.document_kinds')`, which ships EMPTY — so with no owner decision in place this
     * door refuses every upload, which is the correct default for sensitive personal data.
     */
    public function store(Request $request)
    {
        $user = Auth::guard('web')->user();
        $storeId = KycAccess::ownStore($user);

        if ($storeId === null || ! KycAccess::permitsOwnDocuments($user, $storeId)) {
            abort(403);
        }

        $validated = $request->validate([
            'authority' => ['required', 'string', 'in:' . implode(',', KycAuthority::ALL)],
            'subject_type' => ['required', 'string', 'in:' . implode(',', KycVerification::SUBJECT_TYPES)],
            'document_kind' => ['required', 'string', 'max:64'],
            'document' => ['required', 'file'],
            'issued_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date'],
        ]);

        try {
            $record = KycReview::recordFor(
                $storeId,
                $validated['authority'],
                $validated['subject_type'],
                $user instanceof User ? $user : null
            );

            KycDocumentStore::store(
                $record,
                $request->file('document'),
                $validated['document_kind'],
                $user instanceof User ? $user : null,
                [
                    'issued_on' => $validated['issued_on'] ?? null,
                    'expires_on' => $validated['expires_on'] ?? null,
                ]
            );
        } catch (KycRefusal $refusal) {
            return redirect()->route('kyc.index')->with('error', $refusal->getMessage());
        }

        return redirect()->route('kyc.index')->with('success', __('The document was stored.'));
    }

    /** The merchant says a record is ready to be looked at. */
    public function submit(Request $request, string $record)
    {
        $user = Auth::guard('web')->user();
        $found = $this->recordFor($user, $record);

        if ($found === null) {
            abort(404);
        }

        try {
            KycReview::submit($found, $user);
        } catch (KycRefusal $refusal) {
            return redirect()->route('kyc.index')->with('error', $refusal->getMessage());
        }

        return redirect()->route('kyc.index')->with('success', __('The record was submitted for review.'));
    }

    /**
     * SERVE THE BYTES — THE ONE PLACE A PRIVATE DOCUMENT LEAVES THE SERVER, and it writes the
     * access-log row in the same call because `KycDocumentStore::readForActor()` does.
     *
     * `Content-Disposition: attachment` AND A SERVER-CHOSEN FILENAME. The stored name is the secret
     * (see `KycDocument`'s docblock) so it is never in the response, and the client's own name is a
     * stranger's text that must not become a header verbatim — it is reduced to a safe base the same
     * way `PlatformUploadPolicy::storedName()` reduces an uploaded name. `attachment` rather than
     * `inline` because a document rendered in the browser on this application's own origin is a
     * document that can carry script there; the accepted list excludes SVG for the same reason.
     *
     * `X-Content-Type-Options: nosniff` so a browser does not re-decide the type from the bytes.
     */
    public function download(Request $request, string $document)
    {
        $user = Auth::guard('web')->user();
        $found = $this->documentFor($user, $document);

        if ($found === null) {
            abort(404);
        }

        try {
            $bytes = KycDocumentStore::readForActor($found, $user);
        } catch (KycRefusal $refusal) {
            abort(404, $refusal->getMessage());
        }

        $safeBase = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) pathinfo((string) $found->original_name, PATHINFO_FILENAME)), '_');
        $extension = strtolower((string) pathinfo((string) $found->original_name, PATHINFO_EXTENSION));
        $accepted = array_filter((array) config('kyc.accepted_extensions', []), 'is_string');

        $filename = ($safeBase !== '' ? $safeBase : 'document')
            . (in_array($extension, $accepted, true) ? '.' . $extension : '');

        return response($bytes, 200, [
            'Content-Type' => (string) $found->mime,
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    /**
     * WHO HAS LOOKED AT THIS DOCUMENT — the merchant's own view of KYC-002's access log.
     *
     * THIS IS THE READER `AuditAttribution` WARNED ABOUT, in its own words: the historical rows
     * filed under store 1 *"becomes one the day a reader is added"*. So it is predicated on the
     * SUBJECT and on the STORE, and it can return nothing but rows about this one document.
     */
    public function accessLog(Request $request, string $document)
    {
        $user = Auth::guard('web')->user();
        $found = $this->documentFor($user, $document);

        if ($found === null) {
            abort(404);
        }

        return view('kyc.access-log', [
            'document' => $found,
            'entries' => KycAccessLog::forDocument($found)->limit(200)->get(),
        ]);
    }

    /**
     * THE OPERATOR'S CROSS-STORE REVIEW QUEUE. Operator only, and the route says so too.
     *
     * The `authority` filter is the ONE value this controller takes from the request, and it is
     * normalised against the closed vocabulary (`KycAuthority::normalise()` throws rather than
     * guesses) before it reaches a query. It narrows a list the operator may already see in full,
     * so it is a filter and not a tenant predicate — the distinction CLAUDE.md insists on.
     */
    public function queue(Request $request)
    {
        $user = Auth::guard('web')->user();

        if (! KycAccess::permitsAnyStore($user)) {
            abort(403);
        }

        $query = KycVerification::query()
            ->whereIn('status', [KycVerification::SUBMITTED, KycVerification::IN_REVIEW])
            ->orderBy('submitted_at');

        $authority = $request->query('authority');

        if (is_string($authority) && $authority !== '' && KycAuthority::isKnown($authority)) {
            $query->where('authority', KycAuthority::normalise($authority));
        }

        $records = $query->limit(200)->get();

        return view('kyc.queue', [
            'records' => $records,
            'stores' => Store::query()
                ->whereIn('id', $records->pluck('store_id')->unique()->all())
                ->pluck('name', 'id'),
            'documents' => KycDocument::query()
                ->whereIn('kyc_verification_id', $records->pluck('id')->all() ?: [0])
                ->orderByDesc('id')
                ->get()
                ->groupBy('kyc_verification_id'),
            'authorities' => KycAuthority::ALL,
            'selectedAuthority' => is_string($authority) && KycAuthority::isKnown($authority) ? $authority : null,
        ]);
    }

    /** The operator decides one document. The gate lives in `KycReview`, which also logs a refusal. */
    public function decideDocument(Request $request, string $document)
    {
        $user = Auth::guard('web')->user();

        $found = KycDocument::query()->whereKey($this->numericId($document))->first();

        if ($found === null) {
            abort(404);
        }

        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:' . implode(',', KycReview::DECISIONS)],
            'note' => ['nullable', 'string', 'max:1000'],
            'expires_on' => ['nullable', 'date'],
        ]);

        try {
            KycReview::decideDocument(
                $found,
                $user,
                $validated['decision'],
                $validated['note'] ?? null,
                $validated['expires_on'] ?? null
            );
        } catch (KycRefusal $refusal) {
            // `not_a_reviewer` is already an access-log row, written by KycReview.
            if ($refusal->reason === 'not_a_reviewer') {
                abort(403);
            }

            return redirect()->route('kyc.queue')->with('error', $refusal->getMessage());
        }

        return redirect()->route('kyc.queue')->with('success', __('The document decision was recorded.'));
    }

    /** The operator decides a whole record. */
    public function decideRecord(Request $request, string $record)
    {
        $user = Auth::guard('web')->user();

        $found = KycVerification::query()->whereKey($this->numericId($record))->first();

        if ($found === null) {
            abort(404);
        }

        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:' . implode(',', KycReview::DECISIONS)],
            'note' => ['nullable', 'string', 'max:1000'],
            'expires_on' => ['nullable', 'date'],
        ]);

        try {
            KycReview::decideVerification(
                $found,
                $user,
                $validated['decision'],
                $validated['note'] ?? null,
                $validated['expires_on'] ?? null
            );
        } catch (KycRefusal $refusal) {
            if ($refusal->reason === 'not_a_reviewer') {
                abort(403);
            }

            return redirect()->route('kyc.queue')->with('error', $refusal->getMessage());
        }

        return redirect()->route('kyc.queue')->with('success', __('The verification decision was recorded.'));
    }

    /**
     * A document this principal may reach, or null — AND A LOGGED REFUSAL WHEN THE ROW EXISTS.
     *
     * NO ROUTE-MODEL BINDING, deliberately. `KycDocument` is not in `config/tenancy.php`'s
     * `scoped_models`, so `StoreScope::isActiveFor()` short-circuits and the binding would reach
     * EVERY tenant's rows — the shape `OwnedResourceMethodsAreGuardedTest` ratchets, and the shape
     * that made `Product::find($id)` the S19 stock drain. The lookup is explicit and the
     * authorization is a separate statement after it, so neither can be mistaken for the other.
     */
    private function documentFor(mixed $user, string $id): ?KycDocument
    {
        $document = KycDocument::query()->whereKey($this->numericId($id))->first();

        if ($document === null) {
            return null;
        }

        if (! KycAccess::permitsOwnDocuments($user, (int) $document->store_id)) {
            KycDocumentStore::refuse($document, 'not_this_store', $user instanceof User ? $user : null);

            return null;
        }

        return $document;
    }

    private function recordFor(mixed $user, string $id): ?KycVerification
    {
        $record = KycVerification::query()->whereKey($this->numericId($id))->first();

        if ($record === null) {
            return null;
        }

        if (! KycAccess::permitsOwnDocuments($user, (int) $record->store_id)) {
            KycAccessLog::record(
                KycAccessLog::REFUSED,
                $record,
                ['reason' => 'not_this_store'],
                $user instanceof User ? $user : null
            );

            return null;
        }

        return $record;
    }

    /**
     * A route segment read as an id, or 0.
     *
     * `0` AND NOT THE RAW STRING, because `whereKey('abc')` on SQLite matches a row whose id is 0
     * and on MySQL compares a string to an integer with the engine's own coercion. An id that is
     * not a run of digits is not an id, and `0` is never a primary key here.
     */
    private function numericId(string $id): int
    {
        return ctype_digit($id) ? (int) $id : 0;
    }
}
