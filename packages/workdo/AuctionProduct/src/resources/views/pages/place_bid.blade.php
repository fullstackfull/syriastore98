<style>
.bid-modal-wrapper {
    background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
    border-radius: 20px;
    padding: 2rem;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
    position: relative;
    overflow: hidden;
    max-width: 500px;
    margin: 0 auto;
}

.bid-modal-wrapper::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 6px;
    background: linear-gradient(90deg, #667eea, #764ba2, #f093fb, #f5576c);
    background-size: 300% 100%;
    animation: bid-gradient-flow 3s ease infinite;
}

@keyframes bid-gradient-flow {
    0%, 100% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
}

.bid-header {
    text-align: center;
    margin-bottom: 2rem;
}

.bid-title {
    font-size: 1.75rem;
    font-weight: 700;
    color: #111827;
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
}

.bid-subtitle {
    color: #6b7280;
    font-size: 0.95rem;
    margin: 0;
}

.bid-form-group {
    margin-bottom: 1.5rem;
    position: relative;
}

.bid-form-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 600;
    color: #374151;
    margin-bottom: 0.75rem;
    font-size: 0.95rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.bid-form-label i {
    color: var(--primary-color, #3b82f6);
    font-size: 1rem;
    padding: 6px;
    background: rgba(59, 130, 246, 0.1);
    border-radius: 50%;
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.bid-input-wrapper {
    position: relative;
}

.bid-currency-symbol {
    position: absolute;
    left: 16px;
    top: 50%;
    transform: translateY(-50%);
    font-weight: 600;
    color: #6b7280;
    font-size: 1.1rem;
    z-index: 2;
}

.bid-form-input {
    width: 100%;
    padding: 1rem 1.25rem 1rem 3rem;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    font-size: 1.1rem;
    font-weight: 600;
    background: #ffffff;
    color: #111827;
    box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.02);
    transition: all 0.3s ease;
}

.bid-form-input:focus {
    outline: none;
    border-color: var(--primary-color, #3b82f6);
    box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
    transform: translateY(-2px);
}

.bid-form-input::placeholder {
    color: #9ca3af;
    font-weight: 400;
}

.bid-note {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    border: 1px solid #f59e0b;
    border-radius: 10px;
    padding: 1rem;
    margin-top: 1rem;
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
}

.bid-note-icon {
    color: #d97706;
    font-size: 1.25rem;
    margin-top: 0.125rem;
    flex-shrink: 0;
}

.bid-note-text {
    color: #92400e;
    font-size: 0.875rem;
    font-weight: 500;
    line-height: 1.4;
}

.bid-note-text strong {
    font-weight: 600;
}

.bid-submit-section {
    text-align: center;
    margin-top: 2rem;
    padding-top: 1.5rem;
    border-top: 1px solid #e5e7eb;
}

.bid-submit-btn {
    background: linear-gradient(135deg, var(--primary-color, #3b82f6), var(--accent-color, #8b5cf6));
    color: white;
    border: none;
    padding: 1rem 2.5rem;
    border-radius: 50px;
    font-weight: 700;
    font-size: 1rem;
    cursor: pointer;
    text-transform: uppercase;
    letter-spacing: 1px;
    box-shadow: 0 8px 25px rgba(59, 130, 246, 0.3);
    position: relative;
    overflow: hidden;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.bid-submit-btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
    transition: left 0.6s ease;
}

.bid-submit-btn:hover::before {
    left: 100%;
}

.bid-submit-btn:hover {
    transform: translateY(-3px) scale(1.02);
    background: linear-gradient(135deg, var(--primary-dark, #2563eb), var(--accent-dark, #7c3aed));
    box-shadow: 0 15px 35px rgba(59, 130, 246, 0.4);
}

.bid-submit-btn:active {
    transform: translateY(-1px) scale(0.98);
}

.bid-error {
    color: #dc2626;
    font-size: 0.875rem;
    margin-top: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 500;
}

.bid-error i {
    color: #dc2626;
    font-size: 0.875rem;
}

.current-bid-info {
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.05), rgba(139, 92, 246, 0.05));
    border: 1px solid rgba(59, 130, 246, 0.2);
    border-radius: 12px;
    padding: 1rem;
    margin-bottom: 1.5rem;
    text-align: center;
}

.current-bid-label {
    font-size: 0.875rem;
    color: #6b7280;
    font-weight: 500;
    margin-bottom: 0.25rem;
}

.current-bid-amount {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--primary-color, #3b82f6);
}

@media (max-width: 768px) {
    .bid-modal-wrapper {
        padding: 1.5rem;
        margin: 1rem;
        border-radius: 16px;
    }
    
    .bid-title {
        font-size: 1.5rem;
    }
    
    .bid-form-input {
        padding: 0.875rem 1rem 0.875rem 2.75rem;
        font-size: 1rem;
    }
    
    .bid-submit-btn {
        padding: 0.875rem 2rem;
        font-size: 0.9rem;
    }
}
</style>
<div class="bid-modal-wrapper">
    <div class="bid-header">
        <h2 class="bid-title">
            <svg width="28" height="28" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            {{ __('Place Your Bid') }}
        </h2>
        <p class="bid-subtitle">{{ __('Enter your bid amount to participate in this auction') }}</p>
    </div>

    @if(isset($bid_amount))
    <div class="current-bid-info">
        <div class="current-bid-label">{{ __('Current Highest Bid') }}</div>
        <div class="current-bid-amount">${{ number_format($bid_amount, 2) }}</div>
    </div>
    @endif

    {{Form::model($slug, array('route' => array('place_bid.store' ,$slug), 'method' => 'POST', 'enctype' => 'multipart/form-data', 'class' => 'bid-form')) }}
    
    <div class="bid-form-group">
        <label class="bid-form-label">
            <i class="fas fa-dollar-sign"></i>
            {{ __('Your Bid Amount') }}
        </label>
        <div class="bid-input-wrapper">
            <span class="bid-currency-symbol">$</span>
            {!! Form::number('bid_amount', null, [
                'class' => 'bid-form-input',
                'required' => true,
                'min' => $bid_amount ?? 0,
                'step' => '0.01',
                'placeholder' => 'Enter your bid amount'
            ]) !!}
        </div>
        @error('bid_amount')
            <div class="bid-error">
                <i class="fas fa-exclamation-triangle"></i>
                {{ $message }}
            </div>
        @enderror
    </div>

    <div class="bid-note">
        <i class="fas fa-info-circle bid-note-icon"></i>
        <div class="bid-note-text">
            <strong>{{ __('Note:') }}</strong> {{ __('Your bid must be higher than the current highest bid amount to be accepted.') }}
        </div>
    </div>

    <input type="hidden" name="product_id" value="{{ $product->id ?? null }}">
    <input type="hidden" name="customer_id" value="{{ $customer->id ?? null }}">
    <input type="hidden" name="store_id" value="{{ $product->store_id ?? null }}">

    <div class="bid-submit-section">
        <button type="submit" class="bid-submit-btn">
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
            </svg>
            {{ __('Place Bid') }}
        </button>
    </div>

    {!! Form::close() !!}
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const bidForm = document.querySelector('.bid-form');
    const bidInput = document.querySelector('.bid-form-input');
    const submitBtn = document.querySelector('.bid-submit-btn');
    
    if (bidForm && bidInput && submitBtn) {
        // Add real-time validation
        bidInput.addEventListener('input', function() {
            const minBid = parseFloat(this.min) || 0;
            const currentBid = parseFloat(this.value) || 0;
            
            if (currentBid <= minBid) {
                this.style.borderColor = '#dc2626';
                this.style.boxShadow = '0 0 0 4px rgba(220, 38, 38, 0.1)';
            } else {
                this.style.borderColor = '#10b981';
                this.style.boxShadow = '0 0 0 4px rgba(16, 185, 129, 0.1)';
            }
        });
        
        // Form submission handling
        bidForm.addEventListener('submit', function(e) {
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<svg class="animate-spin w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>{{ __("Placing Bid...") }}';
            submitBtn.disabled = true;
            
            // Reset after 5 seconds if form doesn't submit
            setTimeout(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }, 5000);
        });
    }
});
</script>
