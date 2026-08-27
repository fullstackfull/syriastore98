@extends('layouts.app')

@section('page-title', __('Attribute-Option'))

@section('action-button')
@if($attribute)
@permission('Create Attributes Option')
<div class=" text-end d-flex all-button-box justify-content-md-end justify-content-center">
    <a href="#" class="btn btn-sm btn-primary add_attribute btn-badge" data-ajax-popup="true" data-size="md"
        data-title="{{ __('Add Attribute Option') }}"
        data-url="{{ route('product-attribute-option.create',$attribute->id) }}"
        data-bs-toggle="tooltip" title="{{ __('Add Attribute') }}">
        <i class="ti ti-plus"></i>
    </a>
</div>
@endpermission
@endif
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('product-attributes.index') }}">{{ __('Attribute') }}</a></li>
    <li class="breadcrumb-item">{{ __('Attribute-options') }}</li>
@endsection
@section('content')
<div class="row justify-content-center">
    <div class="col-sm-12 col-md-10 col-xxl-8">
        <div class="card mt-5">
            <div class="card-body">
                <div class="tab-content" id="pills-tabContent">
                    @php($i=0)
                    @foreach ($attribute_option as $key => $option)
                    <div class="tab-pane fade show  @if($i==0) active @endif" role="tabpanel">
                            <ul class="list-unstyled list-group sortable stage">
                                @foreach ($attribute_option as $option)
                                    <li class="d-flex align-items-center gap-1 justify-content-between list-group-item p-3" data-id="{{$option->id}}">
                                        <h6 class="mb-0 text-break flex-1 d-flex  align-items-center">
                                            <i class="me-3 ti ti-arrows-maximize " data-feather="move"></i>
                                            <span class="flex-1">{{ $option->terms }}</span>
                                        </h6>
                                        <span class="float-end">
                                            @permission('Edit Attributes Option')
                                            <button class="btn btn-sm btn-info"
                                                data-url="{{ route('product-attribute-option.edit', $option->id) }}"
                                                data-size="md" data-ajax-popup="true"
                                                data-title="{{ __('Edit Attribute Option') }}" data-bs-toggle="tooltip" title="{{ __('Edit') }}">
                                                <i class="ti ti-pencil"></i>
                                            </button>
                                            @endpermission
                                            @permission('Delete Attributes Option')
                                            {!! Form::open(['method' => 'DELETE', 'route' => ['product-attribute-option.destroy', $option->id], 'class' => 'd-inline']) !!}
                                                <button type="button" class="btn btn-sm btn-danger show_confirm" data-bs-toggle="tooltip" data-confirm="{{ __('Are You Sure?') }}"
                                                data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}"  title="{{ __('Delete') }}">
                                                    <i class="ti ti-trash"></i>
                                                </button>
                                            {!! Form::close() !!}
                                            @endpermission
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                        @php($i++)
                    @endforeach
                </div>
                <p class=" mt-4"><strong>{{__('Note')}} : </strong><b>{{__('You can easily change attribute option of attribute using drag & drop.')}}</b></p>
            </div>
        </div>
    </div>
</div>


@endsection

@push('custom-script')
    <script src="{{asset('public/js/jquery-ui.min.js')}}"></script>
    <script>
        $(function () {
            $(".sortable").sortable();
            $(".sortable").disableSelection();
            $(".sortable").sortable({
                stop: function () {
                    var terms = [];
                    $(this).find('li').each(function (index, data) {
                        terms[index] = $(data).attr('data-id');
                    });

                    $.ajax({
                        url: "{{route('attribute-option')}}",
                        data: {terms: terms, _token: $('meta[name="csrf-token"]').attr('content')},
                        type: 'POST',
                        success: function (data) {
                            $('#loader').fadeOut();
                        },
                        error: function (data) {
                            data = data.responseJSON;
                            $('#loader').fadeOut();
                            show_toastr('Error', data.error, 'error')
                        }

                    })
                }
            });
        });
    </script>
@endpush
