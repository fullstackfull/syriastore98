{!! Form::open([
    'method' => 'GET',
    'route' => ['productcatelog.edit', $catelog->id],
    'class' => 'd-inline',
]) !!}
@csrf
<button type="button" class="btn btn-sm btn-info mail_confirm">
    <i class="ti ti-share py-1" data-bs-toggle="tooltip" title="{{__('Share with E-Mail')}}"></i>
</button>
{!! Form::close() !!}
