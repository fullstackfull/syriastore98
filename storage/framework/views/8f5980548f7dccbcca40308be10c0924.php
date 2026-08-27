<!-- Floating Action Buttons -->
<div class="floating-action-menu">
    <div class="fab-container">
        <div class="fab-main" id="fabMain">
            <i class="fas fa-plus fab-icon"></i>
            <span class="fab-tooltip">Quick Actions</span>
        </div>
        <div class="fab-options" id="fabOptions">
            <?php echo $__env->yieldPushContent('addCompareButton'); ?>
            <?php echo $__env->yieldPushContent('addCatelogRequestButton'); ?>
            <?php echo $__env->yieldPushContent('SpinwheelButton'); ?>
            <?php echo $__env->yieldPushContent('DonationFormButton'); ?>
            <?php echo $__env->yieldPushContent('freeShippingPopupButton'); ?>
            <?php echo $__env->yieldPushContent('boostSalesModelPopup'); ?>
            <?php echo $__env->yieldPushContent('couponRequestbutton'); ?>
            <?php echo $__env->yieldPushContent('couponListButton'); ?>
        </div>
    </div>
</div>

<!-- WhatsApp & Other External Buttons -->
<div class="external-btns">
    <?php echo $__env->yieldPushContent('CommmetIconView'); ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($adminSetting['whatsapp_setting_enabled']) && !empty($adminSetting['whatsapp_setting_enabled'])): ?>
        <div class="floating-wpp"></div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>

<style>
/* Floating Action Menu */
.floating-action-menu {
    position: fixed;
    left: 20px;
    bottom: 20px;
    z-index: 9999;
    display: none;
}

.fab-container {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
}

.fab-main {
    width: 45px;
    height: 45px;
    background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 50%, #ff9ff3 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    box-shadow: 0 8px 20px rgba(255, 107, 107, 0.3);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    border: 2px solid rgba(255, 255, 255, 0.2);
}

.fab-main::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: conic-gradient(from 0deg, transparent, rgba(255,255,255,0.4), transparent);
    border-radius: 50%;
    opacity: 0;
    animation: rotate 3s linear infinite;
    transition: opacity 0.3s ease;
}

.fab-main:hover::before {
    opacity: 1;
}

.fab-main:hover {
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 12px 25px rgba(255, 107, 107, 0.4);
}

