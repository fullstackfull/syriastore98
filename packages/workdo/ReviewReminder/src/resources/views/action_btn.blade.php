@permission('Delete Order Reviews')
<td class="text-end">
    {!! Form::open([
    'method' => 'DELETE',
    'route' => ['orderreview.destroy', $prequest->id],
    'class' => 'd-inline',
]) !!}
    <button type="button" class="btn btn-sm btn-danger show_confirm">
        <i class="ti ti-trash text-white py-1" data-bs-toggle="tooltip" title="{{ __('Delete')}}"></i>
    </button>
    {!! Form::close() !!}
</td>
@endpermission
