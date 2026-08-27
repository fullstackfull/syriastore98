<button class="btn btn-sm btn-info" data-url="{{ route('additionalfieldincheckout.edit', $field->id) }}"
    data-size="md" data-ajax-popup="true" data-title="{{ __('Edit Additional Field') }}">
    <i class="ti ti-pencil py-1" data-bs-toggle="tooltip" title="{{ __('Edit') }}"></i>
</button>
{!! Form::open([
    'method' => 'DELETE',
    'route' => ['additionalfieldincheckout.destroy', $field->id],
    'class' => 'd-inline',
]) !!}
<button type="button" class="btn btn-sm btn-danger show_confirm" data-confirm="{{ __('Are You Sure?') }}"
data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}" >
    <i class="ti ti-trash text-white py-1" data-bs-toggle="tooltip" title="{{__('Delete')}}"></i>
</button>
{!! Form::close() !!}
