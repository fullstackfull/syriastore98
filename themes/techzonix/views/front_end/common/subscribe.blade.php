@if (isset($themeSettings['newsletter_status']) && $themeSettings['newsletter_status'] == 1)
<form class="footer-subscribe-form" action="{{ route('newsletter.store', $slug) }}" method="post">
    @csrf
    <section class="lg:pb-20 pb-10">
        <div class="container mx-auto px-4">
            <div class="max-w-xl mx-auto text-center">
                <h2 class="text-2xl lg:text-3xl font-bold text-gray-900 mb-4">
                    {!! $themeSettings['newsletter_title'] ?? __('Stay in the Loop') !!}</h2>
                <p class="text-gray-600 mb-6">
                    {!! $themeSettings['newsletter_sub_title'] ?? __('Subscribe to our newsletter to get updates on our latest offers and tech news.') !!}
                </p>
                <div class="flex flex-col sm:flex-row gap-3">
                    <input type="email" name="email" placeholder="Your email address" class="form-input" value="{{ old('email') }}">
                    <button class="btn-primary">
                        {{ $themeSettings['newsletter_button'] ?? __('Subscribe Now') }}
                    </button>
                </div>
            </div>
        </div>
    </section>
</form>
@endif