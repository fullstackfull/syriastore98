<?php

namespace App\Services\Kyc;

use App\Models\KycDocument;
use App\Models\KycVerification;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * KYC-002's THIRD CLAUSE — التجديد, RENEWAL. THE SYSTEM KNOWS WHAT HAS LAPSED AND WHAT IS ABOUT TO.
 * =================================================================================================
 *
 * WHAT THIS DOES: moves an `approved` document or verification whose `expires_on` has passed into
 * `expired`, writes one access-log row per transition, and answers "what lapses in the next N days".
 *
 * WHAT THIS DELIBERATELY DOES **NOT** DO, and the omission is the requirement being respected rather
 * than ducked: NOTHING ELSE HAPPENS. It does not suspend the store, does not disable payments, does
 * not notify, does not withdraw a plan. What happens to a store whose verification lapses is an
 * owner decision — `D-P6-46` in `docs/development/phase-6/PHASE-06-OWNER-DECISION-PACKAGE.md` — and
 * a sweep that invented a consequence would be a business rule nobody approved, enforced by a cron
 * job. The three approved sentences say "manage renewal"; they do not say what a lapse costs.
 *
 * AND THE GATE DOES NOT WAIT FOR THIS COMMAND TO BE CORRECT. `KycVerification::isCurrent()` and
 * `KycDocument::isCurrent()` read the stored DATE, so a record whose expiry passed an hour ago is
 * already not current whether or not the sweep has run. This class exists to make the state VISIBLE
 * and the lapse AUDITABLE, not to make it true. That is the opposite of the usual projection
 * pattern in this codebase and is the right way round for an authorization answer: the derived
 * state is the pessimistic one, and a missed cron run cannot grant anything.
 *
 * `expired` IS NOT TERMINAL FOR A VERIFICATION and `KycReview` can approve it again after a renewed
 * document arrives; it IS terminal for a document, because a document's own expiry does not move.
 *
 * NO TENANT PREDICATE ON THE SWEEP, AND THAT IS CORRECT RATHER THAN AN OVERSIGHT: it is a platform
 * maintenance pass run from the console, reads no request input, and is reachable from no route.
 * `sweep()` takes no store id for exactly that reason — there is no parameter for a caller to put a
 * request value into. The per-store reader (`dueForRenewal()`) DOES take one, and its caller must
 * derive it from the principal (`KycAccess::ownStore()`), never from the request.
 */
final class KycRenewal
{
    private function __construct()
    {
    }

    /**
     * Move everything past its expiry into `expired`, logging each transition.
     *
     * CHUNKED AND WRITTEN THROUGH ELOQUENT, not `update()` in bulk. Two reasons, both measured in
     * this project: a mass `update()` writes no audit row per subject, and a raw write leaves
     * Eloquent reads serving the pre-write row for the rest of the process under
     * `CACHE_STORE=array` (three recorded false test readings). These two models are not `Cachable`,
     * so the second does not bite here — but the first is the whole point of the pass.
     *
     * @return array{documents:int, verifications:int}
     */
    public static function sweep(?DateTimeInterface $asOf = null): array
    {
        $at = Carbon::instance($asOf ? Carbon::parse($asOf) : now());
        $grace = max(0, (int) config('kyc.expiry_grace_days', 0));
        $cutoff = $at->copy()->subDays($grace)->startOfDay();

        $documents = 0;
        $verifications = 0;

        KycDocument::query()
            ->where('status', KycDocument::APPROVED)
            ->whereNotNull('expires_on')
            ->where('expires_on', '<', $cutoff->toDateString())
            ->orderBy('id')
            ->chunkById(200, function (Collection $chunk) use (&$documents): void {
                foreach ($chunk as $document) {
                    $document->status = KycDocument::EXPIRED;
                    $document->save();

                    KycAccessLog::record(KycAccessLog::EXPIRED, $document, [
                        'expires_on' => (string) $document->expires_on?->toDateString(),
                    ]);

                    $documents++;
                }
            });

        KycVerification::query()
            ->where('status', KycVerification::APPROVED)
            ->whereNotNull('expires_on')
            ->where('expires_on', '<', $cutoff->toDateString())
            ->orderBy('id')
            ->chunkById(200, function (Collection $chunk) use (&$verifications): void {
                foreach ($chunk as $record) {
                    $record->status = KycVerification::EXPIRED;
                    $record->save();

                    KycAccessLog::record(KycAccessLog::VERIFICATION_EXPIRED, $record, [
                        'expires_on' => (string) $record->expires_on?->toDateString(),
                    ]);

                    $verifications++;
                }
            });

        return ['documents' => $documents, 'verifications' => $verifications];
    }

    /**
     * What lapses for THIS STORE within `config('kyc.renewal_notice_days')`.
     *
     * A REPORT AND NOT A GATE. It changes what a screen shows and never what a decision is. The
     * window is configuration because how much warning a merchant gets is a product choice; the
     * default is 30 days and nothing depends on the number.
     *
     * @param  int  $storeId  from the principal, never from the request
     * @return Collection<int, KycDocument>
     */
    public static function dueForRenewal(int $storeId, ?string $authority = null, ?DateTimeInterface $asOf = null): Collection
    {
        if ($storeId <= 0) {
            return new Collection();
        }

        $at = Carbon::instance($asOf ? Carbon::parse($asOf) : now());
        $notice = max(0, (int) config('kyc.renewal_notice_days', 30));

        $query = KycDocument::query()
            ->where('store_id', $storeId)
            ->whereIn('status', [KycDocument::APPROVED, KycDocument::EXPIRED])
            ->whereNotNull('expires_on')
            ->where('expires_on', '<=', $at->copy()->addDays($notice)->toDateString())
            ->orderBy('expires_on');

        if ($authority !== null) {
            $query->where('authority', KycAuthority::normalise($authority));
        }

        return $query->get();
    }
}
