<div class="countdown-popup-style2 no_scroll">
    <div class="overlay d-none fixed inset-0 bg-black/50 backdrop-blur-sm z-[9998]"></div>

    <div class="countdown-popup d-none fixed inset-0 flex items-center justify-center p-4 z-[9999]">
        <div class="relative rounded-2xl shadow-2xl max-w-2xl w-full mx-auto overflow-hidden transform transition-all min-h-[500px]"
             style="background-image: url('{{ !empty($countDown->image) ? asset($countDown->image) : asset('packages/workdo/CountDownTimer/src/resources/assets/images/countdown-bg-img-' . $countDown->theme_layout . '.png') }}'); background-size: cover; background-position: center; background-repeat: no-repeat;">
            
            <!-- Full Modal Overlay -->
            <div class="absolute inset-0 bg-white/40 dark:bg-gray-800/40"></div>
            
            <!-- Close Button -->
            <button class="closePopup absolute top-4 right-4 w-8 h-8 bg-white/90 dark:bg-gray-700/90 hover:bg-white dark:hover:bg-gray-600 rounded-full flex items-center justify-center transition-colors shadow-lg" style="z-index: 9999;">
                <svg class="w-4 h-4 text-gray-600 dark:text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                </svg>
            </button>

            <!-- Content -->
            <div class="p-6 relative z-10">
                <!-- Sale Image & Date Badge -->
                <div class="flex items-center justify-between mb-6">
                    @if(!empty($countDown->sale_image))
                        <div class="w-16 h-16 rounded-xl overflow-hidden border-4 border-white shadow-lg bg-white">
                            <img src="{{ asset($countDown->sale_image) }}" alt="Sale" class="w-full h-full object-cover">
                        </div>
                    @endif
                    
                    @php
                        $parsed_date = \Carbon\Carbon::parse($countDown->start_date);
                        $formatted_date = $parsed_date->format('d F');
                    @endphp
                    <div class="bg-[var(--primary-color)] text-white px-4 py-2 rounded-full text-sm font-semibold shadow-lg">
                        {{ $formatted_date }}
                    </div>
                </div>

                <!-- Title -->
                <div class="text-center mb-6">
                    <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-2">{{ $countDown->title }}</h2>
                    <h3 class="text-lg font-semibold text-gray-700 dark:text-gray-300">{{ __('Starts In') }}</h3>
                </div>

                <!-- Countdown Grid -->
                <div class="grid grid-cols-6 gap-2 mb-6">
                    <div class="text-center">
                        <div class="bg-gradient-to-b from-gray-50 to-gray-100 dark:from-gray-700 dark:to-gray-800 rounded-lg p-2 shadow-sm">
                            <div class="text-lg font-bold text-[var(--primary-color)] count count-months">00</div>
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('Months') }}</div>
                    </div>
                    <div class="text-center">
                        <div class="bg-gradient-to-b from-gray-50 to-gray-100 dark:from-gray-700 dark:to-gray-800 rounded-lg p-2 shadow-sm">
                            <div class="text-lg font-bold text-[var(--primary-color)] count count-weeks">00</div>
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('Weeks') }}</div>
                    </div>
                    <div class="text-center">
                        <div class="bg-gradient-to-b from-gray-50 to-gray-100 dark:from-gray-700 dark:to-gray-800 rounded-lg p-2 shadow-sm">
                            <div class="text-lg font-bold text-[var(--primary-color)] count count-days">00</div>
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('Days') }}</div>
                    </div>
                    <div class="text-center">
                        <div class="bg-gradient-to-b from-gray-50 to-gray-100 dark:from-gray-700 dark:to-gray-800 rounded-lg p-2 shadow-sm">
                            <div class="text-lg font-bold text-[var(--primary-color)] count count-hours">00</div>
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('Hours') }}</div>
                    </div>
                    <div class="text-center">
                        <div class="bg-gradient-to-b from-gray-50 to-gray-100 dark:from-gray-700 dark:to-gray-800 rounded-lg p-2 shadow-sm">
                            <div class="text-lg font-bold text-[var(--primary-color)] count count-minutes">00</div>
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('Mins') }}</div>
                    </div>
                    <div class="text-center">
                        <div class="bg-gradient-to-b from-gray-50 to-gray-100 dark:from-gray-700 dark:to-gray-800 rounded-lg p-2 shadow-sm">
                            <div class="text-lg font-bold text-[var(--primary-color)] count count-seconds">00</div>
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('Secs') }}</div>
                    </div>
                </div>

                <!-- Description -->
                @if(!empty($countDown->description))
                    <div class="text-center">
                        <p class="text-gray-600 dark:text-gray-400 text-sm leading-relaxed">{{ $countDown->description }}</p>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

@push('page-script')
    <script>
        var countDownDateTime = "{{ $countDown->start_date }} {{ $countDown->start_time }}";
        
        $(document).ready(function() {
            $('.countdown-popup-style2 .closePopup').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                $('.countdown-popup-style2').remove();
                $.cookie('countdown_popup', 'closed', { expires: 1, path: '/' });
                return false;
            });
            
            $('.countdown-popup-style2 .overlay').on('click', function(e) {
                if (e.target === this) {
                    $('.countdown-popup-style2').remove();
                    $.cookie('countdown_popup', 'closed', { expires: 1, path: '/' });
                }
            });
        });
    </script>
    <script src="{{ asset('packages/workdo/CountDownTimer/src/resources/assets/js/custom.js') }}"></script>
@endpush