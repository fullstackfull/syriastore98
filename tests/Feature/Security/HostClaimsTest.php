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
        $this->withPlatformHost('platform.example.test', function () {
            $this->giveRows($this->victimStore, $this->victimOwner, ['subdomain' => 'taken.platform.example.test', 'enable_subdomain' => 'on']);
            $fake = $this->recordingWebuzo();

            $response = $this->actingAs($this->olderOwner)->post('/theme-settings', ['enable_domain' => 'enable_subdomain', 'subdomain' => 'taken']);

            $response->assertSessionHas('error');
            $this->assertSame([], $fake->calls, 'The hosting panel was called for a sub-domain another store holds.');
            $this->assertNull($this->stored($this->olderStore, 'subdomain'));
        });
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
     * The six lookups in app/ (HomeController twice, the two in helper.php, ViewServiceProvider, StoreRedirectServiceProvider) are not driven
     * request by request here -- they render themes -- so this pins that none of them has gone back to the unscoped
     * `where('name', 'domains'|'subdomain')->where('value', $host)->first()` and that every one goes through HostClaims::row(). It scans app/
     * only: a seventh, unscoped lookup lives in the vendored ThemeCustomizer module's view composer
     * (packages/workdo/ThemeCustomizer/src/app/Providers/ViewComposer.php), which cannot be edited -- recorded under P1-SEC-31.
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
            // two shapes: `where('name', 'domains')->where('value', ...)` and `whereIn('name', [..., 'domains', ...])->where('value', ...)`
            // (landing_page's, which the first shape did not see and the first review found still unconverted)
            if (preg_match("/where\(\s*'name'\s*,\s*'(?:domains|subdomain)'\s*\)\s*->where\(\s*'value'/", $source)
                || preg_match("/whereIn\(\s*'name'\s*,\s*\[[^\]]*'(?:domains|subdomain)'[^\]]*\]\s*\)\s*->where\(\s*'value'/", $source)) {
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
            'app/Http/Controllers/HomeController.php' => 4,
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

    // ---------------------------------------------------------------------------------------------
    // REVIEW OF THE FIRST FIX
    // ---------------------------------------------------------------------------------------------

    /**
     * MySQL's collation reads `dómains` as the stored `domains` row, `enablé_domain` as the flag and `ón` as 'on' (EXECUTED:
     * tests/Support/Security/bin/verify-host-claims-on-mysql.php, which is the real evidence -- red on the first fix, green on this).
     * SQLite compares bytes, so this test gives its connection an accent-folding LOWER(): the hook must ask the ENGINE which setting a
     * write is, and the first fix asked PHP (`strtolower`, ASCII only), which let all of these through.
     */
    private function foldAccentsLikeTheProductionCollation(): void
    {
        DB::connection()->getPdo()->sqliteCreateFunction('lower', fn ($v) => $v === null ? null : strtr(mb_strtolower((string) $v), ['ó' => 'o', 'é' => 'e', 'í' => 'i', 'á' => 'a', 'ú' => 'u', 'è' => 'e']), 1);
    }

    public function test_an_accented_spelling_of_the_host_name_is_judged_by_the_engine_and_refused(): void
    {
        $this->foldAccentsLikeTheProductionCollation();

        foreach (['dómains', 'DÓMAINS', 'domaíns', 'subdómain'] as $name) {
            $this->actingAs($this->olderOwner)->post('/customize-settings', [$name => self::HOST])->assertSessionHasErrors(str_contains($name, 'sub') ? 'subdomain' : 'domains');
        }

        $this->assertSame('older-shop.example.test', $this->stored($this->olderStore, 'domains'));
        $this->assertSame(0, DB::table('settings')->where('store_id', $this->olderStore->id)->where('value', self::HOST)->count(), 'A row naming the victim\'s host was written.');
        $this->assertSame($this->victimStore->id, $this->hostResolvesTo('domains', self::HOST));
    }

    public function test_an_accented_spelling_of_the_flag_or_of_its_value_is_judged_by_the_engine_and_refused(): void
    {
        $this->foldAccentsLikeTheProductionCollation();
        // the older store staged the host before the victim claimed it (a raw write stands in for that), with its flag off
        DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'enable_domain')->update(['value' => 'off']);
        DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'domains')->update(['value' => self::HOST]);

        foreach ([['enable_domain', 'ón'], ['enablé_domain', 'on'], ['enablé_domain', 'ón']] as [$name, $value]) {
            $this->actingAs($this->olderOwner)->post('/customize-settings', [$name => $value])->assertSessionHasErrors('enable_domain');
        }

        $this->assertSame('off', $this->stored($this->olderStore, 'enable_domain'));
        $this->assertSame($this->victimStore->id, $this->hostResolvesTo('domains', self::HOST));
    }

    public function test_an_accented_name_that_is_not_a_host_name_is_still_saved(): void
    {
        $this->foldAccentsLikeTheProductionCollation();

        $this->actingAs($this->olderOwner)->post('/customize-settings', ['thème_color' => '#123456', 'cust_darklayout' => 'on'])->assertSessionHasNoErrors();

        $this->assertTrue(DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'thème_color')->where('value', '#123456')->exists());
    }

    /** A plain printable-ASCII name can only fold by case and by surrounding spaces (measured on MySQL 8.0.46 by verify-host-claims-on-mysql.php), so it never costs a query. */
    public function test_a_plain_name_and_value_are_decided_without_asking_the_engine(): void
    {
        $asked = [];
        DB::listen(function ($query) use (&$asked) {
            if (str_starts_with($query->sql, 'select case lower(trim(?))') || str_starts_with($query->sql, "select (lower(trim(?)) = 'on')")) {
                $asked[] = $query->sql;
            }
        });

        Setting::create(['name' => 'theme_color', 'value' => '#fff', 'created_by' => $this->olderOwner->id, 'store_id' => $this->olderStore->id]);
        Setting::create(['name' => 'Enable_Domain ', 'value' => ' ON', 'created_by' => $this->olderOwner->id, 'store_id' => $this->victimStore->id]);

        $this->assertSame([], $asked, 'A plain ASCII name or value was put to the engine.');

        Setting::create(['name' => "thème_color", 'value' => '#fff', 'created_by' => $this->olderOwner->id, 'store_id' => $this->olderStore->id]);
        $this->assertNotSame([], $asked, 'A non-ASCII name was NOT put to the engine.');
    }

    // ---- an unchanged re-save is not a claim ------------------------------------------------------

    public function test_a_store_already_in_a_duplicate_can_resave_what_it_already_has(): void
    {
        // data from before this fix: both stores are enabled holders of the host (P1-OWN-11)
        DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'domains')->update(['value' => self::HOST]);

        $this->actingAs($this->olderOwner)->post('/customize-settings', ['domains' => self::HOST, 'enable_domain' => 'on'])->assertSessionHasNoErrors();
        $this->actingAs($this->victimOwner)->post('/customize-settings', ['domains' => self::HOST, 'enable_domain' => 'on'])->assertSessionHasNoErrors();

        // ... but changing to a host the other one holds is still a claim
        $this->actingAs($this->olderOwner)->post('/customize-settings', ['domains' => 'brand-new.example.test'])->assertSessionHasNoErrors();
        $this->actingAs($this->olderOwner)->post('/customize-settings', ['domains' => self::HOST])->assertSessionHasErrors('domains');
    }

    /** The Domain tab re-posts a stale hidden `domains`; a store whose mode is NOT custom domain must still be able to leave a mode and save the rest. */
    public function test_the_domain_tab_with_a_stale_hidden_domains_value_another_store_now_holds_still_saves(): void
    {
        $fake = $this->recordingWebuzo();
        DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'enable_domain')->update(['value' => 'off']);
        DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'domains')->update(['value' => self::HOST]);

        $this->actingAs($this->olderOwner)->post('/theme-settings', ['enable_domain' => 'enable_storelink', 'domains' => self::HOST, 'storecss' => 'body{}'])
            ->assertSessionMissing('error')->assertSessionHasNoErrors();

        $this->assertSame('on', $this->stored($this->olderStore, 'enable_storelink'));
        $this->assertSame('body{}', $this->stored($this->olderStore, 'storecss'), 'Nothing after the stale host was saved.');
        $this->assertSame($this->victimStore->id, $this->hostResolvesTo('domains', self::HOST));
    }

    // ---- the flag is judged against every host the store has saved --------------------------------

    public function test_switching_on_is_judged_against_every_host_the_store_has_saved_not_only_the_newest(): void
    {
        DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'enable_domain')->update(['value' => 'off']);
        DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'domains')->update(['value' => self::HOST]);
        // a second writer's row, newer, with a harmless host: the lookups accept ANY row of a store whose flag is on
        $this->giveRows($this->olderStore, $this->victimOwner, ['domains' => 'x-other.example.test']);

        $this->actingAs($this->olderOwner)->post('/customize-settings', ['enable_domain' => 'on'])->assertSessionHasErrors('enable_domain');

        $this->assertSame('off', $this->stored($this->olderStore, 'enable_domain'));
        $this->assertSame($this->victimStore->id, $this->hostResolvesTo('domains', self::HOST));
    }

    /** Request order puts the flag first; the host row has to be written first, or the flag is judged against the stale host it replaces. */
    public function test_theme_settings_writes_the_host_before_the_flag_that_switches_it_on(): void
    {
        $this->recordingWebuzo();
        DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'enable_domain')->update(['value' => 'off']);
        DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'domains')->update(['value' => self::HOST]);   // stale, held by the victim

        $this->actingAs($this->olderOwner)->post('/theme-settings', ['enable_domain' => 'enable_domain', 'domains' => 'brand-new.example.test'])
            ->assertSessionMissing('error')->assertSessionHasNoErrors();

        $this->assertSame('brand-new.example.test', $this->stored($this->olderStore, 'domains'));
        $this->assertSame('on', $this->stored($this->olderStore, 'enable_domain'));
    }

    // ---- the claimed host is read the way the panel block reads it --------------------------------

    public function test_a_port_user_info_query_or_fragment_does_not_change_which_host_is_meant(): void
    {
        foreach (['victim-shop.example.test:8080', 'user:pw@victim-shop.example.test', 'victim-shop.example.test?x=1', 'victim-shop.example.test#top',
                  'https://u@www.Victim-Shop.Example.TEST:443/p?q#f', 'victim-shop.example.test:'] as $spelling) {
            $this->assertSame(self::HOST, HostClaims::normalise($spelling), $spelling);
        }
    }

    public function test_theme_settings_refuses_a_held_host_written_with_a_port_before_the_hosting_panel_is_called(): void
    {
        $fake = $this->recordingWebuzo();

        $this->actingAs($this->olderOwner)->post('/theme-settings', ['enable_domain' => 'enable_domain', 'domains' => self::HOST . ':8080'])->assertSessionHas('error');

        $this->assertSame([], $fake->calls, 'The hosting panel was called for a host another store holds, written with a port.');
        $this->assertSame('older-shop.example.test', $this->stored($this->olderStore, 'domains'));
    }

    // ---- the hosting-panel account is shared: a store's cleanup must not delete another store's host ----

    public function test_blanking_a_stale_domain_another_store_now_holds_does_not_delete_it_at_the_panel(): void
    {
        $fake = $this->recordingWebuzo();
        DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'enable_domain')->update(['value' => 'off']);
        DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'domains')->update(['value' => self::HOST]);

        $this->actingAs($this->olderOwner)->post('/theme-settings', ['enable_domain' => 'enable_domain', 'domains' => ''])->assertSessionMissing('error');

        $this->assertNotContains(['deleteDomain', self::HOST], $fake->calls, 'The victim\'s live domain was removed at the hosting panel.');
    }

    public function test_leaving_custom_domain_mode_does_not_delete_a_stale_domain_another_store_now_holds(): void
    {
        $fake = $this->recordingWebuzo();
        // the older store is itself an enabled holder of the host (a duplicate from before the fix) and leaves the mode
        DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'domains')->update(['value' => self::HOST]);

        $this->actingAs($this->olderOwner)->post('/theme-settings', ['enable_domain' => 'enable_storelink'])->assertSessionMissing('error');

        $this->assertNotContains(['deleteDomain', self::HOST], $fake->calls, 'The other store\'s live domain was removed at the hosting panel.');
    }

    public function test_the_store_still_deletes_its_own_old_domain_at_the_panel(): void
    {
        $fake = $this->recordingWebuzo();

        $this->actingAs($this->olderOwner)->post('/theme-settings', ['enable_domain' => 'enable_domain', 'domains' => ''])->assertSessionMissing('error');

        $this->assertContains(['deleteDomain', 'older-shop.example.test'], $fake->calls, 'Premise: a store\'s own old domain is still cleaned up at the panel.');
    }

    public static function spellingsOfOn(): array
    {
        return array_map(fn ($v) => [$v], ['ON', 'On', ' on ', "on "]);
    }

    /** @dataProvider spellingsOfOn */
    public function test_a_flag_value_the_lookups_read_as_on_is_judged_as_on(string $value): void
    {
        DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'enable_domain')->update(['value' => 'off']);
        DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'domains')->update(['value' => self::HOST]);

        $this->actingAs($this->olderOwner)->post('/customize-settings', ['enable_domain' => $value])->assertSessionHasErrors('enable_domain');

        $this->assertSame('off', $this->stored($this->olderStore, 'enable_domain'));
    }

    /** Renaming a row onto a host name is a claim even when its value does not change. */
    public function test_renaming_a_row_onto_a_host_name_is_a_claim(): void
    {
        $this->giveRows($this->olderStore, $this->olderOwner, ['note' => self::HOST]);
        $row = Setting::where('store_id', $this->olderStore->id)->where('name', 'note')->first();

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $row->update(['name' => 'domains']);
    }

    /** Whichever of its saved hosts is held, in whatever order the rows are: the first as well as the newest. */
    public function test_switching_on_is_refused_whichever_saved_host_is_the_held_one(): void
    {
        DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'enable_domain')->update(['value' => 'off']);
        // the older store's OLDEST `domains` row is harmless and a newer one (a second writer's) is the held host
        $this->giveRows($this->olderStore, $this->victimOwner, ['domains' => self::HOST]);

        $this->actingAs($this->olderOwner)->post('/customize-settings', ['enable_domain' => 'on'])->assertSessionHasErrors('enable_domain');

        $this->assertSame('off', $this->stored($this->olderStore, 'enable_domain'));
    }

    /** The domain CHANGES while the old one is also another store's: the new one is added, the old one is left at the panel. */
    public function test_replacing_a_stale_domain_another_store_now_holds_does_not_delete_it_at_the_panel(): void
    {
        $fake = $this->recordingWebuzo();
        DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'enable_domain')->update(['value' => 'off']);
        DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'domains')->update(['value' => self::HOST]);

        $this->actingAs($this->olderOwner)->post('/theme-settings', ['enable_domain' => 'enable_domain', 'domains' => 'brand-new.example.test'])->assertSessionMissing('error');

        $this->assertContains(['addDomain', 'brand-new.example.test'], $fake->calls, 'Premise: the new host reaches the panel.');
        $this->assertNotContains(['deleteDomain', self::HOST], $fake->calls, 'The victim\'s live domain was removed at the hosting panel.');
        $this->assertSame('brand-new.example.test', $this->stored($this->olderStore, 'domains'));
    }

    public static function plainSpellingsOfTheHostName(): array
    {
        return array_map(fn ($n) => [$n], ['Domains', 'DOMAINS', 'domains ', ' Domains ', '  domains  ']);
    }

    /** The hook is the model's, so it is driven through the model: every Eloquent writer of `settings` passes through it, not only the web forms. */
    #[\PHPUnit\Framework\Attributes\DataProvider('plainSpellingsOfTheHostName')]
    public function test_a_plain_spelling_of_the_host_name_by_case_or_surrounding_space_is_a_claim(string $name): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        Setting::create(['name' => $name, 'value' => self::HOST, 'created_by' => $this->olderOwner->id, 'store_id' => $this->olderStore->id + 0]);
    }

    public static function plainSpellingsOfTheFlag(): array
    {
        return [['Enable_Domain', 'on'], ['enable_domain ', 'on'], [' ENABLE_DOMAIN ', 'ON'], ['enable_domain', ' On '], ['enable_domain', 'on ']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('plainSpellingsOfTheFlag')]
    public function test_a_plain_spelling_of_the_flag_or_of_its_value_by_case_or_surrounding_space_is_a_claim(string $name, string $value): void
    {
        $this->giveRows($this->olderStore, $this->olderOwner, ['subdomain' => 'unused.example.test']);   // a second saved host: the flag is judged against every one
        DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'enable_domain')->update(['value' => 'off']);
        DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'domains')->update(['value' => self::HOST]);
        $row = Setting::where('store_id', $this->olderStore->id)->where('name', 'enable_domain')->first();

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $row->update(['name' => $name, 'value' => $value]);
    }

    public static function bytesOutsidePrintableAscii(): array
    {
        return array_map(fn ($b) => [$b], ["domains\0", "domains\t", "domains\n", "domains\r", "domains\x0B", "\0domains", "dom\x01ains", "dom ains", "dómains"]);
    }

    /**
     * Anything outside printable ASCII (spaces aside) is not decided in PHP: a collation may ignore the byte or fold the letter, and the
     * lookups run on the engine. trim()'s default would strip NUL, tab and newline and take those names for plain ones.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bytesOutsidePrintableAscii')]
    public function test_a_name_with_a_byte_outside_printable_ascii_is_put_to_the_engine(string $name): void
    {
        $asked = [];
        DB::listen(function ($query) use (&$asked) {
            if (str_starts_with($query->sql, 'select case lower(trim(?))')) {
                $asked[] = $query->bindings;
            }
        });

        Setting::create(['name' => $name, 'value' => 'x', 'created_by' => $this->olderOwner->id, 'store_id' => $this->olderStore->id]);

        $this->assertCount(1, $asked, 'The name ' . json_encode($name) . ' was decided in PHP.');
    }

    public static function valuesOutsidePrintableAscii(): array
    {
        return array_map(fn ($b) => [$b], ["on\0", "on\t", "on\n", "\0on", "ón", "o n"]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('valuesOutsidePrintableAscii')]
    public function test_a_flag_value_with_a_byte_outside_printable_ascii_is_put_to_the_engine(string $value): void
    {
        $asked = 0;
        DB::listen(function ($query) use (&$asked) {
            if (str_starts_with($query->sql, "select (lower(trim(?)) = 'on')")) {
                $asked++;
            }
        });

        Setting::create(['name' => 'enable_subdomain', 'value' => $value, 'created_by' => $this->olderOwner->id, 'store_id' => $this->olderStore->id]);

        $this->assertSame(1, $asked, 'The value ' . json_encode($value) . ' was decided in PHP.');
    }

    /** Switching a flag OFF is never a claim: a store that is in a duplicate from before the fix must still be able to leave it. */
    public function test_switching_a_flag_off_is_never_refused_even_for_a_store_in_a_duplicate(): void
    {
        DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'domains')->update(['value' => self::HOST]);   // both stores enabled holders

        $this->actingAs($this->olderOwner)->post('/customize-settings', ['enable_domain' => 'off'])->assertSessionHasNoErrors();

        $this->assertSame('off', $this->stored($this->olderStore, 'enable_domain'));
    }

    // ---- sub-domain mode: the same order, a free host reaches the panel -----------------------------

    /** the platform host as ThemeSettings reads it (`env('APP_URL')`), pinned for the test so nothing depends on the checkout's .env */
    private function withPlatformHost(string $host, callable $test): void
    {
        $before = [$_ENV['APP_URL'] ?? null, $_SERVER['APP_URL'] ?? null];
        $_ENV['APP_URL'] = $_SERVER['APP_URL'] = 'https://' . $host;
        try {
            $test();
        } finally {
            foreach (['_ENV' => $before[0], '_SERVER' => $before[1]] as $bag => $value) {
                if ($value === null) {
                    unset($GLOBALS[$bag]['APP_URL']);
                } else {
                    $GLOBALS[$bag]['APP_URL'] = $value;
                }
            }
        }
    }

    public function test_theme_settings_still_saves_a_free_sub_domain_and_reaches_the_panel(): void
    {
        $this->withPlatformHost('platform.example.test', function () {
            $fake = $this->recordingWebuzo();

            $this->actingAs($this->olderOwner)->post('/theme-settings', ['enable_domain' => 'enable_subdomain', 'subdomain' => 'fresh'])->assertSessionMissing('error');

            $this->assertContains(['addSubdomain', 'platform.example.test', 'fresh'], $fake->calls, 'Premise: a free sub-domain must still reach the panel.');
            $this->assertSame('fresh.platform.example.test', $this->stored($this->olderStore, 'subdomain'));
            $this->assertSame('on', $this->stored($this->olderStore, 'enable_subdomain'));
        });
    }

    public function test_theme_settings_refuses_a_held_sub_domain_whatever_the_platform_host_is(): void
    {
        $this->withPlatformHost('platform.example.test', function () {
            $this->giveRows($this->victimStore, $this->victimOwner, ['subdomain' => 'taken.platform.example.test', 'enable_subdomain' => 'on']);
            $fake = $this->recordingWebuzo();

            $this->actingAs($this->olderOwner)->post('/theme-settings', ['enable_domain' => 'enable_subdomain', 'subdomain' => 'taken'])->assertSessionHas('error');

            $this->assertSame([], $fake->calls);
            $this->assertNull($this->stored($this->olderStore, 'subdomain'));
        });
    }

    public function test_theme_settings_writes_the_sub_domain_before_the_flag_that_switches_it_on(): void
    {
        $this->withPlatformHost('platform.example.test', function () {
            $this->recordingWebuzo();
            // a stale `subdomain` of the older store that the victim now holds, flag off
            $this->giveRows($this->olderStore, $this->olderOwner, ['subdomain' => 'held.platform.example.test', 'enable_subdomain' => 'off']);
            $this->giveRows($this->victimStore, $this->victimOwner, ['subdomain' => 'held.platform.example.test', 'enable_subdomain' => 'on']);

            $this->actingAs($this->olderOwner)->post('/theme-settings', ['enable_domain' => 'enable_subdomain', 'subdomain' => 'fresh'])->assertSessionMissing('error')->assertSessionHasNoErrors();

            $this->assertSame('fresh.platform.example.test', $this->stored($this->olderStore, 'subdomain'));
            $this->assertSame('on', $this->stored($this->olderStore, 'enable_subdomain'));
        });
    }

    /**
     * `settings.name` is VARCHAR(255) and production runs with `strict => false`: MySQL keeps the first 255 characters, and the spaces
     * that remain are PAD SPACE, so `domains` + 300 spaces + `x` IS the stored `domains` (EXECUTED on MySQL 8.0.46 by
     * verify-host-claims-on-mysql.php, red before the name was judged as stored; SQLite stores the whole string, so here the claim is that
     * the hook judges the name as it WILL be stored). Found by the second review of the fix.
     */
    public function test_a_name_that_is_stored_cut_to_255_characters_is_judged_as_stored(): void
    {
        foreach (['domains', 'subdomain'] as $name) {
            try {
                Setting::create(['name' => $name . str_repeat(' ', 300) . 'x', 'value' => self::HOST, 'created_by' => $this->olderOwner->id, 'store_id' => $this->olderStore->id]);
                $this->fail('An over-long spelling of `' . $name . '` was not judged as the name MySQL stores.');
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertArrayHasKey($name, $e->errors());
            }
        }
        $this->assertSame(0, DB::table('settings')->where('store_id', $this->olderStore->id)->where('value', self::HOST)->count());
    }

    public function test_an_over_long_spelling_of_the_flag_is_judged_as_stored(): void
    {
        DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'enable_domain')->update(['value' => 'off']);
        DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'domains')->update(['value' => self::HOST]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        Setting::create(['name' => 'enable_domain' . str_repeat(' ', 300) . 'x', 'value' => 'on', 'created_by' => $this->olderOwner->id, 'store_id' => $this->olderStore->id]);
    }

    /** the truncation is of the NAME only: a name that is long but not padded stays another name, and a long value is untouched */
    public function test_a_long_name_that_is_not_a_host_name_when_cut_is_still_saved(): void
    {
        $setting = Setting::create(['name' => str_repeat('a', 300), 'value' => str_repeat('b', 5000), 'created_by' => $this->olderOwner->id, 'store_id' => $this->olderStore->id]);

        $this->assertNotNull($setting->id);
    }

    /** the boundary is exact: the 256th character is the first one MySQL drops, the 255th is the last it keeps */
    public function test_the_cut_is_at_exactly_255_characters(): void
    {
        // 7 + 248 = 255 characters of `domains` and padding, then an `x` that is dropped: stored as `domains`
        try {
            Setting::create(['name' => 'domains' . str_repeat(' ', 248) . 'x', 'value' => self::HOST, 'created_by' => $this->olderOwner->id, 'store_id' => $this->olderStore->id]);
            $this->fail('The 256th character is dropped by MySQL, so this is `domains`.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('domains', $e->errors());
        }

        // the `x` is the 255th character: it is kept, so this stays another name
        $setting = Setting::create(['name' => 'domains' . str_repeat(' ', 247) . 'x', 'value' => self::HOST, 'created_by' => $this->olderOwner->id, 'store_id' => $this->olderStore->id]);
        $this->assertNotNull($setting->id);
    }

    // ---- the early check reads the host the way the panel block does; the root dot; the all-or-nothing save ----

    public static function spellingsTheHostingPanelReadsAsTheVictimsHost(): array
    {
        return array_map(fn ($h) => [$h], ['//victim-shop.example.test', '/victim-shop.example.test/', 'a@b@victim-shop.example.test', 'x:y@z@victim-shop.example.test:8080',
            'http://a@b.test@victim-shop.example.test/p?q#f', 'victim-shop.example.test.', 'WWW.Victim-Shop.Example.Test.']);
    }

    /** @dataProvider spellingsTheHostingPanelReadsAsTheVictimsHost */
    public function test_every_spelling_the_hosting_panel_block_reads_as_the_victims_host_is_refused_before_the_panel(string $spelling): void
    {
        $this->assertSame(self::HOST, HostClaims::normalise($spelling), $spelling);

        $fake = $this->recordingWebuzo();
        $this->actingAs($this->olderOwner)->post('/theme-settings', ['enable_domain' => 'enable_domain', 'domains' => $spelling])->assertSessionHas('error');

        $this->assertSame([], $fake->calls, 'The hosting panel was called for a host another store holds, written as ' . json_encode($spelling) . '.');
        $this->assertSame('older-shop.example.test', $this->stored($this->olderStore, 'domains'));
    }

    /** The early check must derive the host exactly as the panel block does, so this compares the two over spellings neither was written for. */
    public function test_the_normalised_host_is_the_host_the_panel_block_would_add(): void
    {
        foreach (['victim.test', 'http://victim.test', 'https://User@victim.test:8443/x', '/victim.test', '//victim.test//', 'a@b@victim.test', 'VICTIM.TEST', 'www.victim.test'] as $input) {
            $panel = trim(trim($input), '/');
            $panel = preg_match('#^http(s)?://#', $panel) ? $panel : 'http://' . $panel;
            $expected = strtolower(preg_replace('/^www\./', '', parse_url($panel)['host']));

            $this->assertSame($expected, HostClaims::normalise($input), $input);
        }
    }

    public function test_a_request_for_the_fully_qualified_spelling_of_a_host_resolves_to_its_holder(): void
    {
        $this->assertSame($this->victimStore->id, $this->hostResolvesTo('domains', self::HOST . '.'));
    }

    public function test_a_store_that_is_serving_a_host_in_a_duplicate_can_still_save_the_custom_tab(): void
    {
        $fake = $this->recordingWebuzo();
        DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'domains')->update(['value' => self::HOST]);   // both stores enabled holders

        $this->actingAs($this->olderOwner)->post('/theme-settings', ['enable_domain' => 'enable_domain', 'domains' => self::HOST, 'storecss' => 'a{}'])
            ->assertSessionMissing('error')->assertSessionHasNoErrors();

        $this->assertSame('a{}', $this->stored($this->olderStore, 'storecss'), 'The rest of the tab was not saved.');
        $this->assertSame([], $fake->calls, 'An unchanged host must not touch the hosting panel.');
    }

    public function test_a_store_that_is_not_serving_a_held_host_cannot_switch_it_on_with_the_stored_value_unchanged(): void
    {
        $fake = $this->recordingWebuzo();
        DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'enable_domain')->update(['value' => 'off']);
        DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'domains')->update(['value' => self::HOST]);

        $this->actingAs($this->olderOwner)->post('/theme-settings', ['enable_domain' => 'enable_domain', 'domains' => self::HOST])->assertSessionHas('error');

        $this->assertSame([], $fake->calls);
        $this->assertSame('off', $this->stored($this->olderStore, 'enable_domain'));
    }

    /** The hook can refuse after earlier keys were written; the save is then undone and the merchant is told (this page shows session('error'), not $errors). */
    public function test_a_refusal_by_the_model_hook_undoes_the_whole_save_and_is_shown(): void
    {
        $this->recordingWebuzo();
        DB::table('settings')->where('store_id', $this->olderStore->id)->where('name', 'enable_domain')->update(['value' => 'off']);
        // a second writer's stale row names the victim's host: the early check reads the stored `domains` of the posting user only
        $this->giveRows($this->olderStore, $this->victimOwner, ['domains' => self::HOST]);

        $response = $this->actingAs($this->olderOwner)->post('/theme-settings', ['enable_domain' => 'enable_domain', 'domains' => 'free.example.test', 'storecss' => 'z{}']);

        $response->assertSessionHas('error', 'This domain is already used by another store.');
        $this->assertSame('older-shop.example.test', $this->stored($this->olderStore, 'domains'), 'The host written before the refused flag stayed saved.');
        $this->assertNull($this->stored($this->olderStore, 'storecss'));
        $this->assertSame('off', $this->stored($this->olderStore, 'enable_domain'));
    }

    public static function namesCutAtAnInvalidByte(): array
    {
        return array_map(fn ($n) => [$n], ["domains\xFFx", "domains\xC3", "domains\xE2\x82x", "domains \xFF", "subdomain\xC0\x80zz", "domains\xED\xA0\x80"]);
    }

    /**
     * Non-strict MySQL ends a name at the first byte that is not valid UTF-8: `domains\xFFx` is STORED as `domains` (EXECUTED on 8.0.46
     * through Laravel's native prepared statements by verify-host-claims-on-mysql.php, red before the hook judged the stored form; a
     * SELECT on the same raw string answers NULL there, so asking the engine does not catch it). SQLite keeps the whole string.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('namesCutAtAnInvalidByte')]
    public function test_a_name_that_is_stored_cut_at_an_invalid_utf8_byte_is_judged_as_stored(string $name): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        Setting::create(['name' => $name, 'value' => self::HOST, 'created_by' => $this->olderOwner->id, 'store_id' => $this->olderStore->id]);
    }

    public function test_valid_multi_byte_names_are_not_cut(): void
    {
        $setting = Setting::create(['name' => "d\u{00F3}m\u{1F600}ains", 'value' => self::HOST, 'created_by' => $this->olderOwner->id, 'store_id' => $this->olderStore->id]);

        $this->assertNotNull($setting->id, 'A valid multi-byte name that is not one of the four was refused.');
    }

    public static function validLeadsFollowedByAnInvalidByte(): array
    {
        // one valid character of each UTF-8 shape, then a byte that is not valid: MySQL cuts AFTER the valid character, so the name is
        // `domains` + that character and is another name
        return array_map(fn ($c) => [$c], ["\xC3\xA9", "\xE0\xA0\x80", "\xE1\x80\x80", "\xE2\x82\xAC", "\xED\x9F\xBF", "\xEE\x80\x80", "\xEF\xBF\xBD",
            "\xF0\x90\x80\x80", "\xF1\x80\x80\x80", "\xF3\xA0\x80\x80", "\xF4\x8F\xBF\xBF"]);
    }

    /** @dataProvider validLeadsFollowedByAnInvalidByte */
    public function test_the_valid_part_of_a_name_before_an_invalid_byte_is_kept_whole(string $character): void
    {
        $setting = Setting::create(['name' => 'domains' . $character . "\xFF", 'value' => self::HOST, 'created_by' => $this->olderOwner->id, 'store_id' => $this->olderStore->id]);

        $this->assertNotNull($setting->id, 'A name whose valid part is `domains` + ' . bin2hex($character) . ' was taken for `domains`.');
    }

    /** The check reads a bounded window: a name of megabytes must not make the cut fail open (a regex over a huge string can run out of stack). */
    public function test_a_name_of_megabytes_with_an_invalid_byte_at_the_end_is_still_judged_as_stored(): void
    {
        $name = 'domains' . str_repeat(' ', 248) . str_repeat('x', 3_000_000) . "\xFF";

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        Setting::create(['name' => $name, 'value' => self::HOST, 'created_by' => $this->olderOwner->id, 'store_id' => $this->olderStore->id]);
    }
}
