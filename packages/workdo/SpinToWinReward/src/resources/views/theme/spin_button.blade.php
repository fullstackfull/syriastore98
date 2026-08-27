
<style>
.spi-wheel-button {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none !important;
    border-radius: 50% !important;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    border: 2px solid rgba(255, 255, 255, 0.2) !important;
    text-decoration: none;
    margin-bottom: 8px;
    z-index: 7;
}

.spi-wheel-button::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: conic-gradient(from 0deg, transparent, rgba(255,255,255,0.3), transparent);
    border-radius: 50%;
    opacity: 0;
    animation: spin-rotate 3s linear infinite;
    transition: opacity 0.3s ease;
}

.spi-wheel-button:hover::before {
    opacity: 1;
}

.spi-wheel-button:hover {
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 12px 25px rgba(102, 126, 234, 0.4);
    text-decoration: none;
}

.spi-wheel-button i {
    color: white;
    font-size: 18px;
    transition: transform 0.3s ease;
    filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.3));
}

.spi-wheel-button:hover i {
    transform: scale(1.1);
}

@keyframes spin-rotate {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

@media (max-width: 768px) {
    .spi-wheel-button {
        width: 42px;
        height: 42px;
    }
    
    .spi-wheel-button i {
        font-size: 16px;
    }
}
</style>
<button class="spi-wheel-button btn" id="wheel-toggler" data-ajax-popup="true"
    data-size="lg" data-title="{{ __('Try Your Luck') }}" data-url="{{ route('spintowinreward.create', $store->slug) }}"
    data-bs-toggle="tooltip" title="{{ __('Try Your Luck') }}">
    <i class="fas fa-dharmachakra"></i>
</button>

<script src="{{ asset('packages/workdo/SpinToWinReward/src/resources/assets/js/chart.min.js') }}"></script>
<script src="{{ asset('packages/workdo/SpinToWinReward/src/resources/assets/js/chartjs-plugin-datalabels.min.js') }} ">
</script>
