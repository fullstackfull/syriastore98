<div class="countdown-popup-style3 no_scroll">
    <div class="overlay d-none fixed inset-0 bg-black/60 backdrop-blur-sm z-[9998]"></div>

    <div class="countdown-popup d-none fixed inset-0 flex items-center justify-center p-4 z-[9999]">
        <div class="relative bg-white dark:bg-gray-800 rounded-3xl shadow-2xl max-w-lg w-full mx-auto overflow-hidden transform transition-all min-h-[400px]">
            <!-- Close Button -->
            <button class="closePopup absolute top-3 right-3 w-7 h-7 bg-black/20 hover:bg-black/30 rounded-full flex items-center justify-center transition-colors" style="z-index: 9999;">
                <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                </svg>
            </button>

            <!-- Full Background Image -->
            <div class="relative h-64 overflow-hidden"
                 style="background-image: url('{{ !empty($countDown->image) ? asset($countDown->image) : asset('packages/workdo/CountDownTimer/src/resources/assets/images/countdown-bg-img-' . $countDown->theme_layout . '.png') }}'); background-size: cover; background-position: center; background-repeat: no-repeat;">
                <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-black/20 to-transparent"></div>
                
                <!-- Content Overlay -->
                <div class="absolute inset-0 flex flex-col items-center justify-center text-center p-6">
                    <!-- Sale Image -->
                    @if(!empty($countDown->sale_image))
                        <div class="w-20 h-20 mb-4 rounded-2xl overflow-hidden border-4 border-white/30 shadow-2xl backdrop-blur-sm">
                            <img src="{{ asset($countDown->sale_image) }}" alt="Sale" class="w-full h-full object-cover">
                        </div>
                    @endif
                    
                    <!-- Title -->
                    <h2 class="text-white text-2xl font-bold mb-2 drop-shadow-lg">{{ $countDown->title }}</h2>
                    <h3 class="text-white/90 text-lg font-semibold mb-6 drop-shadow">{{ __('Starts In') }}</h3>
                    
                    <!-- Compact Countdown -->
                    <div class="flex items-center justify-center space-x-4">
                        <div class="text-center">
                            <div class="bg-white/20 backdrop-blur-sm rounded-xl px-3 py-2 border border-white/30">
                                <div class="text-2xl font-bold text-white count count-days">00</div>
                                <div class="text-xs text-white/80 uppercase">{{ __('Days') }}</div>
                            </div>
                        </div>
                        <div class="text-white text-xl">:</div>
                        <div class="text-center">
                            <div class="bg-white/20 backdrop-blur-sm rounded-xl px-3 py-2 border border-white/30">
                                <div class="text-2xl font-bold text-white count count-hours">00</div>
                                <div class="text-xs text-white/80 uppercase">{{ __('Hrs') }}</div>
                            </div>
                        </div>
                        <div class="text-white text-xl">:</div>
                        <div class="text-center">
                            <div class="bg-white/20 backdrop-blur-sm rounded-xl px-3 py-2 border border-white/30">
                                <div class="text-2xl font-bold text-white count count-minutes">00</div>
                                <div class="text-xs text-white/80 uppercase">{{ __('Mins') }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Description -->
            @if(!empty($countDown->description))
                <div class="p-6">
                    <p class="text-gray-600 dark:text-gray-400 text-sm leading-relaxed text-center">{{ $countDown->description }}</p>
                </div>
            @endif
        </div>
    </div>
</div>

@push('page-script')
    <script>
        var countDownDateTime = "{{ $countDown->start_date }} {{ $countDown->start_time }}";
        
        $(document).ready(function() {
            $('.countdown-popup-style3 .closePopup').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                $('.countdown-popup-style3').remove();
                $.cookie('countdown_popup', 'closed', { expires: 1, path: '/' });
                return false;
            });
            
            $('.countdown-popup-style3 .overlay').on('click', function(e) {
                if (e.target === this) {
                    $('.countdown-popup-style3').remove();
                    $.cookie('countdown_popup', 'closed', { expires: 1, path: '/' });
                }
            });
        });
    </script>
    <script src="{{ asset('packages/workdo/CountDownTimer/src/resources/assets/js/custom.js') }}"></script>
@endpush