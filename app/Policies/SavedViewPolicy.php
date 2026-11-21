<?php

namespace App\Policies;

use App\Models\SavedView;
use App\Models\User;

/**
 * P2-04-9 / WS E3 — authorization for a merchant employee's saved filter views.
 *
 * TWO INDEPENDENT QUESTIONS, and conflating them is how this kind of object leaks:
 *
 *  1. MAY THIS PRINCIPAL USE THE SCREEN AT ALL? That is the list's own permission — `Manage Order`
 *     for the order list, `Manage Products` for the product list (PLURAL; the singular spelling
 *     exists nowhere in this application, which ledger F15 records as having locked people out
 *     before). Checked OPERATOR-FIRST, because Laratrust has no super-admin bypass and
 *     `isAbleTo()` alone REFUSES the platform operator.
 *
 *  2. IS THIS PRINCIPAL THE OWNER OF THIS VIEW? `(store_id, created_by)` must BOTH match. A view
 *     is one employee's private arrangement of one screen in one store.
 *
 * THE OPERATOR IS **NOT** EXEMPT FROM THE SECOND QUESTION, and that is deliberate and different
 * from `OrderNotePolicy`, where a platform administrator legitimately writes in any store. An
 * order note is a commercial record about a merchant's business; a saved view is a person's own
 * screen arrangement. The operator has no business reading a merchant employee's private filters,
 * reading them would serve no purpose, and "the operator can see everything" is how a personal
 * preference becomes a disclosure. So ownership here is strict for EVERYONE.
 *
 * THERE IS NO SHARE PERMISSION, because there is no sharing mechanism to authorise. The owner's
 * condition — *"sharing a filter combination stays a safe LINK and must not become unrestricted
 * sharing of tenant data"* — is satisfied by P2-04-8's existing URL: a link carries filter VALUES
 * only (`?ss_status=2&ss_payment=Unpaid`), never a store id, a view id or any row. Whoever opens
 * it gets THEIR OWN store's orders narrowed the same way, because the list's store predicate comes
 * from the authenticated principal and never from the URL. Nothing is granted by holding a link.
 */
class SavedViewPolicy
{
    /**
     * The permission each screen's views live behind. A screen absent from this map has no saved
     * views at all — fail closed, rather than inheriting whatever permission a caller passes.
     */
    private const SCREEN_PERMISSION = [
        'order' => 'Manage Order',
        'product' => 'Manage Products',
        // ANA-002's saved reporting periods. Same permission as the screen's own door.
        'analytics' => 'Manage Reports',
        /*
         * MKT-002's five Marketing screens. EACH TAKES ITS OWN SCREEN'S DOOR, read off
         * `CompanyMenuListener:613-676` -- so whoever may see a list may save a view of it, and
         * nobody else. `SavedViewIsolationTest` asserts this map and `TableFilterMemory::VOCABULARY`
         * cover each other in BOTH directions, and it is what caught these five missing: a screen
         * with filters and no permission has unreachable saved views, which looks like a broken
         * Save button rather than a missing line.
         *
         * NONE OF THE FIVE IS IN `OWNER_ADMITTED_SCREENS`, and that is deliberate rather than an
         * omission. `/analytics` is there because its own door admits the tenant owner without the
         * permission row, so without the exception the owner would read the screen and be refused
         * its Save button. These five are the opposite case: all five controllers gate on
         * `isAbleTo()` ALONE (GAP-218), so the owner is already refused the SCREEN. Admitting them
         * here would make saving a view more permissive than seeing the list it describes.
         */
        'coupon' => 'Manage Coupon',
        'newsletter' => 'Manage Newsletter',
        'flash-sale' => 'Manage Flash Sale',
        'wishlist' => 'Manage Wishlist',
        'abandon-cart' => 'Manage Cart',
    ];

    /**
     * SCREENS WHOSE OWN DOOR ADMITS THE TENANT OWNER WITHOUT THE PERMISSION ROW.
     *
     * `/analytics` is gated by `ReportPermission`, which has THREE limbs -- operator, then the
     * tenant owner (`creatorId() === id`), then the named permission -- because a merchant created
     * before a permission name existed holds no row for it and must not be locked out of their own
     * reports. `useScreen()` below has only two of those limbs, so without this exception a store
     * owner could READ the analytics screen and be refused SAVING a period on it: a visible button
     * that always answers "Permission denied", which reads to a merchant as a broken product.
     *
     * IT IS A LIST AND NOT A CHANGE TO `useScreen()` FOR EVERYONE. Adding the owner limb to
     * `order` and `product` would widen two existing surfaces that are not this slice's to widen,
     * and `SavedViewIsolationTest` encodes their current shape. The third limb is added where the
     * screen's own door already has it, and nowhere else.
     */
    private const OWNER_ADMITTED_SCREENS = ['analytics'];

