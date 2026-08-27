{!! $setting['tawk_to_messenger'] ?? '' !!}

@push('page-script')
<!-- Additional JavaScript to manipulate the button position -->
<script>
document.addEventListener("DOMContentLoaded", function() {
    // Function to move the button
    function moveTawkToButton() {
        // Wait for the button to be created by the external script
        const interval = setInterval(() => {
            const button = document.querySelector('.widget-visible'); // Adjust selector as needed
            const iframe = document.querySelector('iframe'); // Adjust selector as needed
            if (button) {
                clearInterval(interval);
                // Move the button to the .external-btns div
                const externalBtnsDiv = document.querySelector('.external-btns');
                if (externalBtnsDiv) {
                    externalBtnsDiv.appendChild(button);
                }
                // Apply additional styling if needed
                button.style.setProperty('position', 'relative', 'important'); 
                button.style.setProperty('bottom', '0', 'important'); 
                button.style.setProperty('right', '0', 'important'); 
                if (iframe) {
                    iframe.style.removeProperty('position');
                }
            }
        }, 100); // Check every 100ms
    }

    // Call the function to move the button
    moveTawkToButton();
});
</script>
@endpush