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
    ];

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
        return $this->useScreen($actor, $screen) && ! empty(getCurrentStore());
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
