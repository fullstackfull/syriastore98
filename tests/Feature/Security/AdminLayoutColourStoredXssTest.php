<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Security\DetectsActiveMarkup;

/**
 * P1-SEC-80, third round -- the store's COLOUR is printed raw inside the admin layout's `<style>`, on every panel page.
 * ============================================================================================================================================
 *
 * `layouts/app.blade.php` prints `<?= $color ?>` (not `{!! !!}`, so the round-1 census of raw prints never listed it) into a `:root { --color-customColor: ...; }` rule, and `$color` is the
 * store's `color` setting, which `SettingController::BusinessSettings` writes from `custom_color` / `color` with no rule at all: the route takes EVERY posted name into `settings`, and the XSS
 * middleware is a four-string denylist that passes `}</style><img src=x onerror=...>` unchanged. A member of staff holding `Manage Setting` stores the value; the owner's next panel page,
 * any page, closes the `<style>` element and runs the rest in the admin origin, with no click. A merchant (registration is open) stores it for their own store, and the operator who
 * impersonates that merchant runs it on the first page.
 *
 * The test drives the real route as the staff member and reads the owner's page with the DOM as the oracle (DetectsActiveMarkup). Its premise is a benign custom colour: it shows that a value
 * stored this way reaches the owner's `<style>`, so a refusal of the hostile value is a refusal and not a pipeline that never connected.
 */
class AdminLayoutColourStoredXssTest extends SecurityTestCase
{
    use DetectsActiveMarkup;
    use RefreshDatabase;

    private const PAYLOAD = 'red;}</style><img src=x onerror=XSS-4><style>';

    /** @return array{0:\App\Models\User,1:\App\Models\User} the owner and a member of staff who holds `Manage Setting` */
    private function ownerAndSettingsStaff(): array
    {
        [$owner] = $this->makeTenant();
        $this->grantPermission($owner, 'View Finance Dashboard');
        $staff = $this->makeStaff($owner);
        $this->grantPermission($staff, 'Manage Setting');

        return [$owner, $staff];
    }

    private function saveCustomColour(\App\Models\User $writer, string $colour)
    {
        return $this->actingAs($writer)->post('/business-settings', ['color_flag' => 'true', 'custom_color' => $colour, 'color' => $colour]);
    }

    private function ownersPage(\App\Models\User $owner): string
    {
        $response = $this->actingAs($owner)->get('/finance');
        $response->assertOk();

        return (string) $response->getContent();
    }

    public function test_the_oracle_sees_the_payload_when_the_layout_prints_it_raw(): void
    {
        $page = '<style>:root { --color-customColor: ' . self::PAYLOAD . '; }</style>';

        $this->assertContains('an image with a handler', $this->activeMarkupIn($page), 'premise: the DOM oracle finds the breakout when the colour is printed raw');
    }

    public function test_a_custom_colour_a_staff_member_stores_reaches_the_owners_style_block(): void
    {
        [$owner, $staff] = $this->ownerAndSettingsStaff();

        $this->saveCustomColour($staff, '#336699')->assertRedirect();

        $this->assertStringContainsString('--color-customColor: #336699', $this->ownersPage($owner), 'premise: a stored custom colour is what the owner\'s layout prints');
    }

    public function test_a_custom_colour_is_not_printed_as_markup_on_the_owners_pages(): void
    {
        [$owner, $staff] = $this->ownerAndSettingsStaff();

        $this->saveCustomColour($staff, self::PAYLOAD);

        $this->assertSame([], $this->activeMarkupIn($this->ownersPage($owner)), 'a stored colour closed the layout\'s <style> and wrote markup into the owner\'s page');
    }

    /** The first layer: the Settings handler refuses a value that is not a colour, before it writes or uploads anything. */
    public function test_a_value_that_is_not_a_colour_is_refused_before_it_is_stored(): void
    {
        [$owner, $staff] = $this->ownerAndSettingsStaff();

        $response = $this->saveCustomColour($staff, self::PAYLOAD);

        $this->assertNotEmpty(session('error') ?? $response->getSession()?->get('error'), 'premise: the handler answered with a refusal, not with a success');
        $this->assertNotContains(self::PAYLOAD, DB::table('settings')->whereIn('name', ['color', 'custom_color'])->pluck('value')->all(), 'a value that is not a colour was stored');
    }

    /**
     * The second layer, which is the control: `getAdminAllSetting()` hands the layout whatever the settings table holds, and other handlers take every posted name too, so a value written before the
     * rule existed -- or by a writer that does not ask -- must not leave the declaration it is printed in.
     */
    public function test_a_hostile_colour_already_in_the_table_is_not_printed_as_markup(): void
    {
        [$owner] = $this->ownerAndSettingsStaff();
        $this->writeRawSetting($owner, 'color', self::PAYLOAD);
        $this->writeRawSetting($owner, 'color_flag', 'true');

        $page = $this->ownersPage($owner);

        $this->assertSame([], $this->activeMarkupIn($page), 'a colour stored by a writer that does not ask closed the layout\'s <style>');
        $this->assertStringNotContainsString('XSS-4', $page, 'premise of the fix: the value is not printed at all, not even escaped (a colour is a colour or it is the default)');
    }

    public function test_a_theme_name_and_a_hex_colour_are_still_printed_as_they_were(): void
    {
        [$owner, $staff] = $this->ownerAndSettingsStaff();

        foreach (['theme-4', '#336699', 'rgb(10, 20, 30)'] as $colour) {
            $this->saveCustomColour($staff, $colour)->assertRedirect();
            $this->assertStringContainsString('--color-customColor: ' . $colour . ';', $this->ownersPage($owner), 'a colour a store may choose must print unchanged: ' . $colour);
        }
    }

    private function writeRawSetting(\App\Models\User $owner, string $name, string $value): void
    {
        DB::table('settings')->insert(['name' => $name, 'value' => $value, 'store_id' => $owner->current_store, 'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
    }
}
