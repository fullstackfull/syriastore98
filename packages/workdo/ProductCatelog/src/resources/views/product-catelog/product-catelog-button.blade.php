<style>
.product-catelog-side-btn {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
    border: none !important;
    border-radius: 50% !important;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    box-shadow: 0 8px 20px rgba(250, 112, 154, 0.3);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    border: 2px solid rgba(255, 255, 255, 0.2) !important;
    text-decoration: none;
    margin-bottom: 8px;
}

.product-catelog-side-btn::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: conic-gradient(from 0deg, transparent, rgba(255,255,255,0.3), transparent);
    border-radius: 50%;
    opacity: 0;
    animation: catalog-rotate 3s linear infinite;
    transition: opacity 0.3s ease;
}

.product-catelog-side-btn:hover::before {
    opacity: 1;
}

.product-catelog-side-btn:hover {
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 12px 25px rgba(250, 112, 154, 0.4);
    text-decoration: none;
}

.product-catelog-side-btn i {
    color: white;
    font-size: 18px;
    transition: transform 0.3s ease;
    filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.3));
}

.product-catelog-side-btn:hover i {
    transform: scale(1.1) rotateY(180deg);
}

@keyframes catalog-rotate {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

@media (max-width: 768px) {
    .product-catelog-side-btn {
        width: 42px;
        height: 42px;
    }
    
    .product-catelog-side-btn i {
        font-size: 16px;
    }
}
</style>

<a href="javascript:void(0)" class="catelog-request-link product-catelog-side-btn btn" data-ajax-popup="true" data-size="lg"
    data-title="{{ __('Product Catalog') }}" data-url="{{ route('product_catelog_list', $store->slug) }}" data-bs-toggle="tooltip"
    title="{{ __('Product Catalog') }}">
    <i class="fas fa-book-open"></i>
</a>
