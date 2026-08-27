<style>
@keyframes float {
    0%, 100% { transform: translateY(0px); }
    50% { transform: translateY(-10px); }
}
.floating-icon {
    animation: float 3s ease-in-out infinite;
}
.floating-icon:nth-child(2) { animation-delay: 1s; }
.floating-icon:nth-child(3) { animation-delay: 2s; }
</style>

<div class="p-6">
        <!-- Title -->
        <div class="text-center mb-6">
            <div class="w-16 h-16 bg-primary mx-auto mb-4 rounded-full flex items-center justify-center">
                <i class="fas fa-file-pdf text-white text-2xl"></i>
            </div>
            <h3 class="text-2xl font-bold text-gray-900 mb-2">{{ __('Request PDF Voucher') }}</h3>
            <p class="text-gray-600">{{ __('Get exclusive discount vouchers delivered to your email') }}</p>
        </div>

        <!-- Form -->
        <form method="POST" action="{{ route('coupencode.request.store', $slug) }}" class="space-y-4">
            @csrf

            <!-- Full Name -->
            <div class="space-y-2">
                <label class="flex items-center gap-2 text-sm font-semibold text-gray-700">
                    <div class="w-6 h-6 bg-primary bg-opacity-20 rounded-full flex items-center justify-center">
                        <i class="fas fa-user text-primary text-xs"></i>
                    </div>
                    {{ __('Full Name') }}
                </label>
                <input type="text" name="name" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary outline-none transition-all duration-300" placeholder="{{ __('Enter your full name') }}" required>
            </div>

            <!-- Email -->
            <div class="space-y-2">
                <label class="flex items-center gap-2 text-sm font-semibold text-gray-700">
                    <div class="w-6 h-6 bg-primary bg-opacity-20 rounded-full flex items-center justify-center">
                        <i class="fas fa-envelope text-primary text-xs"></i>
                    </div>
                    {{ __('Email Address') }}
                </label>
                <input type="email" name="email" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary outline-none transition-all duration-300" placeholder="{{ __('Enter your email address') }}" required>
                <div class="flex items-center gap-2 text-sm text-primary">
                    <i class="fas fa-info-circle"></i>
                    <span>{{ __("We'll send your voucher details to this email") }}</span>
                </div>
            </div>

            <!-- Special Requirements -->
            <div class="space-y-2">
                <label class="flex items-center gap-2 text-sm font-semibold text-gray-700">
                    <div class="w-6 h-6 bg-primary bg-opacity-20 rounded-full flex items-center justify-center">
                        <i class="fas fa-comment-alt text-primary text-xs"></i>
                    </div>
                    {{ __('Special Requirements') }}
                </label>
                <textarea name="detail" rows="4" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary outline-none transition-all duration-300 resize-vertical" placeholder="{{ __('Tell us about your specific needs or preferences...') }}" required></textarea>
            </div>

            <!-- Submit -->
            <div class="text-center pt-4">
                <button type="submit" class="w-full bg-primary hover:bg-primary-dark text-white py-3 rounded-lg font-semibold transition-all duration-300 flex items-center justify-center gap-2">
                    <i class="fas fa-paper-plane"></i>
                    {{ __('Request Voucher') }}
                </button>
                <p class="mt-3 text-sm text-gray-500">
                    {{ __("You'll receive your voucher within 24 hours") }}
                </p>
            </div>
        </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('form');
    const submitBtn = document.querySelector('button[type="submit"]');

    form.addEventListener('submit', function () {
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> {{ __("Sending...") }}';
        submitBtn.disabled = true;

        setTimeout(() => {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }, 3000);
    });
});
</script>
