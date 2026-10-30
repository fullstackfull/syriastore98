<?php

namespace Tests\Feature\Security;

use App\Services\GatewayReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

class ZzTempProbeTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const URI = '/finance/unreconciled-captures';

    public function test_probe_html_branch_raises_the_real_error(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'View Finance Dashboard');
        $merchant->current_store = $store->id;
        $merchant->save();

        GatewayReference::claim((int) $store->id, 'paymera', 'ref-html', '125.00');

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($merchant->fresh())->get(self::URI);
            fwrite(STDERR, "\n[PROBE-1] HTML branch returned WITHOUT throwing\n");
        } catch (\Throwable $e) {
            fwrite(STDERR, "\n[PROBE-1] " . get_class($e) . ': ' . $e->getMessage() . "\n");
        }

        // and with the normal handler, what status does a browser get?
        $this->app[\Illuminate\Contracts\Debug\ExceptionHandler::class] = new \App\Exceptions\Handler($this->app);
        $this->assertTrue(true);
    }

    public function test_probe_html_branch_status_with_handler(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'View Finance Dashboard');
        $merchant->current_store = $store->id;
        $merchant->save();

        GatewayReference::claim((int) $store->id, 'paymera', 'ref-html2', '125.00');

        $resp = $this->actingAs($merchant->fresh())->get(self::URI);
        fwrite(STDERR, "\n[PROBE-2] browser status = " . $resp->getStatusCode() . "\n");
        fwrite(STDERR, "[PROBE-2] audit rows after the 500 = "
            . DB::table('audit_logs')->where('action', 'finance.unreconciled_captures.reviewed')->count() . "\n");
        $row = DB::table('audit_logs')->where('action', 'finance.unreconciled_captures.reviewed')->first();
        fwrite(STDERR, "[PROBE-2] audit new_values = " . ($row->new_values ?? 'NONE') . "\n");
        $this->assertTrue(true);
    }

    public function test_probe_f157_operator_with_no_current_store(): void
    {
        // store 1 belongs to merchant A; the operator has NO current_store
        [$merchantA, $storeA] = $this->makeTenant();
        fwrite(STDERR, "\n[PROBE-3] merchant A store id = " . $storeA->id . "\n");

        GatewayReference::claim((int) $storeA->id, 'paymera', 'ref-merchant-a-secret', '9999.00');

        $operator = $this->makePlatformAdmin(['current_store' => null]);

        $resp = $this->actingAs($operator)->getJson(self::URI);
        fwrite(STDERR, "[PROBE-3] operator status = " . $resp->getStatusCode() . "\n");
        fwrite(STDERR, "[PROBE-3] operator body = " . $resp->getContent() . "\n");
        $row = DB::table('audit_logs')->where('action', 'finance.unreconciled_captures.reviewed')->latest('id')->first();
        fwrite(STDERR, "[PROBE-3] audit store_id = " . var_export($row->store_id ?? 'NONE', true) . "\n");
        $this->assertTrue(true);
    }

    public function test_probe_staff_with_no_current_store(): void
    {
        [$merchant, $store] = $this->makeTenant();
        GatewayReference::claim((int) $store->id, 'paymera', 'ref-needs-review', '500.00');

        $staff = $this->makeStaff($merchant, ['current_store' => null]);
        $this->grantPermission($staff, 'View Finance Dashboard');

        $resp = $this->actingAs($staff->fresh())->getJson(self::URI);
        fwrite(STDERR, "\n[PROBE-4] staff(no current_store) status = " . $resp->getStatusCode() . "\n");
        fwrite(STDERR, "[PROBE-4] staff body = " . $resp->getContent() . "\n");
        $row = DB::table('audit_logs')->where('action', 'finance.unreconciled_captures.reviewed')->latest('id')->first();
        fwrite(STDERR, "[PROBE-4] audit store_id = " . var_export($row->store_id ?? 'NONE', true) . "\n");
        $this->assertTrue(true);
    }

    public function test_probe_table_absent_json_branch(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'View Finance Dashboard');
        $merchant->current_store = $store->id;
        $merchant->save();

        \Illuminate\Support\Facades\Schema::drop('order_gateway_references');

        $resp = $this->actingAs($merchant->fresh())->getJson(self::URI);
        fwrite(STDERR, "\n[PROBE-5] table-absent JSON status = " . $resp->getStatusCode() . "\n");
        fwrite(STDERR, "[PROBE-5] table-absent JSON body = " . $resp->getContent() . "\n");
        $this->assertTrue(true);
    }
}
