<style>
    .subscribe-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.6);
        z-index: 9999;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
    }
    .subscribe-overlay.open {
        opacity: 1;
        visibility: visible;
    }
    .subscribe-popup {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        z-index: 10000;
        transition: all ease-in-out 0.5s;
    }
    .form-row {
        position: relative;
    }
    .btn-svg {
        position: absolute;
        top: 50%;
        right: 8px;
        transform: translateY(-50%);
    }
    [lang="ar"] .btn-svg {
        left: 8px;
        right: auto;
    }
    [lang="ar"] .btn-svg svg {
        transform: scaleX(-1);
    }
</style>

<div class="subscribe-overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999;"></div>
<div class="subscribe-popup bg-white rounded-2xl shadow-2xl max-w-md w-11/12 mx-auto p-8 text-center relative" style="display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 10000;">
    <button class="close-sub-btn subscribeCloseNotify absolute top-3 right-3 w-8 h-8 bg-gray-200 hover:bg-gray-300 rounded-full flex items-center justify-center transition-all duration-200 hover:rotate-90">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#374151" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="18" y1="6" x2="6" y2="18"></line>
            <line x1="6" y1="6" x2="18" y2="18"></line>
        </svg>
    </button>
    
    <div class="w-16 h-16 bg-primary mx-auto mb-6 rounded-full flex items-center justify-center">
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 8L10.89 13.26C11.2187 13.4793 11.6049 13.5963 12 13.5963C12.3951 13.5963 12.7813 13.4793 13.11 13.26L21 8M5 19H19C19.5304 19 20.0391 18.7893 20.4142 18.4142C20.7893 18.0391 21 17.5304 21 17V7C21 6.46957 20.7893 5.96086 20.4142 5.58579C20.0391 5.21071 19.5304 5 19 5H5C4.46957 5 3.96086 5.21071 3.58579 5.58579C3.21071 5.96086 3 6.46957 3 7V17C3 17.5304 3.21071 18.0391 3.58579 18.4142C3.96086 18.7893 4.46957 19 5 19Z"/>
        </svg>
    </div>
    
    <h2 class="text-2xl font-bold text-gray-900 mb-3">{{__('Stay Updated!')}}</h2>
    <p class="text-gray-600 mb-6">{{__('Subscribe to get exclusive offers and updates delivered to your inbox.')}}</p>
    
    <form action="{{ route('subscribe.store') }}" method="POST" class="subscriber-form" id="subscriber-form">
        @csrf
        <div class="enter-mail form-row mb-4">
            <input type="email" name="email" id="email" placeholder="{{__('Enter your email address')}}" class="w-full px-4 py-3 pr-12 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary outline-none transition-all duration-300" required>
            <input type="hidden" name="store_id" value="{{$store->id}}">
            <button class="btn-svg w-10 h-10 bg-primary hover:bg-primary-dark text-white rounded-lg flex items-center justify-center transition-all duration-300" type="submit">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none">
                    <path d="M2 8H14M14 8L8 2M14 8L8 14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>
        </div>
        
        <div class="thank-u-for bg-green-100 text-green-800 p-4 rounded-lg" style="display: none;">
            <div class="thanku-note flex items-center justify-center gap-2 font-semibold">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none">
                    <path d="M16.25 6.25L8.125 14.375L3.75 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span>{{__('Thank you for subscribing!')}}</span>
            </div>
        </div>
    </form>
</div>

@push('page-script')
<script>
$(document).ready(function() {
    // Show popup after delay if not closed before
    setTimeout(function() {        
        if (document.cookie.indexOf('subscribe_notification=closed') !== -1 || 
            document.cookie.indexOf('subscribed=true') !== -1) {
            return;
        }
        
        $('.subscribe-overlay').show();
        $('.subscribe-popup').show();
    }, 3000);
    
    // Close popup functionality
    $('.subscribeCloseNotify, .subscribe-overlay').on('click', function() {
        closeSubscribePopup();
    });
    
    // Handle form submission
    $('#subscriber-form').on('submit', function(event) {
        event.preventDefault();
        
        $.ajax({
            type: 'POST',
            url: $(this).attr('action'),
            data: $(this).serialize(),
            success: function(response) {
                if (response.success) {
                    $('.thank-u-for').show();
                    $('.enter-mail').hide();
                    
                    $.cookie('subscribed', 'true', {
                        expires: 365,
                        path: '/'
                    });
                    
                    setTimeout(closeSubscribePopup, 2500);
                    $('#loader').fadeOut();
                }
            },
            error: function() {
                alert('{{ __("Something went wrong. Please try again.") }}');
                $('#loader').fadeOut();
            }
        });
    });
    
    function closeSubscribePopup() {
        $('.subscribe-overlay').removeClass('open').hide();
        $('.subscribe-popup').removeClass('open').hide();
        
        $.cookie('subscribe_notification', 'closed', {
            expires: 1,
            path: '/'
        });
    }
});
</script>
@endpush