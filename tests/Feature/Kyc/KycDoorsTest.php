<?php

namespace Tests\Feature\Kyc;

use App\Models\Customer;
use App\Models\KycDocument;
use App\Models\KycVerification;
use App\Services\Kyc\KycAccessLog;
use App\Services\Kyc\KycAuthority;
use App\Services\Kyc\KycDocumentStore;
use App\Services\Kyc\KycReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\Kyc\MakesKycFixtures;

/**
 * GAP-194 / KYC-001…KYC-003 — THE HTTP DOORS, DRIVEN.
 * =================================================================================================
 *
 * THE RULE ITSELF IS TESTED IN `tests/Unit/Kyc/KycAccessGateTest`, BY CALLING IT DIRECTLY, and that
 * split is deliberate: CLAUDE.md records that *a handler-level check behind an `abort()`ing
 * middleware is invisible to an HTTP test* — the middleware answers 403 and the handler never runs,
 * so the route answers 403 whether or not the handler checks anything. `PlanController::planDisable`
 * admitted a `Customer` typed `super admin` behind a gate whose HTTP tests were all green. So this
 * file asserts what only HTTP can show: which middleware the routes really carry, what the response
 * contains, and that a refusal still writes the access-log row.
 *
 * EVERY REFUSAL ASSERTION IS KEYED ON SOMETHING MORE THAN THE STATUS, for the reason this project
 * states in those words: *"a 302 from a missing row and a 302 from a gate are the same status and
 * opposite facts"*. A cross-tenant document answers 404 — deliberately, because a 403 would confirm
 * the document exists — so the assertion that the GATE fired is the audit row, not the code.
 *
 * `SecurityTestCase` FOR THE UPDATER GATE. `XSS::handle()` redirects a `super admin` to
 * `LaravelUpdater::welcome` whenever any migration on disk is unrecorded, which under
 * `RefreshDatabase` is always — so every operator request would be redirected before reaching the
 * handler, and an "the operator is refused" assertion would pass without exercising anything. That
 * parent's `setUp()` records the on-disk migrations first.
 */
