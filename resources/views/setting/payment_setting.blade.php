<!-- Payment Setting -->
<div class="card" id="Payment_Setting">
    <div class="card-header">
        <div class="float-end">
            <div class="badge bg-success p-2 px-3 rounded"></div>
        </div>
        <h5>{{ __('Payment Settings') }}</h5>
        <small class="text-muted">{{ __('Configure your payment gateways for accepting online payments') }}</small>
    </div>
    {{ Form::open(['route' => 'payment.settings', 'method' => 'post', 'enctype' => 'multipart/form-data']) }}
    <div class="card-body">
        <div class="faq">
            <div class="row">
                <div class="col-12">
                    <div class="accordion accordion-flush" id="payment-gateways">
                       

                        <div class="mb-3">
                            <div class="search-box">
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-light"><i class="ti ti-search"></i></span>
                                    <input type="text" class="form-control border-light" id="payment-search" placeholder="{{ __('Search payment gateway...') }}">
                                </div>
                            </div>
                        </div>

                        <div class="gateway-section">
                            <!-- Admin-only gateways -->
                                @foreach($paymentGateways as $gateway)
                                    @if (auth()->user() && auth()->user()->type == 'admin' && isset($gateway['is_only_admin']) && ($gateway['is_only_admin'] == true || $gateway['is_only_admin'] == false))
                                        @include('setting.payment_partials.payment_gateway', ['gateway' => $gateway])
                                    @elseif (auth()->user() && auth()->user()->type != 'admin' && isset($gateway['is_only_admin']) && $gateway['is_only_admin'] == false)
                                    @include('setting.payment_partials.payment_gateway', ['gateway' => $gateway])
                                    @endif
                                @endforeach

                                
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-end flex-wrap">
        <input type="submit" value="{{ __('Save Changes') }}" class="btn-submit btn btn-primary">
    </div>
    {!! Form::close() !!}
</div>
<!-- End Payment Setting -->