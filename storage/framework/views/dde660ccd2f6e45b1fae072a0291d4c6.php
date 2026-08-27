<style>
    #stars li.star.hover i {
        color: #fbbf24; /* Tailwind yellow-400 */
    }

    #stars li.star.selected i {
        color: #f59e0b; /* Tailwind yellow-500 */
    }

    .success-box {
        margin-top: 10px;
        padding: 10px;
        display: none;
        background-color: #d1fae5; /* Tailwind green-100 */
        border: 1px solid #10b981; /* Tailwind green-500 */
        border-radius: 0.5rem;
        color: #065f46; /* Tailwind green-800 */
    }
</style>

<a href="javascript:void(0)" class="btn btn-sm btn-primary" data-ajax-popup="true" data-size="md" data-title="<?php echo e(__('Add Review')); ?>"
    data-url="<?php echo e(route('product_review.create', [$slug, $product->slug])); ?>" data-toggle="tooltip" title="<?php echo e(__('Product Review')); ?>">
    <span class="lbl"><?php echo e(__('Add Review')); ?></span>
</a>

<?php $__env->startPush('page-script'); ?>
<script>
    $(document).ready(function(){

        /* 1. Visualizing things on Hover - See next part for action on click */
        $(document).on('mouseover','#stars li', function(){
            var onStar = parseInt($(this).data('value'), 10); // The star currently mouse on

            // Now highlight all the stars that's not after the current hovered star
            $(this).parent().children('li.star').each(function(e){
            if (e < onStar) {
                $(this).addClass('hover');
            }
            else {
                $(this).removeClass('hover');
            }
            });

        }).on('mouseout', function(){
            $(this).parent().children('li.star').each(function(e){
            $(this).removeClass('hover');
            });
        });


        /* 2. Action to perform on click */
        $(document).on('click', '#stars li', function(){
            var onStar = parseInt($(this).data('value'), 10); // The star currently selected
            var stars = $(this).parent().children('li.star');
            for (i = 0; i < stars.length; i++) {
            $(stars[i]).removeClass('selected');
            }

            for (i = 0; i < onStar; i++) {
            $(stars[i]).addClass('selected');
            }

            $('#rating_no').val(onStar);
            // JUST RESPONSE (Not needed)
            var ratingValue = parseInt($('#stars li.selected').last().data('value'), 10);
            var msg = "";
            if (ratingValue > 1) {
                msg = "Thanks! You rated this " + ratingValue + " stars.";
            }
            else {
                msg = "We will improve ourselves. You rated this " + ratingValue + " stars.";
            }
            responseMessage(msg);

        });
    });
    function responseMessage(msg) {
    $('.success-box').fadeIn(200);
    $('.success-box div.text-message').html("<span>" + msg + "</span>");
    }
</script>
<?php $__env->stopPush(); ?>
<?php /**PATH /home/storesyria/public_html/packages/workdo/ProductReview/src/app/Providers/../../resources/views/pages/button.blade.php ENDPATH**/ ?>