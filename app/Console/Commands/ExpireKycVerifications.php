<?php

namespace App\Console\Commands;

use App\Services\Kyc\KycRenewal;
use Illuminate\Console\Command;

/**
 * KYC-002's RENEWAL CLAUSE, as a maintenance pass. `php artisan kyc:expire`
 *
 * IT TAKES NO ARGUMENTS AND NO STORE, and that is a control rather than a simplification: there is
 * no parameter for a caller to put a request value into, and no route reaches it. `KycRenewal::sweep()`
 * is platform-wide by design — it is housekeeping over every tenant's dates, run from the console.
 *
 * AND NOTHING DEPENDS ON IT HAVING RUN. `KycVerification::isCurrent()` and `KycDocument::isCurrent()`
 * read the stored date, so a record whose expiry passed an hour ago is already not current. A missed
 * run therefore cannot GRANT anything; it only leaves the stored status, and the trail, behind. That
 * is deliberately the opposite way round from the projection pattern elsewhere in this codebase: a
 * projection the gate trusts is a projection whose staleness is an authorization bug.
 *
 * IT IS NOT SCHEDULED HERE. `routes/console.php` is where this application declares its schedule,
 * and how often a platform runs its compliance housekeeping is an operations decision. Adding a
 * schedule entry would also change what every deployment of this branch does on its own, which is
 * not this workstream's to decide.
 */
class ExpireKycVerifications extends Command
{
    protected $signature = 'kyc:expire';

    protected $description = 'Mark verification documents and records whose expiry date has passed, and log each lapse.';

    public function handle(): int
    {
        $counts = KycRenewal::sweep();

        $this->info(sprintf(
            '%d document(s) and %d verification record(s) marked expired.',
            $counts['documents'],
            $counts['verifications']
        ));

        return self::SUCCESS;
    }
}