class KycDoorsTest extends SecurityTestCase
{
    use MakesKycFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('kyc');
        $this->configureKycKinds(KycVerification::COMPANY, KycAuthority::STORE_SYRIA, ['commercial_register']);
    }

    public function test_a_merchant_sees_their_own_verification_screen(): void
    {
        [$merchant] = $this->kycMerchantWithStore();

        $response = $this->actingAs($merchant)->get('/verification');

        $response->assertStatus(200);
        $response->assertViewIs('kyc.index');
    }

    public function test_an_anonymous_visitor_is_sent_to_sign_in_rather_than_refused(): void
    {
        $response = $this->get('/verification');

        $response->assertRedirect(route('login'));
    }

    /**
     * THE OPERATOR HAS NO "OWN" VERIFICATION SCREEN AND IS NOT HANDED STORE 1.
     *
     * `getCurrentStore()` answers the hardcoded literal 1 for a `super admin`, so a handler that
     * asked it would have shown the operator whichever merchant happens to be store 1 — and
     * offered them an upload form that files documents against that tenant.
     */
    public function test_the_operator_is_refused_their_own_verification_screen(): void
    {
        $operator = $this->makePlatformAdmin();

        $this->actingAs($operator)->get('/verification')->assertStatus(403);
    }

    public function test_a_merchant_cannot_reach_the_operator_queue(): void
    {
        [$merchant] = $this->kycMerchantWithStore();

        $this->actingAs($merchant)->get('/platform/verification')->assertStatus(403);
    }

    public function test_the_operator_sees_the_queue(): void
    {
        $operator = $this->makePlatformAdmin();
        [$merchant, $store] = $this->kycMerchantWithStore();
        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);
        KycDocumentStore::store($record, $this->kycPngUpload(), 'commercial_register', $merchant);
        KycReview::submit($record->fresh(), $merchant);

        $response = $this->actingAs($operator)->get('/platform/verification');

        $response->assertStatus(200);
        $response->assertViewIs('kyc.queue');
    }

    /** The queue's one request-supplied value is a filter over rows the operator may already see. */
    public function test_the_queue_authority_filter_narrows_and_an_unknown_value_is_ignored(): void
    {
        $operator = $this->makePlatformAdmin();
        [$merchant, $store] = $this->kycMerchantWithStore();
        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);
        KycReview::submit($record, $merchant);

        $this->actingAs($operator)
            ->get('/platform/verification?authority=' . KycAuthority::STORE_SYRIA)
            ->assertStatus(200)
            ->assertViewHas('records', fn ($records) => $records->count() === 1);

        $this->actingAs($operator)
            ->get('/platform/verification?authority=' . KycAuthority::PAYMENT_PROVIDER)
            ->assertStatus(200)
            ->assertViewHas('records', fn ($records) => $records->count() === 0);

        $this->actingAs($operator)
            ->get('/platform/verification?authority=' . urlencode("' OR 1=1 --"))
            ->assertStatus(200)
            ->assertViewHas('records', fn ($records) => $records->count() === 1);
    }

    public function test_a_merchant_downloads_their_own_document_and_the_read_is_recorded(): void
    {
        [$merchant, $store] = $this->kycMerchantWithStore();
        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);
        $document = KycDocumentStore::store($record, $this->kycPngUpload(), 'commercial_register', $merchant);

        $response = $this->actingAs($merchant)->get('/verification/documents/' . $document->id . '/download');

        $response->assertStatus(200);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));

        $this->assertSame(
            1,
            DB::table('audit_logs')->where('action', KycAccessLog::READ)->count(),
            'opening a private document must be recorded — KYC-002\'s access log'
        );
    }

    /**
     * THE SERVED `Content-Type` COMES FROM A FIXED MAP, NOT FROM THE STORED COLUMN.
     *
     * `kyc_documents.mime` is a record of what was received. Serving it would let one database row
     * decide what a reviewer's browser does with a stranger's file — and the first version of the
     * store wrote `getClientMimeType()` into that column, which is the BROWSER'S CLAIM and was
     * never what the validator judged (`mimes:` reads the signature). Both halves are driven here:
     * the column is poisoned raw and the response is unmoved.
     */
    public function test_the_served_content_type_is_not_the_stored_column(): void
    {
        [$merchant, $store] = $this->kycMerchantWithStore();
        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);
        $document = KycDocumentStore::store($record, $this->kycPngUpload('scan.png'), 'commercial_register', $merchant);

        $this->assertSame('image/png', (string) $document->mime, 'guessed from the bytes, not claimed');

        DB::table('kyc_documents')->where('id', $document->id)->update(['mime' => 'text/html']);

        $response = $this->actingAs($merchant)->get('/verification/documents/' . $document->id . '/download');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    /** THE SECRET NAME IS NOT IN THE RESPONSE, headers included. */
    public function test_the_download_never_discloses_the_storage_name(): void
    {
        [$merchant, $store] = $this->kycMerchantWithStore();
        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);
        $document = KycDocumentStore::store($record, $this->kycPngUpload('my-register.png'), 'commercial_register', $merchant);

        $leaf = basename((string) $document->storage_name);

        $response = $this->actingAs($merchant)->get('/verification/documents/' . $document->id . '/download');

        $this->assertStringNotContainsString($leaf, (string) $response->headers);
        $this->assertStringNotContainsString($leaf, (string) $response->getContent());
        $this->assertStringContainsString(
            'my-register',
            (string) $response->headers->get('Content-Disposition'),
            'the merchant should still recognise their own file by the name THEY gave it'
        );
    }

    /**
     * A CROSS-TENANT DOWNLOAD: 404 TO THE CALLER, AND A REFUSAL ROW FOR THE OWNER.
     *
     * The status is deliberately indistinguishable from "no such document" — a 403 would confirm
     * that a document with that id exists and that somebody has one. The GATE is asserted on the
     * audit row.
     */
    public function test_another_merchant_is_refused_and_the_refusal_is_recorded(): void
    {
        [$merchant, $store] = $this->kycMerchantWithStore();
        [$stranger] = $this->kycMerchantWithStore();
        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);
        $document = KycDocumentStore::store($record, $this->kycPngUpload(), 'commercial_register', $merchant);

        $this->actingAs($stranger)
            ->get('/verification/documents/' . $document->id . '/download')
            ->assertStatus(404);

        $this->assertSame(
            0,
            DB::table('audit_logs')->where('action', KycAccessLog::READ)->count(),
            'a refused request must not be recorded as a read'
        );

        $refusal = DB::table('audit_logs')->where('action', KycAccessLog::REFUSED)->first();

        $this->assertNotNull($refusal, 'the owner must be able to see that somebody asked');
        $this->assertSame((int) $stranger->id, (int) $refusal->user_id);
        $this->assertSame(
            (int) $store->id,
            (int) $refusal->store_id,
            'filed against the store whose document was asked for, so it reaches THEIR access log'
        );
    }

    public function test_the_operator_may_open_a_merchants_document_and_it_is_recorded_against_that_merchant(): void
    {
        $operator = $this->makePlatformAdmin(['current_store' => 1]);
        [$merchant, $store] = $this->kycMerchantWithStore();
        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);
        $document = KycDocumentStore::store($record, $this->kycPngUpload(), 'commercial_register', $merchant);

        $this->actingAs($operator)
            ->get('/verification/documents/' . $document->id . '/download')
            ->assertStatus(200);

        $row = DB::table('audit_logs')->where('action', KycAccessLog::READ)->first();

        $this->assertSame((int) $operator->id, (int) $row->user_id);
        $this->assertSame((int) $store->id, (int) $row->store_id);
    }

    public function test_the_access_log_screen_is_the_owners_and_the_operators_only(): void
    {
        [$merchant, $store] = $this->kycMerchantWithStore();
        [$stranger] = $this->kycMerchantWithStore();
        $operator = $this->makePlatformAdmin();
        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);
        $document = KycDocumentStore::store($record, $this->kycPngUpload(), 'commercial_register', $merchant);

        $this->actingAs($merchant)
            ->get('/verification/documents/' . $document->id . '/access-log')
            ->assertStatus(200)
            ->assertViewIs('kyc.access-log');

        $this->actingAs($operator)
            ->get('/verification/documents/' . $document->id . '/access-log')
            ->assertStatus(200);

        $this->actingAs($stranger)
            ->get('/verification/documents/' . $document->id . '/access-log')
            ->assertStatus(404);
    }

    /** An id that is not an id reaches no row. */
    public function test_a_non_numeric_id_is_not_an_id(): void
    {
        [$merchant] = $this->kycMerchantWithStore();

        $this->actingAs($merchant)
            ->get('/verification/documents/abc/download')
            ->assertStatus(404);

        $this->actingAs($merchant)
            ->get('/verification/documents/0/download')
            ->assertStatus(404);
    }

    public function test_a_merchant_uploads_a_configured_document(): void
    {
        [$merchant, $store] = $this->kycMerchantWithStore();

        $response = $this->actingAs($merchant)->post('/verification/documents', [
            'authority' => KycAuthority::STORE_SYRIA,
            'subject_type' => KycVerification::COMPANY,
            'document_kind' => 'commercial_register',
            'document' => $this->kycPngUpload(),
        ]);

        $response->assertRedirect(route('kyc.index'));
        $response->assertSessionHas('success');

        $this->assertSame(1, DB::table('kyc_documents')->where('store_id', $store->id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', KycAccessLog::UPLOADED)->count());
    }

    /**
     * WITH NO OWNER DECISION ABOUT WHICH DOCUMENTS ARE COLLECTED, THE DOOR IS CLOSED.
     *
     * Driven over HTTP as well as at the service, because this is the behaviour a merchant actually
     * meets and because it is the property that makes the feature safe to ship before the policy
     * exists.
     */
    public function test_with_no_configured_kinds_the_upload_door_refuses(): void
    {
        config(['kyc.document_kinds' => ['individual' => [], 'company' => []]]);

        [$merchant, $store] = $this->kycMerchantWithStore();

        $response = $this->actingAs($merchant)->post('/verification/documents', [
            'authority' => KycAuthority::STORE_SYRIA,
            'subject_type' => KycVerification::COMPANY,
            'document_kind' => 'commercial_register',
            'document' => $this->kycPngUpload(),
        ]);

        $response->assertRedirect(route('kyc.index'));
        $response->assertSessionHas('error');

        $this->assertSame(0, DB::table('kyc_documents')->count());
        $this->assertSame([], $this->kycStoredFiles(), 'nothing may reach the disk');
    }

    public function test_an_unknown_authority_is_refused_by_validation(): void
    {
        [$merchant] = $this->kycMerchantWithStore();

        $this->actingAs($merchant)
            ->post('/verification/documents', [
                'authority' => 'some_other_registry',
                'subject_type' => KycVerification::COMPANY,
                'document_kind' => 'commercial_register',
                'document' => $this->kycPngUpload(),
            ])
            ->assertSessionHasErrors('authority');

        $this->assertSame(0, DB::table('kyc_documents')->count());
    }

    /** A MERCHANT CANNOT DECIDE, over HTTP, even on their own record. */
    public function test_a_merchant_cannot_approve_their_own_record_over_http(): void
    {
        [$merchant, $store] = $this->kycMerchantWithStore();
        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);

        $this->actingAs($merchant)
            ->post('/platform/verification/' . $record->id . '/decide', ['decision' => 'approve'])
            ->assertStatus(403);

        $this->assertSame(
            KycVerification::UNSUBMITTED,
            DB::table('kyc_verifications')->where('id', $record->id)->value('status')
        );
    }

    public function test_the_operator_decides_a_document_and_a_record(): void
    {
        $operator = $this->makePlatformAdmin();
        [$merchant, $store] = $this->kycMerchantWithStore();
        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);
        $document = KycDocumentStore::store($record, $this->kycPngUpload(), 'commercial_register', $merchant);

        $this->actingAs($operator)
            ->post('/platform/verification/documents/' . $document->id . '/decide', [
                'decision' => 'approve',
                'note' => 'Checked against the register.',
            ])
            ->assertRedirect(route('kyc.queue'));

        $this->assertSame(
            KycDocument::APPROVED,
            DB::table('kyc_documents')->where('id', $document->id)->value('status')
        );

        $this->actingAs($operator)
            ->post('/platform/verification/' . $record->id . '/decide', ['decision' => 'approve'])
            ->assertRedirect(route('kyc.queue'));

        $this->assertSame(
            KycVerification::APPROVED,
            DB::table('kyc_verifications')->where('id', $record->id)->value('status')
        );
    }

    /**
     * A SHOPPER SESSION IS NOT A PANEL PRINCIPAL, and the trap is the DEFAULT GUARD.
     *
     * `actingAs($customer, 'customers')` makes `customers` the default guard, so a handler reading
     * `auth()->user()` would see the `Customer` — CLAUDE.md names this as the fifth way a test
     * passes for the wrong reason, which is why the default guard is put back to `web` here, as
     * `PlatformUploaderDoorsTest::asAShopper()` does. Every handler in this controller reads
     * `Auth::guard('web')` by name, so a customer session resolves nothing and `auth` redirects.
     */
    public function test_a_shopper_session_reaches_no_verification_door(): void
    {
        [$merchant, $store] = $this->kycMerchantWithStore();
        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);
        $document = KycDocumentStore::store($record, $this->kycPngUpload(), 'commercial_register', $merchant);

        $customer = Customer::create([
            'name' => 'Shopper',
            'email' => 'shopper-' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => bcrypt('secret-' . bin2hex(random_bytes(6))),
            'store_id' => $store->id,
            'type' => 'super admin',        // the `plan.disable` shape: typed, but not a `User`
        ]);

        $this->actingAs($customer, 'customers');
        $this->app['auth']->shouldUse('web');

        $this->get('/verification')->assertRedirect(route('login'));
        $this->get('/verification/documents/' . $document->id . '/download')->assertRedirect(route('login'));
        $this->get('/platform/verification')->assertRedirect(route('login'));

        $this->assertSame(
            0,
            DB::table('audit_logs')->where('action', KycAccessLog::READ)->count()
        );
    }

    /**
     * STAFF FAIL CLOSED UNTIL THE MERCHANT GRANTS THE PERMISSION, which is never seeded.
     */
    public function test_staff_reach_the_screen_only_with_the_permission(): void
    {
        [$merchant, $store] = $this->kycMerchantWithStore();
        $staff = $this->kycStaff($merchant, $store);

        $this->actingAs($staff)->get('/verification')->assertStatus(403);

        $this->grantKycPermission($staff, \App\Services\Kyc\KycAccess::PERMISSION, $store->id);

        $this->actingAs($staff->fresh())->get('/verification')->assertStatus(200);
    }

    /**
     * THE ROUTES REALLY CARRY THE MIDDLEWARE THIS WORKSTREAM BELIEVES THEY DO.
     *
     * Read from the resolved route table, because a LATER declaration of the same verb and URI
     * replaces an earlier one and silently drops its middleware — `OrderDocumentAccessGuard`'s
     * docblock has the measured instance, where a vendored module's re-declaration removed
     * `Authenticate` with the action name unchanged.
     */
    public function test_the_routes_carry_the_middleware_they_are_believed_to(): void
    {
        $expected = [
            'kyc.index' => false,
            'kyc.documents.store' => false,
            'kyc.submit' => false,
            'kyc.documents.download' => false,
            'kyc.documents.access-log' => false,
            'kyc.queue' => true,
            'kyc.documents.decide' => true,
            'kyc.records.decide' => true,
        ];

        foreach ($expected as $name => $operatorOnly) {
            $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, $name . ' is not declared');

            $middleware = $route->gatherMiddleware();

            $this->assertContains(
                'auth',
                $middleware,
                $name . ' does not carry `auth`, so an anonymous caller would reach the handler'
            );

            $this->assertSame(
                $operatorOnly,
                in_array(\App\Http\Middleware\PlatformOperatorOnly::class, $middleware, true),
                $name . ': the operator gate is ' . ($operatorOnly ? 'missing' : 'present and should not be')
            );
        }
    }
}
