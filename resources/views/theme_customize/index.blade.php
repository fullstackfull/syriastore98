@extends('layouts.app')

@section('page-title', __('Themes'))

@section('breadcrumb')
 <li class="breadcrumb-item">{{ __('Themes') }}</li>
@endsection


@section('content')
<div class="tab-content mb-4">
    <div class="tab-pane fade show active" id="theme-setting" role="tabpanel" aria-labelledby="theme-setting-tab">
        <div class="card p-3 mb-0">
            <div class="card-body p-0">
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="theme-layout" role="tabpanel"
                        aria-labelledby="theme-layout-tab">
                        <div class="row row-gap-2">
                             @foreach ($themes as $folder)
                                @if (!in_array($folder, $addons))
                                    @continue
                                @endif
                                <div class="col-xxl-3 col-lg-4 col-sm-6 col-12 business-view-card">
                                    <label for="theme-1">
                                        <div class="business-view-inner">
                                            <div class="buisness-img mb-3">
                                                <img src="{{ asset('themes/'.$folder.'/theme_img/img_1.png') }}" alt="theme-img" loading="lazy">
                                            </div>
                                            <div class="d-flex align-items-center justify-content-between gap-2">
                                                <h2 class="h5 mb-0 text-break">{{ ucfirst($folder) }}</h2>
                                                 <div class="d-flex align-items-center justify-content-between gap-1">
                                                    <a class="btn btn-sm btn-primary text-end" href="{{ route('theme.pages',$folder) }}">
                                                    {{ __('Customize') }}
                                                    </a>
                                                    @if (APP_THEME() != $folder)
                                                        {!! Form::open(['method' => 'POST', 'route' => ['theme-preview.make-active'], 'class' => 'd-inline']) !!}
                                                            @csrf
                                                            <input type="hidden" name="theme_id" value="{{ $folder }}">
                                                            <button type="submit" class="btn btn-sm btn-outline-primary text-end" {{ (APP_THEME()  == $folder ? 'disabled' : '') }}>
                                                            {{ __('Make Active') }}
                                                            </button>
                                                        {!! Form::close() !!}
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('custom-script')
<script type="text/javascript">
    $(document).on('keyup','#theme-search', function() {
        var value = $(this).val().toLowerCase();
        $('#theme-list .theme-item').filter(function() {
            $(this).toggle($(this).find('.theme-card-lable').text().toLowerCase().indexOf(value) > -1)
        });
    });
</script>
@endpush
