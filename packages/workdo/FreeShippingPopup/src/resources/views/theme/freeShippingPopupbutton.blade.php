@php
    $lang = app()->getLocale();
@endphp

<style>
    :root {
        --green-gradient: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        --black-blur-bg: rgba(0, 0, 0, 0.8);
        --glass-blur: blur(8px);
        --popup-bg: linear-gradient(145deg, #ffffffcc, #f9f9f9cc);
    }

    /* Common button style */
    .free-shipping-button-div .free-shipping-button {
        width: 48px;
        height: 48px;
        background: var(--green-gradient);
        border: 2px solid rgba(255, 255, 255, 0.2) !important;
        border-radius: 50% !important;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        box-shadow: 0 8px 20px rgba(40, 167, 69, 0.3);
        position: relative;
        overflow: hidden;
        transition: all 0.3s ease;
        text-decoration: none;
        margin-bottom: 8px;
    }

    .free-shipping-button::before {
        content: '';
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background: conic-gradient(from 0deg, transparent, rgba(255,255,255,0.3), transparent);
        border-radius: 50%;
        opacity: 0;
        animation: shipping-rotate 3s linear infinite;
        transition: opacity 0.3s ease;
    }

    .free-shipping-button:hover::before {
        opacity: 1;
    }

    .free-shipping-button:hover {
        transform: translateY(-2px) scale(1.05);
        box-shadow: 0 12px 25px rgba(40, 167, 69, 0.4);
    }

    .free-shipping-button i {
        color: white;
        font-size: 18px;
        transition: transform 0.3s ease;
        filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.3));
        @if ($lang == 'ar' || $lang == 'he') transform: scaleX(-1); @endif
    }

    .free-shipping-button:hover i {
        transform: scale(1.1) translateX({{ $lang == 'ar' || $lang == 'he' ? '-2px' : '2px' }}) {{ $lang == 'ar' || $lang == 'he' ? 'scaleX(-1)' : '' }};
    }

    @keyframes shipping-rotate {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    /* Modal Container */
    .free-shipping-modal {
        position: fixed !important;
        top: 0 !important;
        left: 0 !important;
        width: 100vw !important;
        height: 100vh !important;
        background: rgba(0, 0, 0, 0.85) !important;
        backdrop-filter: blur(8px);
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        padding: 20px;
        z-index: 999999 !important;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
    }

    .free-shipping-modal.active {
        opacity: 1 !important;
        visibility: visible !important;
    }

    /* Modal Content */
    .free-shipping-modal-content {
        background: white;
        border-radius: 20px;
        max-width: 550px;
        width: 90%;
        position: relative;
        box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
        overflow: hidden;
        transform: scale(0.8);
        transition: transform 0.3s ease;
    }

    .free-shipping-modal.active .free-shipping-modal-content {
        transform: scale(1);
    }

    .free-shipping-modal-close {
        position: absolute;
        top: 15px;
        right: 15px;
        width: 35px;
        height: 35px;
        background: rgba(255, 255, 255, 0.9);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-size: 18px;
        color: #666;
        z-index: 10;
        transition: all 0.3s ease;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }

    .free-shipping-modal-close:hover {
        background: #28a745;
        color: white;
        transform: scale(1.1);
    }

    /* Image Container */
    .free-shipping-modal-image {
        position: relative;
        width: 100%;
        padding-top: 60%;
        overflow: hidden;
    }

    .free-shipping-modal-image img {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    /* Message */
    .free-shipping-modal-massage {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        width: 90%;
        z-index: 5;
    }

    .free-shipping-modal-massage p {
        background: rgba(0, 0, 0, 0.8);
        color: white;
        padding: 15px 25px;
        border-radius: 25px;
        font-size: 18px;
        font-weight: 600;
        margin: 0;
        text-align: center;
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        backdrop-filter: blur(10px);
        border: 2px solid rgba(255, 255, 255, 0.1);
    }

    .free-shipping-modal-massage p b {
        color: #ffd700;
        font-size: 20px;
        font-weight: 800;
    }

    /* Animations */
    @keyframes popUp {
        0% { transform: scale(0.8) rotateX(-10deg); opacity: 0; }
        100% { transform: scale(1) rotateX(0deg); opacity: 1; }
    }

    @keyframes floatY {
        0%, 100% { transform: translateY(0px); }
        50% { transform: translateY(-10px); }
    }

    /* Responsive */
    @media (max-width: 500px) {
        .free-shipping-modal-content {
            padding: 1.5rem;
        }

        .free-shipping-modal-massage p {
            font-size: 17px;
            padding: 12px 16px;
        }

        .free-shipping-modal-massage p b {
            font-size: 19px;
        }
    }
</style>

@if (isset($setting['free_shipping_popup_enabled']) && $setting['free_shipping_popup_enabled'] == 'on' && isset($setting['free_shipping_apply_price']) && $setting['free_shipping_apply_price'] != 0)

    {{-- Button --}}
    <div class="free-shipping-button-div">
        <a href="#" class="btn free-shipping-button" id="free-shipping-button" data-toggle="tooltip" title="Free Shipping Pop-Up">
            <i class="fas fa-truck free-shipping-icon"></i>
        </a>
    </div>

    {{-- Popup --}}
    <div class="free-shipping-modal" id="free-shipping-modal">
        <div class="free-shipping-modal-content">
            <div class="free-shipping-modal-close" id="free-shipping-modal-close-modal">&times;</div>
            <div class="free-shipping-modal-image">
                <img src="{{ asset('packages/workdo/FreeShippingPopup/src/resources/assets/image/free-shipping.png') }}" class="default-img">
                <div class="free-shipping-modal-massage">
                    <p>{{ __('Enjoy Free Shipping When You Spend ') }}<b>{{ currency_format_with_sym(($setting['free_shipping_apply_price'] ?? 0), ($store['id'] ?? null)) ?? SetNumberFormat($setting['free_shipping_apply_price'] ?? 0) }}</b>{{ __(' or More!') }}</p>
                </div>
            </div>
        </div>
    </div>

    @push('page-script')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const freeShippingButton = document.querySelector('.free-shipping-button');
            const freeShippingModal = document.querySelector('.free-shipping-modal');
            const freeShippingModalClose = document.querySelector('.free-shipping-modal-close');

            freeShippingButton.addEventListener('click', function(e) {
                e.preventDefault();
                document.body.appendChild(freeShippingModal);
                freeShippingModal.classList.add('active');
                document.body.classList.add('no-scroll');
            });

            freeShippingModalClose.addEventListener('click', function() {
                freeShippingModal.classList.remove('active');
                document.body.classList.remove('no-scroll');
            });

            window.addEventListener('click', function(event) {
                if (event.target === freeShippingModal) {
                    freeShippingModal.classList.remove('active');
                    document.body.classList.remove('no-scroll');
                }
            });
        });
    </script>
    @endpush
@endif
