<div class="countdown-popup-style5 no_scroll">
    <div class="overlay d-none fixed inset-0 bg-black/60 backdrop-blur-sm z-[9998]"></div>

    <div class="countdown-popup d-none fixed inset-0 flex items-center justify-center p-4 z-[9999]">
        <div class="relative rounded-3xl shadow-2xl max-w-2xl w-full mx-auto overflow-hidden transform transition-all min-h-[550px]"
             style="background-image: url('{{ !empty($countDown->image) ? asset($countDown->image) : asset('packages/workdo/CountDownTimer/src/resources/assets/images/countdown-bg-img-' . $countDown->theme_layout . '.png') }}'); background-size: cover; background-position: center; background-repeat: no-repeat;">
            
            <!-- Animated Background Layers -->
            <div class="absolute inset-0 bg-gradient-to-br from-indigo-600/70 via-purple-600/70 to-pink-600/70"></div>
            <div class="absolute top-0 left-0 w-32 h-32 bg-white/10 rounded-full -translate-x-16 -translate-y-16 animate-pulse"></div>
            <div class="absolute bottom-0 right-0 w-24 h-24 bg-white/5 rounded-full translate-x-12 translate-y-12 animate-pulse delay-1000"></div>
            
            <!-- Close Button -->
            <button class="closePopup absolute top-4 right-4 w-8 h-8 bg-white/20 hover:bg-white/30 rounded-full flex items-center justify-center transition-all backdrop-blur-sm" style="z-index: 9999;">
                <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                </svg>
            </button>

            <!-- Content -->
            <div class="relative z-10 p-8">
                <!-- Header Section -->
                <div class="text-center mb-8">
                    @if(!empty($countDown->sale_image))
                        <div class="w-20 h-20 mx-auto mb-4 rounded-2xl overflow-hidden border-4 border-white/30 shadow-2xl">
                            <img src="{{ asset($countDown->sale_image) }}" alt="Sale" class="w-full h-full object-cover">
                        </div>
                    @else
                        <div class="mb-4">
                            <div class="inline-block bg-white/20 backdrop-blur-sm rounded-2xl px-6 py-3 border border-white/30">
                                <span class="text-white/90 text-sm font-medium uppercase tracking-wide">{{ __('Festival Offer') }}</span>
                            </div>
                            <h3 class="text-white text-3xl font-bold mt-2 drop-shadow-lg">{{ __('Big Sale') }}</h3>
                        </div>
                    @endif
                </div>

                <!-- Title & Countdown Label -->
                <div class="text-center mb-6">
                    <h2 class="text-white text-xl font-bold mb-2 drop-shadow-lg">{{ $countDown->title }}</h2>
                    <h3 class="text-white/90 text-lg font-semibold drop-shadow">{{ __('Starts In') }}</h3>
                </div>

                <!-- Main Countdown -->
                <div class="grid grid-cols-3 gap-3 mb-6">
                    <div class="text-center">
                        <div class="bg-white/20 backdrop-blur-md rounded-2xl p-4 border border-white/30 shadow-xl">
                            <div class="text-2xl font-bold text-white count count-days">00</div>
                            <div class="text-xs text-white/80 uppercase tracking-wide">{{ __('Days') }}</div>
                        </div>
                    </div>
                    <div class="text-center">
                        <div class="bg-white/20 backdrop-blur-md rounded-2xl p-4 border border-white/30 shadow-xl">
                            <div class="text-2xl font-bold text-white count count-hours">00</div>
                            <div class="text-xs text-white/80 uppercase tracking-wide">{{ __('Hours') }}</div>
                        </div>
                    </div>
                    <div class="text-center">
                        <div class="bg-white/20 backdrop-blur-md rounded-2xl p-4 border border-white/30 shadow-xl">
                            <div class="text-2xl font-bold text-white count count-minutes">00</div>
                            <div class="text-xs text-white/80 uppercase tracking-wide">{{ __('Minutes') }}</div>
                        </div>
                    </div>
                </div>

                <!-- Extended Time Units -->
                <div class="grid grid-cols-3 gap-2 mb-6">
                    <div class="text-center">
                        <div class="bg-white/10 backdrop-blur-sm rounded-lg p-2 border border-white/20">
                            <div class="text-lg font-bold text-white count count-months">00</div>
                            <div class="text-xs text-white/70">{{ __('Months') }}</div>
                        </div>
                    </div>
                    <div class="text-center">
                        <div class="bg-white/10 backdrop-blur-sm rounded-lg p-2 border border-white/20">
                            <div class="text-lg font-bold text-white count count-weeks">00</div>
                            <div class="text-xs text-white/70">{{ __('Weeks') }}</div>
                        </div>
                    </div>
                    <div class="text-center">
                        <div class="bg-white/10 backdrop-blur-sm rounded-lg p-2 border border-white/20">
                            <div class="text-lg font-bold text-white count count-seconds">00</div>
                            <div class="text-xs text-white/70">{{ __('Seconds') }}</div>
                        </div>
                    </div>
                </div>

                <!-- Description -->
                @if(!empty($countDown->description))
                    <div class="text-center">
                        <p class="text-white/90 text-sm leading-relaxed drop-shadow">{{ $countDown->description }}</p>
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
            $('.countdown-popup-style5 .closePopup').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                $('.countdown-popup-style5').remove();
                $.cookie('countdown_popup', 'closed', { expires: 1, path: '/' });
                return false;
            });
            
            $('.countdown-popup-style5 .overlay').on('click', function(e) {
                if (e.target === this) {
                    $('.countdown-popup-style5').remove();
                    $.cookie('countdown_popup', 'closed', { expires: 1, path: '/' });
                }
            });
        });
    </script>
    <script src="{{ asset('packages/workdo/CountDownTimer/src/resources/assets/js/custom.js') }}"></script>
@endpush