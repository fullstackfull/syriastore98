<div class="mt-6">
    <!-- Header -->
    <div class="mb-6 text-center">
        <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">
            {{ __('Wholesale Pricing') }}
        </h2>
        <p class="text-gray-600 dark:text-gray-400 text-sm">
            {{ __('Volume-based pricing tiers for bulk purchases') }}
        </p>
    </div>

    @if($wholesale_data->isEmpty())
        <!-- Empty State -->
        <div class="text-center py-12">
            <svg class="w-16 h-16 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
            </svg>
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">
                {{ __('No Wholesale Pricing') }}
            </h3>
            <p class="text-gray-600 dark:text-gray-400">
                {{ __('No wholesale pricing tiers are available for this product.') }}
            </p>
        </div>
    @else
        <!-- Pricing Table -->
        <div class="overflow-x-auto">
            <table class="w-full border-collapse">
                <thead>
                    <tr class="border-b-2 border-gray-200 dark:border-gray-700">
                        <th class="text-left text-sm font-semibold text-gray-700 dark:text-gray-300 pb-3 pr-6">
                            {{ __('Min Quantity') }}
                        </th>
                        <th class="text-left text-sm font-semibold text-gray-700 dark:text-gray-300 pb-3 pr-6">
                            {{ __('Max Quantity') }}
                        </th>
                        <th class="text-left text-sm font-semibold text-gray-700 dark:text-gray-300 pb-3">
                            {{ __('Price Per Unit') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($wholesale_data as $index => $data)
                        <tr class="border-b border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
                            <td class="py-4 pr-6">
                                <div class="flex items-center">
                                    <span class="inline-flex items-center justify-center w-8 h-8 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 rounded-full text-sm font-medium mr-3">
                                        {{ $index + 1 }}
                                    </span>
                                    <span class="font-medium text-gray-900 dark:text-white">
                                        {{ number_format($data->min_qty) }}
                                    </span>
                                </div>
                            </td>
                            <td class="py-4 pr-6">
                                <span class="text-gray-700 dark:text-gray-300">
                                    {{ $data->max_qty ? number_format($data->max_qty) : __('Unlimited') }}
                                </span>
                            </td>
                            <td class="py-4">
                                <span class="font-semibold text-gray-900 dark:text-white text-lg">
                                    {{ $data->per_product_price }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <!-- Pricing Info -->
        <div class="mt-6 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-200 dark:border-blue-800">
            <div class="flex items-start gap-3">
                <svg class="w-5 h-5 text-blue-500 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                </svg>
                <div>
                    <h4 class="text-sm font-medium text-blue-900 dark:text-blue-100 mb-1">
                        {{ __('Wholesale Pricing Information') }}
                    </h4>
                    <p class="text-xs text-blue-700 dark:text-blue-200 leading-relaxed">
                        {{ __('Prices automatically adjust based on quantity. Higher quantities unlock better rates. Contact us for custom pricing on larger orders.') }}
                    </p>
                </div>
            </div>
        </div>
    @endif
</div>