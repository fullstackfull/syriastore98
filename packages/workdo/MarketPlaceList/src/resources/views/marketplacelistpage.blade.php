@extends('front_end.layouts.app')
@section('page-title')
    {{ __('MarketPlace List') }}
@endsection
@php
    $lang = app()->getLocale();
@endphp

@section('content')
        <section class="py-10 lg:py-20 bg-gray-50">
            <div class="md:container w-full mx-auto px-4">
                <div class="text-center mb-12">
                    <h2 class="text-2xl lg:text-3xl font-bold text-gray-900 mb-4">{{__('Discover Amazing Stores')}}</h2>
                    <p class="text-gray-600">{{__('Explore our curated collection of premium stores')}}</p>
                </div>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                    @foreach ($stores as $mstore)
                        @php
                            $setting = getAdminAllSetting($mstore->created_by , $mstore->id );
                            $marketBusinessHours = \Workdo\MarketPlaceList\app\Models\StoreBusinessHour::where('store_id', $mstore->id)->first();
                        @endphp
                        <div class="bg-white rounded-lg shadow-md hover:shadow-lg transition-all duration-300 hover:-translate-y-1 overflow-hidden h-full flex flex-col">
                            <div class="relative h-48 overflow-hidden group">
                                @if (!empty($setting['theme_image']))
                                    <img src="{{ get_file($setting['theme_image']) }}" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" alt="{{ $mstore->name }}">
                                @else
                                    <img src="{{ asset('themes/'.$mstore->theme_id.'/theme_img/img_1.png') }}" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" alt="{{ $mstore->name }}">
                                @endif
                                
                                <div class="absolute inset-0 bg-black bg-opacity-80 text-white p-4 opacity-0 group-hover:opacity-100 transition-opacity duration-300 flex flex-col justify-center">
                                    @if (isset($marketBusinessHours) && !empty($marketBusinessHours))
                                        @php
                                            $businessHours = json_decode($marketBusinessHours->content, true);
                                        @endphp
                                        <div class="text-center mb-3">
                                            <h6 class="text-white font-semibold text-sm mb-0">{{__('Business Hours')}}</h6>
                                        </div>
                                        @foreach ($businessHours as $day => $hours)
                                            <div class="flex justify-between items-center mb-2 pb-1 border-b border-white border-opacity-20 last:border-b-0 last:mb-0">
                                                <span class="text-xs font-medium uppercase tracking-wide">{{ ucfirst($day) }}</span>
                                                <span class="text-xs opacity-90">{{ $hours['start_time'] }} - {{ $hours['end_time'] }}</span>
                                            </div>
                                        @endforeach
                                    @else
                                        <p class="text-center text-red-300 italic text-sm">{{ __('No business hours available.')}}</p>
                                    @endif
                                </div>
                            </div>
                            
                            <div class="p-5 flex-1 flex flex-col">
                                <h3 class="text-lg font-semibold text-gray-800 text-center mb-4">{{ ucfirst($mstore->name) }}</h3>
                                
                                <div class="flex gap-2 mt-auto">
                                    <a class="flex-1 bg-primary text-white text-center py-2 px-4 rounded-md text-sm font-medium hover:bg-primary-dark transition-colors duration-200 no-underline" href="{{ route('landing_page', $mstore->slug) }}" target="_blank">
                                        {{ __('Visit Store') }}
                                    </a>
                                    
                                    <button class="bg-gray-100 text-gray-600 hover:bg-gray-200 hover:text-gray-700 p-2 rounded-md transition-colors duration-200 cp_link" 
                                            data-link="{{ route('landing_page', $mstore->slug) }}" 
                                            title="{{ __('Copy Link') }}">
                                        <i class="fas fa-copy text-sm"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
                
                @stack('StoreLocatorAllStoreView')
            </div>
        </section>
@endsection
@push('page-script')
    <script>
        $(document).ready(function() {
            $('.cp_link').on('click', function(event) {
                event.preventDefault();
                var value = $(this).attr('data-link');
                var $temp = $("<input>");
                $("body").append($temp);
                $temp.val(value).select();
                document.execCommand("copy");
                $temp.remove();
                show_toastr('Success', '{{ __('Link copied') }}', 'success');
            });
        });
    </script>
@endpush
