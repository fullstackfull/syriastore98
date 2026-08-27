@if (module_is_active('AdditionalCustomFields'))
<div id="product_AdditionalCustomField_tab" class="tab-content hidden">
    <div class="mt-6">
        <!-- Header -->
        <div class="mb-6 text-center">
            <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">
                {{ __('Product Specifications') }}
            </h2>
            <p class="text-gray-600 dark:text-gray-400 text-sm">
                {{ __('Detailed technical specifications and features') }}
            </p>
        </div>

        @forelse ($customFields as $field)
            @if (!empty($field->value) && !empty($field->label))
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 py-4 border-b border-gray-100 dark:border-gray-800 last:border-b-0">
                    <!-- Label -->
                    <div class="md:col-span-1">
                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                            {{ $field->label }}
                        </dt>
                    </div>
                    
                    <!-- Value -->
                    <div class="md:col-span-2">
                        @php $values = json_decode($field->value, true); @endphp
                        @if (is_array($values))
                            <dd class="text-sm text-gray-900 dark:text-white">
                                {{ implode(', ', array_map('ucfirst', $values)) }}
                            </dd>
                        @else
                            <dd class="text-sm text-gray-900 dark:text-white">
                                {{ $field->value }}
                            </dd>
                        @endif
                    </div>
                </div>
            @endif
        @empty
            <div class="text-center py-8">
                <div class="text-gray-400 mb-3">
                    <svg class="w-12 h-12 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                </div>
                <p class="text-gray-500 dark:text-gray-400 text-sm">
                    {{ __('No specifications available') }}
                </p>
            </div>
        @endforelse
    </div>
</div>
@endif