<style>
    .colorinput {
    margin: 0;
    position: relative;
    cursor: pointer;
}
    .colorinput-color {
        background-color: #fdfdff;
        border-color: #e4e6fc;
        border-width: 1px;
        border-style: solid;
        display: inline-block;
        width: 1.75rem;
        height: 1.75rem;
        border-radius: 3px;
        color: #fff;
        box-shadow: 0 1px 2px 0 rgb(0 0 0 / 5%);
    }

    .colorinput-input {
        position: absolute;
        z-index: -1;
        opacity: 0;
    }

    .colorinput-input:checked~.colorinput-color:before {
        opacity: 1;
    }

    .colorinput-color:before {
        content: '';
        opacity: 0;
        position: absolute;
        top: .25rem;
        left: .25rem;
        height: 1.25rem;
        width: 1.25rem;
        transition: .3s opacity;
        background: url("data:image/svg+xml;charset=utf8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 8 8'%3E%3Cpath fill='%23fff' d='M6.564.75l-3.59 3.612-1.538-1.55L0 4.26 2.974 7.25 8 2.193z'/%3E%3C/svg%3E") no-repeat center center/50% 50%;
    }
</style>
<div id="Print_Setting">
    <div class="card">
        {{ Form::open(['route' => ['order.print.setting'], 'method' => 'post', 'enctype' => 'multipart/form-data']) }}
        <div class="card-header d-flex justify-content-between ">
            <h5 class=""> {{ __('Print Settings') }} </h5>
            {!! Form::hidden('print_setting_enabled', 'off') !!}
            <div class="form-check form-switch d-inline-block">
                {!! Form::checkbox(
                    'print_setting_enabled',
                    'on',
                    isset($settings['print_setting_enabled']) && $settings['print_setting_enabled'] == 'on',
                    [
                        'class' => 'form-check-input',
                        'id' => 'print_setting_enabled',
                    ],
                ) !!}
                <label class="custom-control-label form-control-label" for="print_setting_enabled"></label>
            </div>
        </div>
        <div class="card-body p-4">
            <div class="row">

                <div class="col-lg-3 form-group">
                    <label for="print_footer" class="form-label">{{ __('Footer title') }}</label>
                    {{ Form::text('footer', isset($settings['footer']) ? $settings['footer'] : null, ['class' => 'form-control', 'placeholder' => __('eCommerce 2024'), 'id' => 'print_footer']) }}
                </div>

                <div class="col-lg-3 form-group">
                    <label for="print_footer_notes" class="form-label">{{ __('Footer Notes') }}</label>
                    {{ Form::text('footer_notes', isset($settings['footer_notes']) ? $settings['footer_notes'] : null, ['class' => 'form-control', 'placeholder' => __('eCommerce 2024'), 'id' => 'print_footer_notes']) }}
                </div>

            </div>
        </div>
        <div class="row">
            <div class="col-md-4">
                <div class="card-header card-body">

                    <div class="form-group d-flex align-items-center justify-content-between">
                        {{ Form::label('order_qr_display', __('QR Display?'), ['class' => 'form-label']) }}
                        <div class="text-end form-check form-switch d-inline-block">
                            <input type="checkbox" class="form-check-input" name="order_qr_display"
                                id="order_qr_display"
                                {{ (isset($settings['order_qr_display']) ? $settings['order_qr_display'] : 'off') == 'on' ? 'checked' : '' }}>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="invoice_template" class='form-label text-dark'>{{ __('Invoice Template') }}</label>
                        <select class="form-control" data-toggle="select" name="invoice_template">
                            @foreach (\Workdo\AutomaticOrderPrinting\app\Models\AutomaticOrderPrinting::templateData()['templates'] as $key => $template)
                                <option value="{{ $key }}"
                                    {{ isset($settings['invoice_template']) && $settings['invoice_template'] == $key ? 'selected' : '' }}>
                                    {{ $template }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">{{ __('Color Input') }}</label>
                        <div class="row gutters-xs">
                            @foreach ($templateData['colors'] as $key => $color)
                                <div class="col-auto">
                                    <label class="colorinput">
                                        <input name="invoice_color" type="radio" value="{{ $color }}"
                                            class="colorinput-input"
                                            {{ ($settings['invoice_color'] ?? '') == $color ? 'checked' : '' }}>
                                        <span class="colorinput-color" style="background: #{{ $color }}">
                                        </span>
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="card-footer text-end">
                        <input class="btn btn-print-invoice  btn-primary btn-badge" type="submit"
                            value="{{ __('Save Changes') }}">
                    </div>
                </div>
            </div>
            <div class="col-md-8">
                @if (!empty($settings['invoice_template']) && !empty($settings['invoice_color']))
                    <iframe id="purchase_frame" class="w-100 h-100" frameborder="0"
                        src="{{ route('invoice.preview', [$settings['invoice_template'], $settings['invoice_color']]) }}"></iframe>
                @else
                    <iframe id="purchase_frame" class="w-100 h-100" frameborder="0"
                        src="{{ route('invoice.preview', ['template1', 'fffff']) }}"></iframe>
                @endif
            </div>
            {!! Form::close() !!}
        </div>
    </div>
</div>
@push('custom-script')
    <script>
        $(document).on("change", "select[name='invoice_template'], input[name='invoice_color']", function() {
            var template = $("select[name='invoice_template']").val();
            var color = $("input[name='invoice_color']:checked").val();
            $('#purchase_frame').attr('src', '{{ url('invoices/preview') }}/' + template + '/' + color);
        });
    </script>
@endpush
