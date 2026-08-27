@php
    $settings = \Workdo\LandingPage\Entities\LandingPageSetting::settings();
@endphp
@if (isset($settings['product_campaign']) && $settings['product_campaign'] == 'on' && $productDetail->where('is_expired', false)->count() > 0)
    <section class=" bg-primary section-gap product-section" id="campaign">
        <div class="container">
            <div class="row mb-2 col-md-12">
                <div class="col-md-6 col-sm-12">
                    <div class="title mb-4">
                        <span
                            class="d-block mb-2 fw-bold text-uppercase">{{ $settings['product_campaign_title'] }}</span>
                        <h2 class="mb-4">{!! $settings['product_campaign_heading'] !!}</h2>
                        <p>{!! $settings['product_campaign_description'] !!}</p>
                    </div>
                </div>
                <div class="col-md-6 col-sm-12">
                    <form id="sortForm">
                    <select name="orderby" id="sort_by" class="form-control category-option custom-select">
                        <option value="">{{ __('Sort By') }}</option>
                        <option value="latest" {{ request('orderby') == 'latest' ? 'selected' : '' }}>
                            {{ __('Latest') }}</option>
                        <option value="popularity" {{ request('orderby') == 'popularity' ? 'selected' : '' }}>
                            {{ __(' Popularity') }}
                        </option>
                    </select>
                    </form>
                </div>
            </div>
            <div class="campaign-card swiper campaign-slider">
                <div class="swiper-wrapper" id="campaign-cards">
                    @foreach ($productDetail as $key => $campaign)
                        <div class="swiper-slide">
                            <div class="card border">
                                <div class="card-body text-center featurs-body">
                                    <div class="card-top">
                                        <div class="avatar-wrp d-flex align-items-center gap-4 mb-4">
                                            @php
                                                $theme_logo = \App\Models\Utility::GetValueByName(
                                                    'theme_logo',
                                                    $campaign->stores->id,
                                                );
                                            @endphp
                                            <span class="theme-avtar avtar avtar-xl landing-product-logo">
                                                <img src="{{ asset(isset($theme_logo) && !empty($theme_logo) ? $theme_logo : 'themes/' . $currentTheme . '/assets/images/logo.png') }}"
                                                    alt="">
                                            </span>
                                            <div style="width: 100%;">
                                                <h4 style="text-align: right;">
                                                    {{ ucFirst($campaign->stores->name) }}
                                                </h4>
                                            </div>

                                        </div>
                                        <div class="d-flex justify-content-between text">
                                            <h6>{{ __('User Name') }}</h6>
                                            <label>
                                                {{ ucFirst($campaign->users->name) }}
                                            </label>
                                        </div>
                                        <div class="d-flex justify-content-between text">
                                            <h6>{{ __('Campaign Name') }}</h6>
                                            <label>
                                                {{ ucFirst($campaign->name) }}
                                            </label>
                                        </div>

                                        <div class="d-flex justify-content-between">
                                            <h5>{{ __('Store Name') }}</h5>
                                            <label>
                                                {{ ucFirst($campaign->stores->name) }}
                                            </label>
                                        </div>
                                        <p>{{ __('Lorem ipsum dolor sit amet consectetur adipiscing elit arcu venenatis integer interdum. ') }}
                                        </p>
                                    </div>
                                    <div class="product-content-bottom">
                                        <!-- Your rating content here -->
                                        <div class="card-bottom d-flex align-items-center justify-content-between">
                                            <div class="card-btn-left d-flex align-items-center gap-2">
                                                <a href="{{ url('/' . $campaign->stores->slug . '/home') }}"
                                                    page-name="Listing Page" target="_blank" class=" btn-preview btn">
                                                    {{ __(' Open store') }}
                                                </a>
                                            </div>
                                            <div class="card-btn-right d-flex align-items-center gap-2">
                                                <a class="" data-bs-toggle="tooltip"
                                                    data-bs-original-title="{{ __('Preview') }}"
                                                    title="{{ __('Preview') }}"
                                                    href="{{ url('/' . $campaign->stores->slug . '/home') }}"
                                                    target="_blank"><span><i class="ti ti-eye"></i></span></a>
                                                    @if (module_is_active('Marketing'))

                                                <a class="" data-bs-toggle="tooltip" data-bs-placement="bottom"
                                                    data-size="md" data-title="{{ __('Contact Detail') }}"
                                                    title="{{ __('Contact Detail') }}"
                                                    data-bs-original-title="{{ __('Contact') }}"
                                                    data-url="{{ route('campaigns.contact', $campaign->id) }}"
                                                    data-ajax-popup="true"><span><i class="ti ti-social"></i></span></a>

                                                <a data-size="md"
                                                    data-url="{{ route('campaigns.share', $campaign->stores->slug) }}"
                                                    data-ajax-popup="true" data-bs-toggle="tooltip"
                                                    title="{{ __('Share') }}" data-title="{{ __('Share') }}"
                                                    class="">
                                                    <i class="ti ti-share"></i> </a>

                                                <a href="" page-name="Listing Page" class=" tooltip-btn ">
                                                    <span class="text-white"><i class="ti ti-download"></i></span></a>
                                                </a>

                                                @endif
                                            </div>

                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="swiper-button-next">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M8 5l7 7-7 7" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <div class="swiper-button-prev">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M16 19l-7-7 7-7" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
            </div>
        </div>
    </section>
    <script src="{{ asset('js/jquery-3.6.0.min.js') }}"></script>

    <script>
        $(document).on("click", "#sort_by", function() {
            var orderBy = $(this).val();
            $.ajax({
                url: '{{ route('campaigns.sort') }}',
                type: 'GET',
                data: {
                    orderby: orderBy,
                    _token: '{{ csrf_token() }}'
                },
                success: function(response) {
                    $('#campaign-cards').empty();
                    $('#campaign-cards').append(response);
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error);
                }
            });
        });
    </script>

@endif
