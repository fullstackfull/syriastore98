
<style>
    @media screen and (max-width:767px) {
        .print-setting-wrp iframe{
            height: 450px !important;
        }
    }
</style>
<div id="Print_Setting">
    <div class="card">
        {{ Form::open(['route' => ['automatic-order.print.setting'], 'method' => 'post', 'enctype' => 'multipart/form-data']) }}
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
            <div class="row card-body-inner">
                <div class="col-sm-6">
                    <div class="row">
                        <div class="col-6 form-group">
                            <label for="print_footer" class="form-label">{{ __('Footer title') }}</label>
                            {{ Form::text('footer', isset($settings['footer']) ? $settings['footer'] : null, ['class' => 'form-control', 'placeholder' => __('eCommerce 2024'), 'id' => 'print_footer']) }}
                        </div>

                        <div class="col-6 form-group">
                            <label for="print_footer_notes" class="form-label">{{ __('Footer Notes') }}</label>
                            {{ Form::text('footer_notes', isset($settings['footer_notes']) ? $settings['footer_notes'] : null, ['class' => 'form-control', 'placeholder' => __('eCommerce 2024'), 'id' => 'print_footer_notes']) }}
                        </div>
                        <div class="col-12">

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
                                        {{ $template }}
                                    </option>
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
                            <div class="card-footer d-flex justify-content-end flex-wrap ">
                                <input type="submit" value="{{ __('Save Changes') }}" class="btn-submit btn-print-invoice btn btn-primary btn-badge">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-sm-6">
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
</div>