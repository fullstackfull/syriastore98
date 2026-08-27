 @foreach ($productDetail as $key => $campaign)
     <div class="swiper-slide">
         <div class="card border">
             <div class="card-body text-center featurs-body">
                 <div class="card-top">
                     <div class="avatar-wrp d-flex align-items-center gap-4 mb-4">
                         @php
                             $theme_logo = \App\Models\Utility::GetValueByName(
                                 'theme_logo',
                                 $campaign->stores->theme_id,
                                 $campaign->stores->id,
                             );
                         @endphp
                         <span class="theme-avtar avtar avtar-xl landing-product-logo">
                             <img src="{{ asset(isset($theme_logo) && !empty($theme_logo) ? $theme_logo : 'themes/' . $currentTheme . '/assets/images/logo.png') }}"
                                 alt="">
                         </span>
                         <div style="width: 100%;">
                             <h4 style="text-align: right;">
                                 {{ ucFirst($camuaign->stores->name) }}
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
                             <a href="{{ url('/' . $campaign->stores->slug . '/home') }}" page-name="Listing Page"
                                 target="_blank" class=" btn-preview btn">
                                 {{ __(' Open store') }}
                             </a>
                         </div>
                         <div class="card-btn-right d-flex align-items-center gap-2">
                             <a class="" data-bs-toggle="tooltip" data-bs-original-title="{{ __('Preview') }}"
                                 title="{{ __('Preview') }}" href="{{ url('/' . $campaign->stores->slug . '/home') }}"
                                 target="_blank"><span><i class="ti ti-eye"></i></span></a>

                             <a class="" data-bs-toggle="tooltip" data-bs-placement="bottom" data-size="md"
                                 data-title="{{ __('Contact Detail') }}" title="{{ __('Contact Detail') }}"
                                 data-bs-original-title="{{ __('Contact') }}"
                                 data-url="{{ route('campaigns.contact', $campaign->id) }}"
                                 data-ajax-popup="true"><span><i class="ti ti-social"></i></span></a>

                             <a data-size="md" data-url="{{ route('campaigns.share', $campaign->stores->slug) }}"
                                 data-ajax-popup="true" data-bs-toggle="tooltip" title="{{ __('Share') }}"
                                 data-title="{{ __('Share') }}" class="">
                                 <i class="ti ti-share"></i> </a>

                             <a href="" page-name="Listing Page" class=" tooltip-btn ">
                                 <span class="text-white"><i class="ti ti-download"></i></span></a>
                             </a>

                         </div>

                     </div>
                 </div>
             </div>

         </div>
     </div>
 @endforeach
