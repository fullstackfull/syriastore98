<div class="form-group col-lg-2 col-md-4 col-sm-6">
    <div class="form-check form-switch p-0">
        <?php echo Form::hidden('enable_auction_product', 'off'); ?>

        <label class="form-check-label form-label" for="enable_auction_product"><?php echo e(__('Enable Auction Product')); ?></label>
        <input type="checkbox" class="form-check-input" data-toggle="switchbutton" data-onstyle="primary" name="enable_auction_product" id="enable_auction_product" <?php echo e(isset($setting['enable_auction_product']) && $setting['enable_auction_product'] == 'on' ? 'checked="checked"' : ''); ?>>
    </div>
</div>
<?php /**PATH /home/storesyria/public_html/packages/workdo/AuctionProduct/src/app/Providers/../../resources/views/pages/auctionproductbutton.blade.php ENDPATH**/ ?>