<style>
    .successful-payment .payment-card {
        max-width: 650px;
        width: 100%;
        display: flex;
        flex-direction: column;
        justify-content: center;
        text-align: center;
        gap: 15px;
    }

    .successful-payment .payment-card .back-btn {
        justify-content: center;
        margin-bottom: 20px;
    }

    .successful-payment .payment-card-icon {
        width: 100px;
        height: 100px;
        display: flex;
        align-items: center;
        margin: auto;

        position: relative;
        margin-bottom: 10px;

    }

    .successful-payment .payment-card-icon .check-icon {
        position: absolute;
        top: 50%;
        left: 50%;
        z-index: 10;
        transform: translate3d(-50%, -50%, 0);
        fill: #fff;
    }

    .successful-payment .payment-card-icon .check-bg {
        fill: rgb(37, 211, 102);
        animation: rotate 35s linear both infinite;
    }

    .successful-payment .payment-details {
        border: 1px solid var(--border-color);
        padding: 15px;
        max-width: 400px;
        width: 100%;
        margin: auto;
    }

    .successful-payment .detail-row {
        align-items: center;
        gap: 10px;
    }

    .successful-payment .detail-row .row-left {
        flex: 1;
        text-align: left;
    }

    .successful-payment .detail-row:not(:last-of-type) {
        margin-bottom: 10px;
    }

    @keyframes rotate {
        0% {
            transform: rotate(0deg);
        }

        100% {
            transform: rotate(360deg);
        }
    }

    @media only screen and (max-width: 575px) {
        .successful-payment .payment-card-icon {
            width: 80px;
            height: 80px;
        }
    }

    [lang="ar"] .successful-payment .detail-row .row-left {
        text-align: right;
    }
</style>
@extends('front_end.layouts.app')
@section('page-title')
    {{ __('Donation Form') }}
@endsection
@section('content')
    @include('front_end.partison.header')
    <section class="successful-payment padding-top padding-bottom">
        <div class="container">
            <div class="d-flex justify-content-center">
                <div class="payment-card">
                    <a href="{{ route('landing_page', $slug) }}" class="back-btn">
                        <span class="svg-ic">
                            <svg xmlns="http://www.w3.org/2000/svg" width="11" height="5" viewBox="0 0 11 5"
                                fill="none">
                                <path fill-rule="evenodd" clip-rule="evenodd"
                                    d="M10.5791 2.28954C10.5791 2.53299 10.3818 2.73035 10.1383 2.73035L1.52698 2.73048L2.5628 3.73673C2.73742 3.90636 2.74146 4.18544 2.57183 4.36005C2.40219 4.53467 2.12312 4.53871 1.9485 4.36908L0.133482 2.60587C0.0480403 2.52287 -0.000171489 2.40882 -0.000171488 2.2897C-0.000171486 2.17058 0.0480403 2.05653 0.133482 1.97353L1.9485 0.210321C2.12312 0.0406877 2.40219 0.044729 2.57183 0.219347C2.74146 0.393966 2.73742 0.673036 2.5628 0.842669L1.52702 1.84888L10.1383 1.84875C10.3817 1.84874 10.5791 2.04609 10.5791 2.28954Z"
                                    fill="white"></path>
                            </svg>
                        </span>
                        {{ __('Back to Home') }}
                    </a>
                    <span class="payment-card-icon">
                        <svg class="check-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512">
                            <path
                                d="M438.6 105.4c12.5 12.5 12.5 32.8 0 45.3l-256 256c-12.5 12.5-32.8 12.5-45.3 0l-128-128c-12.5-12.5-12.5-32.8 0-45.3s32.8-12.5 45.3 0L160 338.7 393.4 105.4c12.5-12.5 32.8-12.5 45.3 0z" />
                        </svg>
                        <svg class="check-bg" height="115" viewBox="0 0 120 115" width="120"
                            xmlns="http://www.w3.org/2000 svg">
                            <path
                                d="M107.332 72.938c-1.798 5.557 4.564 15.334 1.21 19.96-3.387 4.674-14.646 1.605-19.298 5.003-4.61 3.368-5.163 15.074-10.695 16.878-5.344 1.743-12.628-7.35-18.545-7.35-5.922 0-13.206 9.088-18.543 7.345-5.538-1.804-6.09-13.515-10.696-16.877-4.657-3.398-15.91-.334-19.297-5.002-3.356-4.627 3.006-14.404 1.208-19.962C10.93 67.576 0 63.442 0 57.5c0-5.943 10.93-10.076 12.668-15.438 1.798-5.557-4.564-15.334-1.21-19.96 3.387-4.674 14.646-1.605 19.298-5.003C35.366 13.73 35.92 2.025 41.45.22c5.344-1.743 12.628 7.35 18.545 7.35 5.922 0 13.206-9.088 18.543-7.345 5.538 1.804 6.09 13.515 10.696 16.877 4.657 3.398 15.91.334 19.297 5.002 3.356 4.627-3.006 14.404-1.208 19.962C109.07 47.424 120 51.562 120 57.5c0 5.943-10.93 10.076-12.668 15.438z">
                            </path>
                        </svg>
                    </span>
                    <h2>{{__('Payment Successful')}}</h2>
                    <p>{{__('Thank you')}}!</p>
                    <div class="payment-details">
                        <div class="detail-row d-flex justify-content-between">
                            <span class="row-left">{{__('Donation Amount')}}:</span>
                            <span class="row-right"><b>{{ currency_format_with_sym(($donation_data->amount ?? 0), $store->id) }}</b></span>
                        </div>
                        <div class="detail-row d-flex justify-content-between">
                            <span class="row-left">{{__('Payment Type')}}:</span>
                            <span class="row-right"><b>{{ $donation_data->payment_type }}</b></span>
                        </div>
                        <div class="detail-row d-flex justify-content-between">
                            <span class="row-left">{{__('Transaction Date')}}:</span>
                            <span class="row-right"><b>{{ $donation_data->created_at }}</b></span>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </section>
    @include('front_end.sections.partision.footer_section')
@endsection
