@php
    $conditionsDataCount = isset($decodeConditions) && !empty($decodeConditions) ? count($decodeConditions ?? null) - 1 : 0;
@endphp
@if (isset($decodeConditions) && !empty($decodeConditions) && $conditionsDataCount >= $repeater_id)
    @foreach ($decodeConditions as $key => $item)
        @if ($key == $repeater_id)
            @if ($condition_type == 'cart_total_quantity' && $item->condition_type == 'cart_total_quantity')
                <div class="form-group col-md-6">
                    {!! Form::label('type', __('Condition'), ['class' => 'form-label']) !!}
                    {!! Form::select(
                        'conditions_repeater[' . $repeater_id . '][type]',
                        $atLeastOrMoreConditions,
                        $item->type ?? null,
                        ['class' => 'form-control', 'required' => 'required'],
                    ) !!}
                </div>
                <div class="form-group col-md-6">
                    {!! Form::label('quantity', __('Quantity'), ['class' => 'form-label']) !!}
                    {!! Form::number('conditions_repeater[' . $repeater_id . '][quantity]', $item->quantity ?? null, [
                        'class' => 'form-control',
                        'placeholder' => __('Enter Quantity'),
                        'required' => 'required',
                        'min' => '0',
                    ]) !!}
                </div>
            @else
                @if ($condition_type == 'cart_total_quantity')
                    <div class="form-group col-md-6">
                        {!! Form::label('type', __('Condition'), ['class' => 'form-label']) !!}
                        {!! Form::select('conditions_repeater[' . $repeater_id . '][type]', $atLeastOrMoreConditions, null, [
                            'class' => 'form-control',
                            'required' => 'required',
                        ]) !!}
                    </div>
                    <div class="form-group col-md-6">
                        {!! Form::label('quantity', __('Quantity'), ['class' => 'form-label']) !!}
                        {!! Form::number('conditions_repeater[' . $repeater_id . '][quantity]', null, [
                            'class' => 'form-control',
                            'placeholder' => __('Enter Quantity'),
                            'required' => 'required',
                            'min' => '0',
                        ]) !!}
                    </div>
                @endif
            @endif
            @if ($condition_type == 'cart_item_count' && $item->condition_type == 'cart_item_count')
                <div class="form-group col-md-6">
                    {!! Form::label('type', __('Condition'), ['class' => 'form-label']) !!}
                    {!! Form::select(
                        'conditions_repeater[' . $repeater_id . '][type]',
                        $atLeastOrMoreConditions,
                        $item->type ?? null,
                        ['class' => 'form-control','required' => 'required'],
                    ) !!}
                </div>
                <div class="form-group col-md-6">
                    {!! Form::label('item', __('Item'), ['class' => 'form-label']) !!}
                    {!! Form::number('conditions_repeater[' . $repeater_id . '][item]', $item->item ?? null, [
                        'class' => 'form-control',
                        'placeholder' => __('Enter Item Number'),
                        'required' => 'required',
                        'min' => '0',
                    ]) !!}
                </div>
            @else
                @if ($condition_type == 'cart_item_count')
                    <div class="form-group col-md-6">
                        {!! Form::label('type', __('Condition'), ['class' => 'form-label']) !!}
                        {!! Form::select('conditions_repeater[' . $repeater_id . '][type]', $atLeastOrMoreConditions, null, [
                            'class' => 'form-control',
                            'required' => 'required',
                        ]) !!}
                    </div>
                    <div class="form-group col-md-6">
                        {!! Form::label('item', __('Item'), ['class' => 'form-label']) !!}
                        {!! Form::number('conditions_repeater[' . $repeater_id . '][item]', null, [
                            'class' => 'form-control',
                            'placeholder' => __('Enter Item Number'),
                            'required' => 'required',
                            'min' => '0',
                        ]) !!}
                    </div>
                @endif
            @endif
            @if ($condition_type == 'cart_items_products' && $item->condition_type == 'cart_items_products')
                <div class="form-group col-md-6">
                    {!! Form::label('type', __('Condition'), ['class' => 'form-label']) !!}
                    {!! Form::select(
                        'conditions_repeater[' . $repeater_id . '][type]',
                        $productItemConditions,
                        $item->type ?? null,
                        ['class' => 'form-control','required' => 'required'],
                    ) !!}
                </div>
                @php
                    $cart_items_products_selected = explode(',', $item->cart_items_products_selected ?? null);
                @endphp
                <div class="form-group col-md-6">
                    {!! Form::label('cart_items_products_selected', __('Products'), ['class' => 'form-label']) !!}
                    {!! Form::select(
                        'conditions_repeater[' . $repeater_id . '][cart_items_products_selected][]',
                        $products,
                        $cart_items_products_selected ?? null,
                        [
                            'class' => 'form-control cart_items_products_selected',
                            'id' => 'cart_items_products_selected' . $dataId,
                            'multiple',
                            'data-role' => 'tagsinput',
                            'data-id' => 0,
                        ],
                    ) !!}
                </div>
            @else
                @if ($condition_type == 'cart_items_products')
                    <div class="form-group col-md-6">
                        {!! Form::label('type', __('Condition'), ['class' => 'form-label']) !!}
                        {!! Form::select('conditions_repeater[' . $repeater_id . '][type]', $productItemConditions, null, [
                            'class' => 'form-control',
                            'required' => 'required',
                        ]) !!}
                    </div>
                    <div class="form-group col-md-6">
                        {!! Form::label('cart_items_products_selected', __('Products'), ['class' => 'form-label']) !!}
                        {!! Form::select('conditions_repeater[' . $repeater_id . '][cart_items_products_selected][]', $products, null, [
                            'class' => 'form-control cart_items_products_selected',
                            'id' => 'cart_items_products_selected' . $dataId,
                            'multiple',
                            'data-role' => 'tagsinput',
                            'data-id' => 0,
                        ]) !!}
                    </div>
                @endif
            @endif
            @if ($condition_type == 'cart_item_quantity_products' && $item->condition_type == 'cart_item_quantity_products')
                @php
                    $cart_items_quantity_products_selected = explode(
                        ',',
                        $item->cart_items_quantity_products_selected ?? null,
                    );
                @endphp
                <div class="form-group col-md-4">
                    {!! Form::label('cart_items_quantity_products_selected', __('Products'), ['class' => 'form-label']) !!}
                    {!! Form::select(
                        'conditions_repeater[' . $repeater_id . '][cart_items_quantity_products_selected][]',
                        $products,
                        $cart_items_quantity_products_selected ?? null,
                        [
                            'class' => 'form-control cart_items_quantity_products_selected',
                            'id' => 'cart_items_quantity_products_selected' . $dataId,
                            'multiple',
                            'data-role' => 'tagsinput',
                            'data-id' => 0,
                        ],
                    ) !!}
                </div>
                <div class="form-group col-md-4">
                    {!! Form::label('type', __('Condition'), ['class' => 'form-label']) !!}
                    {!! Form::select(
                        'conditions_repeater[' . $repeater_id . '][type]',
                        $atLeastOrMoreConditions,
                        $item->type ?? null,
                        ['class' => 'form-control','required' => 'required'],
                    ) !!}
                </div>
                <div class="form-group col-md-4">
                    {!! Form::label('quantity', __('Quantity'), ['class' => 'form-label']) !!}
                    {!! Form::number('conditions_repeater[' . $repeater_id . '][quantity]', $item->quantity ?? null, [
                        'class' => 'form-control',
                        'placeholder' => __('Enter Quantity Number'),
                        'required' => 'required',
                        'min' => '0',
                    ]) !!}
                </div>
            @else
                @if ($condition_type == 'cart_item_quantity_products')
                    <div class="form-group col-md-4">
                        {!! Form::label('cart_items_quantity_products_selected', __('Products'), ['class' => 'form-label']) !!}
                        {!! Form::select(
                            'conditions_repeater[' . $repeater_id . '][cart_items_quantity_products_selected][]',
                            $products,
                            null,
                            [
                                'class' => 'form-control cart_items_quantity_products_selected',
                                'id' => 'cart_items_quantity_products_selected' . $dataId,
                                'multiple',
                                'data-role' => 'tagsinput',
                                'data-id' => 0,
                            ],
                        ) !!}
                    </div>
                    <div class="form-group col-md-4">
                        {!! Form::label('type', __('Condition'), ['class' => 'form-label']) !!}
                        {!! Form::select('conditions_repeater[' . $repeater_id . '][type]', $atLeastOrMoreConditions, null, [
                            'class' => 'form-control',
                            'required' => 'required',
                        ]) !!}
                    </div>
                    <div class="form-group col-md-4">
                        {!! Form::label('quantity', __('Quantity'), ['class' => 'form-label']) !!}
                        {!! Form::number('conditions_repeater[' . $repeater_id . '][quantity]', null, [
                            'class' => 'form-control',
                            'placeholder' => __('Enter Quantity Number'),
                            'required' => 'required',
                            'min' => '0',
                        ]) !!}
                    </div>
                @endif
            @endif
            @if ($condition_type == 'customer' && $item->condition_type == 'customer')
                <div class="form-group col-md-6">
                    {!! Form::label('type', __('Condition'), ['class' => 'form-label']) !!}
                    {!! Form::select('conditions_repeater[' . $repeater_id . '][type]', $inOrNotConditions, $item->type ?? null, [
                        'class' => 'form-control',
                        'required' => 'required',
                    ]) !!}
                </div>
                @php
                    $condition_customers = explode(',', $item->condition_customers ?? null);
                @endphp
                <div class="form-group col-md-6">
                    {!! Form::label('condition_customers', __('Customer'), ['class' => 'form-label']) !!}
                    {!! Form::select(
                        'conditions_repeater[' . $repeater_id . '][condition_customers][]',
                        $customers,
                        $condition_customers ?? null,
                        [
                            'class' => 'form-control condition_customers',
                            'id' => 'condition_customers' . $dataId,
                            'multiple',
                            'data-role' => 'tagsinput',
                            'data-id' => 0,
                        ],
                    ) !!}
                </div>
            @else
                @if ($condition_type == 'customer')
                    <div class="form-group col-md-6">
                        {!! Form::label('type', __('Condition'), ['class' => 'form-label']) !!}
                        {!! Form::select('conditions_repeater[' . $repeater_id . '][type]', $inOrNotConditions, null, [
                            'class' => 'form-control',
                            'required' => 'required',
                        ]) !!}
                    </div>
                    <div class="form-group col-md-6">
                        {!! Form::label('condition_customers', __('Customer'), ['class' => 'form-label']) !!}
                        {!! Form::select('conditions_repeater[' . $repeater_id . '][condition_customers][]', $customers, null, [
                            'class' => 'form-control condition_customers',
                            'id' => 'condition_customers' . $dataId,
                            'multiple',
                            'data-role' => 'tagsinput',
                            'data-id' => 0,
                        ]) !!}
                    </div>
                @endif
            @endif
            @if ($condition_type == 'date_and_time' && $item->condition_type == 'date_and_time')
                <div class="form-group col-md-6">
                    {!! Form::label('start_date_time', __('Start Date/Time'), ['class' => 'form-label']) !!}
                    {!! Form::input(
                        'dateTime-local',
                        'conditions_repeater[' . $repeater_id . '][start_date_time]',
                        $item->start_date_time ?? null,
                        ['class' => 'form-control','required' => 'required'],
                    ) !!}
                </div>
                <div class="form-group col-md-6">
                    {!! Form::label('end_date_time', __('End Date/Time'), ['class' => 'form-label']) !!}
                    {!! Form::input(
                        'dateTime-local',
                        'conditions_repeater[' . $repeater_id . '][end_date_time]',
                        $item->end_date_time ?? null,
                        ['class' => 'form-control','required' => 'required'],
                    ) !!}
                </div>
            @else
                @if ($condition_type == 'date_and_time')
                    <div class="form-group col-md-6">
                        {!! Form::label('start_date_time', __('Start Date/Time'), ['class' => 'form-label']) !!}
                        {!! Form::input('dateTime-local', 'conditions_repeater[' . $repeater_id . '][start_date_time]', null, [
                            'class' => 'form-control',
                            'required' => 'required',
                        ]) !!}
                    </div>
                    <div class="form-group col-md-6">
                        {!! Form::label('end_date_time', __('End Date/Time'), ['class' => 'form-label']) !!}
                        {!! Form::input('dateTime-local', 'conditions_repeater[' . $repeater_id . '][end_date_time]', null, [
                            'class' => 'form-control',
                            'required' => 'required',
                        ]) !!}
                    </div>
                @endif
            @endif
        @endif
    @endforeach
