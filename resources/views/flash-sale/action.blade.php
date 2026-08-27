<span class="d-flex gap-1 justify-content-end">
@permission('Edit Flash Sale')
<button class="btn btn-sm btn-badge btn-info"
    data-url="{{ route('flash-sale.edit', $flashsale->id) }}" data-size="lg"
    data-ajax-popup="true" data-title="{{ __('Edit Flash Sale') }}" data-bs-toggle="tooltip"
    title="{{ __('Edit') }}">
    <i class="ti ti-pencil"></i>
</button>
@endpermission
@permission('Delete Flash Sale')
{!! Form::open(['method' => 'DELETE', 'route' => ['flash-sale.destroy',$flashsale->id], 'class' => 'd-inline']) !!}
<button type="button" class="btn btn-sm btn-danger btn-badge mr-1 show_confirm" data-bs-toggle="tooltip" data-confirm="{{ __('Are You Sure?') }}"
data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}" 
title="{{ __('Delete') }}">
    <i class="ti ti-trash"></i>
</button>
{!! Form::close() !!}
@endpermission
</span>
