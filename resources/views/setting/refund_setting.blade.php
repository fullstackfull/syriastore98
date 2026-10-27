{{--
    Refund settings, on the design system.
    =============================================================================================

    THREE TOGGLES, THREE EMPTY LABELS. The pattern here put the visible name in a
    `<label class="form-label">` with NO `for`, beside a checkbox whose own associated label —
    `<label class="form-check-label" for="manage_stock"></label>` — was empty. So each control's
    name was on screen and nowhere a screen reader would find it, and the label that WAS
    associated said nothing. The same shape as the WhatsApp notification rows.

    The condition was also written `$order_refunds['x'] ?? '' == 1`, which PHP parses as
    `$order_refunds['x'] ?? ('' == 1)` because `??` binds looser than `==`. It happens to behave
    correctly — a present 0 is falsy and a present 1 is truthy — but it reads as a comparison
    that is not being made. Written as the comparison it means.

    The `hidden` input before each checkbox is NOT decoration: an unchecked checkbox posts
    nothing, so without it the handler cannot tell "switched off" from "not submitted".
--}}
<div class="ss-card" id="Refund_Setting">
    {{ Form::open(['route' => 'refund.settings', 'method' => 'post', 'enctype' => 'multipart/form-data']) }}

    <div class="ss-card__header">
        <div class="ss-page-header__text">
            <h2 class="ss-card__title">{{ __('Refund Settings') }}</h2>
        </div>
    </div>

    @php
        $order_refunds = App\Models\OrderRefundSetting::where('store_id', getCurrentStore())
            ->pluck('is_active', 'name')->toArray();
    @endphp

    <div class="ss-card__body">
        <div class="row">
            {{-- WRITTEN OUT RATHER THAN LOOPED, on purpose. A `@foreach` over
                 `['manage_stock' => …]` with `:name="$ssName"` renders identically and reads
                 better, and `SettingsPanelContractTest` cannot see a single one of the three
                 field names in it — which it told me immediately when I tried. A contract test
                 that a loop can defeat is weaker than one that requires the names to be literal
                 in the file, so the names stay literal. --}}
            <div class="col-lg-4 col-md-6">
                {{-- The hidden input is NOT decoration: an unchecked checkbox posts nothing, so
                     without it the handler cannot tell "switched off" from "not submitted". --}}
                <input type="hidden" name="manage_stock" value="0">
                <x-ss.switch name="manage_stock" id="manage_stock" value="1"
                             :label="__('Manage Stock')"
                             :checked="(int) ($order_refunds['manage_stock'] ?? 0) === 1" />
            </div>

            <div class="col-lg-4 col-md-6">
                <input type="hidden" name="attachment" value="0">
                <x-ss.switch name="attachment" id="attachment" value="1"
                             :label="__('Attachment')"
                             :checked="(int) ($order_refunds['attachment'] ?? 0) === 1" />
            </div>

            <div class="col-lg-4 col-md-6">
                <input type="hidden" name="shiping_deduct" value="0">
                <x-ss.switch name="shiping_deduct" id="shiping_deduct" value="1"
                             :label="__('Shipment amount deduct during')"
                             :checked="(int) ($order_refunds['shiping_deduct'] ?? 0) === 1" />
            </div>
        </div>
    </div>

    <div class="ss-card__footer ss-card__footer--end">
        <button type="submit" class="ss-btn ss-btn--primary btn-submit">{{ __('Save Changes') }}</button>
    </div>
    {!! Form::close() !!}
</div>
