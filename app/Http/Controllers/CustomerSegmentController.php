<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerSegment;
use App\Models\CustomerSegmentMember;
use App\Policies\UserPolicy;
use App\Support\Segments\SegmentDefinition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * MKT-004 — CUSTOMER SEGMENTS, STATIC AND DYNAMIC.
 *
 * ONE SCREEN FOR BOTH KINDS, because they are one concept: a named set of this store's customers.
 * A static segment is a list the merchant curates; a dynamic one is a rule evaluated when it is
 * read. `CustomerSegment::customers()` answers both without the caller branching, which is what
 * stops this becoming two half-features that disagree about what a segment is.
 *
 * AUTHORIZATION IS `Manage Customer`, WHICH IS SEEDED. That matters more than it sounds: a
 * permission name absent from `Utility::addNewData()` cannot exist as a row and evaluates FALSE for
 * everybody -- the tenant owner included -- and that exact lockout has happened in this repository
 * (all six finance names were gated on while none was seeded). So no new name is introduced. The
 * ordering is the one this codebase gets wrong by default and is taken from `StoreSettingsAccess`
 * and `ReportAccess`: PLATFORM OPERATOR FIRST (Laratrust has no super-admin bypass, so
 * `isAbleTo()` alone refuses the one principal who sees every store), then the tenant OWNER, then
 * the named permission. `permissions_as_gates => false`, so `@can`/`$user->can()` are ALWAYS false
 * here and `isAbleTo()` is the only working test -- measured.
 *
 * THE STORE IS NEVER A PARAMETER. It comes from `OperatorStoreSelection::reportStoreId()`, the same
 * resolver GAP-223(b) introduced, so an operator who has selected no store gets `NO_STORE` rather
 * than the hard-coded literal 1 that `getCurrentStore()` returns for a `super admin`. Every read
 * and every write carries that id explicitly: neither `customer_segments` nor
 * `customer_segment_members` is in `config/tenancy.php`'s `scoped_models`, and `null_tenant =>
 * 'open'` means a global scope adds NO predicate when no tenant resolves.
 *
 * A SEGMENT IS NOT A SENDER. Nothing here messages anybody. Who may be sent to, how often, and on
 * what consent is MKT-007 and the D8 consent work, whose enforcement is off by owner decision; a
 * segment that quietly acquired a "send" button would route around that.
 */
class CustomerSegmentController extends Controller
{
    private const PERMISSION = 'Manage Customer';

    /**
     * The three principals, in the order that works here.
     *
     * A SEPARATE METHOD because `index`, the two writes and the member actions must not diverge:
     * five copies of an ordering this codebase gets wrong by default is five chances to get it
     * wrong once.
     */
    private function permits(): bool
    {
        $user = auth('web')->user();

        if ($user === null) {
            return false;
        }

        if (UserPolicy::isOperator($user)) {
            return true;
        }

        // The tenant OWNER, so a merchant created before the permission name existed is not locked
        // out of their own customers. `creatorId()` returns `$this->id` for type admin/super admin.
        if ((int) $user->creatorId() === (int) $user->id) {
            return true;
        }

        return (bool) $user->isAbleTo(self::PERMISSION);
    }

    /**
     * @return int|\Illuminate\Http\RedirectResponse the store, or the refusal
     */
    private function resolveStore()
    {
        if (! $this->permits()) {
            return redirect()->route('dashboard')->with('error', __('Permission denied.'));
        }

        $storeId = \App\Support\Tenancy\OperatorStoreSelection::reportStoreId();

        if ($storeId <= 0) {
            /*
             * NO STORE, NO SCREEN. For an operator this is GAP-223(b)'s refusal rather than store 1;
             * for a delegated principal who resolves no store it avoids rendering a page of
             * confident emptiness about nothing. `dashboard` and not `back()`, because `back()` on a
             * refused GET resolves to this handler and loops.
             */
            return redirect()->route('dashboard')->with('error', __('No store is selected.'));
        }

        return $storeId;
    }

    public function index(Request $request)
    {
        $resolved = $this->resolveStore();

        if (! is_int($resolved)) {
            return $resolved;
        }

        /*
         * PAGINATED, because segments are unbounded per store and the loop below runs ONE uncached
         * count subquery per row. `show()` paginated from the start; this did not, which review
         * pointed out as the inconsistency it was. 25 keeps the per-request count work bounded.
         */
        $segments = CustomerSegment::forStore($resolved)->orderBy('name')->paginate(25);

        /*
         * THE COUNT IS COMPUTED PER SEGMENT, NOT STORED, and for a dynamic segment it cannot be:
         * its membership is its rule evaluated now. Computing the static ones the same way keeps one
         * definition -- a cached `member_count` column would be the thing that drifts, and a
         * merchant comparing a stale count against the list they can see is the defect.
         */
        $counts = [];
        $staleRules = [];

        foreach ($segments as $segment) {
            $counts[$segment->id] = $segment->customers()->count();

            /*
             * A DYNAMIC SEGMENT WHOSE STORED RULE NO LONGER PARSES matches nothing, and the merchant
             * must be told rather than shown a zero. `SegmentDefinition` drops a rule it does not
             * recognise (the row outlives the code), so an empty definition on a dynamic segment
             * means either "never configured" or "configured with something this version dropped".
             * Either way the honest word is "no rule", not "0 customers".
             */
            if ($segment->isDynamic() && $segment->rule()->isEmpty()) {
                $staleRules[] = $segment->id;
            }
        }

        return view('customer_segments.index', [
            'segments' => $segments,
            'counts' => $counts,
            'staleRules' => $staleRules,
            'kinds' => CustomerSegment::kinds(),
            /* MKT-005. `availableRules()` and not `RULES`: the loyalty rule needs a column a
               VENDORED module declares, and offering a rule that cannot be evaluated would let a
               merchant save a segment that silently matches nobody. `RULES` stays the sanitising
               vocabulary so a stored threshold round-trips until the module arrives. */
            'rules' => SegmentDefinition::availableRules(),
        ]);
    }

    public function store(Request $request)
    {
        $resolved = $this->resolveStore();

        if (! is_int($resolved)) {
            return $resolved;
        }

        $user = auth('web')->user();

        $validator = \Validator::make($request->all(), [
            // 60 is the column width. A segment name is a label, not a note.
            'name' => 'required|string|max:60',
            'kind' => 'required|string|in:'.implode(',', array_keys(CustomerSegment::kinds())),
        ]);

        if ($validator->fails()) {
            return redirect()->route('customer-segments.index')
                ->with('error', $validator->getMessageBag()->first());
        }

        $kind = (string) $request->input('kind');
        $name = trim((string) $request->input('name'));

        /*
         * THE RULE IS SANITISED BEFORE IT IS STORED AS WELL AS WHEN IT IS READ. `toStored()` returns
         * the vocabulary's own view of what was posted, so an unknown key or an out-of-range value
         * never reaches the column -- and a round trip cannot widen a segment.
         */
        $definition = $kind === CustomerSegment::KIND_DYNAMIC
            ? SegmentDefinition::fromArray((array) $request->input('rules', []))
            : SegmentDefinition::fromArray([]);

        if ($kind === CustomerSegment::KIND_DYNAMIC && $definition->isEmpty()) {
            /*
             * A DYNAMIC SEGMENT WITH NO RULE IS REFUSED RATHER THAN SAVED EMPTY. `applyTo()` makes
             * an empty definition match NOTHING (never everything), so saving it would give the
             * merchant a segment that is permanently empty and no explanation. Refusing says why.
             */
            return redirect()->route('customer-segments.index')
                ->with('error', __('A rule-based segment needs at least one rule.'));
        }

        // Re-saving a name UPDATES, which is the contract the unique index enforces: the merchant's
        // intent is "this is what that segment means now".
        $segment = CustomerSegment::forStore($resolved)->where('name', $name)->first()
            ?? new CustomerSegment();

        $segment->name = $name;
        $segment->kind = $kind;
        $segment->definition = $definition->toStored();

        /*
         * OWNERSHIP SET AS PROPERTIES, never filled. Neither is in `$fillable`, so no mass
         * assignment -- from this request or anywhere else -- can place a segment in another store.
         */
        $segment->store_id = $resolved;

        if (! $segment->exists) {
            $segment->created_by = (int) $user->id;
        }

        $segment->save();

        return redirect()->route('customer-segments.index')->with(
            'success',
            $segment->wasRecentlyCreated ? __('Segment created.') : __('Segment updated.')
        );
    }

    public function destroy(Request $request, $id)
    {
        $resolved = $this->resolveStore();

        if (! is_int($resolved)) {
            return $resolved;
        }

        /*
         * RESOLVED THROUGH THE TENANT PREDICATE, never `find()`. `customer_segments` is not in
         * `scoped_models`, so a bare `find()` would let one merchant delete another's segment by
         * guessing an id -- the pattern CLAUDE.md names as the most productive defect class here.
         */
        $segment = CustomerSegment::forStore($resolved)->where('id', (int) $id)->first();

        if ($segment === null) {
            return redirect()->route('customer-segments.index')->with('error', __('Segment not found.'));
        }

        /*
         * THE MEMBERSHIPS GO WITH IT, inside a transaction, and keyed on BOTH the segment and the
         * store. There is no foreign key on this table (the migration declares none, deliberately,
         * because the vendored schema has almost none), so nothing in the database would remove
         * them -- and orphaned rows carrying a `customer_id` are exactly what a later feature would
         * read as a live membership.
         */
        DB::transaction(function () use ($segment): void {
            /*
             * KEYED ON `segment_id` ALONE, and the `store_id` predicate was REMOVED here after
             * review. The segment was already resolved through the tenant predicate above, and
             * `segment_id` identifies one segment platform-wide -- so adding `store_id` bought no
             * safety and did active harm: a row mis-stamped with another store's id would have
             * SURVIVED the delete, which is exactly the orphan this method's docblock says must
             * not. A delete that leaves behind the row it was written to remove is worse than one
             * predicate short.
             */
            CustomerSegmentMember::where('segment_id', $segment->id)->delete();

            $segment->delete();
        });

        return redirect()->route('customer-segments.index')->with('success', __('Segment deleted.'));
    }

    /**
     * Add a customer to a STATIC segment.
     *
     * REFUSED FOR A DYNAMIC ONE, and not silently: a dynamic segment's membership is its rule, so a
     * hand-added row would be invisible on the screen (which reads the rule) while sitting in the
     * table for whatever later reads memberships. Two sources of truth for one segment is the
     * defect; saying so is the fix.
     */
    public function addMember(Request $request, $id)
    {
        $resolved = $this->resolveStore();

        if (! is_int($resolved)) {
            return $resolved;
        }

        $segment = CustomerSegment::forStore($resolved)->where('id', (int) $id)->first();

        if ($segment === null) {
            return redirect()->route('customer-segments.index')->with('error', __('Segment not found.'));
        }

        if ($segment->isDynamic()) {
            return redirect()->route('customer-segments.index')
                ->with('error', __('This segment is defined by a rule, so its members cannot be edited by hand.'));
        }

        /*
         * THE CUSTOMER IS RESOLVED INSIDE THIS STORE. `customers.id` values are reused across
         * tenants in a shared database, so a posted id that happens to name another store's
         * customer must not resolve -- and `Customer` IS in `scoped_models`, which is exactly why
         * the predicate is written out: the scope filters for a principal who resolves a tenant and
         * adds NOTHING for one who does not, so leaning on it would protect some callers only.
         * `withoutGlobalScopes()` is NOT used here: the explicit predicate is the control, and
         * keeping the soft-delete scope means a deleted customer cannot be added.
         */
        $customer = Customer::where('store_id', $resolved)
            ->where('id', (int) $request->input('customer_id'))
            ->first();

        if ($customer === null) {
            return redirect()->route('customer-segments.index')->with('error', __('Customer not found.'));
        }

        $existing = CustomerSegmentMember::where('segment_id', $segment->id)
            ->where('customer_id', $customer->id)
            ->where('store_id', $resolved)
            ->first();

        if ($existing !== null) {
            // Idempotent rather than an error: a double-submit means the merchant wanted them in.
            return redirect()->route('customer-segments.index')
                ->with('success', __('That customer is already in this segment.'));
        }

        $member = new CustomerSegmentMember();
        $member->segment_id = $segment->id;
        $member->store_id = $resolved;
        $member->customer_id = (int) $customer->id;
        $member->added_by = (int) auth('web')->id();
        $member->save();

        return redirect()->route('customer-segments.index')->with('success', __('Customer added to the segment.'));
    }

    public function removeMember(Request $request, $id)
    {
        $resolved = $this->resolveStore();

        if (! is_int($resolved)) {
            return $resolved;
        }

        $segment = CustomerSegment::forStore($resolved)->where('id', (int) $id)->first();

        if ($segment === null) {
            return redirect()->route('customer-segments.index')->with('error', __('Segment not found.'));
        }

        CustomerSegmentMember::where('segment_id', $segment->id)
            ->where('customer_id', (int) $request->input('customer_id'))
            ->where('store_id', $resolved)
            ->delete();

        return redirect()->route('customer-segments.index')->with('success', __('Customer removed from the segment.'));
    }

    /**
     * The customers in one segment, listed.
     *
     * A READ ON THE SAME GATE AS THE SCREEN. A segment lists real people with real e-mail
     * addresses, so it is not a weaker door than `/customer`: `Manage Customer` is what that screen
     * requires too.
     */
    public function show(Request $request, $id)
    {
        $resolved = $this->resolveStore();

        if (! is_int($resolved)) {
            return $resolved;
        }

        $segment = CustomerSegment::forStore($resolved)->where('id', (int) $id)->first();

        if ($segment === null) {
            return redirect()->route('customer-segments.index')->with('error', __('Segment not found.'));
        }

        return view('customer_segments.show', [
            'segment' => $segment,
            'kinds' => CustomerSegment::kinds(),
            /* MKT-005. THE FULL `RULES` HERE, AND `availableRules()` ON THE CREATE FORM -- the two
               sites differ on purpose. This screen DISPLAYS a stored definition and looks each rule
               up as `$rules[$ruleKey]['label'] ?? $ruleKey`, so the filtered list would print the
               raw key `min_loyalty_points` at a merchant whose store installed RewardClubPoint,
               built a VIP tier, and later removed the module. `index()` renders the form a merchant
               CHOOSES from, which must offer only what can be evaluated. */
            'rules' => SegmentDefinition::RULES,
            // Paginated, because a segment over a real store's customers is not a page.
            'customers' => $segment->customers()->orderBy('customers.id')->paginate(50),
        ]);
    }
}
