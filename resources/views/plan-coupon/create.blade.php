{{--
    GAP-179 / P06-06 slice 3 -- THE "GENERATE" CONTROL ON THE DIALOG'S WHITE SHEET.

    Slice 1 measured `button.btn.btn-outline-primary` here at **3.07:1 in dark**,
    rgb(120,148,198) on rgb(255,255,255), against a 4.5:1 label floor, and left it on the grounds
    that the Phase 2.5 white-dialog decision needed re-reading rather than assuming. It is
    re-read, it exists, and it prescribes this repair.

    P2.5-A-01/A-02: the host keeps `--bs-modal-bg: #ffffff` even in dark mode, so this dialog is a
    WHITE SHEET on a dark page. That is a recorded design decision, not a surface that escaped the
    theme -- and its stated consequence is that "any design-system component placed inside a modal
    must carry `data-ss-theme="light"`", because a component inside one otherwise resolves its
    DARK token values onto white. The same idiom, for the same measured reason, is at
    `users/create.blade.php`, `category/create.blade.php`, `product_brand/create.blade.php` and
    `finance/index.blade.php`.

    So the wrapper names the surface and `.ss-btn--secondary` takes `--ss-surface` / `--ss-fg`,
    which inside that scope are #ffffff and #1a1d23. No new CSS: `store-syria-ds.css` is unchanged
    by this slice.

    `edit.blade.php` IS THE SAME FORM AND GETS THE SAME CHANGE IN THE SAME COMMIT. Comment-
    stripped the two differ in `Form::open` vs `Form::model` and `Create` vs `Update`. Repairing
    one of a byte-similar pair and measuring only that one is how `plans/edit.blade.php` kept
    slice 1's own 2.58:1 button; `PlanCouponScreenLegibilityTest` compares the two files' control
    classes as sets and fails if they ever drift.
--}}
<div data-ss-theme="light">
{{ Form::open(['route' => 'plan-coupon.store', 'method' => 'post', 'enctype' => 'multipart/form-data']) }}
<div class="row">
    <div class="form-group col-md-12">
        {{ Form::label('name', __('Name'), ['class' => 'form-label']) }}<span class="ss-label__required" aria-hidden="true">*</span>
        {{ Form::text('name', null, ['class' => 'form-control', 'placeholder' => __('Enter Name'), 'required' => 'required']) }}
    </div>
    <div class="form-group col-md-6">
        {{ Form::label('discount', __('Discount'), ['class' => 'form-label']) }}<span class="ss-label__required" aria-hidden="true">*</span>
        {{ Form::number('discount', null, ['class' => 'form-control', 'step' => '0.01', 'placeholder' => __('Enter Discount'), 'required' => 'required']) }}
        <span class="small">{{ __('Note: Discount in Percentage') }}</span>
    </div>
    <div class="form-group col-md-6">
        {{ Form::label('limit', __('Limit'), ['class' => 'form-label']) }}<span class="ss-label__required" aria-hidden="true">*</span>
        {{ Form::number('limit', null, ['class' => 'form-control', 'placeholder' => __('Enter Limit'), 'required' => 'required']) }}
    </div>
    <div class="form-group col-md-12" id="auto">
        {{ Form::label('limit', __('Code'), ['class' => 'form-label']) }}<span class="ss-label__required" aria-hidden="true">*</span>
        <div class="input-group">
            {{ Form::text('code', null, ['class' => 'form-control', 'id' => 'auto-code', 'required' => 'required', 'oninput'=>'this.value = this.value.toUpperCase()']) }}
            <button class="ss-btn ss-btn--secondary" type="button" id="code-generate"><i
                    class="ti ti-history pr-1" aria-hidden="true"></i>{{ __(' Generate') }}</button>
        </div>
    </div>
    <div class="modal-footer pb-0">
        <input type="button" value="{{ __('Cancel') }}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{ __('Create') }}" class="ss-btn ss-btn--primary mx-1">
    </div>
</div>
{!! Form::close() !!}
</div>
