{!! $setting['wizzchat_messenger'] ?? '' !!}
<style>
    .sb-active .sb-body {
        right: 70px;
    }
</style>
@push('page-script')
<!-- Additional JavaScript to manipulate the button position -->
<script>
document.addEventListener("DOMContentLoaded", function() {
    // Function to move the button
    function moveWizzchatButton() {
        // Wait for the button to be created by the external script
        const interval = setInterval(() => {
            const button = document.querySelector('.sb-chat-btn'); // Adjust selector as needed
            const wizzchatBody = document.querySelector('.sb-body'); // Adjust selector as needed
            if (button) {
                clearInterval(interval);
                // Get the external button container
                const externalBtnsDiv = document.querySelector('.external-btns');
                if (externalBtnsDiv) {
                    // Append the button to the external button container
                    externalBtnsDiv.appendChild(button);
                    // Apply additional styling if needed
                    button.style.position = 'relative'; // Adjust position style as needed
                    button.style.bottom = '0'; // Adjust position style as needed
                    button.style.right = '0'; // Adjust position style as needed
                    if (wizzchatBody) {
                        wizzchatBody.style.right = '70'; // Adjust position style as needed
                    }
                } else {
                    console.log(".external-btns container not found");
                }
            }
        }, 100); // Check every 100ms
    }

    // Call the function to move the button
    moveWizzchatButton();
});
</script>
@endpush