@if (isset($themeSettings['newsletter_status']) && $themeSettings['newsletter_status'] == 1)
    <section class="lg:pb-20 pb-10 bg-gray-50 newsletter-sec">
        <div class="md:container w-full mx-auto px-4">
            <div class="bg-primary flex items-center flex-col md:flex-row justify-between rounded-lg gap-4 lg:p-10 py-6 px-4 text-white">
                <div class="newsletter-content ltr:md:text-left rtl:md:text-right text-center flex-1">
                    <h2 class="text-2xl md:text-3xl font-bold mb-4">{!! $themeSettings['newsletter_title'] ?? __('Join Our Newsletter') !!}</h2>
                    <p class="max-w-[500px] w-full">{!! $themeSettings['newsletter_sub_title'] ?? __('Subscribe to get exclusive offers recipes, and health tips delivered to your inbox.') !!}</p>
                </div>
                <div class="flex gap-3 flex-1 justify-end">
                    <form action="{{ route('newsletter.store', $slug) }}" method="post">
                        <div class="flex gap-3 flex-1 ltr:justify-end rtl:justify-end">
                            @csrf
                            <input name="email" type="email" placeholder="{{ __('enter email') }}" class="form-input rounded-lg max-w-[350px] w-full ltr:pl-4 rtl:pr-4">
                            <button
                                class="bg-white text-black border border-white rounded-lg md:px-5 px-3 md:py-3 py-2 font-semibold hover:bg-transparent hover:text-white transition-all duration-300 ease-in-out">
                                {!! $themeSettings['newsletter_button_text'] ?? __('Subscribe') !!} 
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>
@endif