<div class="countdown-popup-style1 no_scroll">
    <div class="overlay d-none fixed inset-0 bg-black/50 backdrop-blur-sm z-[9998]"></div>

    <div class="countdown-popup d-none fixed inset-0 flex items-center justify-center p-4 z-[9999]">
        <div class="relative rounded-2xl shadow-2xl max-w-xl w-full mx-auto overflow-hidden transform transition-all min-h-[450px]"
             style="background-image: url('{{ !empty($countDown->image) ? asset($countDown->image) : asset('packages/workdo/CountDownTimer/src/resources/assets/images/countdown-bg-img-' . $countDown->theme_layout . '.png') }}'); background-size: cover; background-position: center; background-repeat: no-repeat;">
            
            <!-- Full Modal Overlay -->
            <div class="absolute inset-0 bg-white/40 dark:bg-gray-800/40"></div>
            
            <!-- Close Button -->
            <button class="closePopup absolute top-4 right-4 w-8 h-8 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 rounded-full flex items-center justify-center transition-colors" style="z-index: 9999;">
                <svg class="w-4 h-4 text-gray-600 dark:text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                </svg>
            </button>

            <!-- Content -->
            <div class="p-6 relative z-10">
                <!-- Sale Image & Title -->
                <div class="text-center mb-6">
                    @if(!empty($countDown->sale_image))
                        <div class="w-16 h-16 mx-auto mb-3 rounded-full overflow-hidden border-4 border-white shadow-lg">
                            <img src="{{ asset($countDown->sale_image) }}" alt="Sale" class="w-full h-full object-cover">
                        </div>
                    @endif
                    <h2 class="text-gray-900 dark:text-white text-xl font-bold mb-1">{{ $countDown->title }}</h2>
                </div>
                <!-- Countdown Timer -->
                <div class="text-center mb-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">{{ __('Starts In') }}</h3>
                    <div class="grid grid-cols-3 gap-2 text-center">
                        <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-3">
                            <div class="text-2xl font-bold text-[var(--primary-color)] count count-days">00</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('Days') }}</div>
                        </div>
                        <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-3">
                            <div class="text-2xl font-bold text-[var(--primary-color)] count count-hours">00</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('Hours') }}</div>
                        </div>
                        <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-3">
                            <div class="text-2xl font-bold text-[var(--primary-color)] count count-minutes">00</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('Minutes') }}</div>
                        </div>
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
            $('.countdown-popup-style1 .closePopup').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                $('.countdown-popup-style1').remove();
                $.cookie('countdown_popup', 'closed', { expires: 1, path: '/' });
                return false;
            });
            
            $('.countdown-popup-style1 .overlay').on('click', function(e) {
                if (e.target === this) {
                    $('.countdown-popup-style1').remove();
                    $.cookie('countdown_popup', 'closed', { expires: 1, path: '/' });
                }
            });
        });
    </script>
    <script src="{{ asset('packages/workdo/CountDownTimer/src/resources/assets/js/custom.js') }}"></script>
@endpush