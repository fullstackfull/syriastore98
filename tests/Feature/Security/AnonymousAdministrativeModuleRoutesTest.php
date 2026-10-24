<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Security\EnablesWorkdoModules;

/**
 * P1-SEC-04 / P1-SEC-36..40 -- ADMINISTRATIVE MODULE ROUTES THAT ANSWERED AN ANONYMOUS CALLER (EXECUTED).
 * ======================================================================================================
 *
 * The route census (tests/Support/Security/bin/route-census.php, every module loaded through the production loader) found 452 module routes with
 * no authentication middleware; the read-only classification of the 143 mutating ones found six whose anonymous effect is real. Each is driven
 * here through the real route, with the module enabled, BEFORE the fix, and asserts the anonymous caller changes nothing:
 *
 *   1. Campaigns        POST campaigns/campaigns-enable          -- publish/expire any tenant's campaign by id
 *   2. Campaigns        POST campaigns/campaigns-system-setup    -- write the PLATFORM landing page (`landing_page_settings`, rendered unescaped)
 *   3. Helpdesk         POST helpdesk-ticket/{ticketId}          -- append a reply (raw HTML) to any ticket; ticket ids are Unix seconds
 *   4. PartialPayments  POST payment-status-change/{id}          -- set the payment status of any tenant's partial-payment row
 *   5. ProductCatelog   POST admin_email_template_lang/{id}     -- rewrite subject/body/From of any tenant's e-mail template
 *   6. PDFVouchers      POST coupencode/Status/store             -- accept/reject any tenant's coupon request
 *
 * None of them can be fixed in the module (packages/workdo/ is vendored); App\Http\Middleware\ModuleAdministrativeRouteGuard and two new
 * entries of ModuleOrderMutationGuard are the host-side control. A signed-in caller of ANOTHER tenant is refused on the id-keyed ones, and the
 * owner / the platform operator still get through (positive controls, so "nothing changed" cannot pass on a route that 500s for everyone).
 */
class AnonymousAdministrativeModuleRoutesTest extends SecurityTestCase
{
    use EnablesWorkdoModules;
    use RefreshDatabase;

    private function enable(array $modules): void
    {
        $failed = $this->enableWorkdoModules($modules);

        foreach ($modules as $module) {
            $this->assertNotEmpty(
                array_filter(Route::getRoutes()->getRoutes(), fn ($r) => str_starts_with(ltrim($r->getActionName(), '\\'), 'Workdo\\' . $module . '\\')),
                "Premise: {$module} is not loaded, so this test would reach nothing. Failed migrations: " . implode('; ', $failed)
            );
        }
    }

