<?php
    $settings = \Workdo\LandingPage\Entities\LandingPageSetting::settings();
?>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($settings['product_campaign']) && $settings['product_campaign'] == 'on' && $productDetail->where('is_expired', false)->count() > 0): ?>
    <section class=" bg-primary section-gap product-section" id="campaign">
        <div class="container">
            <div class="row mb-2 col-md-12">
                <div class="col-md-6 col-sm-12">
                    <div class="title mb-4">
                        <span
                            class="d-block mb-2 fw-bold text-uppercase"><?php echo e($settings['product_campaign_title']); ?></span>
                        <h2 class="mb-4"><?php echo $settings['product_campaign_heading']; ?></h2>
                        <p><?php echo $settings['product_campaign_description']; ?></p>
                    </div>
                </div>
                <div class="col-md-6 col-sm-12">
                    <form id="sortForm">
                    <select name="orderby" id="sort_by" class="form-control category-option custom-select">
                        <option value=""><?php echo e(__('Sort By')); ?></option>
                        <option value="latest" <?php echo e(request('orderby') == 'latest' ? 'selected' : ''); ?>>
                            <?php echo e(__('Latest')); ?></option>
                        <option value="popularity" <?php echo e(request('orderby') == 'popularity' ? 'selected' : ''); ?>>
                            <?php echo e(__(' Popularity')); ?>

                        </option>
                    </select>
                    </form>
                </div>
            </div>
            <div class="campaign-card swiper campaign-slider">
                <div class="swiper-wrapper" id="campaign-cards">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $productDetail; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $campaign): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <div class="swiper-slide">
                            <div class="card border">
                                <div class="card-body text-center featurs-body">
                                    <div class="card-top">
                                        <div class="avatar-wrp d-flex align-items-center gap-4 mb-4">
                                            <?php
                                                $theme_logo = \App\Models\Utility::GetValueByName(
                                                    'theme_logo',
                                                    $campaign->stores->id,
                                                );
                                            ?>
                                            <span class="theme-avtar avtar avtar-xl landing-product-logo">
                                                <img src="<?php echo e(asset(isset($theme_logo) && !empty($theme_logo) ? $theme_logo : 'themes/' . $currentTheme . '/assets/images/logo.png')); ?>"
                                                    alt="">
                                            </span>
                                            <div style="width: 100%;">
                                                <h4 style="text-align: right;">
                                                    <?php echo e(ucFirst($campaign->stores->name)); ?>

                                                </h4>
                                            </div>

                                        </div>
                                        <div class="d-flex justify-content-between text">
                                            <h6><?php echo e(__('User Name')); ?></h6>
                                            <label>
                                                <?php echo e(ucFirst($campaign->users->name)); ?>

                                            </label>
                                        </div>
                                        <div class="d-flex justify-content-between text">
                                            <h6><?php echo e(__('Campaign Name')); ?></h6>
                                            <label>
                                                <?php echo e(ucFirst($campaign->name)); ?>

                                            </label>
                                        </div>

                                        <div class="d-flex justify-content-between">
                                            <h5><?php echo e(__('Store Name')); ?></h5>
                                            <label>
                                                <?php echo e(ucFirst($campaign->stores->name)); ?>

                                            </label>
                                        </div>
                                        <p><?php echo e(__('Lorem ipsum dolor sit amet consectetur adipiscing elit arcu venenatis integer interdum. ')); ?>

                                        </p>
                                    </div>
                                    <div class="product-content-bottom">
                                        <!-- Your rating content here -->
                                        <div class="card-bottom d-flex align-items-center justify-content-between">
                                            <div class="card-btn-left d-flex align-items-center gap-2">
                                                <a href="<?php echo e(url('/' . $campaign->stores->slug . '/home')); ?>"
                                                    page-name="Listing Page" target="_blank" class=" btn-preview btn">
                                                    <?php echo e(__(' Open store')); ?>

                                                </a>
                                            </div>
                                            <div class="card-btn-right d-flex align-items-center gap-2">
                                                <a class="" data-bs-toggle="tooltip"
                                                    data-bs-original-title="<?php echo e(__('Preview')); ?>"
                                                    title="<?php echo e(__('Preview')); ?>"
                                                    href="<?php echo e(url('/' . $campaign->stores->slug . '/home')); ?>"
                                                    target="_blank"><span><i class="ti ti-eye"></i></span></a>
                                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(module_is_active('Marketing')): ?>

                                                <a class="" data-bs-toggle="tooltip" data-bs-placement="bottom"
                                                    data-size="md" data-title="<?php echo e(__('Contact Detail')); ?>"
                                                    title="<?php echo e(__('Contact Detail')); ?>"
                                                    data-bs-original-title="<?php echo e(__('Contact')); ?>"
                                                    data-url="<?php echo e(route('campaigns.contact', $campaign->id)); ?>"
                                                    data-ajax-popup="true"><span><i class="ti ti-social"></i></span></a>

                                                <a data-size="md"
                                                    data-url="<?php echo e(route('campaigns.share', $campaign->stores->slug)); ?>"
                                                    data-ajax-popup="true" data-bs-toggle="tooltip"
                                                    title="<?php echo e(__('Share')); ?>" data-title="<?php echo e(__('Share')); ?>"
                                                    class="">
                                                    <i class="ti ti-share"></i> </a>

                                                <a href="" page-name="Listing Page" class=" tooltip-btn ">
                                                    <span class="text-white"><i class="ti ti-download"></i></span></a>
                                                </a>

                                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                            </div>

                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
                <div class="swiper-button-next">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M8 5l7 7-7 7" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <div class="swiper-button-prev">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M16 19l-7-7 7-7" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
            </div>
        </div>
    </section>
    <script src="<?php echo e(asset('js/jquery-3.6.0.min.js')); ?>"></script>

    <script>
        $(document).on("click", "#sort_by", function() {
            var orderBy = $(this).val();
            $.ajax({
                url: '<?php echo e(route('campaigns.sort')); ?>',
                type: 'GET',
                data: {
                    orderby: orderBy,
                    _token: '<?php echo e(csrf_token()); ?>'
                },
                success: function(response) {
                    $('#campaign-cards').empty();
                    $('#campaign-cards').append(response);
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error);
                }
            });
        });
    </script>

<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php /**PATH /home/storesyria/public_html/packages/workdo/Campaigns/src/app/Providers/../../resources/views/superAdmin/campaigns.blade.php ENDPATH**/ ?>