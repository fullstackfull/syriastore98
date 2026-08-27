<style>
.donation-side-btn {
    width: 52px;
    height: 52px;
    background: linear-gradient(45deg, #ff6b6b, #feca57, #48dbfb, #ff9ff3);
    background-size: 400% 400%;
    border: none !important;
    border-radius: 50% !important;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    box-shadow: 0 10px 25px rgba(255, 107, 107, 0.4);
    transition: all 0.4s ease;
    position: relative;
    overflow: hidden;
    text-decoration: none;
    margin-bottom: 8px;
    animation: gradient-shift 4s ease infinite;
}

@keyframes gradient-shift {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}

.donation-side-btn::before {
    content: '';
    position: absolute;
    top: 2px;
    left: 2px;
    right: 2px;
    bottom: 2px;
    background: linear-gradient(45deg, #ff6b6b, #feca57, #48dbfb, #ff9ff3);
    background-size: 400% 400%;
    border-radius: 50%;
    z-index: -1;
    filter: blur(8px);
    animation: gradient-shift 4s ease infinite;
}

.donation-side-btn:hover {
    transform: translateY(-4px) scale(1.1);
    box-shadow: 0 15px 35px rgba(255, 107, 107, 0.6);
    text-decoration: none;
    animation-duration: 2s;
}

.donation-side-btn i {
    color: white;
    font-size: 20px;
    transition: all 0.4s ease;
    filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.4));
    z-index: 2;
}

.donation-side-btn:hover i {
    transform: scale(1.2) rotateY(360deg);
    filter: drop-shadow(0 3px 6px rgba(0, 0, 0, 0.5));
}

.donation-side-btn::after {
    content: '💝';
    position: absolute;
    top: -8px;
    right: -8px;
    font-size: 16px;
    animation: bounce-gift 3s ease-in-out infinite;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.donation-side-btn:hover::after {
    opacity: 1;
}

@keyframes bounce-gift {
    0%, 100% { transform: translateY(0) rotate(0deg); }
    25% { transform: translateY(-8px) rotate(10deg); }
    50% { transform: translateY(-4px) rotate(-5deg); }
    75% { transform: translateY(-12px) rotate(5deg); }
}

@media (max-width: 768px) {
    .donation-side-btn {
        width: 46px;
        height: 46px;
    }
    
    .donation-side-btn i {
        font-size: 18px;
    }
    
    .donation-side-btn::after {
        font-size: 14px;
        top: -6px;
        right: -6px;
    }
}
</style>

<a href="{{ route('customerdonation.create', $store->slug) }}" class="btn donation-side-btn" 
    data-bs-toggle="tooltip" title="{{ __('Make a Donation') }}">
    <i class="fas fa-donate"></i>
</a>