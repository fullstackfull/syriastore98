<?php

namespace Tests\Feature\Security;

use App\Models\ApikeySetiings;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

/**
 * F117, for the two credentials that are NOT rows in `settings`.
 *
 * `SecretSettingPersistenceTest` covers the eleven panels whose secrets live in the `settings`
 * table, where `SecretSetting::resolve()` falls back to `Setting::value()`. Two do not, and each
 * needed its own fallback — which is exactly the kind of difference a masked display gets wrong:
 *
 *   - **Delivery Syria** keeps its token on the USER row (`users.deliverysyria_secret`). The
 *     handler also POSTS the token to an external verifier before saving, so masking the box
 *     without resolving FIRST would have sent the empty string and failed verification against a
 *     token that is valid and still stored. The route carries `auth` and nothing else, so the
 *     permission check is asserted here too.
 *
 *   - **ChatGPT keys** are a repeater, and the stored VALUE used to be the row's identity: the
 *     old save did `updateOrCreate(['key' => $posted])` then `whereNotIn('key', $posted)->delete()`.
 *     A masked box posts nothing that identifies a row, so that save could only misfire. Each
 *     saved row now posts its own id as `api_key[e<id>]`.
 *
 * AND EMPTY MEANS NULL, NOT ''. `ConvertEmptyStringsToNull` runs before every one of these
 * handlers, so a masked box that the merchant leaves alone arrives as NULL. That is not a detail:
 * driving these cases against the legacy ChatGPT save showed `whereNotIn('key', [null])` matching
 * no row at all — SQL three-valued logic — so two cases reported "the key survived" over a
 * handler that had simply stopped reconciling the list. `SecretSetting::resolve()` treats any
 * non-string as absent for exactly this reason, and `test_an_empty_box_arrives_as_null` pins it.
 *
 * NO REAL CREDENTIAL APPEARS HERE. The fixtures are obvious inventions and no assertion message
 * interpolates a stored value, so a failure cannot print one into CI output.
 */
class SecretSettingNonSettingStoresTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const INVENTED_DS_TOKEN = 'DS-INVENTED-TOKEN-not-real';
    private const INVENTED_GPT_KEY = 'sk-INVENTED-GPT-KEY-not-real';

    /**
     * THE PREMISE EVERY OTHER CASE RESTS ON, asserted rather than assumed.
     *
     * `SecretSetting::resolve()` reads "the merchant left the box alone" from a value that is not
     * a non-empty string. If a blank box ever started arriving as `''` instead of `null` — or as
     * `'0'`, or an array — the fallback would still be correct, but the reason recorded in these
     * docblocks would not be, and the next person would reason from the wrong premise. The
     * framework middleware that does this (`ConvertEmptyStringsToNull`) is a default, so it is
     * exactly the kind of thing a future `bootstrap/app.php` edit removes without noticing.
     */
    public function test_an_empty_box_arrives_as_null_and_resolve_treats_it_as_absent(): void
    {
        $seen = [];

        \Illuminate\Support\Facades\Route::post('/__f117_empty_box', function (\Illuminate\Http\Request $r) use (&$seen) {
            $seen = ['scalar' => $r->input('tok'), 'repeater' => $r->input('api_key')];

            return 'ok';
        })->middleware('web');

        $this->post('/__f117_empty_box', ['tok' => '', 'api_key' => ['e1' => '', 0 => '']]);

        $this->assertNull($seen['scalar'], 'a blank secret box no longer arrives as null');
        $this->assertSame(['e1' => null, 0 => null], $seen['repeater'],
            'a blank repeater box no longer arrives as null');

        /* And the resolver reads all three spellings of "absent" the same way. */
        foreach ([null, '', '   '] as $blank) {
            $this->assertSame('KEPT-not-real', \App\Support\SecretSetting::resolve($blank, 'KEPT-not-real'));
        }
        $this->assertSame('TYPED-not-real', \App\Support\SecretSetting::resolve(' TYPED-not-real ', 'KEPT-not-real'));
    }

    /* ------------------------------------------------------------------ Delivery Syria */

    /**
     * The verifier's happy path, with one zone, so the handler reaches its save.
     *
     * THE COUNTRY AND STATE ROWS ARE NOT DECORATION. `shipping_zones` has foreign keys onto
     * `countries` and `states`, and the handler hard-codes country 215. Without them
     * `ShippingZone::create()` throws, the handler's own `catch (\Exception)` swallows it and
     * reports "Could not connect to verification server" — a DATABASE error dressed as a network
     * one — and returns 302 before ever reaching the token save. The first version of this
     * fixture omitted them, and `test_an_empty_delivery_token_box_keeps_the_stored_token` passed
     * against a handler that had not written anything at all. That is why every delivery case
     * below also asserts the zone was written.
     */
    private function fakeVerifierAccepts(): void
    {
        \Illuminate\Support\Facades\DB::table('countries')->insertOrIgnore([
            'id' => 215, 'name' => 'Syria', 'created_at' => now(), 'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('states')->insertOrIgnore([
            'id' => 1, 'name' => 'Damascus', 'country_id' => 215, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Http::fake(['deliverysyria.com/*' => Http::response([
            'data' => [['code' => '1', 'governorate' => 'Damascus', 'base_cost' => '1000']],
        ], 200)]);
    }

    /**
     * The WITNESS for every delivery case that expects the handler to run to completion: a
     * non-secret row the same code path writes just before the token save. A secret-survived
     * assertion is worthless without it — an untouched value looks identical to a value nothing
     * ever reached.
     */
    private function assertTheZoneSyncRan(int $storeId): void
    {
        $this->assertTrue(
            \App\Models\ShippingZone::withoutGlobalScopes()->where('store_id', $storeId)
                ->where('state_id', '1')->exists(),
            'the handler never reached its save: no shipping zone was written. Check the session '
            . 'error — its `catch` reports every exception, database ones included, as a '
            . 'connection failure.'
        );
    }

    private function merchantWithDeliveryToken(): User
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);
        $merchant->current_store = $store->id;
        $merchant->deliverysyria_secret = self::INVENTED_DS_TOKEN;
        $merchant->save();

        return $merchant->fresh();
    }

    /**
     * THE CENTRAL CASE. A merchant re-syncing their zones posts the masked box empty, and both
     * the stored token and the outbound verification must use the value they already saved.
     */
    public function test_an_empty_delivery_token_box_keeps_the_stored_token_and_verifies_with_it(): void
    {
        $merchant = $this->merchantWithDeliveryToken();
        $this->fakeVerifierAccepts();

        $this->actingAs($merchant)
            ->post(route('delivery.syria.update'), ['delivery_syria_token' => ''])
            ->assertRedirect();

        $this->assertTheZoneSyncRan((int) $merchant->current_store);

        $this->assertSame(self::INVENTED_DS_TOKEN, (string) $merchant->fresh()->deliverysyria_secret,
            'an empty masked box erased the stored Delivery Syria token');

        /* The WITNESS, and the half a save-only assertion would miss: the handler sends the token
           to the verifier BEFORE storing it. Resolving only at the save would still have posted
           an empty secret and dropped the merchant into "verification failed". */
        Http::assertSent(fn ($request) => ($request->data()['secret'] ?? null) === self::INVENTED_DS_TOKEN);
    }

    public function test_a_typed_delivery_token_replaces_the_stored_one(): void
    {
        $merchant = $this->merchantWithDeliveryToken();
        $this->fakeVerifierAccepts();

        $this->actingAs($merchant)
            ->post(route('delivery.syria.update'), ['delivery_syria_token' => 'DS-REPLACEMENT-not-real'])
            ->assertRedirect();

        $this->assertTheZoneSyncRan((int) $merchant->current_store);

        $this->assertSame('DS-REPLACEMENT-not-real', (string) $merchant->fresh()->deliverysyria_secret,
            'a typed token did not replace the stored one — "blank keeps" must not become "nothing writes"');

        Http::assertSent(fn ($request) => ($request->data()['secret'] ?? null) === 'DS-REPLACEMENT-not-real');
    }

    /**
     * The route carries `auth` and no permission. A staff member without `Manage Setting` must
     * not be able to rewrite the store's shipping costs or the owner's token.
     */
    public function test_staff_without_the_settings_permission_cannot_save_the_delivery_token(): void
    {
        $merchant = $this->merchantWithDeliveryToken();
        $staff = $this->makeStaff($merchant);
        $this->fakeVerifierAccepts();

        $this->actingAs($staff)
            ->post(route('delivery.syria.update'), ['delivery_syria_token' => 'DS-FORGED-not-real'])
            ->assertRedirect();

        $this->assertFalse(
            \App\Models\ShippingZone::withoutGlobalScopes()->where('store_id', $merchant->current_store)->exists(),
            'the refusal let the zone sync run: staff rewrote the store\'s shipping costs');

        $this->assertSame(self::INVENTED_DS_TOKEN, (string) $merchant->fresh()->deliverysyria_secret,
            "staff without `Manage Setting` changed the owner's Delivery Syria token");

        $this->assertNull($staff->fresh()->deliverysyria_secret,
            'the refusal must not write the forged token onto the staff row either');

        /* The refusal has to land BEFORE the outbound call: otherwise an unprivileged user can
           still make the server post to a third party at will. */
        Http::assertNothingSent();
    }

    /* ------------------------------------------------------------------- ChatGPT keys */

    /** The repeater is operator-only (`type == 'super admin'` in the handler). */
    private function operatorWithKey(string $key = self::INVENTED_GPT_KEY): array
    {
        $operator = $this->makePlatformAdmin();
        $store = $this->makeStore($operator);
        $operator->current_store = $store->id;
        $operator->save();

        $row = ApikeySetiings::create(['key' => $key, 'created_by' => $operator->id]);

        return [$operator->fresh(), $row];
    }

    /**
     * READ THE TABLE, NOT THE MODEL. `ApikeySetiings` is `Cachable`, and under
     * `CACHE_STORE=array` a query-builder delete can leave the model cache serving the row that
     * is already gone. Driving this test against the pre-fix handler showed exactly that: two
     * cases reported the stored key intact when the legacy `whereNotIn('key', ...)->delete()`
     * had removed it, so the test would have "passed" against the code it exists to catch.
     */
    private function keyOf(int $id): ?string
    {
        $row = \Illuminate\Support\Facades\DB::table('apikey_setiings')->where('id', $id)->first();

        return $row ? (string) $row->key : null;
    }

    /**
     * THE WITNESS FOR EVERY ChatGPT CASE. `ChatgptSettings` refuses a non-operator by returning
     * the SAME redirect it returns on success, so `assertRedirect()` accepts a refusal, and a
     * case whose only assertion is "the row still holds its old value" passes just as well
     * against a handler that did nothing. The non-secret `chat_gpt_model` row is written on the
     * success path only. An independent review found two cases below missing this, which is
     * exactly the trap this file's own docblock warns about — recorded rather than quietly fixed.
     */
    private function assertTheKeySaveRan(User $operator): void
    {
        $this->assertSame('gpt-4o', (string) Setting::withoutGlobalScopes()
            ->where('store_id', $operator->current_store)->where('name', 'chat_gpt_model')->value('value'),
            'the handler never reached its write — it probably refused, so any "the key survived" '
            . 'assertion in this case proves nothing');
    }

    /**
     * THE CASE THAT WOULD HAVE DELETED EVERY KEY. Blank box, saved row posts only its id.
     */
    public function test_an_empty_chatgpt_key_box_keeps_the_stored_key(): void
    {
        [$operator, $row] = $this->operatorWithKey();

        $this->actingAs($operator)->post(route('chatgpt.setting'), [
            'chat_gpt_model' => 'gpt-4o',
            'api_key' => ['e' . $row->id => ''],
        ])->assertRedirect();

        $this->assertSame(self::INVENTED_GPT_KEY, $this->keyOf($row->id),
            'an empty masked box deleted or emptied the stored ChatGPT key');

        $this->assertTheKeySaveRan($operator);
    }

    public function test_a_typed_chatgpt_key_replaces_that_row(): void
    {
        [$operator, $row] = $this->operatorWithKey();

        $this->actingAs($operator)->post(route('chatgpt.setting'), [
            'chat_gpt_model' => 'gpt-4o',
            'api_key' => ['e' . $row->id => 'sk-REPLACEMENT-not-real'],
        ])->assertRedirect();

        $this->assertTheKeySaveRan($operator);

        $this->assertSame('sk-REPLACEMENT-not-real', $this->keyOf($row->id),
            'a typed key did not replace the stored one');
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('apikey_setiings')->where('created_by', $operator->id)->count(),
            'replacing a key must edit the row, not add a second one beside it');
    }

    /** Removing the row from the form still deletes it — the behaviour the old save had. */
    public function test_a_row_dropped_from_the_form_is_deleted(): void
    {
        [$operator, $row] = $this->operatorWithKey();
        $second = ApikeySetiings::create(['key' => 'sk-SECOND-not-real', 'created_by' => $operator->id]);

        $this->actingAs($operator)->post(route('chatgpt.setting'), [
            'chat_gpt_model' => 'gpt-4o',
            'api_key' => ['e' . $row->id => ''],
        ])->assertRedirect();

        $this->assertTheKeySaveRan($operator);

        $this->assertSame(self::INVENTED_GPT_KEY, $this->keyOf($row->id), 'the kept row was deleted');
        $this->assertNull($this->keyOf($second->id), 'the row removed from the form was not deleted');
    }

    /** A new row posts under the bare `api_key[]` the add-button JS clones. */
    public function test_a_new_row_is_created_alongside_the_kept_one(): void
    {
        [$operator, $row] = $this->operatorWithKey();

        $this->actingAs($operator)->post(route('chatgpt.setting'), [
            'chat_gpt_model' => 'gpt-4o',
            'api_key' => ['e' . $row->id => '', 0 => 'sk-BRAND-NEW-not-real'],
        ])->assertRedirect();

        $this->assertTheKeySaveRan($operator);

        $this->assertSame(self::INVENTED_GPT_KEY, $this->keyOf($row->id), 'the kept row was lost');
        $this->assertTrue(\Illuminate\Support\Facades\DB::table('apikey_setiings')
            ->where('created_by', $operator->id)->where('key', 'sk-BRAND-NEW-not-real')->exists(),
            'the newly typed key was not stored');
    }

    /**
     * The row id now travels in the request, so it is caller-supplied input: a forged id must
     * neither read nor delete another operator's row. (Both operators are `super admin`, which
     * is the only type the handler admits, so this is the real adversary and not a hypothetical.)
     */
    public function test_a_forged_row_id_cannot_reach_another_operators_key(): void
    {
        [$operator, $own] = $this->operatorWithKey();
        [$victim, $theirs] = $this->operatorWithKey('sk-VICTIM-KEY-not-real');

        $this->actingAs($operator)->post(route('chatgpt.setting'), [
            'chat_gpt_model' => 'gpt-4o',
            'api_key' => [
                'e' . $own->id => '',
                'e' . $theirs->id => 'sk-STOLEN-not-real',
            ],
        ])->assertRedirect();

        $this->assertTheKeySaveRan($operator);

        $this->assertSame('sk-VICTIM-KEY-not-real', $this->keyOf($theirs->id),
            "a forged row id overwrote another operator's key");
        $this->assertSame(self::INVENTED_GPT_KEY, $this->keyOf($own->id));
    }

    /**
     * And the delete stays inside the caller's own rows. Under the old unscoped
     * `whereNotIn('key', ...)` this save would have wiped the other operator's key outright.
     */
    public function test_a_save_does_not_delete_another_operators_keys(): void
    {
        [$operator, $own] = $this->operatorWithKey();
        [$victim, $theirs] = $this->operatorWithKey('sk-VICTIM-KEY-not-real');

        $this->actingAs($operator)->post(route('chatgpt.setting'), [
            'chat_gpt_model' => 'gpt-4o',
            'api_key' => ['e' . $own->id => ''],
        ])->assertRedirect();

        $this->assertTheKeySaveRan($operator);

        $this->assertSame('sk-VICTIM-KEY-not-real', $this->keyOf($theirs->id),
            "saving one operator's keys deleted another operator's");
    }

    /** A save that posts NO rows at all empties the caller's list, and only the caller's. */
    public function test_posting_no_rows_clears_only_the_callers_keys(): void
    {
        [$operator, $own] = $this->operatorWithKey();
        [$victim, $theirs] = $this->operatorWithKey('sk-VICTIM-KEY-not-real');

        $this->actingAs($operator)->post(route('chatgpt.setting'), [
            'chat_gpt_model' => 'gpt-4o',
        ])->assertRedirect();

        $this->assertTheKeySaveRan($operator);

        $this->assertNull($this->keyOf($own->id), 'removing every row did not clear the list');
        $this->assertSame('sk-VICTIM-KEY-not-real', $this->keyOf($theirs->id));
    }
}
