
            @if ($product->variant_product == 1)
            <div class="flex flex-wrap items-center gap-5 mb-5">
                    @php
                        $variant = json_decode($product->product_attribute);
                        $varint_name_array = [];
                        if (!empty($product->DefaultVariantData->variant)) {
                            $varint_name_array = explode('-', $product->DefaultVariantData->variant);
                        }
                    @endphp
                    @foreach ($variant as $key => $value)
                        @php
                            $p_variant = App\Models\Utility::ProductAttribute($value->attribute_id);
                            $attribute = json_decode($p_variant);
                            $propertyKey = 'for_variation_' . $attribute->id;
                            $variation_option = $value->$propertyKey;
                        @endphp
                              @if ($variation_option == 1)
                                <div class="flex items-center gap-2">
                                    <div class="product-labl mb-0 inline_lable font-semibold">{{ $attribute->name.__(" : ") }}</div>
                                        <div class="inline_contant">
                                        <select data-product="{{ $product->id }}" class="custom-select-btn product_variatin_option variant_loop radio-btn rounded-[6px]"  name="varint[{{ $attribute->name }}]">
                                                @php
                                                    $optionValues = [];
                                                @endphp

                                                @foreach ($value->values as $variant1)
                                                    @php
                                                        $parts = explode('|', $variant1);
                                                    @endphp
                                                    @foreach ($parts as $p)
                                                        @php
                                                            $id = App\Models\ProductAttributeOption::where('id', $p)->first();
                                                            if (isset($id->terms)) {
                                                                $optionValues[] = $id->terms;
                                                            }
                                                        @endphp
                                                    @endforeach
                                                @endforeach
                                                <option value="">
                                                    {{ __('Select an option') }}
                                                </option>

                                                @if (is_array($optionValues))
                                                    @foreach ($optionValues as $optionValue)
                                                        <option>{{ $optionValue }}</option>
                                                    @endforeach
                                                @endif
                                            </select>
                                    </div>
                                </div>
                            @endif
                    @endforeach
                </div>
            @endif