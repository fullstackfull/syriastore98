<div id="product_enquiry_tab" class="tab-content hidden">
    <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl shadow-lg p-6 md:p-8 mt-6">
        <div class="mb-6">
            <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">{{ __('Product Enquiry') }}</h3>
            <p class="text-gray-600 dark:text-gray-400 text-sm">{{ __('Have questions about this product? We\'d love to help!') }}</p>
        </div>
        
        <form action="{{ route('product_enquiry.store', $slug) }}" method="POST" class="space-y-6">
            @csrf

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- First Name -->
                <div class="space-y-2">
                    <label for="first_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ __('First Name') }} <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="first_name" id="first_name" required
                        class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-[var(--primary-color)] focus:border-transparent bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 text-sm transition-colors"
                        placeholder="{{ __('Enter your first name') }}">
                </div>

                <!-- Last Name -->
                <div class="space-y-2">
                    <label for="last_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ __('Last Name') }} <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="last_name" id="last_name" required
                        class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-[var(--primary-color)] focus:border-transparent bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 text-sm transition-colors"
                        placeholder="{{ __('Enter your last name') }}">
                </div>

                <!-- Email -->
                <div class="space-y-2">
                    <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ __('Email Address') }} <span class="text-red-500">*</span>
                    </label>
                    <input type="email" name="email" id="email" required
                        class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-[var(--primary-color)] focus:border-transparent bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 text-sm transition-colors"
                        placeholder="{{ __('your.email@example.com') }}">
                </div>

                <!-- Telephone -->
                <div class="space-y-2">
                    <label for="contact" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ __('Phone Number') }} <span class="text-red-500">*</span>
                    </label>
                    <input type="tel" name="contact" id="contact" required
                        class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-[var(--primary-color)] focus:border-transparent bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 text-sm transition-colors"
                        placeholder="{{ __('Enter your phone number') }}">
                </div>
            </div>

            <!-- Comment -->
            <div class="space-y-2">
                <label for="comment" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                    {{ __('Your Message') }} <span class="text-red-500">*</span>
                </label>
                <textarea name="comment" id="comment" rows="4" required
                    class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-[var(--primary-color)] focus:border-transparent bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 text-sm resize-none transition-colors"
                    placeholder="{{ __('Please describe your enquiry or questions about this product...') }}"></textarea>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Please provide as much detail as possible to help us assist you better.') }}
                </p>
            </div>

            <input type="hidden" name="product_id" value="{{ $product->id }}">

            <!-- Submit Button -->
            <div class="flex justify-end pt-4 border-t border-gray-200 dark:border-gray-700">
                <button type="submit"
                    class="inline-flex items-center justify-center gap-2 px-6 py-3 text-sm font-medium text-white bg-[var(--primary-color)] hover:bg-[var(--primary-color)]/90 focus:outline-none focus:ring-2 focus:ring-[var(--primary-color)] focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition-all duration-200 rounded-lg shadow-sm hover:shadow-md disabled:opacity-50 disabled:cursor-not-allowed">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
                    </svg>
                    {{ __('Send Enquiry') }}
                </button>
            </div>
        </form>
    </div>
</div>