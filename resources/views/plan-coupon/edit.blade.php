{{--
    GAP-179 / P06-06 slice 3 -- THE SCREEN THAT KEPT SLICE 1'S OWN DEFECT, AND WHY NOBODY SAW IT.

    This dialog was never a measured surface, and not because anyone judged it clean. The live
    harness opens a Tier C fragment by clicking its trigger on the parent screen; the edit trigger
    is in `plan-coupon/action.blade.php`, which renders only once a coupon ROW exists, and
    `database/seed-p2dev.php` created none. So `/plan-coupon` showed an empty grid, no trigger
    existed on any page, and this file was UNMEASURABLE -- which reads exactly like clean.

    What it was carrying, measured as the operator once the fixture had a coupon, APP_DEBUG off,
    all four combinations:

        input.btn.btn-badge.btn-primary   "Update" / "تحديث"      2.58:1 dark
        button.btn.btn-outline-primary    "Generate" / "توليد"    3.07:1 dark

    The first is THE defect slice 1 existed to close: `style-dark.css` paints the host's primary
    `--bs-btn-color: var(--theme-color)` on `--bs-btn-bg: var(--third-color)`, which resolves to
    rgb(4,34,42) on rgb(51,93,168). Slice 1 converted `create.blade.php`'s submit and never
    touched this one. That is the `plans/edit.blade.php` finding for the third time in this phase:
    a byte-similar sibling of a repaired view, repaired on one side only.

    Both repairs are reuse. `.ss-btn--primary` reads `--ss-brand` / `--ss-on-brand`, so it is
    correct in both themes by construction rather than by a measurement that has to be repeated;
    inside the `data-ss-theme="light"` scope below that is #ffffff on #335da8. The scope itself is
    the recorded P2.5-A-01/A-02 decision -- the host keeps `--bs-modal-bg: #ffffff` in dark mode,
    so this dialog is a white sheet and a design-system component inside it would otherwise
    resolve its dark tokens onto white. The reasoning in full, and the four views that already use
    the idiom, are at the head of `create.blade.php`.

    `store-syria-ds.css` is unchanged by this slice.
--}}
<div data-ss-theme="light">
{{ Form::model($planCoupon, ['route' => ['plan-coupon.update', $planCoupon->id], 'method' => 'PUT', 'enctype' => 'multipart/form-data']) }}
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
            <button class="ss-btn ss-btn--secondary" type="button" id="code-generate">
                <i class="ti ti-history pr-1" aria-hidden="true"></i>{{ __(' Generate') }}
            </button>
        </div>
    </div>

    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Update')}}" class="ss-btn ss-btn--primary mx-1">
    </div>
</div>
{!! Form::close() !!}
</div>
