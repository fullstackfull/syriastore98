@if (isset($themeSettings['service_status']) && $themeSettings['service_status'] == 1)
    <section class="lg:pb-20 pb-10">
        <div class="container mx-auto px-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 text-center">
                @foreach (json_decode($themeSettings['service_repeater'], true) as $service)
                    <div class="card lg:p-6 p-4">
                        <div class="bg-primary/10 rounded-full h-16 w-16 flex items-center justify-center mx-auto mb-4">
                            <img src="{{ get_file($service['image']) }}" alt="{{ $service['title'] }}"
                                class="w-12 h-12 object-contain" loading="lazy">
                        </div>
                        <h3 class="text-lg font-medium mb-2">{!! $service['title'] ?? __('Free Shipping') !!}</h3>
                        <p class="text-gray-600">{!! $service['content'] ?? __('On orders over $100') !!}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
@endif