    public static function permissionFor(string $screen): ?string
    {
        return self::SCREEN_PERMISSION[$screen] ?? null;
    }

    /**
     * May the actor use the screen whose views these are?
     *
     * OPERATOR-FIRST. `UserPolicy::isOperator()` is the one definition of the operator in this
     * application (it normalises with `rtrim(' ')` to agree with MySQL's PAD SPACE); a bare
     * `isAbleTo()` here would refuse the one principal who legitimately reads every store.
     */
    public function useScreen(User $actor, string $screen): bool
    {
        $permission = self::permissionFor($screen);

        if ($permission === null) {
            return false;
        }

        /*
         * `ReportAccess::permits()` IS the three-limb gate, extracted from `ReportPermission` and
         * shared rather than re-derived: operator first (Laratrust has no super-admin bypass, so
         * `isAbleTo()` alone refuses the one principal who reads every store), then the tenant
         * owner, then the named permission. Reusing it is what keeps this policy and the screen's
         * middleware from drifting into two different answers for the same user.
         */
        if (in_array($screen, self::OWNER_ADMITTED_SCREENS, true)) {
            return \App\Services\ReportAccess::permits($actor, $permission);
        }

        return UserPolicy::isOperator($actor) || $actor->isAbleTo($permission);
    }

    public function viewAny(User $actor, string $screen): bool
    {
        return $this->useScreen($actor, $screen);
    }

    /**
     * Creating additionally needs a RESOLVED store, because a view with no tenant is a row that
     * belongs to nobody — and F149 is what happens when a null store reaches a query.
     */
    public function create(User $actor, string $screen): bool
    {
        if (! $this->useScreen($actor, $screen)) {
            return false;
        }

        /*
         * AN OPERATOR WHO HAS CHOSEN NO STORE MAY NOT CREATE A VIEW, and `getCurrentStore()` is
         * why that needs saying explicitly.
         *
         * `getCurrentStore()` returns the HARDCODED LITERAL 1 for a `super admin` (helper.php), so
         * `! empty(getCurrentStore())` below is satisfied for an operator who has selected nothing
         * at all -- and `SavedViewController::store()` would then write the row with
         * `store_id = 1`, filing a platform operator's private filter set inside one particular
         * merchant's partition. Found by an independent review, which also established the limit of
         * the harm: every read carries `created_by = Auth::id()`, so only that operator ever sees
         * the row and nothing is disclosed to merchant 1.
         *
         * It is still wrong, and it is an ASYMMETRY: `/analytics` refuses this exact principal
         * ("No store is selected"), so without this they are refused the screen and allowed its
         * Save button. The condition is the screen's own, reused rather than re-derived.
         *
         * NOT WIDENED BEYOND THE OPERATOR. Every other principal was checked and is symmetric --
         * an owner without the permission row is admitted by both (that is what
         * `OWNER_ADMITTED_SCREENS` fixed), staff with the permission by both, and staff with no
         * resolvable store refused by both.
         */
        if (UserPolicy::isOperator($actor) && empty($actor->current_store)) {
            return false;
        }

        return ! empty(getCurrentStore());
    }

    public function view(User $actor, SavedView $savedView): bool
    {
        return $this->useScreen($actor, (string) $savedView->screen)
            && $this->owns($actor, $savedView);
    }

    public function update(User $actor, SavedView $savedView): bool
    {
        return $this->view($actor, $savedView);
    }

    public function delete(User $actor, SavedView $savedView): bool
    {
        return $this->view($actor, $savedView);
    }

    /**
     * BOTH predicates, and `created_by` compared against the actor's OWN id rather than
     * `creatorId()`: that helper returns the owner's id for staff, so using it here would let any
     * employee of a merchant read every colleague's views.
     *
     * Fails closed when no store resolves, so a principal with no tenant owns nothing.
     */
    private function owns(User $actor, SavedView $savedView): bool
    {
        $storeId = getCurrentStore();

        if (empty($storeId)) {
            return false;
        }

        return (int) $savedView->store_id === (int) $storeId
            && (int) $savedView->created_by === (int) $actor->id;
    }
}
