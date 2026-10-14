<?php

namespace Tests\Feature\Lynomia;

use Tests\TestCase;

/**
 * THE TEST THAT WOULD HAVE CAUGHT THE PHASE 2 DEFECT.
 *
 * Phase 2B overwrote a file in the Lynomia repository that implemented the channel-configuration
 * audit trail, and nothing failed: the request still returned 200, and every test the feature
 * brought with it still passed. The only symptom anywhere was two red examples in a suite that
 * feature had no reason to run. A compliance control was deleted and the product reported success.
 *
 * The lesson is not "write more feature tests" -- the feature tests were green. It is that a
 * compliance surface needs a test that asserts its EXISTENCE, independently of whether the
 * feature that happens to sit next to it works. That is what this file is. It is deliberately
 * crude: it reads source, not behaviour, because the failure mode being guarded against is
 * deletion, and deleted code has no behaviour to assert on.
 *
 * It does not check that the audit rows are CORRECT -- the service tests do that. It checks that
 * the call sites are still there at all.
 */
class LynomiaAuditPreservationTest extends TestCase
{
    /**
     * Every audit action this module is required to emit.
     *
     * Each entry is a compliance statement: "this integration records when X happens". Removing
     * one is a product decision that needs a human to make it, which is the whole point of
     * failing here rather than discovering it in an incident.
     */
    private const REQUIRED_AUDIT_ACTIONS = [
        'lynomia_workspace_provisioned' => 'a Lynomia workspace was created for a store',
        'lynomia_link_requested' => 'a merchant claimed an existing Lynomia workspace',
        'lynomia_link_approved' => 'an operator approved a workspace claim',
        'lynomia_link_revoked' => 'an operator revoked a workspace connection',
        'lynomia_link_restored' => 'an operator restored a revoked connection',
        'lynomia_sso_issued' => 'a one-time sign-in URL was minted for a person',
        'lynomia_identity_created' => 'a person was given their own Lynomia identity',
        'lynomia_identity_role_changed' => "a person's role in the workspace changed",
        'lynomia_seat_allowance_synced' => 'the included agent-seat ceiling was pushed to Lynomia',
    ];

    private function moduleSource(): string
    {
        static $source = null;

        if ($source !== null) {
            return $source;
        }

        $root = base_path('packages/workdo/LynomiaChat/src/app');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        $source = '';

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $source .= file_get_contents($file->getPathname());
            }
        }

        return $source;
    }

    public function test_every_required_audit_action_is_still_emitted(): void
    {
        $source = $this->moduleSource();
        $missing = [];

        foreach (self::REQUIRED_AUDIT_ACTIONS as $action => $meaning) {
            if (! str_contains($source, "'{$action}'")) {
                $missing[] = "{$action} ({$meaning})";
            }
        }

        $this->assertSame([], $missing, "audit actions have been removed from the module:\n  "
            . implode("\n  ", $missing)
            . "\n\nIf a removal is intended, delete the entry from REQUIRED_AUDIT_ACTIONS in this "
            . 'test in the same commit, so the decision is visible in review.');
    }

    /**
     * The credential rule, which is the other half of an audit trail being safe to keep.
     * An audit row that contains the secret it is recording the rotation of is worse than none.
     */
    public function test_no_audit_call_site_passes_a_credential(): void
    {
        $forbidden = ['platform_token', 'webhook_master_secret', 'api_access_token'];
        $source = $this->moduleSource();

        foreach ($forbidden as $needle) {
            // Crude but sufficient: these names must never appear on the same line as an audit write.
            foreach (explode("\n", $source) as $line) {
                if (str_contains($line, 'AuditLog::record') && str_contains($line, $needle)) {
                    $this->fail("an audit call site references {$needle}: " . trim($line));
                }
            }
        }

        $this->assertTrue(true);
    }

    /**
     * The ingress is the one unauthenticated surface; a refusal there must stay observable.
     *
     * Read across the whole ingress PATH rather than one file. The signature check deliberately
     * lives in the middleware now -- it has to run before the per-store rate limit so an
     * anonymous caller cannot spend a merchant's delivery budget -- so pinning the controller
     * alone would have failed for a change that improved the design.
     */
    public function test_the_event_ingress_still_logs_every_refusal(): void
    {
        $path = base_path('packages/workdo/LynomiaChat/src/app/Http/');
        $source = file_get_contents($path . 'Controllers/EventIngressController.php')
            . file_get_contents($path . 'Middleware/GuardEventIngress.php');

        $this->assertStringContainsString('Log::warning', $source,
            'the ingress no longer logs refusals; a rejected delivery would be silent');

        $reasons = [
            'bad_signature', 'malformed_json', 'unknown_workspace',
            'missing_delivery_id', 'body_too_large', 'unverified_request',
        ];

        foreach ($reasons as $reason) {
            $this->assertStringContainsString("'{$reason}'", $source,
                "the ingress no longer distinguishes the {$reason} refusal");
        }
    }

    /**
     * THE ORDERING IS THE CONTROL. Verification must happen before the per-store throttle:
     * otherwise an unauthenticated caller who knows a store id spends that store's budget, and
     * since Lynomia never retries an account webhook, every genuine delivery after it is
     * destroyed. Pinned because it is invisible in any single file.
     */
    public function test_the_signature_is_verified_before_the_per_store_throttle(): void
    {
        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName('lynomia.events.ingress');
        $this->assertNotNull($route);

        $stack = array_values(array_map(
            static fn ($m) => is_string($m) ? $m : (is_object($m) ? get_class($m) : (string) $m),
            app('router')->gatherRouteMiddleware($route)
        ));

        $guard = null;
        $storeThrottle = null;

        foreach ($stack as $i => $entry) {
            if (str_contains($entry, 'GuardEventIngress')) {
                $guard = $i;
            }
            if (str_contains($entry, 'ThrottleRequests:lynomia-events')) {
                $storeThrottle = $i;
            }
        }

        $this->assertNotNull($guard, 'the signature guard is not on the ingress route');
        $this->assertNotNull($storeThrottle, 'the per-store throttle is not on the ingress route');
        $this->assertLessThan($storeThrottle, $guard,
            'the per-store throttle runs before verification, so anonymous traffic can burn a merchant budget');
    }
}
