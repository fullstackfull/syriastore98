@if (count($combinations[0]) > 0)
    <div class="card-body">
        <div class="faq" id="accordionExample">
            <div class="row">
                <div class="col-12">
                        @foreach ($combinations as $key => $combination)
                            @php
                                $sku = $product_name;

                                $str = '';
                                foreach ($combination as $key => $item) {
                                    if ($key > 0) {
                                        $str .= '-' . str_replace(' ', '', $item);
                                        $sku .= '-' . str_replace(' ', '', $item);
                                    } else {
                                        $str .= str_replace(' ', '', $item);
                                        $sku .= '-' . str_replace(' ', '', $item);
                                    }
                                }
                                /* F163. FIVE HARDCODED ids WERE REPEATED FOR EVERY VARIANT in this
                                   view -- `COD` (a leftover from a payment-gateway template),
                                   `enable_manage_stock`, `download-product`, `downloadable_product`
                                   and `down_product` -- and one of them had a `<label for>` pointing
                                   at it. With N variants on the page there were N elements sharing
                                   the id, and a label activates the FIRST, so clicking the third
                                   variant's "downloadable product" label opened the file picker for
                                   the FIRST variant and the chosen file landed on the wrong
                                   variant's field. They are suffixed with `$safe_str` below.
                                   Checked before changing them: NOTHING selects any of these five
                                   ids -- not this view, not the sibling views, not public/js, not
                                   public/assets/js. The JavaScript keys on the CLASSES
                                   (`.enable_manage_stock`, `.down_product_<term>`,
                                   `.manageble_stock_<term>`), which are untouched.

                                   F73. A CSS-SAFE id THAT IS ALSO UNIQUE.
                                   `preg_replace('/[^a-zA-Z0-9_-]/', '_', $str)` maps every special
                                   character to `_`, so two terms differing only in one -- `M/L` and
                                   `M.L` -- collapse to the same `M_L`. That put two elements on the
                                   page with the SAME `id="collapseone_M_L"` and the same
                                   `data-bs-target`, so opening one opened the wrong panel, and any
                                   class-based lookup matched both.
                                   The loop index makes it injective by construction rather than
                                   probabilistically: this value is only ever a DOM identifier
                                   within one rendered page (the handler above now uses `closest()`,
                                   and the form field names use the RAW `$str`), so it needs no
                                   stability across requests. The readable prefix is kept because it
                                   is what makes the DOM legible when something goes wrong. */
                                $safe_str = preg_replace('/[^a-zA-Z0-9_-]/', '_', $str) . '_' . $loop->index;
                                $option = \App\Models\ProductAttributeOption::where('terms', $str)->first();
                            @endphp
                            <div class="accordion accordion-flush" id="">
                                <div id="" class="accordion-item card attribute_options_datas ">
                                    @if (strlen($str) > 0)
                                    <div class="accordion-item card remove_option_{{ $safe_str }}">
                                            <h2 class="accordion-header" id="variant_head_{{ $safe_str }}">
                                                <button class="accordion-button collapsed according-delete-input" type="button"
                                                    data-bs-toggle="collapse" data-bs-target="#collapseone_{{ $safe_str }}"
                                                    aria-expanded="false" aria-controls="collapseone_{{ $safe_str }}">
                                                    <span class="d-flex align-items-center">
                                                        <i class="ti ti-credit-card me-2"></i>{{ $str }}
                                                            <a href="#" class="btn btn-sm btn-danger delete_option" data-id="{{ $safe_str }}">
                                                                <i class="ti ti-trash"></i>
                                                            </a>
                                                    </span>
                                                </button>
                                            </h2>
                                            <div id="collapseone_{{ $safe_str }}" class="accordion-collapse collapse"
                                                aria-labelledby="variant_head_{{ $safe_str }}" data-bs-parent="#accordionExample">
                                                <div class="accordion-body">
                                                    <div class="row">
                                                        <div class="form-group col-md-3">
                                                            <div class="form-check">
                                                                <input class="form-check-input input-primary enable_view" value="enabled" type="checkbox" name="variation_option_{{ $str }}[]" id="enable_view_{{ $str }}">
                                                                <label class="form-check-label" for="enable_view_{{ $str }}">{{__('Enabled')}}</label>
                                                            </div>
                                                        </div>
                                                        <div class="form-group col-md-3">
                                                            <div class="form-check">
                                                                <input class="form-check-input input-primary downloadable" value="downloadable_product" name="variation_option_{{ $str }}[]" type="checkbox" id="downloadable_product_{{ $str }}">
                                                                <label class="form-check-label downloadable" for="downloadable_product_{{ $str }}">{{__('Downloadable')}}</label>
                                                            </div>
                                                        </div>
                                                        <div class="form-group col-md-3">
                                                            <div class="form-check">
                                                                <input class="form-check-input input-primary virtual_product" value="virtual_product" type="checkbox" name="variation_option_{{ $str }}[]" id="virtual_product_{{ $str }}">
                                                                <label class="form-check-label" for="virtual_product_{{ $str }}">{{__('Virtual')}}</label>
                                                            </div>
                                                        </div>
                                                        <div class="form-group col-md-3">
                                                            <div class="form-check">
                                                                <input class="form-check-input input-primary manage_stock" value="manage_stock" name="variation_option_{{ $str }}[]" type="checkbox" id="manage_stock_{{ $str }}">
                                                                <label class="form-check-label" for="manage_stock_{{ $str }}">{{__('Manage stock?')}}</label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="row">
                                                        <div class="form-group col-md-6 sku">
                                                            {!! Form::label('', __('SKU'), ['class' => 'form-label']) !!}
                                                            {!! Form::text('product_sku_' . $str, $sku, ['class' => 'form-control']) !!}
                                                        </div>
                                                        <div class="form-group col-md-6 weight-div ">
                                                            {!! Form::label('', __('Weight(Kg)'), ['class' => 'form-label ']) !!}
                                                            {!! Form::number('product_weight_' . $str, null, ['class' => 'form-control', 'min' => '0', 'step' => '0.01']) !!}
                                                        </div>
                                                    </div>
                                                    <div class="row">
                                                        <div class="form-group col-md-6">
                                                            {!! Form::label('', __('Variation Price'), ['class' => 'form-label']) !!}
                                                            {!! Form::number('product_variation_price_' . $str, null, ['class' => 'form-control', 'min' => '0', 'step' => '0.01']) !!}
                                                        </div>
                                                        <div class="form-group col-md-6">
                                                            {!! Form::label('', __('Sale Price'), ['class' => 'form-label']) !!}
                                                            {!! Form::number('product_sale_price_' . $str, $unit_price, ['class' => 'form-control', 'min' => '0', 'step' => '0.01']) !!}
                                                        </div>
                                                    </div>
                                                    <div class="form-group col-md-12 shipping-div">
                                                        {{-- F163, last of the repeated ids. `'id' => 'Shipping'` was
                                                             emitted once per variant, and the label's first argument was
                                                             `''` -- so its `for` was empty and the label was associated
                                                             with NOTHING, in addition to the id being duplicated N times.
                                                             Both halves fixed: the select gets the variant's unique id and
                                                             the label now points at it, which is an association the screen
                                                             never had rather than one being preserved. --}}
                                                        {!! Form::label('shipping_id_' . $safe_str, __('Shipping'), ['class' => 'form-label']) !!}
                                                        {!! Form::select('shipping_id_' . $str, $Shipping, null, [
                                                            'class' => 'form-control',
                                                            'data-role' => 'tagsinput',
                                                            'id' => 'shipping_id_' . $safe_str,
                                                        ]) !!}
                                                    </div>
                                                    <div class="form-group col-md-12 stock_status">
                                                        {!! Form::label('', __('Stock Status'), ['class' => 'form-label']) !!}
                                                        {!! Form::select('stock_status_' . $str, ['' => 'Select option','in_stock' => 'In Stock', 'out_of_stock' => 'Out Of Stock', 'on_backorder' => 'On Backorder'], null, ['class' => 'form-control']) !!}
                                                    </div>
                                                    <div class="form-group col-md-12">
                                                        {!! Form::label('', __('Description'), ['class' => 'form-label']) !!}
                                                        {!! Form::textarea('product_description_' . $str, null, ['rows' => 4, 'class'=>'form-control']) !!}
                                                    </div>
                                                    <div class="row col-md-12 d-none enable_manage_stock manageble_stock_{{ $str }}" id="enable_manage_stock_{{ $safe_str }}" data-id="{{ $str }}">
                                                        <div class="form-group col-md-4 " >
                                                            {!! Form::label('', __('Stock'), ['class' => 'form-label']) !!}
                                                            {!! Form::number('product_stock_' . $str, $stock, ['class' => 'form-control productVariantStock']) !!}
                                                        </div>
                                                        <div class="form-group col-md-5">
                                                        {!! Form::label('', __('Allow BackOrders:'), ['class' => 'form-label']) !!}
                                                            <div class="form-check m-1">
                                                                <input type="radio" id="not_allow_{{ $str }}" value="not_allow" name="stock_order_status_{{ $str }}" class="form-check-input code"
                                                                    checked="checked">
                                                                <label class="form-check-label" for="not_allow_{{ $str }}">{{ __('Do Not Allow') }}</label>
                                                            </div>
                                                            <div class="form-check m-1">
                                                                <input type="radio" id="notify_customer_{{ $str }}" value="notify_customer" name="stock_order_status_{{ $str }}" class="form-check-input code">
                                                                <label class="form-check-label" for="notify_customer_{{ $str }}">{{ __('Allow, But notify customer') }}</label>
                                                            </div>
                                                            <div class="form-check m-1">
                                                                <input type="radio" id="allow_{{ $str }}" value="allow" name="stock_order_status_{{ $str }}" class="form-check-input code">
                                                                <label class="form-check-label" for="allow_{{ $str }}">{{ __('Allow') }}</label>
                                                            </div>
                                                        </div>
                                                        <div class="form-group col-md-3 ">
                                                            {!! Form::label('', __('Low stock threshold'), ['class' => 'form-label ']) !!}
                                                            {!! Form::number('low_stock_threshold_' . $str, null, ['class' => 'form-control', 'min' => '0', 'step' => '0.01']) !!}
                                                        </div>
                                                    </div>
                                                    <input type="hidden" class="options_data_{{ $str }}[]" name ="options[]" value="{{ $str }}">
                                                    <div class="row download-product d-none down_product_{{ $str }}" data-id="{{ $str }}" id="download-product-{{ $safe_str }}">
                                                        <div class="form-group mb-0">
                                                            <label for="downloadable_product_id_{{ $safe_str }}"
                                                                class="form-label">{{ __('Downloadable Product') }}</label>
                                                            <input type="file" name="downloadable_product_{{ $str }}" id="downloadable_product_id_{{ $safe_str }}" data-value="downloadable_product_{{ $str }}"
                                                                class="form-control downloadable_product_variant"
                                                                {{-- AND THE HANDLER HAD TO MOVE WITH THE ID. Giving the
                                                                     preview a per-variant id (F163) left this `onchange`
                                                                     naming `down_product`, which no longer exists -- so
                                                                     `.src` on null THREW on every file choice, which is
                                                                     worse than writing the wrong preview. `?.setAttribute`
                                                                     rather than `.src =` so a future rename degrades to
                                                                     no preview instead of a thrown error. --}}
                                                                onchange="document.getElementById('down_product_{{ $safe_str }}')?.setAttribute('src', window.URL.createObjectURL(this.files[0]))">
                                                            <img id="down_product_{{ $safe_str }}" src="" width="20%" class="mt-2" />
                                                        </div>
                                                    </div>
                                                    @php
                                                        $module = App\Facades\ModuleFacade::find('CartQuantityControl');
                                                        $setting = getAdminAllSetting(Auth::user()->id);
                                                    @endphp
                                                    @if (isset($module) && $module->isEnabled())
                                                        @if (Auth::check() && strpos(Auth::user()->currentPlan['modules'] , 'CartQuantityControl'))
                                                            @if (isset($setting['cart_quantity_control_enable']) && $setting['cart_quantity_control_enable'] == 'on')
                                                                @include('cart-quantity-control::pages.addCartQuantityControlVariant',['str' => $str])
                                                            @endif
                                                        @endif
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                </div>
            </div>
        </div>
    </div>

