@extends('layouts.app')
@section('page-title')
    {{ __('Custom Page') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Custom Page') }}</li>
@endsection
@php
    $settings = \Workdo\LandingPage\Entities\LandingPageSetting::settings();
    $logo = get_file('storage/uploads/landing_page_image');
@endphp
@push('custom-script')
    <script src="{{ asset('packages/workdo/LandingPage/src/Resources/assets/js/plugins/tinymce.min.js') }}" referrerpolicy="origin">
    </script>
@endpush
@section('content')
    <div class="row">
        <div class="col-sm-12">
            <div class="card">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col-9">
                            <h5>{{ __('Menu') }}</h5>
                        </div>
                        <div class="col-3 justify-content-end d-flex">
                            <a href="#" data-size="lg" data-url="{{ route('custom_page.create') }}" data-ajax-popup="true"
                                data-bs-toggle="tooltip" title="{{ __('Add Custom Page') }}" data-title="{{ __('Create Custom Page') }}"
                                class="btn-badge btn btn-sm btn-primary">
                                <i class="ti ti-plus"></i>
                            </a>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ __('No') }}</th>
                                    <th>{{ __('Name') }}</th>
                                    <th class="text-end">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @if (is_array($pages) || is_object($pages))
                                    @php
                                        $no = 1;
                                    @endphp
                                    @foreach ($pages as $key => $value)
                                        <tr>
                                            <td>{{ $no++ }}</td>
                                            <td>{{ $value['menubar_page_name'] }}</td>
                                            <td class="">
                                                <span class="d-flex gap-1 justify-content-end">
                                                    <button class="btn btn-sm btn-info btn-badge"
                                                        data-url="{{ route('custom_page.edit', $key) }}" data-size="lg"
                                                        data-ajax-popup="true" data-title="{{ __('Edit Custom Page') }}"  data-bs-toggle="tooltip"
                                                        title="{{ __('Edit') }}">
                                                        <i class="ti ti-pencil"></i>
                                                    </button>
                                                    @if (
                                                        $value['page_slug'] != 'terms_and_conditions' &&
                                                            $value['page_slug'] != 'about_us' &&
                                                            $value['page_slug'] != 'privacy_policy')
                                                        {!! Form::open(['method' => 'DELETE', 'route' => ['custom_page.destroy', $key], 'class' => 'd-inline']) !!}
                                                        <button type="button" class="btn btn-badge mx-1 btn-sm btn-danger show_confirm"  data-bs-toggle="tooltip" data-confirm="{{ __('Are You Sure?') }}"
                                                        data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}"  title="{{ __('Delete') }}">
                                                            <i class="ti ti-trash"></i>
                                                        </button>
                                                        {!! Form::close() !!}
                                                    @endif
                                                </span>
                                            </td>
                                        </tr>
                                    @endforeach
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
