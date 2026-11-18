<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * THE READ-ONLY QUERIES THE OWNER IS ASKED TO RUN, EXECUTED EXACTLY AS THE DOCUMENT WRITES THEM.
 * ============================================================================================
 *
 * `docs/security/P01-SECURITY-WORK-QUEUE.md` (P1-SEC-66/67/67b) tells the owner how to find users whose `users.current_store` was pointed at the
 * operator's store by the OLD `StoreController::changeStore`. The first query it offered was
 *
 *     s.created_by <> CASE WHEN u.type = 'admin' THEN u.id ELSE u.created_by END
 *
 * which is the exact NEGATION of that old gate (`stores.created_by = creatorId()`): it kept only the pointers the gate would have REFUSED, and so could not
 * return a single one it had written. The fourth independent review found it by hand-evaluating one row. It had no test, because nothing ran it.
 *
 * This test reads the SQL out of the document (so the document and the test cannot drift) and runs it on a fixture that holds every population:
 *
 *   - a merchant who demoted themselves and is still pointed at the operator's store          (the exposed population)
 *   - staff the operator created, pointed at the operator's store                             (a designed population)
 *   - staff of the demoted merchant, whose pointer was COPIED from their creator             (propagation, UserController:160-162)
 *   - a merchant pointed at the operator's store by an older, unchecked writer
 *   - a merchant pointed at ANOTHER merchant's store by an older, unchecked writer
 *   - and, as negatives, a merchant and their staff pointed at their own store, and the operator
 *
 * Nothing here talks to production: the document asks the owner to run these read-only, and this test proves they return what the document says.
 */
class StalePointerDetectionQueryTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The SQL of the ```sql block whose first line is `-- <label>:` in the work queue. */
    private function documentedQuery(string $label): string
    {
        $doc = (string) file_get_contents(base_path('docs/security/P01-SECURITY-WORK-QUEUE.md'));

        $this->assertSame(
            1,
            preg_match('/```sql\n(-- '.preg_quote($label, '/').':.*?)```/s', $doc, $m),
            "docs/security/P01-SECURITY-WORK-QUEUE.md no longer carries a ```sql block labelled `-- {$label}:`. The owner is told to run it; keep it, and keep it executable."
        );

        return trim($m[1]);
    }

    /** @return list<int> the ids a documented query returns, ascending */
    private function idsOf(string $sql): array
    {
        $ids = array_map(fn ($row) => (int) $row->id, DB::select($sql));
        sort($ids);

        return $ids;
    }

    /** @return array<string, int> */
    private function fixture(): array
    {
        $operator = $this->makePlatformAdmin();
        $this->assertSame(1, (int) $operator->id, 'Premise: the operator is user 1, the id every merchant has as created_by.');
        $operatorStore = $this->makeStore($operator);

        // a merchant who demoted themselves (type is a role name, created_by is the operator) and owns a store, still pointed at the OPERATOR'S store
        $demoted = User::factory()->create(['type' => 'Manager', 'created_by' => $operator->id, 'is_active' => 1, 'email_verified_at' => now()]);
        $demotedStore = $this->makeStore($demoted);
        // staff the operator created
        $operatorsStaff = User::factory()->create(['type' => 'staff', 'created_by' => $operator->id, 'is_active' => 1, 'email_verified_at' => now()]);
        // staff the demoted merchant created: the pointer is copied from the creator
        $demotedsStaff = User::factory()->create(['type' => 'staff', 'created_by' => $demoted->id, 'is_active' => 1, 'email_verified_at' => now()]);
        // a merchant pointed at the operator's store by an older writer
        $strayedMerchant = $this->makeMerchant();
        $this->makeStore($strayedMerchant);
        // a merchant pointed at ANOTHER merchant's store by an older writer
        [$merchant, $merchantStore] = $this->makeTenant();
        $crossed = $this->makeMerchant();
        $this->makeStore($crossed);
        // negatives: a merchant and their staff, each pointed at their own merchant's store
        $staff = $this->makeStaff($merchant);

        $point = fn (User $user, $storeId) => DB::table('users')->where('id', $user->id)->update(['current_store' => $storeId]);
        $point($demoted, $operatorStore->id);
        $point($operatorsStaff, $operatorStore->id);
        $point($demotedsStaff, $operatorStore->id);
        $point($strayedMerchant, $operatorStore->id);
        $point($crossed, $merchantStore->id);
        $point($operator, $operatorStore->id);

        return [
            'operator' => (int) $operator->id,
            'demoted' => (int) $demoted->id,
            'operatorsStaff' => (int) $operatorsStaff->id,
            'demotedsStaff' => (int) $demotedsStaff->id,
            'strayedMerchant' => (int) $strayedMerchant->id,
            'crossed' => (int) $crossed->id,
            'merchant' => (int) $merchant->id,
            'staff' => (int) $staff->id,
        ];
    }

    public function test_detect_a_finds_every_user_pointed_at_the_operators_store_and_nobody_else(): void
    {
        $u = $this->fixture();

        $rows = DB::select($this->documentedQuery('detect-A'));
        $found = array_map(fn ($r) => (int) $r->id, $rows);
        sort($found);

        $expected = [$u['demoted'], $u['operatorsStaff'], $u['demotedsStaff'], $u['strayedMerchant']];
        sort($expected);
        $this->assertSame($expected, $found, 'detect-A must return exactly the users pointed at an operator-owned store: the demoted merchant, the operator\'s staff, the demoted merchant\'s staff, and the stray merchant.');

        // The triage column the document tells the owner to read: a demoted merchant owns a store, operator-created staff do not.
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r->id] = (int) $r->stores_they_own;
        }
        $this->assertSame(1, $byId[$u['demoted']], 'the exposed population owns a store of its own');
        $this->assertSame(0, $byId[$u['operatorsStaff']], 'staff the operator created own none');
        $this->assertSame(0, $byId[$u['demotedsStaff']], 'staff of the demoted merchant own none');
    }

    /**
     * detect-C (P1-SEC-67c): the settings rows a self-demoted merchant's saves left stamped with the operator's id inside a store the operator does not own.
     * They are read as the platform's by every reader keyed on `created_by = 1` with no store predicate. The fixture holds the operator's own platform rows
     * (not returned), a merchant's own rows and a merchant's staff's rows re-stamped to the merchant (not returned), and one row stamped with the operator's
     * id inside a merchant's store (returned -- as is a legitimate "parked" save, which is why the document tells the owner to look before deleting).
     */
    public function test_detect_c_finds_settings_rows_stamped_with_the_operators_id_inside_a_store_it_does_not_own(): void
    {
        $operator = $this->makePlatformAdmin();
        $operatorStore = $this->makeStore($operator);
        [$merchant, $merchantStore] = $this->makeTenant();

        $insert = fn (string $name, int $createdBy, int $storeId) => DB::table('settings')->insertGetId([
            'name' => $name, 'value' => 'x', 'created_by' => $createdBy, 'store_id' => $storeId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $platform = $insert('storage_setting', (int) $operator->id, (int) $operatorStore->id);          // the platform's own row
        $own = $insert('s3_bucket', (int) $merchant->id, (int) $merchantStore->id);                      // a merchant's row, their id
        $contaminated = $insert('storage_setting', (int) $operator->id, (int) $merchantStore->id);       // stamped 1 inside a merchant's store

        $rows = DB::select($this->documentedQuery('detect-C'));
        $ids = array_map(fn ($r) => (int) $r->id, $rows);

        $this->assertSame([$contaminated], $ids, 'detect-C must return exactly the row stamped with the operator\'s id inside a store the operator does not own.');
        $this->assertNotContains($platform, $ids);
        $this->assertNotContains($own, $ids);
        $this->assertSame((int) $merchant->id, (int) $rows[0]->store_owner, 'the triage column names the store\'s real owner');
    }

    public function test_detect_b_finds_pointers_into_another_tenant_and_cannot_find_what_the_old_change_store_wrote(): void
    {
        $u = $this->fixture();

        // detect-B compares the store's owner with the user's creatorId(). It therefore finds a pointer into a store the user's own root does not own -- the
        // merchant pointed at ANOTHER merchant's store, the merchant pointed at the operator's, and the staff whose pointer was COPIED from a contaminated creator
        // (their creatorId() is the demoted merchant, who is not the operator that owns the store).
        $expected = [$u['crossed'], $u['demotedsStaff'], $u['strayedMerchant']];
        sort($expected);
        $this->assertSame($expected, $this->idsOf($this->documentedQuery('detect-B')), 'detect-B returns the pointers into a store the user\'s own root does not own.');

        // THE FOURTH REVIEW'S FINDING, PINNED: it returns neither of the populations the OLD changeStore wrote -- a demoted merchant (creatorId() = the operator)
        // pointed at the operator's store, and staff the operator created -- because for them the store's owner EQUALS creatorId(), which is the very test the old
        // gate passed. That is why detect-A exists, and why detect-B must not be offered as the detector for them.
        $blind = array_intersect($this->idsOf($this->documentedQuery('detect-B')), [$u['demoted'], $u['operatorsStaff']]);
        $this->assertSame([], array_values($blind), 'detect-B is the negation of the old changeStore gate: it cannot return the users that gate wrote.');
    }
}
