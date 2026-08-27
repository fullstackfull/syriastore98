<style>
.coupon-btn {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
    border: none !important;
    border-radius: 50% !important;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
    text-decoration: none;
    margin-bottom: 8px;
    border: 3px solid rgba(255, 255, 255, 0.3) !important;
}

.coupon-btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
    transition: left 0.6s ease;
}

.coupon-btn:hover::before {
    left: 100%;
}

.coupon-btn::after {
    content: '';
    position: absolute;
    top: -2px;
    left: -2px;
    right: -2px;
    bottom: -2px;
    background: linear-gradient(45deg, #667eea, #764ba2, #f093fb, #667eea);
    border-radius: 50%;
    z-index: -1;
}

@keyframes rotate-border {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.coupon-btn:hover {
    transform: translateY(-3px) scale(1.08);
    box-shadow: 0 15px 40px rgba(102, 126, 234, 0.5);
    text-decoration: none;
}

.coupon-btn i {
    color: white;
    font-size: 20px;
    transition: all 0.4s ease;
    filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.3));
    z-index: 2;
}

.coupon-btn:hover i {
    transform: scale(1.2) rotateY(180deg);
    filter: drop-shadow(0 3px 6px rgba(0, 0, 0, 0.4));
}

@media (max-width: 768px) {
    .coupon-btn {
        width: 44px;
        height: 44px;
    }
    
    .coupon-btn i {
        font-size: 18px;
    }
}
</style>

<a href="javascript:void(0)" class="coupon-btn btn" data-ajax-popup="true" data-size="lg"
    data-title="<?php echo e(__('Request PDF Voucher')); ?>" data-url="<?php echo e(route('coupencode.get', $slug)); ?>" data-bs-toggle="tooltip"
    title="<?php echo e(__('Request PDF Voucher')); ?>">
    <i class="fas fa-file-pdf"></i>
</a><?php /**PATH /home/storesyria/public_html/packages/workdo/PDFVouchers/src/app/Providers/../../resources/views/coupon/tab.blade.php ENDPATH**/ ?>