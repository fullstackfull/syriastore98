{{--
    F184 / D-28c — THE VARIANT-DELETION CONFIRMATION, SHARED BY EVERY SCREEN THAT SHOWS A TRASH
    BUTTON.
    =============================================================================================

    THREE SCREENS RENDER THE VARIANT PANELS: the product editor (`product/edit.blade.php`), the
    READ-ONLY product view (`product/show.blade.php`), and the vendored **AuctionProduct** module's
    edit screen, which calls the same two first-party routes
    (`products.attribute_combination_data` / `..._edit`) and so renders the same two partials.
    The trash button's delegated handler ships inside `attribute_combinations_data.blade.php`
    itself, which is why it is live wherever that partial is injected and why this dialog has to
    be shared rather than pushed from one screen.

    CORRECTED after independent review. An earlier version of this comment said all three screens
    "have LIVE trash buttons" and that `show.blade.php`'s delete "has always been live". That
    overstates the read-only screen. On that route the panel renders **EMPTY**: the view posts
    `$('#choice_form').serialize()`, no `id="choice_form"` exists anywhere in `resources/views`,
    so no id reaches the endpoint and `Product::find(null)` returns nothing. Measured by
    `tests/Feature/Merchant/ProductShowReadOnlyLockTest.php` against the real endpoint. So on
    `show` the variant delete is **LATENT, not live**, and this include is defensive -- correct to
    keep, because the panel would become populated the moment that id is wired up or a module
    pushes into one of the four `@stack` hooks inside the locked scope.

    Also corrected: that comment described `show.blade.php`'s lock as the old document-wide
    `$('input').attr('readonly', true)` sweep. It is now `ssLockProductShow()`, scoped to
    `#ss-product-show` and re-run after each async injection -- it still never touches an `<a>`,
    so this dialog remains the control for the trash button either way.

    (The read-only screen keeping a delete capability at all is recorded as an observation rather
    than removed: the owner's condition is that a deletion be confirmed, not that a capability be
    withdrawn, and removing a control a merchant may be using is a product decision.)

    WHY THIS IS INLINE AND IDEMPOTENT RATHER THAN `@push`ed, AND IT IS THE REVIEW'S CORRECTION.
    ---------------------------------------------------------------------------------------------
    The first version was `@push('custom-script')` and was included only by the two first-party
    views. Both variant partials then fail CLOSED when `window.ssConfirmVariantDelete` is absent —
    which is right — so on the AuctionProduct screen every trash button started answering *"This
    variant could not be deleted"* and sending no request at all. A capability that worked before
    the change was gone, and the message blamed the variant. `packages/workdo/` must not be edited
    (§4.7), so the fix has to be here.

    Two consequences for the shape of this file:

      - **Inline, not `@push`.** A partial injected by `$('…').html(data)` is rendered outside any
        layout, so a `@push` would land in a stack nobody renders and the script would simply
        vanish. An inline `<script>` is evaluated by jQuery on injection, which is how both
        variant panels' own handlers already arrive.
      - **Idempotent, so including it four times is safe.** The shell lives in a `<template>`,
        whose content is invisible to `getElementById`, and is cloned into `document.body` only if
        the dialog is not already there — so the editor's page-load copy and a later AJAX copy
        cannot produce two elements with one id. The bridge is defined only if it is not already a
        function. `StoreSyriaDS.dialogEls()` resolves the element live on every call, so a shell
        appended after the DS loaded still works.

    WHY THE SCRIPT AND THE MARKUP TRAVEL TOGETHER. The strings must be rendered by `__()` —
    `store-syria-ds.js` has no translator, the same reason `StoreSyriaDS.applyFormErrors()` takes
    its labels as arguments — so a second copy in a second view would be a second place for an
    English literal to creep in.

    THE DIALOG ID IS PUBLISHED ON `window` because `edit.blade.php` opens the same shell for the
    SAVE path's refusal, which is a different flow with the same surface.
--}}

<template data-ss-dialog-template="ss-variant-delete-dialog">
    <x-ss.confirm-dialog id="ss-variant-delete-dialog" />
</template>

<script>
    (function () {
        var id = 'ss-variant-delete-dialog';
        window.SS_VARIANT_DIALOG = id;

        /* The shell, once per document. */
        if (!document.getElementById(id) && document.body) {
            var tpl = document.querySelector('[data-ss-dialog-template="' + id + '"]');
            if (tpl && tpl.content) {
                document.body.appendChild(tpl.content.cloneNode(true));
            }
        }

        if (typeof window.ssConfirmVariantDelete === 'function') { return; }

        /*
         * THE TRASH DOOR'S CONFIRMATION.
         *
         * `opts.variant` is the CONSENT TOKEN, read from the button's `data-variant`, which both
         * variant panels render from `VariantRemovalPolicy::consentToken()`. It is both what the
         * merchant is shown and what the delete doors compare the echo against, so the dialog
         * cannot name one thing while the request confirms another. Nothing here builds a selector
         * from it: `M/L-Natural` is a legal variant name and an illegal selector, which is the F72
         * defect class.
         *
         * FAIL CLOSED. If the design system is missing, this refuses and says so. A missing dialog
         * must not degrade into the one-click delete it replaced.
         */
        window.ssConfirmVariantDelete = function (opts, onConfirm) {
            var variant = (opts && opts.variant) || '';

            var opened = window.StoreSyriaDS
                && typeof StoreSyriaDS.confirmDestructive === 'function'
                && StoreSyriaDS.confirmDestructive(window.SS_VARIANT_DIALOG, {
                    title: @json(__('Delete this variant permanently?')),
                    /* `split().join()`, NOT `.replace()`. A merchant's variant name is the
                       REPLACEMENT argument here, and in a replacement string `$$`, `$&`, `` $` ``
                       and `$'` are substitution patterns: a variant named `A$&B` rendered as
                       `A<marker>B` and ``A$`B`` rendered as `AVariant: B` (measured). The row
                       deleted was still the right one — the echo is the untouched `data-variant` —
                       but the merchant approved a name that was not the variant's, which is the
                       one guarantee the name echo exists to give. `join()` substitutes nothing.

                       AND THE MARKER CONTAINS NO `@`, which is not fussiness. The first version
                       used `__SS_VARIANT_NAME__`, and Blade's `@@` IS THE ESCAPE FOR A LITERAL `@`: it rewrote
                       the `.split()` argument to `'@v@@'` while leaving the copy inside the `__()`
                       call alone, so the split matched one character late and every rendered line
                       read `Variant: @S-Natural`. Both existing checks used `includes(name)` and
                       passed straight through it; it was caught by LOOKING at the screenshot. */
                    lines: variant ? [@json(__('Variant: :name', ['name' => '__SS_VARIANT_NAME__'])).split('__SS_VARIANT_NAME__').join(variant)] : [],
                    note: @json(__('This cannot be undone. The price, stock and SKU this variant carries will be lost.')),
                    confirmText: @json(__('Delete permanently')),
                    cancelText: @json(__('Cancel')),
                    closeText: @json(__('Close'))
                }, onConfirm, opts && opts.trigger);

            if (!opened) {
                show_toastr(@json(__('Error')), @json(__('This variant could not be deleted.')), 'error');
            }
        };
    })();
</script>
