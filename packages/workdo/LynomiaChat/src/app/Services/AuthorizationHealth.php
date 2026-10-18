<?php

namespace Workdo\LynomiaChat\app\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Log;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;

/**
 * WHETHER LYNOMIA STILL LETS THIS APP INTO A WORKSPACE WE ARE STILL LINKED TO, recorded the only
 * way it honestly can be: from an answer Lynomia actually gave.
 *
 * THE GAP THIS FILLS. A merchant removing the integration through Lynomia's Connected platforms
 * page deletes the single grant the whole integration rests on. Nothing on this side is wrong
 * afterwards -- the link row, the account id, the commerce installation id and the credential are
 * all still exactly what they were -- so every surface that renders from those columns goes on
 * reporting a healthy connection while every call fails. The merchant is told their store is
 * connected and their inbox has no orders in it, which is the worst of the three possible
 * answers.
 *
 * ONE WRITER, CALLED FROM THE PATHS THAT ALREADY TALK TO LYNOMIA. There is no new remote call
 * here and deliberately none added to page rendering: this installation's own panels are built
 * from local tables precisely so they do not go blank when the far side is down. What is added
 * is that the paths which DO call -- opening a workspace, connecting commerce, the hourly
 * reconciliation sweep -- write down what they learned.
 *
 * ONLY ONE KIND COUNTS. `KIND_NOT_PERMISSIBLE` means Lynomia said `non_permissible_resource`:
 * this app, on this workspace, no longer. A bad platform credential (`KIND_UNAUTHORIZED`) is a
 * different incident with a different owner -- it is this installation's configuration, it
 * breaks every merchant at once, and no merchant can recover it -- so it must not be recorded
 * here, or one operator's mistake would tell a thousand merchants to go and re-authorise.
 */
class AuthorizationHealth
{
    /**
     * Record that Lynomia refused this workspace, if that is what happened.
     *
     * SAFE TO CALL WITH ANY FAILURE, which is what lets the call sites wrap their existing catch
     * blocks without having to classify first. Anything that is not a permissible refusal is
     * ignored and returns false.
     *
     * THE FIRST OBSERVATION IS THE ONE KEPT. `authorization_lost_at` answers "since when", so a
     * second refusal must not move it forward -- an operator looking at a store that has been
     * broken for three days should see three days, not three seconds.
     */
    public static function record(?LynomiaStoreLink $link, LynomiaApiException $e): bool
    {
        if ($link === null || $e->kind !== LynomiaApiException::KIND_NOT_PERMISSIBLE) {
            return false;
        }

        if ($link->authorization_lost_at !== null) {
            return true;
        }

        $link->forceFill(['authorization_lost_at' => now()])->save();

        AuditLog::record('lynomia_authorization_lost', $link->fresh(), [], [
            'store_id' => $link->store_id,
            'lynomia_account_id' => $link->lynomia_account_id,
            'status' => $e->status,
            'error_code' => $e->errorCode,
        ]);

        Log::warning('[lynomia] workspace authorization was revoked on the Lynomia side', [
            'store_id' => $link->store_id,
            'lynomia_account_id' => $link->lynomia_account_id,
        ]);

        return true;
    }

    /**
     * Record that a call just succeeded, so a repaired store stops reading as broken.
     *
     * CALLED ON SUCCESS RATHER THAN ONLY ON RECOVERY. The grant can come back without this app
     * being told -- an operator restores it, or the merchant redeems a code against a different
     * surface -- and the next call that works is proof enough. A store that is working must not
     * keep a banner because nobody pressed the button that would have cleared it.
     */
    public static function clear(?LynomiaStoreLink $link): bool
    {
        if ($link === null || $link->authorization_lost_at === null) {
            return false;
        }

        $link->forceFill([
            'authorization_lost_at' => null,
            'authorization_recovered_at' => now(),
        ])->save();

        AuditLog::record('lynomia_authorization_recovered', $link->fresh(), [], [
            'store_id' => $link->store_id,
            'lynomia_account_id' => $link->lynomia_account_id,
        ]);

        return true;
    }

    /** Whether this store's workspace is currently refusing us. */
    public static function lost(?LynomiaStoreLink $link): bool
    {
        return $link !== null && $link->authorization_lost_at !== null;
    }
}
