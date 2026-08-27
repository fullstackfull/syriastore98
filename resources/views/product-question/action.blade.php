<span class="d-flex gap-1 justify-content-end">
<button class="btn btn-sm btn-primary" data-url="{{ route('product-question.edit', $question->id) }}" data-size="lg"
    data-ajax-popup="true" data-title="{{ __('Reply Product Question') }}" data-bs-toggle="tooltip"
    title="{{ __('Reply') }}">
    <i class="fas fa-share"></i>
</button>
{!! Form::open(['method' => 'DELETE', 'route' => ['product-question.destroy', $question->id], 'class' => 'd-inline']) !!}
<button type="button" class="btn btn-sm btn-danger show_confirm" data-bs-toggle="tooltip" data-confirm="{{ __('Are You Sure?') }}"
data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}" 
title="{{ __('Delete') }}">
    <i class="ti ti-trash"></i>
</button>
{!! Form::close() !!}
</span>
