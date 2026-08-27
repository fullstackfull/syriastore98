<h4 class="mt-3">{{ __('Cart Quantity Management') }}</h4>
<div class="row mt-2">
    <div class="form-group col-md-6 col-12">
        {!! Form::label('minimum_quantity', __('Minimum Quantity'), ['class' => 'form-label']) !!}
        {!! Form::number('minimum_quantity_' . $str, isset($minimum_quantity) ? $minimum_quantity : '', [
            'class' => 'form-control minimum_quantity',
            'min' => '1',
            'step' => '0.01',
            'required' => true,
        ]) !!}
        @error('minimum_quantity')
            <div class="text-danger">{{ $message }}</div>
        @enderror
    </div>
    <div class="form-group col-md-6 col-12">
        {!! Form::label('maximum_quantity ascasc', __('Maximum Quantity'), ['class' => 'form-label']) !!}
        {!! Form::number('maximum_quantity_' . $str, isset($maximum_quantity) ? $maximum_quantity : '', [
            'class' => 'form-control maximum_quantity',
            'min' => '1',
            'max' => '5',
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


<script>
    $(document).ready(function() {
        function validateQuantities(stock, minimum_quantity, maximum_quantity, msg_div) {
            var productVariantStock = stock ? parseInt($(stock).val(), 10) : null;
            var minimumQuantity = parseInt($(minimum_quantity).val(), 10);
            var maximumQuantity = parseInt($(maximum_quantity).val(), 10);
            var Messages = '';
            $(msg_div).empty();

            // Check for required fields
            if (!minimumQuantity) {
                Messages = 'Minimum quantity is required.';
            } else if (!maximumQuantity) {
                Messages = 'Maximum quantity is required.';
            } else if (stock && !productVariantStock == '') {
                Messages = 'Product stock is required.';
            }

            if (stock) {
                if (productVariantStock > 0) {
                    if (maximumQuantity > productVariantStock) {
                        Messages = 'You can enter a maximum quantity less than or equal to ' +
                            productVariantStock;
                    } else if (minimumQuantity > productVariantStock) {
                        Messages = 'You can enter a minimum quantity less than or equal to ' +
                            productVariantStock;
                    }
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

        function validateForm() {
            var isValid = true;
            $('.validationMessage').each(function() {
                if ($(this).text() !== '') {
                    isValid = false;
                }
            });
            return isValid;
        }

        function attachValidationHandlers(stockEnabled) {
            $(document).on("keyup change", ".productVariantStock, .minimum_quantity, .maximum_quantity",
                function() {
                    var parentDiv = $(this).parent().parent().parent().parent();
                    var stock = stockEnabled ? parentDiv.find('.productVariantStock') : null;
                    var minimum_quantity = parentDiv.find('.minimum_quantity');
                    var maximum_quantity = parentDiv.find('.maximum_quantity');
                    var msg_div = parentDiv.find('.validationMessage');
                    validateQuantities(stock, minimum_quantity, maximum_quantity, msg_div);
                });
        }

        // Initial setup based on checkbox state
        var b = $('.attribute_option_data').closest('.parent-clase').find('.input-options');
        var dataid = b.attr('data-id');
        var stockEnabled = $('.enable_variation_' + dataid).prop('checked')
        attachValidationHandlers(stockEnabled);

        // Handling the checkbox change event
        $(document).on("change", ".enable_variation_" + dataid, function() {
            stockEnabled = $(this).prop('checked');
            $(document).off("keyup change",".productVariantStock, .minimum_quantity, .maximum_quantity");
            attachValidationHandlers(stockEnabled);
        });
    });
</script>
