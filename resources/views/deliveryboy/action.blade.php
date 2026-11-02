<span class="d-flex gap-1 justify-content-end">
    <a aria-label="{{ __('Edit DeliveryBoy') }}" href="#!" data-size="md"
        data-url="{{ route('deliveryboy.edit', $deliveryboy->id) }}" data-bs-toggle="tooltip" data-ajax-popup="true"
        class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost" data-bs-original-title="{{ __('Edit DeliveryBoy') }}" data-title="{{ __('Edit DeliveryBoy') }}"
        title="{{ \Auth::user()->type == 'super admin' ? __('Edit DeliveryBoy') : __('Edit DeliveryBoy') }}">
        <i class="ti ti-pencil" aria-hidden="true"></i>
    </a>
    {!! Form::open(['method' => 'DELETE', 'route' => ['deliveryboy.destroy', $deliveryboy->id], 'class' => 'd-inline']) !!}
    <a aria-label="{{ __('Delete :thing', ['thing' => __('DeliveryBoy')]) }}" href="#" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--danger-quiet bs-pass-para show_confirm" data-confirm="{{ __('Are You Sure?') }}"
        data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}" 
        data-confirm-yes="delete-form-{{ $deliveryboy->id }}"  data-bs-toggle="tooltip" data-bs-original-title="{{ __('Delete') }}" data-title="{{ __('Delete') }}" title="{{ __('Delete') }}">
        <i class="ti ti-trash" aria-hidden="true"></i>
    </a>
    {!! Form::close() !!}
    <a aria-label="{{ __('Reset Password') }}" href="#"
        data-url="{{ route('deliveryboy.reset', \Crypt::encrypt($deliveryboy->id)) }}"
        data-ajax-popup="true" data-size="md" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost" data-bs-toggle="tooltip"
        data-bs-original-title="{{ __('Reset Password') }}"
        data-title="{{ __('Reset Password') }}"
        title="{{ __('Reset Password') }}">
        <i class="ti ti-adjustments" aria-hidden="true"></i>
    </a>
</span>