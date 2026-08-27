<span class="d-flex gap-1 justify-content-end">
@permission('Edit Donation')
<button class="btn btn-sm btn-info" data-url="{{ route('donation.edit', $donation->id) }}" data-size="md"
    data-ajax-popup="true" data-title="{{ __('Edit Donation') }}" data-bs-toggle="tooltip" title="{{ __('Edit') }}">
    <i class="ti ti-pencil" ></i>
</button>
@endpermission
@permission('Delete Donation')
{!! Form::open([
    'method' => 'DELETE',
    'route' => ['donation.destroy', $donation->id],
    'class' => 'd-inline',
]) !!}
<button type="button" class="btn btn-sm btn-danger show_confirm"  data-confirm="{{ __('Are You Sure?') }}"
data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}"  data-bs-toggle="tooltip" title="{{__('Delete')}}">
    <i class="ti ti-trash"></i>
</button>
{!! Form::close() !!}
@endpermission
</span>

