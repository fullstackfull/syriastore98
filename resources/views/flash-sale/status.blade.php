{{--
    P2.5 -- THE FLASH-SALE STATUS CELL. Inventory row "Flash sale status cell", audited deficient.

    Two defects, both of which a screenshot of a working switch hides.

    1. THE SWITCH HAD NO NAME. It was `<input type="checkbox" class="... flashsale-toggle">` with
       no `<label>`, no `aria-label` and no text, announced as "checkbox" and nothing else -- once
       per row, in a table where every row looks the same. This is the defect already repaired in
       customer/status.blade.php (P2-05-2), and the name is built the same way: it describes WHAT
       THE CONTROL GOVERNS and names the flash sale, because a screen-reader user arriving by
       column has no row context. It does NOT put active/inactive in the name -- the checkbox role
       already conveys that to assistive technology, and a name carrying the state would announce
       it twice and contradict itself the instant it is toggled.

    2. IT RENDERED A LIVE SWITCH FOR VIEWERS THE HANDLER REFUSES. `FlashSaleController::updateStatus`
       (:699) answers 403 unless the caller is a non-operator holding `Edit Flash Sale`:

           if (! $user || UserPolicy::isOperator($user) || ! $user->isAbleTo('Edit Flash Sale'))

       This cell asked nothing, so a viewer without that permission got a switch that flips, fires
       the XHR, takes a 403 and reverts. `FlashSaleDataTable.php:61` prints a `__('Status')` header
       above it either way.

       THE OPERATOR LIMB IS NARROWER THAN IT LOOKS, and an independent review was right to say so.
       `FlashSaleController::index():135` gates the LIST on `Manage Flash Sale` with no operator
       limb of its own, so the platform operator reaches this cell at all only when a role grants
       them that permission. The limb below is therefore defensive rather than a repair of
       something observed -- but it is not optional: the moment an operator IS granted
       `Manage Flash Sale` they see the list, and `updateStatus` refuses them by name.

       THE GATE BELOW MIRRORS THE CONTROLLER'S, including the operator limb, and grants nobody
       anything. It is asked with `isAbleTo` rather than `@permission` on purpose: `@permission`
       compiles to Laratrust's `hasPermission()`, which has no operator bypass and therefore cannot
       express "operator is refused HERE" -- it would merely fail for a different reason and the
       two conditions would drift. The state is still shown; only the ability to change it is
       withheld, which is exactly what the permission governs.
--}}
@php
    /*
     * Read-only. One question, asked the way the handler asks it, so the cell and the route cannot
     * disagree about who may act.
     */
    $ssUser = auth()->user();
    $ssMayToggleFlashSale = $ssUser
        && ! \App\Policies\UserPolicy::isOperator($ssUser)
        && $ssUser->isAbleTo('Edit Flash Sale');
@endphp
@if ($ssMayToggleFlashSale)
    <div class="form-check form-switch">
        <input type="checkbox" class="form-check-input flashsale-toggle"
            aria-label="{{ __('Flash sale status for :name', ['name' => $flashsale->name]) }}"
            data-flashsale-id="{{ $flashsale->id }}" {{ $flashsale->is_active ? 'checked' : '' }} />
    </div>
@else
    <x-ss.status-badge
        :tone="$flashsale->is_active ? 'success' : 'neutral'"
        :label="$flashsale->is_active ? __('Active') : __('Inactive')" />
@endif
