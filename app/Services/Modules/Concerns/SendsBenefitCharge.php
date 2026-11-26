<?php

namespace App\Services\Modules\Concerns;

use App\Support\Modules\ModuleBenefitCallback;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;

/**
 * THE BENEFIT CHARGE POST, SHARED BY THE THREE HOST OVERRIDES — and the one seam this fix adds.
 * =================================================================================================
 *
 * GAP-138. Identical, deliberately, to the three vendored transports it replaces: the same raw
 * `new Client()`, the same endpoint, the same three headers, the same bearer. The ONLY thing this
 * fix changes about any Benefit charge is the value of `post.url` inside the body; everything about
 * how that body travels is left exactly as the modules wrote it, so a difference in behaviour
 * cannot come from here.
 *
 * WHY IT IS A METHOD AT ALL, since it could have been inlined three times. The vendored services
 * construct `$client = new Client()` INLINE — no injection, not the `Http` facade — which is the
 * seam gap `GatewayCallbackDestinationTest` records as the reason that file is a source-reading
 * test: `Http::fake()` cannot see the call and there is nothing to intercept. Pulling the POST into
 * one overridable method gives the host side the seam the vendored side lacks, so
 * `ModuleBenefitCallbackDestinationTest` can capture THE EXACT BYTES `process()` would have sent
 * and assert on them, with no request to api.tap.company — for which no credential exists here and
 * which must not be probed.
 *
 * IT IS NOT A SWAPPABLE TRANSPORT. There is no property, no setter and no container binding: the
 * only way to replace it is to subclass, which is what a test double does and what nothing in
 * production does. A mutable transport on a payment service would be a worse thing than the defect
 * being fixed.
 *
 * `$secretKey` IS NULLABLE BECAUSE THE VENDORED READS ARE. Donation and LotteryPrizes take it from
 * `Utility::GetValueByName('benefit_secret_key', $store->id)`, which answers null for a store that
 * has never saved one; the vendored code then sends `'Bearer ' . null` — i.e. `'Bearer '` — and Tap
 * refuses it. That is preserved rather than corrected: a store with no key configured behaves
 * exactly as it did before this change, and turning it into a TypeError here would be a new
 * failure mode on a payment path that this fix is not authorised to introduce.
 */
trait SendsBenefitCharge
{
    protected function sendBenefitCharge(string $json, ?string $secretKey = null): ResponseInterface
    {
        $client = new Client();

        return $client->request('POST', ModuleBenefitCallback::TAP_CHARGES_ENDPOINT, [
            'body' => $json,
            'headers' => [
                'Authorization' => 'Bearer ' . $secretKey,
                'accept' => 'application/json',
                'content-type' => 'application/json',
            ],
        ]);
    }
}
