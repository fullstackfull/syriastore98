<div class="min-max-quantity-div mt-4">
    <h4 class="mb-3">{{ __('Cart Quantity Management') }}</h4>
    <div class="row mt-2">
        <div class="form-group col-md-6 col-12">
            {!! Form::label('minimum_quantity', __('Minimum Quantity'), ['class' => 'form-label']) !!}
            {!! Form::number('minimum_quantity', isset($product->minimum_quantity) ? $product->minimum_quantity : '', [
                'class' => 'form-control minimum_quantity',
                'step' => '0.01',
                'required' => true,
            ]) !!}
            @error('minimum_quantity')
                <div class="text-danger">{{ $message }}</div>
            @enderror
        </div>
        <div class="form-group col-md-6 col-12">
            {!! Form::label('maximum_quantity', __('Maximum Quantity'), ['class' => 'form-label']) !!}
            {!! Form::number('maximum_quantity', isset($product->maximum_quantity) ? $product->maximum_quantity : '', [
                'class' => 'form-control maximum_quantity',
                'step' => '0.01',
                'required' => true,
            ]) !!}
            @error('maximum_quantity')
                <div class="text-danger">{{ $message }}</div>
            @enderror
        </div>
        <span id="validationMessage" class="text-danger validationMessage"></span>
        <input type="hidden" name="cartQuantityValidationCheck" class="cartQuantityValidationCheck" value="true">
    </div>
</div>


@push('custom-script')
    <script>
        $(document).ready(function() {

            function toggleMinMaxQuantityDiv() {

                if ($('#enable_product_variant').prop('checked')) {
                    $('.min-max-quantity-div').hide();
                } else {
                    $('.min-max-quantity-div').show();
                }
            }

            toggleMinMaxQuantityDiv();

            $(document).on("change", "#enable_product_variant", function() {
                toggleMinMaxQuantityDiv();
            });
        });
    </script>

    <script>

        $(document).ready(function() {
            checkCart();

            function checkCart() {

                function validateQuantities(stock, minimum_quantity, maximum_quantity, msg_div) {

                    var productStock = stock ? parseInt($(stock).val(), 10) : null;
                    var minimumQuantity = parseInt($(minimum_quantity).val(), 10);
                    var maximumQuantity = parseInt($(maximum_quantity).val(), 10);

                    var Messages = '';
                    $(msg_div).empty();

                    // Check for required fields
                    if (!minimumQuantity) {
                        Messages = 'Minimum quantity is required.';
                    } else if (!maximumQuantity) {
                        Messages = 'Maximum quantity is required.';
                    } else if (stock && !productStock) {
                        Messages = 'Product stock is required.';
                    }

                    if (stock) {
                        if (maximumQuantity > productStock) {
                            Messages = 'You can enter a maximum quantity less than or equal to ' + productStock;
                        } else if (minimumQuantity > productStock) {
                            Messages = 'You can enter a minimum quantity less than or equal to ' + productStock;
                        }
                    }

                    if (maximumQuantity <= 0) {
                        Messages = 'You can enter a maximum quantity greater than 0';
                    } else if (minimumQuantity <= 0) {
                        Messages = 'You can enter a minimum quantity greater than 0';
                    }
                    if (Messages !== '') {
                        $(msg_div).text(Messages);
                        $(".cartQuantityValidationCheck").val('false');
                        return false;
                    }

                    $(".cartQuantityValidationCheck").val('true');
                    return true;
                }

                function defaultvalidateQuantities(minimum_quantity, maximum_quantity, msg_div) {
                    var minimumQuantity = parseInt($(minimum_quantity).val(), 10);
                    var maximumQuantity = parseInt($(maximum_quantity).val(), 10);
                    var Messages = '';
                    $(msg_div).empty();

                    if (!minimumQuantity) {
                        Messages = 'Minimum quantity is required.';
                    } else if (!maximumQuantity) {
                        Messages = 'Maximum quantity is required.';
                    }

                    if (maximumQuantity <= 0) {
                        Messages = 'You can enter a maximum quantity greater than 0';
                    } else if (minimumQuantity <= 0) {
                        Messages = 'You can enter a minimum quantity greater than 0';
                    }

                    if (Messages !== '') {
                        $(msg_div).text(Messages);
                        return false;
                    }

                    return true;
                }

                function validateForm() {
                    var isValid = true;
                    $('.validationMessage').each(function() {
                        if ($(this).text() !== '') {
                            isValid = false;
                            return false;
                        }
                    });
                    return isValid;
                }

                function attachValidationHandlers() {
                    $(document).on("keyup change", ".productStock, .minimum_quantity, .maximum_quantity",
                        function() {
                            var parentDiv = $(this).parent().parent().parent().parent();
                            var stock = parentDiv.find('.productStock');
                            var minimum_quantity = parentDiv.find('.minimum_quantity');
                            var maximum_quantity = parentDiv.find('.maximum_quantity');
                            var msg_div = parentDiv.find('.validationMessage');
                            validateQuantities(stock.length ? stock : null, minimum_quantity, maximum_quantity,
                                msg_div);
                        });
                }

                if ($('.enable_product_stock').prop('checked')) {
                    attachValidationHandlers();
                } else {
                    $(document).on("keyup change", ".minimum_quantity, .maximum_quantity", function() {
                        var parentDiv = $(this).parent().parent().parent().parent();
                        var minimum_quantity = parentDiv.find('.minimum_quantity');
                        var maximum_quantity = parentDiv.find('.maximum_quantity');
                        var msg_div = parentDiv.find('.validationMessage');

                        defaultvalidateQuantities(minimum_quantity, maximum_quantity, msg_div);
                    });
                }

                $(document).on("change", ".enable_product_stock", function() {
                    if ($(this).prop('checked')) {
                        attachValidationHandlers();
                    } else {
                        $(document).off("keyup change",
                            ".productStock, .minimum_quantity, .maximum_quantity");
                        $(document).on("keyup change", ".minimum_quantity, .maximum_quantity", function() {
                            var parentDiv = $(this).parent().parent().parent().parent();
                            var minimum_quantity = parentDiv.find('.minimum_quantity');
                            var maximum_quantity = parentDiv.find('.maximum_quantity');
                            var msg_div = parentDiv.find('.validationMessage');
                            validateQuantities(null, minimum_quantity, maximum_quantity, msg_div);
                        });
                    }
                });
            }
        });
    </script>
@endpush
