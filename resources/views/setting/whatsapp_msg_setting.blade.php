{{--
    WhatsApp message template, on the design system.
    =============================================================================================

    TWO LABELS POINTED AT THE WRONG CONTROL. `<label for="storejs">` sat over
    `whatsapp_item_variable` — `storejs` is the STYLE panel's field, copied in with the markup —
    and `Form::label('content', …)` emitted `for="content"` over a field named
    `whatsapp_content`. Both are dangling: a `for` that names no control in the page gives the
    field no accessible name, and it is silent because the label still LOOKS attached.

    The variable reference was a list of `<p>` tags using `pull-right`, a Bootstrap 3 class this
    admin does not ship, so the tokens did not align anywhere. It is a term-and-definition list,
    which is what `<dl>` is for, and a screen reader can then navigate it as pairs.
--}}
<div class="ss-card" id="whatsapp_message_Setting">
    {{ Form::model($setting, ['route' => ['customMassage'], 'method' => 'POST']) }}

    <div class="ss-card__header">
        <div class="ss-page-header__text">
            <h2 class="ss-card__title">{{ __('Whatsapp Message Settings') }}</h2>
            <p class="ss-page-header__subtitle">
                {{ __('Put any of these tokens in your message and they are replaced when it is sent.') }}
            </p>
        </div>
    </div>

    <div class="ss-card__body">
        <div class="row">
            <div class="col-md-6">
                <h3 class="ss-label">{{ __('Order Variable') }}</h3>
                <dl class="ss-vars">
                        <dt class="ss-vars__term">{{ __('Store Name') }}</dt>
                        <dd class="ss-vars__def"><code>{store_name}</code></dd>
                        <dt class="ss-vars__term">{{ __('Order No') }}</dt>
                        <dd class="ss-vars__def"><code>{order_no}</code></dd>
                        <dt class="ss-vars__term">{{ __('Customer Name') }}</dt>
                        <dd class="ss-vars__def"><code>{customer_name}</code></dd>
                        <dt class="ss-vars__term">{{ __('Billing Address') }}</dt>
                        <dd class="ss-vars__def"><code>{billing_address}</code></dd>
                        <dt class="ss-vars__term">{{ __('Billing Country') }}</dt>
                        <dd class="ss-vars__def"><code>{billing_country}</code></dd>
                        <dt class="ss-vars__term">{{ __('Billing City') }}</dt>
                        <dd class="ss-vars__def"><code>{billing_city}</code></dd>
                        <dt class="ss-vars__term">{{ __('Billing Postalcode') }}</dt>
                        <dd class="ss-vars__def"><code>{billing_postalcode}</code></dd>
                        <dt class="ss-vars__term">{{ __('Shipping Address') }}</dt>
                        <dd class="ss-vars__def"><code>{shipping_address}</code></dd>
                        <dt class="ss-vars__term">{{ __('Shipping Country') }}</dt>
                        <dd class="ss-vars__def"><code>{shipping_country}</code></dd>
                        <dt class="ss-vars__term">{{ __('Shipping City') }}</dt>
                        <dd class="ss-vars__def"><code>{shipping_city}</code></dd>
                        <dt class="ss-vars__term">{{ __('Shipping Postalcode') }}</dt>
                        <dd class="ss-vars__def"><code>{shipping_postalcode}</code></dd>
                        <dt class="ss-vars__term">{{ __('Item Variable') }}</dt>
                        <dd class="ss-vars__def"><code>{item_variable}</code></dd>
                        <dt class="ss-vars__term">{{ __('Qty Total') }}</dt>
                        <dd class="ss-vars__def"><code>{qty_total}</code></dd>
                        <dt class="ss-vars__term">{{ __('Sub Total') }}</dt>
                        <dd class="ss-vars__def"><code>{sub_total}</code></dd>
                        <dt class="ss-vars__term">{{ __('Discount Amount') }}</dt>
                        <dd class="ss-vars__def"><code>{discount_amount}</code></dd>
                        <dt class="ss-vars__term">{{ __('Shipping Amount') }}</dt>
                        <dd class="ss-vars__def"><code>{shipping_amount}</code></dd>
                        <dt class="ss-vars__term">{{ __('Total Tax') }}</dt>
                        <dd class="ss-vars__def"><code>{total_tax}</code></dd>
                        <dt class="ss-vars__term">{{ __('Final Total') }}</dt>
                        <dd class="ss-vars__def"><code>{final_total}</code></dd>
                </dl>
            </div>

            <div class="col-md-6">
                <h3 class="ss-label">{{ __('Item Variable') }}</h3>
                <dl class="ss-vars">
                        <dt class="ss-vars__term">{{ __('Sku') }}</dt>
                        <dd class="ss-vars__def"><code>{sku}</code></dd>
                        <dt class="ss-vars__term">{{ __('Quantity') }}</dt>
                        <dd class="ss-vars__def"><code>{quantity}</code></dd>
                        <dt class="ss-vars__term">{{ __('Product Name') }}</dt>
                        <dd class="ss-vars__def"><code>{product_name}</code></dd>
                        <dt class="ss-vars__term">{{ __('Variant Name') }}</dt>
                        <dd class="ss-vars__def"><code>{variant_name}</code></dd>
                        <dt class="ss-vars__term">{{ __('Item Tax') }}</dt>
                        <dd class="ss-vars__def"><code>{item_tax}</code></dd>
                        <dt class="ss-vars__term">{{ __('Item total') }}</dt>
                        <dd class="ss-vars__def"><code>{item_total}</code></dd>
                </dl>

                <div class="ss-field">
                    <label class="ss-label" for="whatsapp_item_variable"><code>{item_variable}</code></label>
                    <input type="text" class="ss-input" id="whatsapp_item_variable" name="whatsapp_item_variable"
                           value="{{ $setting['whatsapp_item_variable'] ?? '' }}"
                           placeholder="{quantity} x {product_name} - {variant_name} + {item_tax} = {item_total}">
                </div>

                <div class="ss-field">
                    <label class="ss-label" for="whatsapp_content">{{ __('Whatsapp Message') }}</label>
                    <textarea class="ss-input" id="whatsapp_content" name="whatsapp_content" rows="5"
                              required>{{ $setting['whatsapp_content'] ?? '' }}</textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="ss-card__footer ss-card__footer--end">
        <button type="submit" class="ss-btn ss-btn--primary">{{ __('Save Changes') }}</button>
    </div>
    {{ Form::close() }}
</div>
