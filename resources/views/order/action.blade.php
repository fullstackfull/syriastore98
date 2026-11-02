{{--
    P2-04-4 — the order list's row actions, on the design system.

    THREE DEFECTS IN WHAT WAS HERE, all measured in the running application rather than read.

    1. THREE OF THE FOUR BUTTONS HAD NO ACCESSIBLE NAME AT RUNTIME. They carried `title`, which
       looks like a name and is not: `data-bs-toggle="tooltip"` makes Bootstrap MOVE the title
       into `data-bs-original-title` and blank the attribute on initialisation, and `aria-describedby`
       is only attached while the tooltip is open. So after page load each of these was an
       unnamed control wrapping an unlabelled `<i>` — "button" and nothing else, four times per
       row, down the whole list. The quick-preview button escaped only because it was written
       later with an explicit `aria-label`. Every one now carries one, and names the ORDER as
       well as the verb, because a screen-reader user moving through the table hears the row's
       actions without the row's context.

    2. ICON CONTRAST BELOW THE 3:1 NON-TEXT MINIMUM, worst in dark mode. `btn-info`'s pencil
       measured 1.09:1 — white on `#e8f8ff`, because the vendored dark theme turns button icons
       white while leaving this button's light background alone. In light mode the eye measured
       1.68:1, the preview icon 2.22:1 and the pencil 2.52:1. These are the design system's own
       quiet icon buttons now, whose colours are theme-aware tokens.

    3. `class="x-3 btn btn-sm align-items-center btn btn-sm btn-warning"` — `btn` and `btn-sm`
       each twice, and an `x-3` that matches no rule in any stylesheet in the repository.

    WHAT IS DELIBERATELY UNCHANGED. The JavaScript hooks are behaviour, not styling, and all of
    them stay exactly as they were: `.return_request` (bound in `order/index.blade.php`),
    `.show_confirm` and `data-ajax-popup="true"` (both bound in `public/js/custom.js` by
    delegated handler, so they survive a DataTables redraw), the `data-ss-panel-*` attributes,
    and the enclosing `<span class="d-flex …">` that `order/index.blade.php:87` reaches through
    with `.parent()`. `data-bs-toggle="tooltip"` and `title` also stay: the tooltip is a sighted
    affordance worth keeping, and where both exist `aria-label` is what assistive technology
    announces.
--}}
<span class="d-flex gap-1 justify-content-end">
    @if ($order->delivered_status == 3 && $order->return_status == 1)
    <a href="#" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--secondary return_request"
        data-id="{{ $order->id }}" data-status="2" data-bs-toggle="tooltip"
        title="{{ __('Approve') }}"
        aria-label="{{ __('Approve the return of order :id', ['id' => $order->product_order_id]) }}">
        <i class="ti ti-check" aria-hidden="true"></i>
    </a>
    <a href="#" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--danger-quiet return_request"
        data-id="{{ $order->id }}" data-status="3" data-bs-toggle="tooltip"
        title="{{ __('Cancel') }}"
        aria-label="{{ __('Reject the return of order :id', ['id' => $order->product_order_id]) }}">
        <i class="ti ti-circle-x" aria-hidden="true"></i>
    </a>
    @endif
    {{--
        P2-04-5. THE QUICK PREVIEW, ADDED BESIDE THE EXISTING MODAL RATHER THAN
        REPLACING IT. Workstream F's rule is to preserve existing AJAX modal
        contracts during migration, and `data-ajax-popup` is driven by the host
        theme's own script on ~65 screens -- removing one caller would not
        simplify anything and would drop a surface merchants use today.

        The panel opens from the list and fetches `order.preview`, a read-only
        fragment on its own uri registered in OrderDocumentAccessGuard::TARGETS.
        The id is raw: authorization is the guard's, not the ciphertext's.
    --}}
    <button type="button"
        data-ss-panel-open="ss-order-preview-panel"
        data-ss-panel-url="{{ route('order.preview', $order->id) }}"
        class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost"
        data-bs-toggle="tooltip"
        title="{{ __('Quick preview') }}"
        aria-label="{{ __('Quick preview of order :id', ['id' => $order->product_order_id]) }}">
        {{-- `ti-layout-sidebar-right`, NOT `…-right-expand`. The bundled
             `public/assets/fonts/tabler-icons.min.css` has no `-expand` variant, so this
             button has painted NO GLYPH since P2-04-5 shipped it: an 18px-wide control with
             nothing in it, which read as a thin sliver beside the other three and was in every
             screenshot of the order list. A missing icon name fails silently -- the class is
             valid CSS, the pseudo-element is there, the content is a character the font does
             not have. Ratcheted now by Tests\Unit\Ui\IconNamesExistTest. --}}
        <i class="ti ti-layout-sidebar-right" aria-hidden="true"></i>
    </button>

    <a href="javascript:void(0)"
        data-url="{{ route('order.order_view', \Illuminate\Support\Facades\Crypt::encrypt($order->id)) }}" data-size="lg"
        data-ajax-popup="true" data-title="{{ __('Order') }}    #{{ $order->product_order_id }}"
        class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost" data-bs-toggle="tooltip"
        title="{{ __('Show') }}"
        aria-label="{{ __('Show order :id', ['id' => $order->product_order_id]) }}">
        <i class="ti ti-eye" aria-hidden="true"></i>
    </a>
    <a href="{{ route('order.view', \Illuminate\Support\Facades\Crypt::encrypt($order->id)) }}"
        class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost" data-bs-toggle="tooltip"
        title="{{ __('Edit') }}"
        aria-label="{{ __('Edit order :id', ['id' => $order->product_order_id]) }}">
        <i class="ti ti-pencil" aria-hidden="true"></i>
    </a>
    {!! Form::open(['method' => 'DELETE', 'route' => ['order.destroy', $order->id], 'class' => 'd-inline']) !!}
    <button type="button" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--danger-quiet show_confirm"
        data-bs-toggle="tooltip" data-confirm="{{ __('Are You Sure?') }}"
        data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}"
        title="{{ __('Delete') }}"
        aria-label="{{ __('Delete order :id', ['id' => $order->product_order_id]) }}">
        <i class="ti ti-trash" aria-hidden="true"></i>
    </button>
    {!! Form::close() !!}
</span>
