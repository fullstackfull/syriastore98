<style>
#char-count.text-red-500 { color: #ef4444; }
</style>

<div class="p-6">
    <!-- Header -->
    <div class="text-center mb-6">
        <div class="w-16 h-16 bg-primary mx-auto mb-4 rounded-full flex items-center justify-center">
            <i class="fas fa-book text-white text-2xl"></i>
        </div>
        <h2 class="text-2xl font-bold text-gray-900 mb-2">{{ __('Request Product Catalog') }}</h2>
        <p class="text-gray-600">{{ __('Get detailed information about our products') }}</p>
    </div>

    <!-- Form -->
    <form class="catelog-request-form space-y-4" action="{{ route('productcatelog.store') }}" method="post">
        @csrf
        @php
            $MainCategoryList = \App\Models\Category::with('product_details')->where('status', 1)->where('store_id', $store_id)->get();
        @endphp

        @guest('customers')
        <!-- Category and Email in single row -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="space-y-2">
                <label class="flex items-center gap-2 text-sm font-semibold text-gray-700">
                    <div class="w-6 h-6 bg-primary bg-opacity-20 rounded-full flex items-center justify-center">
                        <i class="fas fa-tags text-primary text-xs"></i>
                    </div>
                    {{ __('Category') }} <span class="text-red-500">*</span>
                </label>
                <select name="category_id" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary outline-none transition-all duration-300" required>
                    <option value="">{{ __('Choose category...') }}</option>
                    @foreach ($MainCategoryList as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="space-y-2">
                <label class="flex items-center gap-2 text-sm font-semibold text-gray-700">
                    <div class="w-6 h-6 bg-primary bg-opacity-20 rounded-full flex items-center justify-center">
                        <i class="fas fa-envelope text-primary text-xs"></i>
                    </div>
                    {{ __('Email') }} <span class="text-red-500">*</span>
                </label>
                <input type="email" name="email" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary outline-none transition-all duration-300" placeholder="{{ __('Enter email') }}" required>
            </div>
        </div>
        @else
        <!-- Category only for logged in users -->
        <div class="space-y-2">
            <label class="flex items-center gap-2 text-sm font-semibold text-gray-700">
                <div class="w-6 h-6 bg-primary bg-opacity-20 rounded-full flex items-center justify-center">
                    <i class="fas fa-tags text-primary text-xs"></i>
                </div>
                {{ __('Select Category') }} <span class="text-red-500">*</span>
            </label>
            <select name="category_id" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary outline-none transition-all duration-300" required>
                <option value="">{{ __('Choose a category...') }}</option>
                @foreach ($MainCategoryList as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </select>
        </div>
        @endguest

        <!-- Requirements -->
        <div class="space-y-2">
            <label class="flex items-center gap-2 text-sm font-semibold text-gray-700">
                <div class="w-6 h-6 bg-primary bg-opacity-20 rounded-full flex items-center justify-center">
                    <i class="fas fa-comment-alt text-primary text-xs"></i>
                </div>
                {{ __('Additional Requirements') }} <span class="text-red-500">*</span>
            </label>
            <textarea name="description" rows="3" maxlength="500" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary outline-none transition-all duration-300 resize-none" placeholder="{{ __('Tell us about your requirements...') }}" required></textarea>
            <div class="text-right text-sm text-gray-500">
                <span id="char-count">0</span>/500
            </div>
        </div>

        <input type="hidden" name="store_id" value="{{ $store_id }}">

        <!-- Submit -->
        <div class="text-center pt-4">
            <button type="submit" class="w-full bg-primary hover:bg-primary-dark text-white py-3 rounded-lg font-semibold transition-all duration-300 flex items-center justify-center gap-2">
                <i class="fas fa-paper-plane"></i>
                {{ __('Send Request') }}
            </button>
            <p class="mt-3 text-sm text-gray-500">
                {{ __('We\'ll send you a catalog within 24 hours') }}
            </p>
        </div>
    </form>
</div>

<script>
$(document).ready(function () {
    $('textarea[name="description"]').on('input', function () {
        const current = $(this).val().length;
        $('#char-count').text(current);
        if (current > 400) {
            $('#char-count').addClass('text-red-500').removeClass('text-gray-500');
        } else {
            $('#char-count').addClass('text-gray-500').removeClass('text-red-500');
        }
    });

    $('.catelog-request-form').on('submit', function () {
        const btn = $('button[type="submit"]');
        const originalText = btn.html();
        btn.html('<i class="fas fa-spinner fa-spin"></i> {{ __('Sending...') }}');
        btn.prop('disabled', true);
        setTimeout(() => {
            btn.html(originalText);
            btn.prop('disabled', false);
        }, 3000);
    });
});
</script>