@endif
<script>

    $(document).on('click', '.delete_option', function(event) {
        event.preventDefault();

        /* F162. THIS HANDLER USED TO DELETE OTHER VARIANTS' PRICES, SILENTLY.
           It was:

               $('.remove_option_' + id).remove();
               $('input[name*="' + originalId + '"], select[...], textarea[...]').remove();

           and `[name*=]` is a SUBSTRING match on the term. Every per-variant field is named
           `<prefix>_<term>`, so deleting the variant `M` matched `product_variation_price_M/L`,
           `product_sale_price_M/L`, `product_stock_M/L`, `product_sku_M/L` and the rest -- any
           term that CONTAINS the deleted one.

           MEASURED on the running create page, driving this view through its own
           `products/attribute_combination` endpoint with the terms `M` and `M/L`: clicking delete
           on `M` removed 33 fields, SEVENTEEN of them M/L's, including its price, sale price,
           stock and SKU. A save afterwards writes the product without them. That is the same class
           of loss as F82/F83/F105 -- a variant's financial values disappearing without a message --
           in the one path D-09's fix did not cover.

           SCOPED TO THE CLICKED VARIANT'S OWN CONTAINER instead, which is the idiom the sibling
           view already uses -- its `.delete_option` handler, the one call of
           `closest('.attribute_options_datas')` in that file -- rather than a third one:
           the combination loop renders exactly one `.attribute_options_datas` per combination, so
           (the directive is not named literally here: Blade compiles its own directives even inside
           a JavaScript comment, and writing it out raised "Malformed @@foreach statement" and 500ed
           this view -- the same class of trap as a ratchet counting its own explanatory comment)
           `closest()` reaches that variant and nothing else. Removing the container takes its
           fields with it, so no separate name-based removal is needed -- and a name-based removal
           is precisely what cannot be made safe here, because a term is free text. */
        $(this).closest('.attribute_options_datas').remove();
    });


    $('.enable_view').change(function() {
        if ($(this).prop('checked') == true) {
            var optionValue = $(this).val();
            if (optionValue == 'enabled') {
            }
        }
    });

    $(document).ready(function() {
        if($('.enable_product_stock').prop('checked') == true)
        {
            $('.stock_status').hide();
        }

    });
    $('#enable_product_stock').change(function() {
        $('.stock_status').show();
        if ($(this).prop('checked') == true) {
            $('.stock_status').hide();
        }
    });

    $(document).on('change', '.downloadable', function() {
        var optionValue = $(this).val();
        var container = $(this).closest('.accordion-item');

        if ($(this).prop('checked')) {
            if (optionValue == 'downloadable_product') {
                container.find('.download-product').removeClass('d-none');
            }
        } else {
            container.find('.download-product').addClass('d-none');
        }
    });

    $(document).on('change', '.virtual_product', function() {
        var optionValue = $(this).val();
        var container = $(this).closest('.accordion-item');

        if ($(this).prop('checked')) {
            if (optionValue == 'virtual_product') {
                container.find('.weight-div').addClass('d-none');
                container.find('.shipping-div').addClass('d-none');
            }
        } else {
            container.find('.weight-div').removeClass('d-none');
            container.find('.shipping-div').removeClass('d-none');
        }
    });

    $(document).on('change', '.manage_stock', function() {
        var optionValue = $(this).val();
        var container = $(this).closest('.accordion-item');
        if ($(this).prop('checked')) {
            if (optionValue == 'manage_stock') {
                container.find('.enable_manage_stock').removeClass('d-none');
                container.find('.stock_status').hide();
            }
        } else {
            container.find('.enable_manage_stock').addClass('d-none');
            container.find('.stock_status').show();
        }
    });

</script>
