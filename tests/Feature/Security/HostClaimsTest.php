<?php

namespace Tests\Feature\Security;

use App\Models\Setting;
use App\Services\HostClaims;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * P1-SEC-29 -- a merchant could claim another merchant's custom domain or sub-domain.
 * =============================================================================================
 *
 * The storefront resolves a request's HOST to a store with `Setting::where('name', 'domains')->where('value', $host)->first()` (and the
 * same for `subdomain`): no store predicate, no ORDER BY, so the row with the lowest id of ANY tenant won, and nothing kept a host
 * unique across stores. EXECUTED before this fix: the owner of an older store (nothing unusual, a permitted merchant) posted
 * `domains = <the newer store's host>` to /customize-settings and the lookup then returned the older store -- shoppers who typed the
 * newer merchant's own domain were served the older merchant's storefront.
 *
 * Two controls, both pinned here:
 *   WRITE  A host that another store HOLDS cannot be saved for this store (App\Models\Setting::saving -> App\Services\HostClaims), in
 *          either name (`domains` / `subdomain`), however it is spelled (case, `www.`), and a store cannot ENABLE a host another store
 *          holds. "Holds" is: the row AND that store's matching enable flag. ThemeSettings checks first, before the hosting panel.
 *   READ   The lookups resolve a row only when its own store has the matching flag on, so a duplicate that already exists in the data
 *          (nothing in the repository can see the live table: P1-OWN-11) resolves to the store that is actually serving the host.
 */
class HostClaimsTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const HOST = 'victim-shop.example.test';

    private $olderOwner;
    private $olderStore;
    private $victimOwner;
    private $victimStore;

    protected function setUp(): void
    {
        parent::setUp();

        // The older merchant first, so its rows have the lower ids -- the precondition of the attack.
        [$this->olderOwner, $this->olderStore] = $this->makeTenant();
        $this->giveRows($this->olderStore, $this->olderOwner, ['domains' => 'older-shop.example.test', 'enable_domain' => 'on']);
        [$this->victimOwner, $this->victimStore] = $this->makeTenant();
        $this->giveRows($this->victimStore, $this->victimOwner, ['domains' => self::HOST, 'enable_domain' => 'on']);
    }

    /** raw rows: they model data that is already in the table, and do not go through the model hook */
    private function giveRows($store, $owner, array $rows): void
    {
        foreach ($rows as $name => $value) {
            DB::table('settings')->insert(['name' => $name, 'value' => $value, 'created_by' => $owner->id, 'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function hostResolvesTo(string $name, string $host): ?int
    {
        $row = HostClaims::row($name, $host);

        return $row ? (int) $row->store_id : null;
    }

    private function stored($store, string $name): ?string
    {
        return DB::table('settings')->where('store_id', $store->id)->where('name', $name)->orderBy('id')->value('value');
    }

    private function recordingWebuzo(): object
    {
        $fake = new class extends \App\Services\WebuzoDomainService {
            public array $calls = [];

            public function __construct()
            {
            }

            public function addDomain(string $domain, bool $issueSsl = false): array
            {
                $this->calls[] = ['addDomain', $domain];

                return ['success' => true, 'message' => ''];
            }

            public function deleteDomain(string $domain): array
            {
                $this->calls[] = ['deleteDomain', $domain];

                return ['success' => true, 'message' => ''];
            }

            public function addSubdomain(string $domain, string $subdomain, bool $issueSsl = false): array
            {
                $this->calls[] = ['addSubdomain', $domain, $subdomain];

                return ['success' => true, 'error' => []];
            }

            public function domainExists(string $domain): bool
            {
                $this->calls[] = ['domainExists', $domain];

                return false;
            }
        };
        $this->app->instance(\App\Services\WebuzoDomainService::class, $fake);

        return $fake;
    }

    // ---------------------------------------------------------------------------------------------
    // WRITE
    // ---------------------------------------------------------------------------------------------

    public function test_premise_the_victim_resolves_to_the_victim_and_the_older_owner_can_write_their_own_domain(): void
    {
        $this->assertSame($this->victimStore->id, $this->hostResolvesTo('domains', self::HOST));

        $this->actingAs($this->olderOwner)->post('/customize-settings', ['domains' => 'another-older.example.test'])->assertSessionHasNoErrors();
        $this->assertTrue(DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'domains')->where('value', 'another-older.example.test')->exists(),
            'Premise: the generic writer must still save a free host for its owner.');
    }

    public static function spellingsOfTheVictimsHost(): array
    {
        return array_map(fn ($h) => [$h], [self::HOST, 'Victim-Shop.Example.TEST', 'www.' . self::HOST, ' ' . self::HOST . ' ', 'WWW.VICTIM-SHOP.EXAMPLE.TEST']);
    }

    /** @dataProvider spellingsOfTheVictimsHost */
    public function test_an_older_merchant_cannot_claim_the_victims_custom_domain_by_name(string $host): void
    {
        $this->actingAs($this->olderOwner)->post('/customize-settings', ['domains' => $host])->assertSessionHasErrors('domains');

        $this->assertSame('older-shop.example.test', $this->stored($this->olderStore, 'domains'), 'The older store\'s domain row was overwritten with the victim\'s host.');
        $this->assertSame($this->victimStore->id, $this->hostResolvesTo('domains', self::HOST));
    }

    /** The lookup compares the bare host: a claim written with a scheme and a path is the same claim. */
    public function test_a_claim_written_with_a_scheme_and_a_path_is_the_same_claim(): void
    {
        foreach (['https://' . self::HOST, 'http://www.' . self::HOST . '/', self::HOST . '/shop'] as $claim) {
            $this->actingAs($this->olderOwner)->post('/customize-settings', ['domains' => $claim])->assertSessionHasErrors('domains');
        }
        $this->assertSame('older-shop.example.test', $this->stored($this->olderStore, 'domains'));
        $this->assertSame(self::HOST, HostClaims::normalise('HTTPS://www.Victim-Shop.Example.TEST/path?x=1'));
    }

    /** Data already in the table need not be canonical: a holder stored as `WWW.Victim-Shop.Example.TEST` still holds the host. */
    public function test_a_holder_whose_stored_value_is_not_canonical_still_holds_the_host(): void
    {
        DB::table('settings')->where('store_id', $this->victimStore->id)->where('name', 'domains')->update(['value' => 'WWW.Victim-Shop.Example.TEST']);

        $this->actingAs($this->olderOwner)->post('/customize-settings', ['domains' => self::HOST])->assertSessionHasErrors('domains');

        $this->assertSame('older-shop.example.test', $this->stored($this->olderStore, 'domains'));
    }

    /** A platform-level setting (no store) is not a store's claim, and cannot be refused by one. */
    public function test_a_row_with_no_store_is_not_checked(): void
    {
        $setting = Setting::create(['name' => 'domains', 'value' => self::HOST, 'created_by' => $this->olderOwner->id, 'store_id' => null]);

        $this->assertNotNull($setting->id);
    }

    public function test_the_two_names_share_one_namespace(): void
    {
        // the victim holds a SUB-domain; the attacker tries to take it as a custom DOMAIN, and the reverse
        $this->giveRows($this->victimStore, $this->victimOwner, ['subdomain' => 'shop.platform.test', 'enable_subdomain' => 'on']);

        $this->actingAs($this->olderOwner)->post('/customize-settings', ['domains' => 'shop.platform.test'])->assertSessionHasErrors('domains');
        $this->actingAs($this->olderOwner)->post('/customize-settings', ['subdomain' => self::HOST])->assertSessionHasErrors('subdomain');

        $this->assertSame('older-shop.example.test', $this->stored($this->olderStore, 'domains'));
        $this->assertNull($this->stored($this->olderStore, 'subdomain'));
    }

    public function test_theme_settings_refuses_a_held_custom_domain_before_the_hosting_panel_is_called(): void
    {
        $fake = $this->recordingWebuzo();

        $response = $this->actingAs($this->olderOwner)->post('/theme-settings', ['enable_domain' => 'enable_domain', 'domains' => self::HOST]);

        $response->assertSessionHas('error');
        $this->assertSame([], $fake->calls, 'The hosting panel was called for a host another store holds.');
        $this->assertSame('older-shop.example.test', $this->stored($this->olderStore, 'domains'));
        $this->assertSame($this->victimStore->id, $this->hostResolvesTo('domains', self::HOST));
    }

    public function test_theme_settings_refuses_a_held_sub_domain_before_the_hosting_panel_is_called(): void
    {
        $this->giveRows($this->victimStore, $this->victimOwner, ['subdomain' => 'taken.localhost', 'enable_subdomain' => 'on']);
        $fake = $this->recordingWebuzo();

        $response = $this->actingAs($this->olderOwner)->post('/theme-settings', ['enable_domain' => 'enable_subdomain', 'subdomain' => 'taken']);

        $response->assertSessionHas('error');
        $this->assertSame([], $fake->calls, 'The hosting panel was called for a sub-domain another store holds.');
        $this->assertNull($this->stored($this->olderStore, 'subdomain'));
    }

    public function test_theme_settings_still_saves_a_free_custom_domain_for_its_owner(): void
    {
        $fake = $this->recordingWebuzo();

        $this->actingAs($this->olderOwner)->post('/theme-settings', ['enable_domain' => 'enable_domain', 'domains' => 'brand-new.example.test'])->assertSessionMissing('error');

        $this->assertContains(['addDomain', 'brand-new.example.test'], $fake->calls, 'Premise: a free host must still reach the panel.');
        $this->assertTrue(DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'domains')->where('value', 'brand-new.example.test')->exists());
    }

    public function test_a_store_can_resave_the_host_it_holds(): void
    {
        $this->actingAs($this->victimOwner)->post('/customize-settings', ['domains' => self::HOST])->assertSessionHasNoErrors();

        $this->assertSame($this->victimStore->id, $this->hostResolvesTo('domains', self::HOST));
    }

    /** "Holds" is the row AND the flag: a host a store has switched off is free, and cannot then be taken back from the new holder. */
    public function test_a_host_a_store_has_switched_off_is_free_and_cannot_be_taken_back_from_its_new_holder(): void
    {
        DB::table('settings')->where('store_id', $this->victimStore->id)->where('name', 'enable_domain')->update(['value' => 'off']);

        // the older store may now take it
        $this->actingAs($this->olderOwner)->post('/customize-settings', ['domains' => self::HOST, 'enable_domain' => 'on'])->assertSessionHasNoErrors();
        $this->assertSame($this->olderStore->id, $this->hostResolvesTo('domains', self::HOST));

        // ... and the victim switching it back on is refused, the flag stays off
        $this->actingAs($this->victimOwner)->post('/customize-settings', ['enable_domain' => 'on'])->assertSessionHasErrors('enable_domain');
        $this->assertSame('off', $this->stored($this->victimStore, 'enable_domain'));
        $this->assertSame($this->olderStore->id, $this->hostResolvesTo('domains', self::HOST));
    }

    public function test_enabling_a_host_another_store_already_holds_is_refused_whichever_name_is_written_last(): void
    {
        // the older store holds the host as a value without the flag (a stored but unused claim), then asks for the flag
        DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'enable_domain')->update(['value' => 'off']);
        DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'domains')->update(['value' => self::HOST]);

        $this->actingAs($this->olderOwner)->post('/customize-settings', ['enable_domain' => 'on'])->assertSessionHasErrors('enable_domain');

        $this->assertSame('off', $this->stored($this->olderStore, 'enable_domain'));
        $this->assertSame($this->victimStore->id, $this->hostResolvesTo('domains', self::HOST));
    }

    public function test_an_empty_value_and_an_unrelated_name_are_never_blocked(): void
    {
        $this->actingAs($this->olderOwner)->post('/customize-settings', ['domains' => '', 'cust_darklayout' => 'on', 'enable_domain' => 'off'])->assertSessionHasNoErrors();

        $this->assertTrue(DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'cust_darklayout')->where('value', 'on')->exists());
    }

    // ---------------------------------------------------------------------------------------------
    // READ
    // ---------------------------------------------------------------------------------------------

    /** Data that already holds a duplicate: the older store's row has the lower id but its flag is off. It must not shadow the store that is serving the host. */
    public function test_a_stale_duplicate_does_not_shadow_the_store_that_is_serving_the_host(): void
    {
        DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'enable_domain')->update(['value' => 'off']);
        DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'domains')->update(['value' => self::HOST]);

        $this->assertSame($this->victimStore->id, $this->hostResolvesTo('domains', self::HOST), 'A disabled row with a lower id shadowed the enabled one.');
        $this->assertSame($this->victimStore->id, (int) getEnableSetting(self::HOST)->store_id);
    }

    /** Two ENABLED holders (data from before this fix): deterministic, the lowest id, which is what the old ->first() did. */
    public function test_two_enabled_holders_resolve_deterministically_to_the_lowest_id(): void
    {
        DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'domains')->update(['value' => self::HOST]);

        $this->assertSame($this->olderStore->id, $this->hostResolvesTo('domains', self::HOST));
    }

    public function test_a_single_holder_resolves_as_before_and_an_unknown_host_resolves_to_nothing(): void
    {
        $this->assertSame($this->victimStore->id, $this->hostResolvesTo('domains', self::HOST));
        $this->assertSame($this->olderStore->id, $this->hostResolvesTo('domains', 'older-shop.example.test'));
        $this->assertNull($this->hostResolvesTo('domains', 'nobody.example.test'));
        $this->assertNull($this->hostResolvesTo('subdomain', self::HOST), 'A custom domain is not a sub-domain.');
    }

    /**
     * The five lookups (HomeController, the two in helper.php, ViewServiceProvider, StoreRedirectServiceProvider) are not driven
     * request by request here -- they render themes -- so this pins that none of them has gone back to the unscoped
     * `where('name', 'domains'|'subdomain')->where('value', $host)->first()` and that every one goes through HostClaims::row().
     */
    public function test_no_host_lookup_in_app_bypasses_hostclaims(): void
    {
        $offenders = [];
        $users = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php' || str_ends_with(str_replace('\\', '/', $file->getPathname()), 'Services/HostClaims.php')) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            if (preg_match("/where\(\s*'name'\s*,\s*'(?:domains|subdomain)'\s*\)\s*->where\(\s*'value'/", $source)) {
                $offenders[] = substr($file->getPathname(), strlen(base_path()) + 1);
            }
            $count = preg_match_all("/HostClaims::row\(/", $source);
            if ($count) {
                $users[substr($file->getPathname(), strlen(base_path()) + 1)] = $count;
            }
        }
        ksort($users);

        $this->assertSame([], $offenders, 'A host lookup bypasses HostClaims (the unscoped ->first() of P1-SEC-29).');
        $this->assertSame([
            'app/Helper/helper.php' => 4,
            'app/Http/Controllers/HomeController.php' => 2,
            'app/Providers/StoreRedirectServiceProvider.php' => 2,
            'app/Providers/ViewServiceProvider.php' => 2,
        ], $users, 'The set of host lookups changed; each must go through HostClaims::row().');
    }

    public function test_the_model_hook_is_the_only_writer_path_this_pins_and_a_raw_insert_bypasses_it(): void
    {
        // pins the premise of the READ tests above: duplicates can only exist as data, because every Eloquent writer is checked
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        Setting::updateOrCreate(['name' => 'domains', 'store_id' => $this->olderStore->id], ['value' => self::HOST, 'created_by' => $this->olderOwner->id]);
    }
}
