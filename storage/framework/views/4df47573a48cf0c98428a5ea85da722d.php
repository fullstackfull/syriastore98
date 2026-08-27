
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($product->variant_product == 1): ?>
            <div class="flex flex-wrap items-center gap-5 mb-5">
                    <?php
                        $variant = json_decode($product->product_attribute);
                        $varint_name_array = [];
                        if (!empty($product->DefaultVariantData->variant)) {
                            $varint_name_array = explode('-', $product->DefaultVariantData->variant);
                        }
                    ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $variant; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $value): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <?php
                            $p_variant = App\Models\Utility::ProductAttribute($value->attribute_id);
                            $attribute = json_decode($p_variant);
                            $propertyKey = 'for_variation_' . $attribute->id;
                            $variation_option = $value->$propertyKey;
                        ?>
                              <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($variation_option == 1): ?>
                                <div class="flex items-center gap-2">
                                    <div class="product-labl mb-0 inline_lable font-semibold"><?php echo e($attribute->name.__(" : ")); ?></div>
                                        <div class="inline_contant">
                                        <select data-product="<?php echo e($product->id); ?>" class="custom-select-btn product_variatin_option variant_loop radio-btn rounded-[6px]"  name="varint[<?php echo e($attribute->name); ?>]">
                                                <?php
                                                    $optionValues = [];
                                                ?>

                                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $value->values; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $variant1): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                                    <?php
                                                        $parts = explode('|', $variant1);
                                                    ?>
                                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $parts; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $p): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                                        <?php
                                                            $id = App\Models\ProductAttributeOption::where('id', $p)->first();
                                                            if (isset($id->terms)) {
                                                                $optionValues[] = $id->terms;
                                                            }
                                                        ?>
                                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                <option value="">
                                                    <?php echo e(__('Select an option')); ?>

                                                </option>

                                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(is_array($optionValues)): ?>
                                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $optionValues; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $optionValue): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                                        <option><?php echo e($optionValue); ?></option>
                                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                            </select>
                                    </div>
                                </div>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?><?php /**PATH /home/storesyria/public_html/resources/views/front_end/common/product/variant.blade.php ENDPATH**/ ?>