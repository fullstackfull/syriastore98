@extends('layouts.app')

@section('page-title', __('Theme Customize'))

@section('breadcrumb')
<li class="ss-breadcrumb__item"><a class="ss-breadcrumb__link" href="{{ route('theme.index') }}">{{ __('Themes') }}</a></li>
<li class="ss-breadcrumb__item"><a class="ss-breadcrumb__link" href="{{ route('theme.pages', $theme) }}">{{ __('Pages') }}</a></li>
<li aria-current="page" class="ss-breadcrumb__item">{{ __('Customize') }}</li>
@endsection


@section('content')
<div class="card">
    <div class="card-header p-3">
        <h2 class="h3">{{ ucfirst($theme) }}</h2>
        <span>{{ __('Organize and adjust all settings about') }} {{ $theme }}.</span>
    </div>
    <div class="card-body p-3">
        <div class="row row-gap-2">
            <div class="col-xxl-2 col-sm-6 col-12">
                <div class="card mb-0">
                    <div class="card-header p-3">
                        <h4 class="mb-0">{{ __('Jump To Page') }}</h4>
                    </div>
                    <div class="card-body setting-tab p-3">
                        <ul class="nav nav-pills flex-column gap-1">
                            
                            @foreach ($page_json as $page)
                            @if(isset($page['is_order']) && $page['is_order'] == true)
                            @php 
                                if (empty($orders)) {
                                    $orders = $page['orders'];
                                }
                            @endphp
                            <li class="nav-item">
                                <a href="{{ route('theme.customize.order', [$theme, $page['slug']]) }}"
                                    class="nav-link {{ $page['slug'] == $slug ? 'active' : '' }}">{{ $page['title'] }}</a>
                            </li>
                            @endif
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>

            <div class="col-xxl-10 col-12">
                <div class="card mb-0">
                    <div class="card-header p-3">
                        <h4 class="mb-0">{{ __('Change :page Page Order', ['page' => ucfirst($slug)]) }}</h4>
                    </div>
                     {!! Form::open(['route' => ['theme.customize.update.order', $theme], 'enctype' => 'multipart/form-data']) !!}
                    @csrf
                    <div class="card-body setting-tab p-3">
                        <input type="hidden" name="page_slug" value="{{ $slug }}" class="ui-sortable-handle">
                        <input type="hidden" name="orders" value="{{ $orders }}" id="hidden_order" class="ui-sortable-handle">
                        <ul class="list-unstyled list-group sortable ui-sortable">
                            @foreach (explode(',', $orders) as $order)
                            <li class="list-group-item d-flex align-items-center justify-content-between ui-sortable-handle"
                                data-id="{{ $order }}" style="">
                                <h6 class="mb-0">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round" class="feather feather-move me-3">
                                        <polyline points="5 9 2 12 5 15"></polyline>
                                        <polyline points="9 5 12 2 15 5"></polyline>
                                        <polyline points="15 19 12 22 9 19"></polyline>
                                        <polyline points="19 9 22 12 19 15"></polyline>
                                        <line x1="2" y1="12" x2="22" y2="12"></line>
                                        <line x1="12" y1="2" x2="12" y2="22"></line>
                                    </svg>
                                    <span>{{ ucfirst(str_replace('_', ' ', $order)) }}</span>
                                </h6>
                            </li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="card-footer p-3">
                        {!! Form::button(__('Save Changes'), ['type' => 'submit', 'class' => 'btn btn-primary']) !!}
                    </div>
                     {!! Form::close() !!}
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://demo.workdo.io/vcardgo-saas/custom/libs/jquery-ui/jquery-ui.js"></script>
<script>
   $(function() {
       $(".sortable").sortable();
       $(".sortable").disableSelection();
       $(".sortable").sortable({
           stop: function() {
               var order = [];
               $(this).find('li').each(function(index, data) {
                   order[index] = $(data).attr('data-id');
               });
               $('#hidden_order').val(order);

           }
       });
       var block_order = [];
       $(".sortable").find('li').each(function(index, data) {
           block_order[index] = $(data).attr('data-id');
       });
       $('#hidden_order').val(block_order);
   });
</script>
@endpush