@else
    @if ($condition_type == 'cart_total_quantity')
        <div class="form-group col-md-6">
            {!! Form::label('type', __('Condition'), ['class' => 'form-label']) !!}
            {!! Form::select('conditions_repeater[' . $repeater_id . '][type]', $atLeastOrMoreConditions, null, [
                'class' => 'form-control',
                'required' => 'required',
            ]) !!}
        </div>
        <div class="form-group col-md-6">
            {!! Form::label('quantity', __('Quantity'), ['class' => 'form-label']) !!}
            {!! Form::number('conditions_repeater[' . $repeater_id . '][quantity]', null, [
                'class' => 'form-control',
                'placeholder' => __('Enter Quantity'),
                'required' => 'required',
                'min' => '0',
            ]) !!}
        </div>
    @endif

    @if ($condition_type == 'cart_item_count')
        <div class="form-group col-md-6">
            {!! Form::label('type', __('Condition'), ['class' => 'form-label']) !!}
            {!! Form::select('conditions_repeater[' . $repeater_id . '][type]', $atLeastOrMoreConditions, null, [
                'class' => 'form-control',
                'required' => 'required',
            ]) !!}
        </div>
        <div class="form-group col-md-6">
            {!! Form::label('item', __('Item'), ['class' => 'form-label']) !!}
            {!! Form::number('conditions_repeater[' . $repeater_id . '][item]', null, [
                'class' => 'form-control',
                'placeholder' => __('Enter Item Number'),
                'required' => 'required',
                'min' => '0',
            ]) !!}
        </div>
    @endif

    @if ($condition_type == 'cart_items_products')
        <div class="form-group col-md-6">
            {!! Form::label('type', __('Condition'), ['class' => 'form-label']) !!}
            {!! Form::select('conditions_repeater[' . $repeater_id . '][type]', $productItemConditions, null, [
                'class' => 'form-control',
                'required' => 'required',
            ]) !!}
        </div>
        <div class="form-group col-md-6">
            {!! Form::label('cart_items_products_selected', __('Products'), ['class' => 'form-label']) !!}
            {!! Form::select('conditions_repeater[' . $repeater_id . '][cart_items_products_selected][]', $products, null, [
                'class' => 'form-control cart_items_products_selected',
                'id' => 'cart_items_products_selected' . $dataId,
                'multiple',
                'data-role' => 'tagsinput',
                'data-id' => 0,
            ]) !!}
        </div>
    @endif

    @if ($condition_type == 'cart_item_quantity_products')
        <div class="form-group col-md-4">
            {!! Form::label('cart_items_quantity_products_selected', __('Products'), ['class' => 'form-label']) !!}
            {!! Form::select(
                'conditions_repeater[' . $repeater_id . '][cart_items_quantity_products_selected][]',
                $products,
                null,
                [
                    'class' => 'form-control cart_items_quantity_products_selected',
                    'id' => 'cart_items_quantity_products_selected' . $dataId,
                    'multiple',
                    'data-role' => 'tagsinput',
                    'data-id' => 0,
                ],
            ) !!}
        </div>
        <div class="form-group col-md-4">
            {!! Form::label('type', __('Condition'), ['class' => 'form-label']) !!}
            {!! Form::select('conditions_repeater[' . $repeater_id . '][type]', $atLeastOrMoreConditions, null, [
                'class' => 'form-control',
            ]) !!}
        </div>
        <div class="form-group col-md-4">
            {!! Form::label('quantity', __('Quantity'), ['class' => 'form-label']) !!}
            {!! Form::number('conditions_repeater[' . $repeater_id . '][quantity]', null, [
                'class' => 'form-control',
                'placeholder' => __('Enter Quantity Number'),
                'required' => 'required',
                'min' => '0',
            ]) !!}
        </div>
    @endif

    @if ($condition_type == 'customer')
        <div class="form-group col-md-6">
            {!! Form::label('type', __('Condition'), ['class' => 'form-label']) !!}
            {!! Form::select('conditions_repeater[' . $repeater_id . '][type]', $inOrNotConditions, null, [
                'class' => 'form-control',
                'required' => 'required',
            ]) !!}
        </div>
        <div class="form-group col-md-6">
            {!! Form::label('condition_customers', __('Customer'), ['class' => 'form-label']) !!}
            {!! Form::select('conditions_repeater[' . $repeater_id . '][condition_customers][]', $customers, null, [
                'class' => 'form-control condition_customers',
                'id' => 'condition_customers' . $dataId,
                'multiple',
                'data-role' => 'tagsinput',
                'data-id' => 0,
            ]) !!}
        </div>
    @endif

    @if ($condition_type == 'date_and_time')
        <div class="form-group col-md-6">
            {!! Form::label('start_date_time', __('Start Date/Time'), ['class' => 'form-label']) !!}
            {!! Form::input('dateTime-local', 'conditions_repeater[' . $repeater_id . '][start_date_time]', null, [
                'class' => 'form-control',
                'required' => 'required',
            ]) !!}
        </div>
        <div class="form-group col-md-6">
            {!! Form::label('end_date_time', __('End Date/Time'), ['class' => 'form-label']) !!}
            {!! Form::input('dateTime-local', 'conditions_repeater[' . $repeater_id . '][end_date_time]', null, [
                'class' => 'form-control',
                'required' => 'required',
            ]) !!}
        </div>
    @endif
@endif

<script>
    multiSelection();
    // Multiple selection
    function multiSelection() {
        if ($('[data-role="tagsinput"]').length > 0) {
            $('[data-role="tagsinput"]').each(function(index, element) {
                var obj_id = $(this).attr('id');
                var textRemove = new Choices(
                    document.getElementById(obj_id), {
                        delimiter: ',',
                        editItems: true,
                        removeItemButton: true,
                    }
                );
            });
        }
    };
</script>
