<?php

namespace Tests\Feature\Security;

use App\Models\EmailTemplate;
use App\Models\EmailTemplateLang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * P1-SEC-64 -- ANY VERIFIED USER COULD REWRITE THE EMAIL BODIES EVERY STORE SENDS.
 *
 * Found by the independent review of the email consent seam (D8-2a), and OUTSIDE that change: `EmailTemplateController::updateEmailSettings`
 * (`PUT /email_template_lang/{id}`) took any template id from the URL and rewrote its `from`, subject and body, with NO authorisation and NO
 * ownership check, behind nothing but `auth`, `xss`, `setlocate` and `verified`. The content-editing SCREEN was always the platform operator's
 * -- `emailTemplate()` lists only `created_by = Auth::id()` -- and the six host templates are ONE platform-global row each
 * (`EmailTemplateOwnerResolutionTest`: "one body per template for the whole platform"), so a merchant who has never been shown the screen could
 * PUT to it and put their own HTML into the order confirmation that every other store's customers receive. The seven module copies of this same
 * write were fixed earlier with an ownership guard; the host's own route was not.
 *
 * THE RULE, the one the screen already follows: a template is edited by the user who owns it. The platform operator owns the six host templates;
 * a merchant owns the per-tenant rows their own modules seeded.
 *
 * This is also what keeps the consent classification honest. `MailSenderRegistry` decides what a template is FOR from its name; the body is data.
 * Whoever can write the body of 'Order Created' can make a receipt carry a promotion, and nothing at the mail layer can tell.
 */
class EmailTemplateWriteOwnershipTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @return array{0: EmailTemplate, 1: EmailTemplateLang} */
    private function template(string $name, int $ownerId): array
    {
        $template = EmailTemplate::forceCreate(['name' => $name, 'from' => 'original@example.test', 'created_by' => $ownerId]);
        $lang = EmailTemplateLang::forceCreate([
            'parent_id' => $template->id, 'language' => 'en', 'subject' => 'ORIGINAL SUBJECT', 'content' => '<p>ORIGINAL BODY</p>',
        ]);

        return [$template, $lang];
    }

    private function payload(): array
    {
        return ['from' => 'attacker@example.test', 'subject' => 'HIJACKED SUBJECT', 'content' => '<p>HIJACKED BODY <a href="https://evil.test">pay here</a></p>', 'language' => 'en', 'lang' => 'en'];
    }

    private function assertUntouched(EmailTemplate $template, EmailTemplateLang $lang, string $message): void
    {
        $row = DB::table('email_templates')->where('id', $template->id)->first();
        $body = DB::table('email_template_langs')->where('id', $lang->id)->first();

        $this->assertSame('original@example.test', $row->from, $message);
        $this->assertSame('ORIGINAL SUBJECT', $body->subject, $message);
        $this->assertSame('<p>ORIGINAL BODY</p>', $body->content, $message);
    }

    public function test_a_merchant_cannot_rewrite_the_platform_wide_template_every_store_sends(): void
    {
        $admin = $this->makePlatformAdmin();
        [$merchant] = $this->makeTenant();
        [$template, $lang] = $this->template('Order Created', $admin->id);

        $response = $this->actingAs($merchant)->put(route('updateEmail.settings', $template->id), $this->payload());

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertUntouched($template, $lang, 'a merchant rewrote the order-confirmation every store sends');
    }

    public function test_a_merchant_cannot_rewrite_another_merchants_template(): void
    {
        [$owner] = $this->makeTenant();
        [$other] = $this->makeTenant();
        [$template, $lang] = $this->template('Review Reminder', $owner->id);

        $this->actingAs($other)->put(route('updateEmail.settings', $template->id), $this->payload())->assertSessionHas('error');

        $this->assertUntouched($template, $lang, "a merchant rewrote another merchant's template");
    }

    public function test_a_merchant_can_still_edit_a_template_they_own(): void
    {
        [$merchant] = $this->makeTenant();
        [$template, $lang] = $this->template('Review Reminder', $merchant->id);

        $this->actingAs($merchant)->put(route('updateEmail.settings', $template->id), $this->payload())->assertSessionHas('success');

        $this->assertSame('HIJACKED SUBJECT', DB::table('email_template_langs')->where('id', $lang->id)->value('subject'),
            'the owner can no longer edit their own template: the guard is too wide');
    }

    public function test_the_platform_operator_can_edit_the_templates_they_own(): void
    {
        $admin = $this->makePlatformAdmin();
        [$template, $lang] = $this->template('Order Created', $admin->id);

        $this->actingAs($admin)->put(route('updateEmail.settings', $template->id), $this->payload())->assertSessionHas('success');

        $this->assertSame('HIJACKED SUBJECT', DB::table('email_template_langs')->where('id', $lang->id)->value('subject'));
    }

    public function test_a_refused_write_creates_no_language_row_either(): void
    {
        $admin = $this->makePlatformAdmin();
        [$merchant] = $this->makeTenant();
        [$template] = $this->template('Order Created', $admin->id);
        $before = DB::table('email_template_langs')->count();

        // A language the template has no row for: the controller would CREATE one. A refused write must not.
        $this->actingAs($merchant)->put(route('updateEmail.settings', $template->id), ['language' => 'ar', 'lang' => 'ar'] + $this->payload())->assertSessionHas('error');

        $this->assertSame($before, DB::table('email_template_langs')->count(), 'a refused write still created a language row');
    }

    public function test_a_merchant_cannot_read_a_template_they_do_not_own_through_the_edit_screen(): void
    {
        $admin = $this->makePlatformAdmin();
        [$merchant] = $this->makeTenant();
        [$template] = $this->template('Order Created', $admin->id);

        $response = $this->actingAs($merchant)->get(route('manage.email.language', [$template->id, 'en']));

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_the_edit_screen_still_opens_for_the_owner(): void
    {
        // A merchant with a store: the platform operator in this suite has none, and the layout needs one to build its theme URL.
        [$merchant] = $this->makeTenant();
        [$template] = $this->template('Review Reminder', $merchant->id);

        $this->actingAs($merchant)->get(route('manage.email.language', [$template->id, 'en']))->assertOk();
    }

    public function test_a_guest_is_sent_to_log_in(): void
    {
        $admin = $this->makePlatformAdmin();
        [$template] = $this->template('Order Created', $admin->id);

        $this->put(route('updateEmail.settings', $template->id), $this->payload())->assertRedirect();
        $this->assertSame('original@example.test', DB::table('email_templates')->where('id', $template->id)->value('from'));
    }
}