    /** Plan entitlement: PlanModuleCheck refuses a merchant whose plan lacks the module (the operator bypasses it). */
    private function entitle(\App\Models\User $owner, string $module): void
    {
        DB::table('user_active_modules')->insert(['user_id' => $owner->id, 'module' => $module, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertContains($module, ActivatedModule($owner->id), 'The entitlement fixture did not take.');
    }

    private function assertRefusedWithoutEffect($response): void
    {
        $this->assertTrue(
            $response->isRedirect() || in_array($response->status(), [401, 403], true),
            'The anonymous request was neither redirected nor refused: ' . $response->status()
        );
        $this->assertNotSame(401, $response->status(), 'A 401 is a forced logout in the Flutter app; refusals here are redirects or 403.');
    }

    // ---- 1. Campaigns: enable ------------------------------------------------------------------

    /** @param int|null $approval null = awaiting the operator, 1 = approved, 2 = declined (Campaigns::ChangeStatus) */
    private function campaign(int $createdBy, int $status = 0, ?int $approval = 1): int
    {
        return (int) DB::table('campaigns')->insertGetId(['name' => 'C', 'status' => $status, 'approval' => $approval, 'created_by' => $createdBy, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_an_anonymous_caller_cannot_publish_or_expire_a_campaign(): void
    {
        $this->enable(['Campaigns']);
        [$owner] = $this->makeTenant();
        $pending = $this->campaign($owner->id, 0);
        $active = $this->campaign($owner->id, 1);

        $publish = $this->post('/campaigns/campaigns-enable', ['id' => $pending, 'is_disable' => 1]);
        $expire = $this->post('/campaigns/campaigns-enable', ['id' => $active, 'is_disable' => 0]);

        $this->assertRefusedWithoutEffect($publish);
        $this->assertRefusedWithoutEffect($expire);
        $this->assertSame(0, (int) DB::table('campaigns')->where('id', $pending)->value('status'), 'An anonymous caller published a pending campaign.');
        $this->assertSame(1, (int) DB::table('campaigns')->where('id', $active)->value('status'), 'An anonymous caller expired an active campaign.');
    }

    public function test_another_tenants_merchant_cannot_toggle_a_campaign_but_the_owner_and_the_operator_can(): void
    {
        $this->enable(['Campaigns']);
        [$owner] = $this->makeTenant();
        [$stranger] = $this->makeTenant();
        $operator = $this->makePlatformAdmin();
        $this->entitle($owner, 'Campaigns');
        $this->entitle($stranger, 'Campaigns');
        $campaign = $this->campaign($owner->id, 0);

        $this->assertRefusedWithoutEffect($this->actingAs($stranger->fresh())->post('/campaigns/campaigns-enable', ['id' => $campaign, 'is_disable' => 1]));
        $this->assertSame(0, (int) DB::table('campaigns')->where('id', $campaign)->value('status'), "A stranger changed another tenant's campaign.");

        $own = $this->actingAs($owner->fresh())->post('/campaigns/campaigns-enable', ['id' => $campaign, 'is_disable' => 1]);
        $this->assertSame(200, $own->status(), $own->getContent());
        $this->assertSame(1, (int) DB::table('campaigns')->where('id', $campaign)->value('status'), 'Premise: the owner can still publish their own campaign.');

        $this->actingAs($operator)->post('/campaigns/campaigns-enable', ['id' => $campaign, 'is_disable' => 0])->assertOk();
        $this->assertSame(2, (int) DB::table('campaigns')->where('id', $campaign)->value('status'), 'Premise: the operator can still expire a campaign.');
    }

    public function test_a_merchant_naming_a_campaign_that_does_not_exist_is_refused_not_an_error(): void
    {
        $this->enable(['Campaigns']);
        [$merchant] = $this->makeTenant();
        $this->entitle($merchant, 'Campaigns');

        $toggle = $this->actingAs($merchant->fresh())->post('/campaigns/campaigns-enable', ['id' => 987654, 'is_disable' => 1]);
        $screen = $this->actingAs($merchant->fresh())->get('/campaigns/campaigns-analytics/987654');

        $this->assertRefusedWithoutEffect($toggle);
        $this->assertRefusedWithoutEffect($screen);
    }

    public function test_the_owner_cannot_publish_a_campaign_the_operator_has_not_approved_but_the_operator_can(): void
    {
        $this->enable(['Campaigns']);
        [$owner] = $this->makeTenant();
        $operator = $this->makePlatformAdmin();
        $this->entitle($owner, 'Campaigns');
        $awaiting = $this->campaign($owner->id, 0, null);
        $declined = $this->campaign($owner->id, 3, 2);

        // the merchant's own list never offers the toggle for either (action.blade.php: approval == 1 only)
        $this->assertRefusedWithoutEffect($this->actingAs($owner->fresh())->post('/campaigns/campaigns-enable', ['id' => $awaiting, 'is_disable' => 1]));
        $this->assertRefusedWithoutEffect($this->actingAs($owner->fresh())->post('/campaigns/campaigns-enable', ['id' => $declined, 'is_disable' => 1]));
        $this->assertSame(0, (int) DB::table('campaigns')->where('id', $awaiting)->value('status'), 'The owner published a campaign awaiting approval.');
        $this->assertSame(3, (int) DB::table('campaigns')->where('id', $declined)->value('status'), 'The owner re-published a declined campaign.');

        $this->actingAs($operator)->post('/campaigns/campaigns-enable', ['id' => $awaiting, 'is_disable' => 1])->assertOk();
        $this->assertSame(1, (int) DB::table('campaigns')->where('id', $awaiting)->value('status'), 'Premise: the operator can still publish it.');
    }

    // ---- 2. Campaigns: platform landing page ---------------------------------------------------

    public function test_an_anonymous_caller_cannot_write_the_platform_landing_page(): void
    {
        $this->enable(['Campaigns', 'LandingPage']);
        $this->assertTrue(Schema::hasTable('landing_page_settings'), 'Premise: the landing-page settings table exists.');

        $response = $this->post('/campaigns/campaigns-system-setup', [
            'product_campaign' => 'on',
            'product_campaign_title' => 'T',
            'product_campaign_heading' => '<script>FIXTURE-injected()</script>',
            'product_campaign_description' => 'D',
        ]);

        $this->assertRefusedWithoutEffect($response);
        $this->assertSame(0, DB::table('landing_page_settings')->where('name', 'like', 'product_campaign%')->count(), 'An anonymous caller wrote the platform landing page.');
    }

    public function test_a_merchant_cannot_write_the_platform_landing_page_but_the_operator_can(): void
    {
        $this->enable(['Campaigns', 'LandingPage']);
        [$merchant] = $this->makeTenant();
        $operator = $this->makePlatformAdmin();
        $this->entitle($merchant, 'Campaigns');
        $post = ['product_campaign' => 'on', 'product_campaign_title' => 'T', 'product_campaign_heading' => 'H', 'product_campaign_description' => 'D'];

        $this->actingAs($merchant->fresh())->post('/campaigns/campaigns-system-setup', $post);
        $this->assertSame(0, DB::table('landing_page_settings')->where('name', 'like', 'product_campaign%')->count(), 'A merchant wrote the PLATFORM landing page.');

        $this->actingAs($operator)->post('/campaigns/campaigns-system-setup', $post);
        $this->assertSame(4, DB::table('landing_page_settings')->where('name', 'like', 'product_campaign%')->count(), 'Premise: the operator can still save it.');
    }

    // ---- 3. Helpdesk: ticket reply -------------------------------------------------------------

    private function ticket(int $createdBy): int
    {
        return (int) DB::table('helpdesk_tickets')->insertGetId([
            'ticket_id' => time() + random_int(1, 100000), 'name' => 'N', 'email' => 'ticket-FIXTURE@example.test', 'subject' => 'S', 'attachments' => '[]',
            'user_id' => $createdBy, 'created_by' => $createdBy, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_an_anonymous_caller_cannot_append_a_reply_to_a_ticket(): void
    {
        $this->enable(['Helpdesk']);
        [$owner] = $this->makeTenant();
        $id = $this->ticket($owner->id);
        $ticketNumber = DB::table('helpdesk_tickets')->where('id', $id)->value('ticket_id');

        $response = $this->post('/helpdesk-ticket/' . $ticketNumber, ['reply_description' => '<b>FIXTURE-anonymous-reply</b>']);

        $this->assertRefusedWithoutEffect($response);
        $this->assertSame(0, DB::table('helpdesk_conversions')->where('ticket_id', $id)->count(), 'An anonymous caller appended a reply to a ticket.');
    }

    public function test_only_the_ticket_owner_or_the_operator_can_reply(): void
    {
        $this->enable(['Helpdesk']);
        [$owner] = $this->makeTenant();
        [$stranger] = $this->makeTenant();
        $operator = $this->makePlatformAdmin();
        $this->entitle($owner, 'Helpdesk');
        $this->entitle($stranger, 'Helpdesk');
        $id = $this->ticket($owner->id);
        $number = DB::table('helpdesk_tickets')->where('id', $id)->value('ticket_id');

        $this->actingAs($stranger->fresh())->post('/helpdesk-ticket/' . $number, ['reply_description' => 'stranger']);
        $this->assertSame(0, DB::table('helpdesk_conversions')->where('ticket_id', $id)->count(), "A stranger replied on another tenant's ticket.");

        $this->actingAs($owner->fresh())->post('/helpdesk-ticket/' . $number, ['reply_description' => 'owner']);
        $this->assertSame(1, DB::table('helpdesk_conversions')->where('ticket_id', $id)->count(), 'Premise: the ticket owner can still reply.');

        $this->actingAs($operator)->post('/helpdesk-ticket/' . $number, ['reply_description' => 'operator']);
        $this->assertSame(2, DB::table('helpdesk_conversions')->where('ticket_id', $id)->count(), 'Premise: the operator can still reply.');
    }

    public function test_a_ticket_the_owner_raised_for_someone_else_is_still_the_owners_to_reply_to(): void
    {
        $this->enable(['Helpdesk']);
        [$owner] = $this->makeTenant();
        $operator = $this->makePlatformAdmin();
        $this->entitle($owner, 'Helpdesk');
        // created_by = the owner, user_id = the operator: only the created_by limb of the ownership rule lets the owner in
        $id = $this->ticket($owner->id);
        DB::table('helpdesk_tickets')->where('id', $id)->update(['user_id' => $operator->id]);
        $number = DB::table('helpdesk_tickets')->where('id', $id)->value('ticket_id');

        $this->actingAs($owner->fresh())->post('/helpdesk-ticket/' . $number, ['reply_description' => 'owner']);

        $this->assertSame(1, DB::table('helpdesk_conversions')->where('ticket_id', $id)->count(), 'The creator of a ticket was refused it because its user_id is someone else.');
    }

    // ---- 4. PartialPayments: payment status ----------------------------------------------------

    private function partialPayment(int $storeId): int
    {
        return (int) DB::table('order_partial_payments')->insertGetId(['order_id' => 'P-1', 'customer_id' => '1', 'payment_status' => 'Pending payment', 'store_id' => $storeId, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_an_anonymous_caller_cannot_set_a_partial_payment_status(): void
    {
        $this->enable(['PartialPayments']);
        [, $store] = $this->makeTenant();
        $id = $this->partialPayment($store->id);

        $response = $this->post('/payment-status-change/' . $id, ['id' => $id, 'delivered' => 'Paid']);

        $this->assertRefusedWithoutEffect($response);
        $this->assertSame('Pending payment', DB::table('order_partial_payments')->where('id', $id)->value('payment_status'), 'An anonymous caller marked a partial payment as paid.');
    }

    public function test_only_the_stores_owner_or_the_operator_can_set_a_partial_payment_status(): void
    {
        $this->enable(['PartialPayments']);
        [$owner, $store] = $this->makeTenant();
        [$stranger] = $this->makeTenant();
        $operator = $this->makePlatformAdmin(['current_store' => $store->id]);
        $this->entitle($owner, 'PartialPayments');
        $this->entitle($stranger, 'PartialPayments');
        $id = $this->partialPayment($store->id);

        $this->actingAs($stranger->fresh())->post('/payment-status-change/' . $id, ['id' => $id, 'delivered' => 'Paid']);
        $this->assertSame('Pending payment', DB::table('order_partial_payments')->where('id', $id)->value('payment_status'), "A stranger changed another store's partial payment.");

        $this->actingAs($owner->fresh())->post('/payment-status-change/' . $id, ['id' => $id, 'delivered' => 'Paid'])->assertOk();
        $this->assertSame('Paid', DB::table('order_partial_payments')->where('id', $id)->value('payment_status'), 'Premise: the store owner can still set it.');

        $this->actingAs($operator)->post('/payment-status-change/' . $id, ['id' => $id, 'delivered' => 'Refunded'])->assertOk();
        $this->assertSame('Refunded', DB::table('order_partial_payments')->where('id', $id)->value('payment_status'), 'Premise: the operator can still set it.');
    }

    // ---- 5. ProductCatelog: e-mail template ----------------------------------------------------

    /** @return array{0:int,1:int} [email_templates.id, user_email_templates.id] */
    private function emailTemplate(int $ownerId): array
    {
        $template = DB::table('email_templates')->insertGetId(['name' => 'Product Catelog', 'from' => 'ORIGINAL-FIXTURE', 'created_by' => $ownerId, 'created_at' => now(), 'updated_at' => now()]);
        $user = DB::table('user_email_templates')->insertGetId(['template_id' => $template, 'user_id' => $ownerId, 'is_active' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('email_template_langs')->insert(['parent_id' => $template, 'language' => 'en', 'subject' => 'ORIGINAL-SUBJECT', 'content' => 'ORIGINAL-CONTENT', 'created_at' => now(), 'updated_at' => now()]);

        return [(int) $template, (int) $user];
    }

    private function templateBody(int $user): array
    {
        return ['from' => 'ATTACKER-FIXTURE', 'subject' => 'ATTACKER-SUBJECT', 'content' => '<script>FIXTURE()</script>', 'language' => 'en', 'user_email_template_id' => $user, 'is_active' => 'on'];
    }

    public function test_a_user_template_id_with_trailing_junk_never_names_the_row_it_starts_with(): void
    {
        $this->enable(['ProductCatelog']);
        [$owner] = $this->makeTenant();
        $this->entitle($owner, 'ProductCatelog');
        [$template, $ownRow] = $this->emailTemplate($owner->id);

        // MySQL reads '7abc' as 7: the owner's own row id with junk after it must not be accepted as that row
        $response = $this->actingAs($owner->fresh())->post('/admin_email_template_lang/' . $template, array_merge($this->templateBody($ownRow), ['user_email_template_id' => $ownRow . 'abc']));

        $this->assertRefusedWithoutEffect($response);
        $this->assertSame(0, (int) DB::table('user_email_templates')->where('id', $ownRow)->value('is_active'), 'A junk-suffixed row id switched the row it starts with.');
    }

    public function test_an_anonymous_caller_cannot_rewrite_an_email_template(): void
    {
        $this->enable(['ProductCatelog']);
        [$owner] = $this->makeTenant();
        [$template, $user] = $this->emailTemplate($owner->id);

        $response = $this->post('/admin_email_template_lang/' . $template, $this->templateBody($user));

        $this->assertRefusedWithoutEffect($response);
        $this->assertSame('ORIGINAL-FIXTURE', DB::table('email_templates')->where('id', $template)->value('from'), "An anonymous caller rewrote a tenant's e-mail From.");
        $this->assertSame('ORIGINAL-SUBJECT', DB::table('email_template_langs')->where('parent_id', $template)->value('subject'), "An anonymous caller rewrote a tenant's e-mail subject.");
        $this->assertSame(0, (int) DB::table('user_email_templates')->where('id', $user)->value('is_active'));
    }

    public function test_only_the_templates_owner_or_the_operator_can_rewrite_an_email_template(): void
    {
        $this->enable(['ProductCatelog']);
        [$owner] = $this->makeTenant();
        [$stranger] = $this->makeTenant();
        $operator = $this->makePlatformAdmin();
        $this->entitle($owner, 'ProductCatelog');
        $this->entitle($stranger, 'ProductCatelog');
        [$template, $user] = $this->emailTemplate($owner->id);

        $this->actingAs($stranger->fresh())->post('/admin_email_template_lang/' . $template, $this->templateBody($user));
        $this->assertSame('ORIGINAL-FIXTURE', DB::table('email_templates')->where('id', $template)->value('from'), "A stranger rewrote another tenant's e-mail template.");

        $own = ['from' => 'OWNER-FROM', 'subject' => 'OWNER-SUBJECT', 'content' => 'OWNER-CONTENT', 'language' => 'en', 'user_email_template_id' => $user, 'is_active' => 'on'];
        $this->actingAs($owner->fresh())->post('/admin_email_template_lang/' . $template, $own);
        $this->assertSame('OWNER-FROM', DB::table('email_templates')->where('id', $template)->value('from'), 'Premise: the owner can still edit their template.');
        $this->assertSame('OWNER-SUBJECT', DB::table('email_template_langs')->where('parent_id', $template)->value('subject'));
    }

    public function test_a_template_id_in_the_url_cannot_be_paired_with_a_foreign_user_template_row(): void
    {
        $this->enable(['ProductCatelog']);
        [$owner] = $this->makeTenant();
        [$stranger] = $this->makeTenant();
        $this->entitle($stranger, 'ProductCatelog');
        [$strangersTemplate] = $this->emailTemplate($stranger->id);
        [, $ownersUserRow] = $this->emailTemplate($owner->id);

        // the stranger names THEIR template in the URL and the OWNER's user-template row in the body: the handler would flip the owner's switch
        $this->actingAs($stranger->fresh())->post('/admin_email_template_lang/' . $strangersTemplate, $this->templateBody($ownersUserRow));

        $this->assertSame(0, (int) DB::table('user_email_templates')->where('id', $ownersUserRow)->value('is_active'), "A stranger switched another tenant's e-mail template on.");
    }

    // ---- 6. PDFVouchers: coupon request status -------------------------------------------------

    public function test_an_anonymous_caller_cannot_decide_a_coupon_request(): void
    {
        $this->enable(['PDFVouchers']);
        [, $store] = $this->makeTenant();
        $id = (int) DB::table('coupon_requests')->insertGetId(['name' => 'N', 'email' => 'req-FIXTURE@example.test', 'detail' => 'D', 'status' => 'pending', 'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->post('/coupencode/Status/store', ['id' => $id, 'status' => 'reject']);

        $this->assertRefusedWithoutEffect($response);
        $this->assertSame('pending', DB::table('coupon_requests')->where('id', $id)->value('status'), 'An anonymous caller decided a coupon request.');
    }

    // ---- 7. Duo2FA: the second factor's own switch ---------------------------------------------

    private function duoPost(array $overrides = []): array
    {
        return array_merge(['duo_setting_enabled' => 'off', 'duo_api_host_name' => 'api-FIXTURE.example.test', 'duo_integration_key' => str_repeat('I', 20), 'duo_secret_key' => str_repeat('s', 40)], $overrides);
    }

    public function test_an_anonymous_caller_cannot_change_the_duo_settings(): void
    {
        $this->enable(['Duo2FA']);
        [, $store] = $this->makeTenant();

        $response = $this->post('/duo2fa-setting', $this->duoPost());

        $this->assertRefusedWithoutEffect($response);
        $this->assertNull(DB::table('stores')->where('id', $store->id)->value('duo_secret_key'));
    }

    public function test_staff_cannot_change_the_owners_duo_settings_but_the_owner_can(): void
    {
        $this->enable(['Duo2FA']);
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner);

        $this->actingAs($staff->fresh())->post('/duo2fa-setting', $this->duoPost());
        $this->assertNull(DB::table('stores')->where('id', $store->id)->value('duo_secret_key'), "Staff rewrote the owner's Duo configuration.");

        $this->actingAs($owner->fresh())->post('/duo2fa-setting', $this->duoPost());
        $this->assertSame(str_repeat('s', 40), DB::table('stores')->where('id', $store->id)->value('duo_secret_key'), 'Premise: the owner can still configure Duo.');
    }

    public function test_while_duo_is_enforced_a_password_only_session_cannot_switch_it_off_or_rekey_it(): void
    {
        $this->enable(['Duo2FA']);
        [$owner, $store] = $this->makeTenant();
        DB::table('stores')->where('id', $store->id)->update(['duo_setting_enabled' => 'on', 'duo_integration_key' => str_repeat('K', 20), 'duo_secret_key' => str_repeat('o', 40)]);
        (new \App\Models\Store())->flushCache();

        $this->actingAs($owner->fresh())->post('/duo2fa-setting', $this->duoPost(['duo_setting_enabled' => 'off']));
        $this->assertSame('on', DB::table('stores')->where('id', $store->id)->value('duo_setting_enabled'), 'A session that has not passed Duo switched Duo off.');
        $this->assertSame(str_repeat('o', 40), DB::table('stores')->where('id', $store->id)->value('duo_secret_key'), 'A session that has not passed Duo re-keyed it.');

        // the same owner, in a session that HAS passed Duo (the handler sets this marker), can
        $this->withSession(['is_login' => 'true'])->actingAs($owner->fresh())->post('/duo2fa-setting', $this->duoPost(['duo_setting_enabled' => 'off']));
        $this->assertSame('off', DB::table('stores')->where('id', $store->id)->value('duo_setting_enabled'), 'Premise: a session that passed Duo can still change it.');
    }

    // ---- 8. the GET twins (state changes and disclosures on GET) --------------------------------

    public function test_an_anonymous_get_cannot_approve_or_decline_a_campaign(): void
    {
        $this->enable(['Campaigns']);
        [$owner] = $this->makeTenant();
        $campaign = $this->campaign($owner->id, 1, null);

        $this->assertRefusedWithoutEffect($this->get('/campaigns/campaigns-status/' . $campaign . '/2'));

        $row = DB::table('campaigns')->where('id', $campaign)->first();
        $this->assertSame(1, (int) $row->status, 'An anonymous GET declined an active campaign.');
        $this->assertNull($row->approval, 'An anonymous GET wrote the approval of a campaign.');
    }

    public function test_only_the_operator_can_approve_or_decline_a_campaign(): void
    {
        $this->enable(['Campaigns']);
        [$owner] = $this->makeTenant();
        $operator = $this->makePlatformAdmin();
        $this->entitle($owner, 'Campaigns');
        $campaign = $this->campaign($owner->id, 1, null);

        $this->actingAs($owner->fresh())->get('/campaigns/campaigns-status/' . $campaign . '/2');
        $this->assertNull(DB::table('campaigns')->where('id', $campaign)->value('approval'), 'The campaign\'s own merchant approved/declined it; that is the operator\'s decision.');

        $this->actingAs($operator)->get('/campaigns/campaigns-status/' . $campaign . '/2');
        $this->assertSame(2, (int) DB::table('campaigns')->where('id', $campaign)->value('approval'), 'Premise: the operator can still decline.');
        $this->assertSame(3, (int) DB::table('campaigns')->where('id', $campaign)->value('status'));
    }

    public function test_campaign_screens_do_not_disclose_another_tenants_campaign(): void
    {
        $this->enable(['Campaigns']);
        [$owner] = $this->makeTenant();
        [$stranger] = $this->makeTenant();
        $this->entitle($stranger, 'Campaigns');
        $campaign = $this->campaign($owner->id, 1);

        foreach (['/campaigns/campaigns-view/' . $campaign, '/campaigns/campaigns-analytics/' . $campaign] as $uri) {
            $this->assertRefusedWithoutEffect($this->get($uri));
            $this->assertRefusedWithoutEffect($this->actingAs($stranger->fresh())->get($uri));
        }
    }

    public function test_an_anonymous_get_cannot_flip_the_default_language_of_an_email_template(): void
    {
        $this->enable(['ProductCatelog']);
        [$owner] = $this->makeTenant();
        [$template] = $this->emailTemplate($owner->id);
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('email_template_langs', 'is_default'), 'Premise: the column the GET handler writes exists.');
        DB::table('email_template_langs')->insert(['parent_id' => $template, 'language' => 'fr', 'subject' => 'S', 'content' => 'C', 'is_default' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('email_template_langs')->where('parent_id', $template)->where('language', 'en')->update(['is_default' => 1]);

        $this->assertRefusedWithoutEffect($this->get('/admin_email_template_lang/' . $template . '/fr'));

        $this->assertSame(1, (int) DB::table('email_template_langs')->where('parent_id', $template)->where('language', 'en')->value('is_default'), 'An anonymous GET changed the default language of a tenant\'s template.');
    }

    public function test_a_stranger_cannot_flip_it_either_but_the_owner_can(): void
    {
        $this->enable(['ProductCatelog']);
        [$owner] = $this->makeTenant();
        [$stranger] = $this->makeTenant();
        $this->entitle($owner, 'ProductCatelog');
        $this->entitle($stranger, 'ProductCatelog');
        [$template] = $this->emailTemplate($owner->id);
        DB::table('email_template_langs')->insert(['parent_id' => $template, 'language' => 'fr', 'subject' => 'S', 'content' => 'C', 'is_default' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('email_template_langs')->where('parent_id', $template)->where('language', 'en')->update(['is_default' => 1]);

        $this->actingAs($stranger->fresh())->get('/admin_email_template_lang/' . $template . '/fr');
        $this->assertSame(1, (int) DB::table('email_template_langs')->where('parent_id', $template)->where('language', 'en')->value('is_default'), 'A stranger changed another tenant\'s default template language.');

        $this->actingAs($owner->fresh())->get('/admin_email_template_lang/' . $template . '/fr');
        $this->assertSame(1, (int) DB::table('email_template_langs')->where('parent_id', $template)->where('language', 'fr')->value('is_default'), 'Premise: the owner can still switch their template\'s language.');
    }

    public function test_the_helpdesk_ticket_link_page_is_for_the_ticket_owner_and_the_operator_only(): void
    {
        $this->enable(['Helpdesk']);
        [$owner] = $this->makeTenant();
        [$stranger] = $this->makeTenant();
        $operator = $this->makePlatformAdmin();
        $id = $this->ticket($owner->id);
        DB::table('helpdesk_tickets')->where('id', $id)->update(['subject' => 'TICKET-BODY-FIXTURE', 'description' => 'TICKET-BODY-FIXTURE']);
        $number = DB::table('helpdesk_tickets')->where('id', $id)->value('ticket_id');
        $link = '/helpdesk-ticket-show/' . \Illuminate\Support\Facades\Crypt::encrypt($number);

        $anonymous = $this->get($link);
        $this->assertStringNotContainsString('TICKET-BODY-FIXTURE', (string) $anonymous->getContent(), 'An anonymous link holder was shown a ticket.');
        $this->assertRefusedWithoutEffect($anonymous);

        $this->assertStringNotContainsString('TICKET-BODY-FIXTURE', (string) $this->actingAs($stranger->fresh())->get($link)->getContent(), "A stranger was shown another tenant's ticket.");

        $this->entitle($owner, 'Helpdesk');
        $ownerView = $this->actingAs($owner->fresh())->get($link);
        $this->assertStringContainsString('TICKET-BODY-FIXTURE', (string) $ownerView->getContent(), 'Premise: the ticket owner still sees their ticket.');

        $this->assertStringContainsString('TICKET-BODY-FIXTURE', (string) $this->actingAs($operator)->get($link)->getContent(), 'Premise: the operator still sees it.');
    }

    public function test_a_forged_or_malformed_ticket_link_segment_is_refused(): void
    {
        $this->enable(['Helpdesk']);
        [$owner] = $this->makeTenant();
        $id = $this->ticket($owner->id);
        $number = DB::table('helpdesk_tickets')->where('id', $id)->value('ticket_id');
        $this->entitle($owner, 'Helpdesk');

        foreach ([(string) $number, 'not-encrypted', \Illuminate\Support\Facades\Crypt::encrypt(['x' => 1]), \Illuminate\Support\Facades\Crypt::encryptString('abc')] as $segment) {
            $response = $this->actingAs($owner->fresh())->get('/helpdesk-ticket-show/' . $segment);

            $this->assertLessThan(500, $response->status(), 'A malformed ticket link segment caused a server error.');
            $this->assertStringNotContainsString('TICKET-BODY-FIXTURE', (string) $response->getContent());
        }
    }

    // ---- gaps found by the removal checks ---------------------------------------------------------

    public function test_the_owner_still_opens_their_own_campaign_screens(): void
    {
        $this->enable(['Campaigns']);
        [$owner] = $this->makeTenant();
        $this->entitle($owner, 'Campaigns');
        $campaign = $this->campaign($owner->id, 1);

        $response = $this->actingAs($owner->fresh())->get('/campaigns/campaigns-analytics/' . $campaign);

        $this->assertFalse($response->isRedirect() && str_contains((string) session('error'), 'Permission denied'), 'The owner was refused their own campaign analytics.');
        $this->assertNotSame(403, $response->status());
    }

    public function test_the_owner_staff_and_an_assigned_user_pass_the_ownership_rules_of_their_own_tenant(): void
    {
        $this->enable(['Campaigns', 'Helpdesk', 'ProductCatelog']);
        [$owner] = $this->makeTenant();
        $staff = $this->makeStaff($owner);
        // ActivatedModule() is memoised on first read: insert every entitlement BEFORE the first read
        foreach (['Campaigns', 'Helpdesk', 'ProductCatelog'] as $module) {
            DB::table('user_active_modules')->insert(['user_id' => $owner->id, 'module' => $module, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->assertEqualsCanonicalizing(['Campaigns', 'Helpdesk', 'ProductCatelog'], array_values(array_intersect(['Campaigns', 'Helpdesk', 'ProductCatelog'], ActivatedModule($owner->id))), 'The entitlement fixture did not take.');

        // campaigns are created with the tenant owner's id (creatorId()); staff of that tenant may toggle them
        $campaign = $this->campaign($owner->id, 0);
        $this->actingAs($staff->fresh())->post('/campaigns/campaigns-enable', ['id' => $campaign, 'is_disable' => 1]);
        $this->assertSame(1, (int) DB::table('campaigns')->where('id', $campaign)->value('status'), "Staff of the campaign's tenant were refused.");

        // a ticket raised BY THE OPERATOR FOR the owner (created_by = operator, user_id = owner): the owner is its user
        $operator = $this->makePlatformAdmin();
        $ticketId = $this->ticket($operator->id);
        DB::table('helpdesk_tickets')->where('id', $ticketId)->update(['user_id' => $owner->id]);
        $number = DB::table('helpdesk_tickets')->where('id', $ticketId)->value('ticket_id');
        $this->actingAs($owner->fresh())->post('/helpdesk-ticket/' . $number, ['reply_description' => 'from the ticket\'s user']);
        $this->assertSame(1, DB::table('helpdesk_conversions')->where('ticket_id', $ticketId)->count(), 'The user a ticket was raised for was refused a reply.');

        // an e-mail template owned by the tenant owner, edited by staff of that tenant
        [$template, $userRow] = $this->emailTemplate($owner->id);
        $this->actingAs($staff->fresh())->post('/admin_email_template_lang/' . $template, ['from' => 'STAFF-FROM', 'subject' => 'S', 'content' => 'C', 'language' => 'en', 'user_email_template_id' => $userRow, 'is_active' => 'on']);
        $this->assertSame('STAFF-FROM', DB::table('email_templates')->where('id', $template)->value('from'), 'Staff of the template\'s tenant were refused.');
    }

    public function test_a_user_template_row_must_belong_to_the_template_and_to_the_callers_tenant(): void
    {
        $this->enable(['ProductCatelog']);
        [$owner] = $this->makeTenant();
        [$stranger] = $this->makeTenant();
        $this->entitle($owner, 'ProductCatelog');
        [$template, $ownRow] = $this->emailTemplate($owner->id);
        [, $otherTemplatesRow] = $this->emailTemplate($owner->id);                     // the owner's row for a DIFFERENT template
        // the platform seeds a user_email_templates row for every user against every template: the stranger's row for the OWNER's template
        $strangersRowForOwnersTemplate = (int) DB::table('user_email_templates')->insertGetId(['template_id' => $template, 'user_id' => $stranger->id, 'is_active' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $body = fn (int $userRow) => ['from' => 'F', 'subject' => 'S', 'content' => 'C', 'language' => 'en', 'user_email_template_id' => $userRow, 'is_active' => 'on'];

        // the owner names their template in the URL and ANOTHER template's row in the body: refused (template match)
        $this->actingAs($owner->fresh())->post('/admin_email_template_lang/' . $template, $body($otherTemplatesRow));
        $this->assertSame(0, (int) DB::table('user_email_templates')->where('id', $otherTemplatesRow)->value('is_active'), 'A user-template row of a different template was switched on through this template.');

        // the owner names their template and the STRANGER's row for it: refused (tenant match)
        $this->actingAs($owner->fresh())->post('/admin_email_template_lang/' . $template, $body($strangersRowForOwnersTemplate));
        $this->assertSame(0, (int) DB::table('user_email_templates')->where('id', $strangersRowForOwnersTemplate)->value('is_active'), "Another tenant's user-template row was switched on.");

        $this->actingAs($owner->fresh())->post('/admin_email_template_lang/' . $template, $body($ownRow));
        $this->assertSame(1, (int) DB::table('user_email_templates')->where('id', $ownRow)->value('is_active'), 'Premise: the owner\'s own row is switched on.');
    }

    public function test_a_refusal_to_a_json_caller_is_a_403_never_a_401(): void
    {
        $this->enable(['Campaigns']);
        [$owner] = $this->makeTenant();
        $campaign = $this->campaign($owner->id, 0);

        $response = $this->postJson('/campaigns/campaigns-enable', ['id' => $campaign, 'is_disable' => 1]);

        $this->assertSame(403, $response->status(), 'The shipped Flutter app forces a logout on any 401; a refusal here must be a 403.');
    }

    public function test_only_the_stores_owner_decides_a_coupon_request(): void
    {
        $this->enable(['PDFVouchers']);
        [$owner, $store] = $this->makeTenant();
        [$stranger] = $this->makeTenant();
        $id = (int) DB::table('coupon_requests')->insertGetId(['name' => 'N', 'email' => 'req-FIXTURE@example.test', 'detail' => 'D', 'status' => 'pending', 'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($stranger->fresh())->post('/coupencode/Status/store', ['id' => $id, 'status' => 'reject']);
        $this->assertSame('pending', DB::table('coupon_requests')->where('id', $id)->value('status'), "A stranger decided another store's coupon request.");

        $this->actingAs($owner->fresh())->post('/coupencode/Status/store', ['id' => $id, 'status' => 'reject']);
        $this->assertSame('reject', DB::table('coupon_requests')->where('id', $id)->value('status'), 'Premise: the store owner can still decide it.');
    }

    // ---- 9. store-row rules: handlers that mutate a row by a bare id ---------------------------

    public function test_only_the_stores_owner_decides_an_affiliate_payout_request(): void
    {
        $this->enable(['ProductAffiliate']);
        [$owner, $store] = $this->makeTenant();
        [$stranger] = $this->makeTenant();
        $shopper = \App\Models\Customer::forceCreate([
            'first_name' => 'Aff', 'last_name' => 'Shopper', 'email' => 'aff-' . uniqid() . '@example.test', 'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'), 'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
        $id = (int) DB::table('transaction_orders')->insertGetId(['req_amount' => 10, 'req_user_id' => $shopper->id, 'status' => 1, 'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now()]);

        // anonymous and another tenant's merchant: refused, the row is untouched (status 0 = rejected is the effect a decision has)
        $this->assertRefusedWithoutEffect($this->get('/request-amount/' . $id . '/0'));
        $this->assertRefusedWithoutEffect($this->actingAs($stranger->fresh())->get('/request-amount/' . $id . '/0'));
        $this->assertSame(1, (int) DB::table('transaction_orders')->where('id', $id)->value('status'), "A stranger decided another store's payout request.");

        $this->actingAs($owner->fresh())->get('/request-amount/' . $id . '/0');
        $this->assertSame(0, (int) DB::table('transaction_orders')->where('id', $id)->value('status'), 'Premise: the store owner can still decide it.');
    }

    public function test_the_row_id_must_come_from_the_route_not_the_query(): void
    {
        $this->enable(['ProductAffiliate']);
        [, $store] = $this->makeTenant();
        [$stranger, $strangersStore] = $this->makeTenant();
        $victimsRow = (int) DB::table('transaction_orders')->insertGetId(['req_amount' => 10, 'req_user_id' => 1, 'status' => 1, 'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now()]);
        $strangersRow = (int) DB::table('transaction_orders')->insertGetId(['req_amount' => 10, 'req_user_id' => 1, 'status' => 1, 'store_id' => $strangersStore->id, 'created_at' => now(), 'updated_at' => now()]);

        // THE ATTACK: the handler mutates the row named in the ROUTE; a guard that authorised the row named in the QUERY would let the stranger
        // present their own row there and act on the victim's. The victim's row is in the route; the stranger's own is in the query.
        $this->assertRefusedWithoutEffect($this->actingAs($stranger->fresh())->get('/request-amount/' . $victimsRow . '/0?id=' . $strangersRow));

        $this->assertSame(1, (int) DB::table('transaction_orders')->where('id', $victimsRow)->value('status'), 'A query id authorised a row the route did not name.');
    }

    public function test_only_the_stores_owner_deletes_an_order_review(): void
    {
        $this->enable(['ReviewReminder']);
        [$owner, $store] = $this->makeTenant();
        [$stranger] = $this->makeTenant();
        $this->grantPermission($owner, 'Delete Order Reviews');
        $this->grantPermission($stranger, 'Delete Order Reviews');
        $id = (int) DB::table('order_reviews')->insertGetId(['name' => 'N', 'email' => 'rev-FIXTURE@example.test', 'rating' => 5, 'description' => 'D', 'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertRefusedWithoutEffect($this->delete('/order-review-store/' . $id));
        $this->assertRefusedWithoutEffect($this->actingAs($stranger->fresh())->delete('/order-review-store/' . $id));
        $this->assertSame(1, DB::table('order_reviews')->where('id', $id)->count(), "A stranger deleted another store's review.");

        $this->actingAs($owner->fresh())->delete('/order-review-store/' . $id);
        $this->assertSame(0, DB::table('order_reviews')->where('id', $id)->count(), 'Premise: the store owner can still delete it.');
    }
}