@keyframes rotate {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.fab-icon {
    color: white;
    font-size: 16px;
    transition: transform 0.3s ease;
}

.fab-main.active .fab-icon {
    transform: rotate(45deg);
}

.fab-tooltip {
    position: absolute;
    left: 75px;
    top: 50%;
    transform: translateY(-50%);
    background: rgba(0, 0, 0, 0.8);
    color: white;
    padding: 8px 12px;
    border-radius: 6px;
    font-size: 12px;
    white-space: nowrap;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease;
    pointer-events: none;
}

.fab-tooltip::before {
    content: '';
    position: absolute;
    right: 100%;
    top: 50%;
    transform: translateY(-50%);
    border: 5px solid transparent;
    border-right-color: rgba(0, 0, 0, 0.8);
}

.fab-main:hover .fab-tooltip {
    opacity: 1;
    visibility: visible;
    left: 80px;
}

.fab-options {
    position: absolute;
    bottom: 80px;
    left: 0;
    display: flex;
    flex-direction: column;
    gap: 12px;
    opacity: 0;
    visibility: hidden;
    transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
    pointer-events: none;
    transform: translateY(20px);
}

.fab-options.show {
    opacity: 1;
    visibility: visible;
    pointer-events: all;
    transform: translateY(0);
}

.fab-options > * {
    transform: scale(0) translateY(20px);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.fab-options.show > * {
    transform: scale(1) translateY(0);
}

.fab-options.show > *:nth-child(1) { transition-delay: 0.1s; }
.fab-options.show > *:nth-child(2) { transition-delay: 0.15s; }
.fab-options.show > *:nth-child(3) { transition-delay: 0.2s; }
.fab-options.show > *:nth-child(4) { transition-delay: 0.25s; }
.fab-options.show > *:nth-child(5) { transition-delay: 0.3s; }
.fab-options.show > *:nth-child(6) { transition-delay: 0.35s; }
.fab-options.show > *:nth-child(7) { transition-delay: 0.4s; }

/* External buttons positioning */
[dir="ltr"] .external-btns {
    position: fixed;
    right: 30px;
    bottom: 30px;
    z-index: 9999;
}
[dir="rtl"] .external-btns {
    position: fixed;
    left: 30px;
    bottom: 30px;
    z-index: 9999;
}

/* Mobile Responsive */
@media (max-width: 768px) {
    .floating-action-menu {
        left: 15px;
        bottom: 15px;
    }
    
    [dir="ltr"] .external-btns {
        right: 15px;
        bottom: 15px;
    }
    [dir="rtl"] .external-btns {
        left: 15px;
        bottom: 15px;
    }
    
    .fab-main {
        width: 38px;
        height: 38px;
    }
    
    .fab-icon {
        font-size: 14px;
    }
    
    .fab-tooltip {
        display: none;
    }
}

/* Dark mode support */
@media (prefers-color-scheme: dark) {
    .fab-tooltip {
        background: rgba(255, 255, 255, 0.9);
        color: #333;
    }
    
    .fab-tooltip::before {
        border-right-color: rgba(255, 255, 255, 0.9);
    }
}
</style>

<?php $__env->startPush('page-script'); ?>
<script>
    $(document).ready(function() {
        // Check if fab-options contains any children
        if ($('#fabOptions').children().length > 0) {
            $('.floating-action-menu').show();
        } else {
            $('.floating-action-menu').hide();
        }
        
        // Floating Action Button Toggle
        $('#fabMain').click(function(e) {
            e.preventDefault();
            
            const fabOptions = $('#fabOptions');
            const fabMain = $(this);
            
            // Toggle the options visibility
            fabOptions.toggleClass('show');
            fabMain.toggleClass('active');
            
            // Add ripple effect
            createRipple(e, this);
        });
        
        // Close FAB when clicking outside
        $(document).click(function(e) {
            if (!$(e.target).closest('.fab-container').length) {
                $('#fabOptions').removeClass('show');
                $('#fabMain').removeClass('active');
            }
        });
        
        // Ripple effect function
        function createRipple(event, element) {
            const circle = document.createElement('span');
            const diameter = Math.max(element.clientWidth, element.clientHeight);
            const radius = diameter / 2;
            
            const rect = element.getBoundingClientRect();
            circle.style.width = circle.style.height = diameter + 'px';
            circle.style.left = (event.clientX - rect.left - radius) + 'px';
            circle.style.top = (event.clientY - rect.top - radius) + 'px';
            circle.classList.add('ripple');
            
            const ripple = element.getElementsByClassName('ripple')[0];
            if (ripple) {
                ripple.remove();
            }
            
            element.appendChild(circle);
            
            // Add ripple CSS if not exists
            if (!document.getElementById('ripple-style')) {
                const style = document.createElement('style');
                style.id = 'ripple-style';
                style.textContent = `
                    .ripple {
                        position: absolute;
                        border-radius: 50%;
                        background: rgba(255, 255, 255, 0.3);
                        transform: scale(0);
                        animation: ripple-animation 0.6s linear;
                        pointer-events: none;
                    }
                    @keyframes ripple-animation {
                        to {
                            transform: scale(4);
                            opacity: 0;
                        }
                    }
                `;
                document.head.appendChild(style);
            }
        }
    });
</script>
<?php $__env->stopPush(); ?><?php /**PATH /home/storesyria/public_html/resources/views/front_end/hooks/footer_link.blade.php ENDPATH**/ ?>