<span class="d-flex justify-content-end">
 @permission('Edit CountDown Timer')
     <button class="btn btn-sm btn-info btn-badge" data-url="{{ route('countdowntimer.edit', $countDownEntry->id) }}"
         data-size="md" data-ajax-popup="true" data-title="{{ __('Edit Count-Down') }}"  data-bs-toggle="tooltip" title="{{ __('Edit') }}">
         <i class="ti ti-pencil"></i>
     </button>
 @endpermission
 @permission('Delete CountDown Timer')
     {!! Form::open([
         'method' => 'DELETE',
         'route' => ['countdowntimer.destroy', $countDownEntry->id],
         'class' => 'd-inline',
     ]) !!}
     <button type="button" class="btn btn-sm btn-danger show_confirm btn-badge mx-1"  data-confirm="{{ __('Are You Sure?') }}"
     data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}"  data-bs-toggle="tooltip" title="{{ __('Delete') }}">
         <i class="ti ti-trash"></i>
     </button>
     {!! Form::close() !!}
 @endpermission
</span>
