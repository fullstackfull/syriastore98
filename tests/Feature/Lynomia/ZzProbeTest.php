<?php

namespace Tests\Feature\Lynomia;

use Illuminate\Support\Facades\Http;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Services\AccountLinker;
use Workdo\LynomiaChat\app\Services\SsoLinkIssuer;

class ZzProbeTest extends LynomiaConnectionTest
{
    private $sideEffect;

    public function test_probe_operator_approval_activates_a_workspace_they_never_reviewed(): void
    {
        $r = new \ReflectionClass(LynomiaConnectionTest::class);
        $entitled = $r->getMethod('entitledTenant'); $entitled->setAccessible(true);
        $fake = $r->getMethod('fakeLynomia'); $fake->setAccessible(true);
        $operatorM = $r->getMethod('operator'); $operatorM->setAccessible(true);

        [$owner, $store] = $entitled->invoke($this);

        // 1. Merchant claims workspace 7001. Operator is handed verification code $code1.
        $fake->invoke($this, ['account_id' => 7001, 'user_id' => 9001]);
        $first = app(AccountLinker::class)->request($store, $owner, 'CODE-ONE');
        $code1 = $first['verification_code'];

        $op = $operatorM->invoke($this);

        // 2. Merchant submits a second code (workspace 7002). The operator, looking at the
        //    claim for 7001, approves with $code1 WHILE POST /account_links is in flight.
        $fake->invoke($this, ['account_id' => 7002, 'user_id' => 9002]);

        $this->sideEffect = function () use ($store, $op, $code1) {
            $row = LynomiaStoreLink::forStore((int) $store->id)->first();
            app(AccountLinker::class)->approve($row, $op, $code1);
            fwrite(STDERR, "  [operator approved claim on account {$row->lynomia_account_id}]\n");
        };

        Http::fake(function ($request) {
            if (strtoupper($request->method()) === 'POST'
                && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/platform/api/v1/account_links')) {
                ($this->sideEffect)();
            }

            return null; // let the scripted fake answer
        });

        app(AccountLinker::class)->request($store->fresh(), $owner->fresh(), 'CODE-TWO');

        $row = LynomiaStoreLink::forStore((int) $store->id)->first();
        fwrite(STDERR, "FINAL: state={$row->state} account={$row->lynomia_account_id} user={$row->lynomia_user_id}"
            . ' verified_by=' . var_export($row->verified_by, true)
            . ' verified_at=' . var_export((string) $row->verified_at, true)
            . ' allowsSso=' . ($row->allowsSso() ? 'YES' : 'no') . "\n");

        $url = app(SsoLinkIssuer::class)->issueFor($row, $owner->fresh());
        fwrite(STDERR, "SSO issued into user {$row->lynomia_user_id}: " . ($url !== '' ? 'YES' : 'no') . "\n");

        $this->assertTrue(true);
    }
}
