<style>
.product-compare-side-btn {
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
}

.product-compare-side-btn::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: conic-gradient(from 0deg, transparent, rgba(255,255,255,0.3), transparent);
    border-radius: 50%;
    opacity: 0;
    animation: compare-rotate 3s linear infinite;
    transition: opacity 0.3s ease;
}

.product-compare-side-btn:hover::before {
    opacity: 1;
}

.product-compare-side-btn:hover {
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 12px 25px rgba(102, 126, 234, 0.4);
    text-decoration: none;
}

.product-compare-side-btn i {
    color: white;
    font-size: 18px;
    transition: transform 0.3s ease;
    filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.3));
}

.product-compare-side-btn:hover i {
    transform: scale(1.1) rotate(10deg);
}

@keyframes compare-rotate {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

@media (max-width: 768px) {
    .product-compare-side-btn {
        width: 42px;
        height: 42px;
    }
    
    .product-compare-side-btn i {
        font-size: 16px;
    }
}
</style>
<a href="javascript:void(0)" class="compare-link product-compare-side-btn btn" 
        data-ajax-popup="true" 
        data-size="lg" 
        data-title="{{ __('Product Compare') }}"
        data-url="{{ route('product_compare_list',$store->slug) }}" 
        data-bs-toggle="tooltip"
        title="{{ __('Product Compare') }}">
        <i class="fa fa-refresh"></i>
    </a>

