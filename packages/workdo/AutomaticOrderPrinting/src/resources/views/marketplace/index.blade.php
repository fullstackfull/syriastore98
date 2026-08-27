@extends('marketplace.marketplace')
@php
    $path = url("/packages/workdo/AutomaticOrderPrinting/src/marketplace");
@endphp
@section('page-title')
    {{ __('Software Details') }}
@endsection
@section('content')
<!-- wrapper start -->
<div class="wrapper">
    <section class="product-main-section padding-bottom padding-top">
        <div class="offset-container offset-left">
            <div class="row row-gap align-items-center pdp-summery-row">
                <div class="col-lg-6 col-md-6 col-12 pdp-left-side">
                    <div class="pdp-summery">
                        <div class="section-title">
                            <h2>{{ $module->alias }}</h2>
                        </div>
                        <p>{{__('The Automatic Order Printing Module of eCommerceGo SaaS allows you to create invoices that are as unique as your brand. With a wide selection of templates and color schemes, you can design invoices that reflect your brands identity and enhance the customer experience. This customization extends to adding your logo and other brand elements, ensuring every invoice reinforces your brand image. The flexibility to choose and modify designs means your invoices can range from simple and clean to detailed and vibrant, catering to different customer preferences.')}}</p>
                        <div class="cart-view btn-group">
                            <a href="#" class="link-btn">{{__('View Live Demo')}} <svg xmlns="http://www.w3.org/2000/svg"
                                    width="20" height="20" viewBox="0 0 20 20" fill="none">
                                    <g clip-path="url(#clip0_78_820)">
                                        <path
                                            d="M9.33984 1.18359L9.33985 18.519L4.612 18.519C2.87125 18.519 1.4601 17.1079 1.4601 15.3671L1.4601 4.33549C1.4601 2.59475 2.87125 1.18359 4.612 1.18359L9.33984 1.18359Z"
                                            fill="#10B77F" />
                                        <path fill-rule="evenodd" clip-rule="evenodd"
                                            d="M17.222 19.3066C18.5276 19.3066 19.5859 18.2483 19.5859 16.9427L19.5859 15.6294L19.5859 2.75918C19.5859 1.45362 18.5276 0.39526 17.222 0.39526L10.1302 0.39526L9.77566 0.39526C9.34047 0.39526 8.98768 0.748047 8.98768 1.18324C8.98768 1.61842 9.34047 1.97121 9.77566 1.97121L10.1302 1.97121L17.222 1.97121C17.6572 1.97121 18.01 2.324 18.01 2.75918L18.01 15.6294L18.01 16.9427C18.01 17.3779 17.6572 17.7307 17.222 17.7307L10.1302 17.7307L9.77566 17.7307C9.34047 17.7307 8.98769 18.0835 8.98769 18.5187C8.98769 18.9539 9.34047 19.3066 9.77566 19.3066L10.1302 19.3066L17.222 19.3066ZM7.72693 18.5187C7.72693 18.0835 7.37414 17.7307 6.93895 17.7307L6.22977 17.7307C5.79459 17.7307 5.4418 18.0835 5.4418 18.5187C5.4418 18.9539 5.79459 19.3066 6.22977 19.3066L6.93895 19.3066C7.37414 19.3066 7.72693 18.9539 7.72693 18.5187ZM7.72693 1.18324C7.72693 0.748047 7.37414 0.39526 6.93895 0.39526L6.22977 0.39526C5.79459 0.39526 5.4418 0.748047 5.4418 1.18324C5.4418 1.61842 5.79459 1.97121 6.22977 1.97121L6.93895 1.97121C7.37414 1.97121 7.72693 1.61842 7.72693 1.18324ZM4.18104 18.5187C4.18104 18.0835 3.82825 17.7307 3.39307 17.7307L3.03848 17.7307C2.99569 17.7307 2.95423 17.7274 2.9142 17.7211C2.48429 17.6535 2.08101 17.9472 2.01344 18.3772C1.94588 18.8071 2.23962 19.2103 2.66953 19.2779C2.79021 19.2969 2.91347 19.3066 3.03848 19.3066L3.39307 19.3066C3.82825 19.3066 4.18104 18.9539 4.18104 18.5187ZM4.18104 1.18324C4.18104 0.748048 3.82825 0.395261 3.39307 0.395261L3.03848 0.395261C2.91347 0.395261 2.7902 0.405034 2.66953 0.423997C2.23962 0.491559 1.94588 0.894841 2.01344 1.32475C2.08101 1.75466 2.48429 2.0484 2.9142 1.98084C2.95423 1.97455 2.99569 1.97121 3.03848 1.97121L3.39307 1.97121C3.82825 1.97121 4.18104 1.61842 4.18104 1.18324ZM1.60405 17.9678C2.03396 17.9002 2.3277 17.4969 2.26014 17.067C2.25384 17.027 2.25051 16.9855 2.25051 16.9427L2.25051 16.5881C2.25051 16.1529 1.89772 15.8002 1.46253 15.8002C1.02735 15.8002 0.674557 16.1529 0.674557 16.5881L0.674557 16.9427C0.674557 17.0677 0.68433 17.191 0.703293 17.3117C0.770857 17.7416 1.17414 18.0353 1.60405 17.9678ZM1.60405 1.73415C1.17414 1.66659 0.770856 1.96033 0.703292 2.39024C0.684329 2.51091 0.674556 2.63417 0.674556 2.75918L0.674556 3.11377C0.674556 3.54896 1.02734 3.90175 1.46253 3.90175C1.89772 3.90175 2.2505 3.54896 2.2505 3.11377L2.2505 2.75918C2.2505 2.7164 2.25384 2.67493 2.26013 2.6349C2.3277 2.20499 2.03396 1.80171 1.60405 1.73415ZM1.46253 14.5394C1.89772 14.5394 2.25051 14.1866 2.25051 13.7514L2.25051 13.0422C2.25051 12.6071 1.89772 12.2543 1.46253 12.2543C1.02735 12.2543 0.674556 12.6071 0.674556 13.0422L0.674556 13.7514C0.674557 14.1866 1.02735 14.5394 1.46253 14.5394ZM1.46253 10.9935C1.89772 10.9935 2.25051 10.6407 2.25051 10.2055L2.25051 9.49636C2.2505 9.06118 1.89772 8.70839 1.46253 8.70839C1.02735 8.70839 0.674556 9.06118 0.674556 9.49636L0.674556 10.2055C0.674556 10.6407 1.02735 10.9935 1.46253 10.9935ZM1.46253 7.44763C1.89772 7.44763 2.2505 7.09484 2.2505 6.65966L2.2505 5.95048C2.2505 5.51529 1.89772 5.16251 1.46253 5.16251C1.02735 5.16251 0.674556 5.51529 0.674556 5.95048L0.674556 6.65966C0.674556 7.09484 1.02735 7.44763 1.46253 7.44763ZM6.97806 9.06298C6.54288 9.06298 6.19009 9.41577 6.19009 9.85095C6.19009 10.2861 6.54288 10.6389 6.97806 10.6389L11.3798 10.6389L10.3611 11.6577C10.0534 11.9654 10.0534 12.4643 10.3611 12.7721C10.6688 13.0798 11.1677 13.0798 11.4754 12.7721L13.8394 10.4081C13.9871 10.2604 14.0702 10.0599 14.0702 9.85095C14.0702 9.64197 13.9871 9.44154 13.8394 9.29377L11.4754 6.92985C11.1677 6.62213 10.6688 6.62213 10.3611 6.92985C10.0534 7.23757 10.0534 7.73649 10.3611 8.04421L11.3798 9.06298L6.97806 9.06298Z"
                                            fill="#002332" />
                                    </g>
                                    <defs>
                                        <clipPath id="clip0_78_820">
                                            <rect width="18.9114" height="18.9114" fill="white"
                                                transform="translate(0.675781 0.395508)" />
                                        </clipPath>
                                    </defs>
                                </svg>
                            </a>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 col-md-6 col-12 pdp-left-side">
                    <div class="pdp-image-wrapper">
                        <div class="pdp-media banner-img-wrapper">
                            <img src="{{ asset('public/market_assets/images/banner-image.png')}}" alt="">
                            <img src="{{$path.'/image1.png'}}" alt="" class="inner-frame-img">
                       </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <section class="dedicated-themes-section padding-bottom">
        <div class="container">
            <div class="section-title text-center">
                <h2>{{__('Instant Printing for Immediate Order Processing')}}</h2>
                <p>{{__('Efficiency is key in eCommerce, and our module delivers by instantly printing order details upon purchase. As soon as an order is confirmed, the invoice is automatically printed, and ready for packing and dispatch. This real-time printing capability eliminates delays associated with manual order processing, ensuring your customers receive their products faster. The immediate documentation also helps maintain accurate records, streamlining your fulfillment process and boosting overall operational efficiency.')}}</p>
            </div>
            <div class="row row-gap align-items-center padding-bottom">
                <div class="col-lg-5 col-md-6 col-12">
                    <div class="abt-theme">
                        <div class="section-title">
                            <div class="subtitle">

                            </div>
                            <h2>{{__('Streamlined Order Management')}}</h2>
                        </div>
                        <p>{{__('By automating the order printing process, eCommerceGo module significantly enhances your order management system. The centralized control panel allows you to manage all printing settings easily, from adjusting templates to overseeing the print queue. This streamlined approach not only saves time but also reduces the risk of errors, ensuring each order is accurately documented and processed. With less manual intervention required, your team can focus on other critical aspects of the business, such as customer service and inventory management.')}}</p>
                    </div>
                </div>
                <div class="col-lg-7 col-md-6 col-12">
                    <div class="dash-theme-preview">
                        <img src="{{$path.'/image2.png'}}" alt="">
                    </div>
                </div>
            </div>

            <div class="row row-gap align-items-center padding-bottom">
                <div class="col-lg-7 col-md-6 col-12">
                    <div class="dash-theme-preview">
                        <img src="{{$path.'/image3.png'}}" alt="">
                    </div>
                </div>
                <div class="col-lg-5 col-md-6 col-12">
                    <div class="abt-theme">
                        <div class="section-title">
                            <div class="subtitle">

                            </div>
                            <h2>{{__('Enhanced Brand Consistency')}}</h2>
                        </div>
                        <p>{{__('Consistent branding is crucial for building trust and recognition, and our module helps you achieve this through customizable invoices. Every printed order will feature your chosen colors, templates, and branding elements, providing a cohesive look across all customer touchpoints. This consistency not only enhances your professional image but also reinforces your brands presence in the minds of your customers. Whether a customer orders once or multiple times, they will always receive a familiar and professional invoice, boosting their confidence in your business.')}}</p>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <section class="why-choose-section padding-top padding-bottom">
        <div class="container">
            <div class="section-title text-center">
                <h2>{{__('Why choose dedicated modules')}} <b>{{__('for your business?')}}</b></h2>
                <p>{{__('With eCommerceGo, you can conveniently manage all your business functions from a single location')}}</p>
            </div>
            <div class="pricing-area">
                <div class="section-title">
                    <h2>{{__('Empower Your Workforce with eCommerceGo')}}</h2>
                    <p>{{__('Access over Premium Add-ons for Product Enquiry, Product Review, Product Quick-view, and more, all in one place!')}}</p>
                </div>
                <ul class="variable-list">
                    <li>
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                            fill="none">
                            <path
                                d="M12 2C6.48 2 2 6.48 2 12C2 17.52 6.48 22 12 22C17.52 22 22 17.52 22 12C22 6.48 17.52 2 12 2ZM16.03 10.2L11.36 14.86C11.22 15.01 11.03 15.08 10.83 15.08C10.64 15.08 10.45 15.01 10.3 14.86L7.97 12.53C7.68 12.24 7.68 11.76 7.97 11.47C8.26 11.18 8.74 11.18 9.03 11.47L10.83 13.27L14.97 9.14001C15.26 8.84001 15.74 8.84001 16.03 9.14001C16.32 9.43001 16.32 9.90001 16.03 10.2Z"
                                fill="white" />
                        </svg>
                        {{__('Pay-as-you-go')}}
                    </li>
                    <li>
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                            fill="none">
                            <path
                                d="M12 2C6.48 2 2 6.48 2 12C2 17.52 6.48 22 12 22C17.52 22 22 17.52 22 12C22 6.48 17.52 2 12 2ZM16.03 10.2L11.36 14.86C11.22 15.01 11.03 15.08 10.83 15.08C10.64 15.08 10.45 15.01 10.3 14.86L7.97 12.53C7.68 12.24 7.68 11.76 7.97 11.47C8.26 11.18 8.74 11.18 9.03 11.47L10.83 13.27L14.97 9.14001C15.26 8.84001 15.74 8.84001 16.03 9.14001C16.32 9.43001 16.32 9.90001 16.03 10.2Z"
                                fill="white" />
                        </svg>
                        {{__('Unlimited installation')}}
                    </li>
                    <li>
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                            fill="none">
                            <path
                                d="M12 2C6.48 2 2 6.48 2 12C2 17.52 6.48 22 12 22C17.52 22 22 17.52 22 12C22 6.48 17.52 2 12 2ZM16.03 10.2L11.36 14.86C11.22 15.01 11.03 15.08 10.83 15.08C10.64 15.08 10.45 15.01 10.3 14.86L7.97 12.53C7.68 12.24 7.68 11.76 7.97 11.47C8.26 11.18 8.74 11.18 9.03 11.47L10.83 13.27L14.97 9.14001C15.26 8.84001 15.74 8.84001 16.03 9.14001C16.32 9.43001 16.32 9.90001 16.03 10.2Z"
                                fill="white" />
                        </svg>
                        {{__('Secure cloud storage')}}
                    </li>
                </ul>
            </div>
        </div>
    </section>
    <section class="screenshot-section padding-top padding-bottom">
        <div class="container">
            <div class="section-title text-center ">
                <h2><b></b></h2>
            </div>
            <div class="screenshot-slider">
                <div class="screenshot-itm">
                    <div class="screenshot-image">
                        <a href="{{$path.'/image2.png'}}" target="_blank" class="img-zoom">
                            <img src="{{$path.'/image2.png'}}" alt="">
                        </a>
                    </div>
                </div>
                <div class="screenshot-itm">
                    <div class="screenshot-image">
                        <a href="{{$path.'/image3.png'}}" target="_blank" class="img-zoom">
                            <img src="{{$path.'/image3.png'}}" alt="">
                        </a>
                    </div>
                </div>
                <div class="screenshot-itm">
                    <div class="screenshot-image">
                        <a href="{{$path.'/image1.png'}}" target="_blank" class="img-zoom">
                            <img src="{{$path.'/image1.png'}}" alt="">
                        </a>
                    </div>
                </div>
                <div class="screenshot-itm">
                    <div class="screenshot-image">
                        <a href="{{$path.'/image2.png'}}" target="_blank" class="img-zoom">
                            <img src="{{$path.'/image2.png'}}" alt="">
                        </a>
                    </div>
                </div>
                <div class="screenshot-itm">
                    <div class="screenshot-image">
                        <a href="{{$path.'/image3.png'}}" target="_blank" class="img-zoom">
                            <img src="{{$path.'/image3.png'}}" alt="">
                        </a>
                    </div>
                </div>
                <div class="screenshot-itm">
                    <div class="screenshot-image">
                        <a href="{{$path.'/image1.png'}}" target="_blank" class="img-zoom">
                            <img src="{{$path.'/image1.png'}}" alt="">
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <section class="bg-white padding-top padding-bottom ">
        <div class="container">
            <div class="section-title text-center">
                <h2>{{__('Why choose dedicated modules')}} <b>{{__('for your business?')}}</b></h2>
                <p>{{__('With EcommerceGo, you can conveniently manage all your business functions from a single location')}}</p>
            </div>
            @if (count($modules) > 0)
                <div class="row product-row">
                    @foreach ($modules as $module)
                        @if (!isset($module->display) || $module->display == true)
                        <div class="col-xl-2 col-lg-4 col-md-4 col-sm-6 col-12 product-card">
                            <div class="product-card-inner">
                                <div class="product-img">
                                    <a href="{{ route('software.details',$module->alias) }}">
                                        <img src="{{ $module->image ?? 'assets/images/Custom-Fields.png' }}" alt="">
                                    </a>
                                </div>
                                <div class="product-content">
                                    <h4> <a href="javascript::void(0)">{{ $module->alias ?? '' }}</a> </h4>
                                    <a href="{{ route('software.details',$module->alias) }}" target="_new"  class="btn cart-btn">{{__('View Details')}}</a>
                                </div>
                            </div>
                        </div>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>
    </section>
</div>
<!-- wrapper end -->
@endsection

