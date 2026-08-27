<div class="countdown-popup-style4 no_scroll">
    <div class="overlay d-none fixed inset-0 bg-black/50 backdrop-blur-sm z-[9998]"></div>

    <div class="countdown-popup d-none fixed inset-0 flex items-center justify-center p-4 z-[9999]">
        <div class="relative rounded-2xl shadow-2xl max-w-xl w-full mx-auto overflow-hidden transform transition-all min-h-[500px]"
             style="background-image: url('{{ !empty($countDown->image) ? asset($countDown->image) : asset('packages/workdo/CountDownTimer/src/resources/assets/images/countdown-bg-img-' . $countDown->theme_layout . '.png') }}'); background-size: cover; background-position: center; background-repeat: no-repeat;">
            
            <!-- Overlay -->
            <div class="absolute inset-0 bg-gradient-to-br from-orange-600/80 to-red-700/80"></div>
            
            <!-- Close Button -->
            <button class="closePopup absolute top-4 right-4 w-8 h-8 bg-white/20 hover:bg-white/30 rounded-full flex items-center justify-center transition-colors backdrop-blur-sm" style="z-index: 9999;">
                <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                </svg>
            </button>

            <!-- Content -->
            <div class="relative z-10 p-8 text-center text-white">
                <!-- Sale Image -->
                @if(!empty($countDown->sale_image))
                    <div class="w-24 h-24 mx-auto mb-6 rounded-full overflow-hidden border-4 border-white/30 shadow-2xl">
                        <img src="{{ asset($countDown->sale_image) }}" alt="Sale" class="w-full h-full object-cover">
                    </div>
                @endif

                <!-- Title -->
                <h2 class="text-2xl font-bold mb-2 drop-shadow-lg">{{ $countDown->title }}</h2>
                <h3 class="text-xl font-semibold mb-8 text-white/90 drop-shadow">{{ __('Starts In') }}</h3>

                <!-- Countdown Timer -->
                <div class="grid grid-cols-3 gap-4 mb-8">
                    <div class="text-center">
                        <div class="bg-white/20 backdrop-blur-sm rounded-2xl p-4 border border-white/30 shadow-lg">
                            <div class="text-3xl font-bold count count-days">00</div>
                            <div class="text-sm uppercase tracking-wide opacity-90">{{ __('Days') }}</div>
                        </div>
                    </div>
                    <div class="text-center">
                        <div class="bg-white/20 backdrop-blur-sm rounded-2xl p-4 border border-white/30 shadow-lg">
                            <div class="text-3xl font-bold count count-hours">00</div>
                            <div class="text-sm uppercase tracking-wide opacity-90">{{ __('Hours') }}</div>
                        </div>
                    </div>
                    <div class="text-center">
                        <div class="bg-white/20 backdrop-blur-sm rounded-2xl p-4 border border-white/30 shadow-lg">
                            <div class="text-3xl font-bold count count-minutes">00</div>
                            <div class="text-sm uppercase tracking-wide opacity-90">{{ __('Minutes') }}</div>
                        </div>
                    </div>
                </div>

                <!-- Additional Time Units (Smaller) -->
                <div class="flex justify-center space-x-4 mb-6">
                    <div class="text-center">
                        <div class="bg-white/10 rounded-lg px-3 py-2">
                            <div class="text-lg font-bold count count-months">00</div>
                            <div class="text-xs opacity-75">{{ __('Months') }}</div>
                        </div>
                    </div>
                    <div class="text-center">
                        <div class="bg-white/10 rounded-lg px-3 py-2">
                            <div class="text-lg font-bold count count-weeks">00</div>
                            <div class="text-xs opacity-75">{{ __('Weeks') }}</div>
                        </div>
                    </div>
                    <div class="text-center">
                        <div class="bg-white/10 rounded-lg px-3 py-2">
                            <div class="text-lg font-bold count count-seconds">00</div>
                            <div class="text-xs opacity-75">{{ __('Seconds') }}</div>
                        </div>
                    </div>
                </div>

                <!-- Description -->
                @if(!empty($countDown->description))
                    <p class="text-white/90 text-sm leading-relaxed drop-shadow">{{ $countDown->description }}</p>
                @endif
            </div>
        </div>
    </div>
</div>

@push('page-script')
    <script>
        var countDownDateTime = "{{ $countDown->start_date }} {{ $countDown->start_time }}";
        
        $(document).ready(function() {
            $('.countdown-popup-style4 .closePopup').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                $('.countdown-popup-style4').remove();
                $.cookie('countdown_popup', 'closed', { expires: 1, path: '/' });
                return false;
            });
            
            $('.countdown-popup-style4 .overlay').on('click', function(e) {
                if (e.target === this) {
                    $('.countdown-popup-style4').remove();
                    $.cookie('countdown_popup', 'closed', { expires: 1, path: '/' });
                }
            });
        });
    </script>
    <script src="{{ asset('packages/workdo/CountDownTimer/src/resources/assets/js/custom.js') }}"></script>
@endpush