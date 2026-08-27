<style>
.coupon-list-button {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 50%, #4facfe 100%);
    border: none !important;
    border-radius: 50% !important;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    box-shadow: 0 8px 20px rgba(245, 87, 108, 0.3);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    border: 2px solid rgba(255, 255, 255, 0.2) !important;
    text-decoration: none;
    margin-bottom: 8px;
}

.coupon-list-button::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: conic-gradient(from 0deg, transparent, rgba(255,255,255,0.3), transparent);
    border-radius: 50%;
    opacity: 0;
    animation: coupon-rotate 3s linear infinite;
    transition: opacity 0.3s ease;
}

.coupon-list-button:hover::before {
    opacity: 1;
}

.coupon-list-button:hover {
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 12px 25px rgba(245, 87, 108, 0.4);
    text-decoration: none;
}

.coupon-list-button i {
    color: white;
    font-size: 18px;
    transition: transform 0.3s ease;
    filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.3));
}

@keyframes coupon-rotate {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

@media (max-width: 768px) {
    .coupon-list-button {
        width: 42px;
        height: 42px;
    }
    
    .coupon-list-button i {
        font-size: 16px;
    }
}
</style>

@if (isset($setting['coupon_list_enable']) && $setting['coupon_list_enable'] == 'on')
    <a href="{{route('coupon.list.page',$slug)}}"  class="btn coupon-list-button"  id="coupon-list-button">
        <i class="fas fa-gift"></i>
    </a>
@endif
