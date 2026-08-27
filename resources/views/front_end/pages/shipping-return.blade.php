@extends('front_end.layouts.app')

@section('page-title')
{{ __('Error') }}
@endsection

@section('content')
<main>
    @if ($themeSettings['wishlist_banner_status'] && $themeSettings['wishlist_banner_status'] == '1')
    <!-- Common Banner Section -->
    <section class="banner-section relative lg:py-16 py-10 bg-cover bg-center"
        style="background-image: url('{{ get_file($themeSettings['wishlist_banner_image'] ?? '') }}');">
        <div class="md:container w-full mx-auto px-4">
            <div class="text-center relative z-[2]">
                <h2 class="text-[26px] sm:text-4xl lg:text-5xl font-bold mb-4 capitalize">
                    {{ $themeSettings['wishlist_banner_title'] ?? __('Wishlist') }}</h2>
            </div>
        </div>
    </section>
    @endif

    @if ($themeSettings['wishlist_status'] && $themeSettings['wishlist_status'] == '1')
    <section class="lg:py-20 py-10">
      <div class="md:container w-full mx-auto px-4">
          <div class="max-w-4xl mx-auto">
              <!-- Shipping Policy Section -->
              <div id="shipping" class="lg:mb-16 mb-10">
                  <div class="flex items-center mb-6">
                      <div
                          class="bg-primary text-white sm:h-12 sm:w-12 h-10 w-10 rounded-full flex items-center justify-center mr-4">
                          <i class="fas fa-warehouse text-white"></i>
                      </div>
                      <h2 class="flex-1 text-2xl lg:text-3xl font-bold text-gray-900">Shipping Policy</h2>
                  </div>

                  <div>
                      <p class="mb-5">At greentic, we're committed to delivering top-quality electronics to your
                          doorstep quickly and reliably. We offer multiple shipping options to suit your needs.
                      </p>

                      <h3 class="text-xl font-bold text-gray-900">Shipping Methods</h3>
                      <div class="my-6">
                          <div class="grid grid-cols-1 md:grid-cols-2 md:gap-6 gap-4">
                              <!-- Standard Delivery -->
                              <div class="border border-gray-200 rounded-lg p-4 bg-gray-50">
                                  <div class="flex justify-between items-start mb-2">
                                      <h4 class="font-semibold text-lg">Standard Delivery</h4>
                                      <span class="text-primary font-semibold">$4.99</span>
                                  </div>
                                  <p class="text-gray-600 text-sm mb-2">Delivered within 1-2 business days</p>
                                  <ul class="text-sm text-gray-600 space-y-1">
                                      <li class="flex items-start">
                                          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                              viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                              stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                              class="h-4 w-4 text-primary mr-2 mt-0.5">
                                              <polyline points="20 6 9 17 4 12"></polyline>
                                          </svg>
                                          <span class="flex-1">Available for orders placed before 5 PM</span>
                                      </li>
                                      <li class="flex items-start">
                                          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                              viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                              stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                              class="h-4 w-4 text-primary mr-2 mt-0.5">
                                              <polyline points="20 6 9 17 4 12"></polyline>
                                          </svg>
                                          <span class="flex-1">Free for orders over $50</span>
                                      </li>
                                      <li class="flex items-start">
                                          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                              viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                              stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                              class="h-4 w-4 text-primary mr-2 mt-0.5">
                                              <polyline points="20 6 9 17 4 12"></polyline>
                                          </svg>
                                          <span class="flex-1">Order tracking available</span>
                                      </li>
                                  </ul>
                              </div>

                              <!-- Express Delivery -->
                              <div class="border border-gray-200 rounded-lg p-4 bg-gray-50">
                                  <div class="flex justify-between items-start mb-2">
                                      <h4 class="font-semibold text-lg">Express Delivery</h4>
                                      <span class="text-primary font-semibold">$9.99</span>
                                  </div>
                                  <p class="text-gray-600 text-sm mb-2">Same-day or next-day delivery for eligible
                                      electronics</p>
                                  <ul class="text-sm text-gray-600 space-y-1">
                                      <li class="flex items-start">
                                          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                              viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                              stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                              class="h-4 w-4 text-primary mr-2 mt-0.5">
                                              <polyline points="20 6 9 17 4 12"></polyline>
                                          </svg>
                                          <span class="flex-1">Same-day delivery for orders placed before 12
                                              PM</span>
                                      </li>
                                      <li class="flex items-start">
                                          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                              viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                              stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                              class="h-4 w-4 text-primary mr-2 mt-0.5">
                                              <polyline points="20 6 9 17 4 12"></polyline>
                                          </svg>
                                          <span class="flex-1">Next-day delivery for orders placed after 12
                                              PM</span>
                                      </li>
                                      <li class="flex items-start">
                                          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                              viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                              stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                              class="h-4 w-4 text-primary mr-2 mt-0.5">
                                              <polyline points="20 6 9 17 4 12"></polyline>
                                          </svg>
                                          <span class="flex-1">Real-time order tracking</span>
                                      </li>
                                  </ul>
                              </div>
                          </div>

                          <!-- Subscription Delivery -->
                          <div class="border border-gray-200 rounded-lg p-4 bg-gray-50 md:mt-6 mt-4">
                              <div class="flex justify-between items-start mb-2">
                                  <h4 class="font-semibold text-lg">Subscription Delivery</h4>
                                  <span class="text-primary font-semibold">Free</span>
                              </div>
                              <p class="text-gray-600 text-sm mb-2">Scheduled delivery service for office or
                                  business essentials</p>
                              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                  <ul class="text-sm text-gray-600 space-y-1">
                                      <li class="flex items-start">
                                          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                              viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                              stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                              class="h-4 w-4 text-primary mr-2 mt-0.5">
                                              <polyline points="20 6 9 17 4 12"></polyline>
                                          </svg>
                                          <span class="flex-1">Free shipping on all subscription orders</span>
                                      </li>
                                      <li class="flex items-start">
                                          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                              viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                              stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                              class="h-4 w-4 text-primary mr-2 mt-0.5">
                                              <polyline points="20 6 9 17 4 12"></polyline>
                                          </svg>
                                          <span class="flex-1">Choose weekly, bi-weekly, or monthly
                                              deliveries</span>
                                      </li>
                                  </ul>
                                  <ul class="text-sm text-gray-600 space-y-1">
                                      <li class="flex items-start">
                                          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                              viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                              stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                              class="h-4 w-4 text-primary mr-2 mt-0.5">
                                              <polyline points="20 6 9 17 4 12"></polyline>
                                          </svg>
                                          <span class="flex-1">Flexible delivery dates and times</span>
                                      </li>
                                      <li class="flex items-start">
                                          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                              viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                              stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                              class="h-4 w-4 text-primary mr-2 mt-0.5">
                                              <polyline points="20 6 9 17 4 12"></polyline>
                                          </svg>
                                          <span class="flex-1">Easily modify or pause your subscription
                                              anytime</span>
                                      </li>
                                  </ul>
                              </div>
                          </div>
                      </div>

                      <h3 class="text-xl font-bold text-gray-900 mb-4">Service Area</h3>
                      <p class="mb-4">We currently deliver electronics to the following regions:</p>
                      <ul class="list-disc ps-5 mb-4 space-y-1">
                          <li>San Francisco and surrounding Bay Area</li>
                          <li>Los Angeles metropolitan area</li>
                          <li>Portland, Oregon</li>
                          <li>Seattle, Washington</li>
                          <li>Denver, Colorado</li>
                      </ul>
                      <p class="mb-4">We're expanding rapidly. To check delivery availability in your area, enter
                          your zip code at checkout or reach out to our support team.</p>

                      <h3 class="text-xl font-bold text-gray-900 mb-4">Shipping Restrictions</h3>
                      <p>Due to certain product types and manufacturer restrictions, some electronics may not be
                          available for shipping to all locations. We will notify you at checkout if your selected
                          items cannot be shipped to your address.</p>
                  </div>
              </div>

              <!-- Delivery Information Section -->
              <div id="delivery" class="lg:mb-16 mb-10">
                  <div class="flex items-center mb-6">
                      <div
                          class="bg-primary text-white sm:h-12 sm:w-12 h-10 w-10 rounded-full flex items-center justify-center mr-4 text-lg">
                          <i class="fas fa-truck"></i>
                      </div>
                      <h2 class="flex-1 text-2xl lg:text-3xl font-bold text-gray-900">Delivery Information</h2>
                  </div>

                  <div>
                      <h3 class="text-xl font-bold text-gray-900 mb-4">Delivery Times</h3>
                      <p>At greentic, we ensure fast and flexible delivery for all your tech needs. Standard
                          deliveries typically arrive between 10 AM and 8 PM. For urgent needs, express deliveries
                          can be scheduled within specific time windows.</p>

                      <div class="my-6">
                          <h4 class="font-semibold text-lg mb-4">Available Delivery Windows (Express Delivery)
                          </h4>
                          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                              <div class="border rounded-lg overflow-hidden">
                                  <div class="bg-primary text-white px-4 py-2 font-medium">Weekdays</div>
                                  <div class="p-4 space-y-2 text-gray-600">
                                      <p class="flex justify-between">
                                          <span>Morning</span>
                                          <span>10 AM - 1 PM</span>
                                      </p>
                                      <p class="flex justify-between">
                                          <span>Afternoon</span>
                                          <span>1 PM - 4 PM</span>
                                      </p>
                                      <p class="flex justify-between">
                                          <span>Evening</span>
                                          <span>5 PM - 8 PM</span>
                                      </p>
                                  </div>
                              </div>

                              <div class="border rounded-lg overflow-hidden">
                                  <div class="bg-primary text-white px-4 py-2 font-medium">Weekends</div>
                                  <div class="p-4 space-y-2 text-gray-600">
                                      <p class="flex justify-between">
                                          <span>Morning</span>
                                          <span>9 AM - 12 PM</span>
                                      </p>
                                      <p class="flex justify-between">
                                          <span>Afternoon</span>
                                          <span>12 PM - 3 PM</span>
                                      </p>
                                      <p class="flex justify-between">
                                          <span>Evening</span>
                                          <span>4 PM - 7 PM</span>
                                      </p>
                                  </div>
                              </div>
                          </div>
                      </div>

                      <h3 class="text-xl font-bold text-gray-900 mb-4">Packaging & Protection</h3>
                      <p class="mb-4">We use secure and protective packaging to ensure your electronics arrive in
                          perfect condition:</p>
                      <ul class="list-disc ps-5 mb-4 space-y-1">
                          <li>Shock-absorbing materials for fragile electronics</li>
                          <li>Anti-static packaging for sensitive components</li>
                          <li>Eco-friendly, recyclable outer boxes</li>
                          <li>Sealed and tamper-proof packaging for security</li>
                      </ul>

                      <h3 class="text-xl font-bold text-gray-900 mb-4">Tracking Your Delivery</h3>
                      <p class="mb-4">Stay updated on your order every step of the way:</p>
                      <ol class="list-decimal ps-5 mb-4 space-y-1">
                          <li>Receive a tracking link via email and SMS after dispatch</li>
                          <li>Get real-time status updates and ETA notifications</li>
                          <li>Receive alerts when your delivery is about to arrive</li>
                          <li>Communicate directly with the delivery agent for any instructions</li>
                      </ol>

                      <h3 class="text-xl font-bold text-gray-900 mb-4">Contactless Delivery</h3>
                      <p>We provide contactless delivery for your safety and convenience. At checkout, select the
                          contactless option and include specific instructions. Our delivery team will follow your
                          preferences and notify you once the package is delivered.</p>

                      <div class="bg-primary/10 md:p-6 p-4 rounded-lg my-6 ltr:border-l-4 rtl:border-r-4 border-primary">
                          <h4 class="font-semibold mb-2">Missing or Incomplete Deliveries</h4>
                          <p>If your order is missing items or hasn't arrived within the expected delivery window,
                              please contact our customer support team at <strong>(800) 987-6543</strong> or <a
                                  href="mailto:support@greentic.com" class="underline">support@greentic.com</a>.
                              We will resolve the issue promptly to ensure you receive your complete order as soon
                              as possible.</p>
                      </div>
                  </div>
              </div>

              <!-- Returns & Refunds Section -->
              <div id="returns">
                  <div class="flex items-center mb-6">
                      <div
                          class="bg-primary text-white sm:h-12 sm:w-12 h-10 w-10 rounded-full flex items-center justify-center mr-4">
                          <i class="fas fa-undo-alt text-white"></i>
                      </div>
                      <h2 class="flex-1 text-2xl lg:text-3xl font-bold text-gray-900">Returns & Refunds</h2>
                  </div>

                  <div>
                      <h3 class="text-xl font-bold text-gray-900 mb-4">100% Satisfaction Guarantee</h3>
                      <p>At greentic, your satisfaction is our top priority. If you're not completely satisfied
                          with the
                          quality, functionality, or condition of your order, we offer a hassle-free return and
                          refund process.</p>

                      <div class="bg-gray-50 rounded-lg my-6 md:p-6 p-4">
                          <h4 class="font-semibold text-lg mb-4">Our Guarantee Covers:</h4>
                          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                              <div>
                                  <h5 class="font-medium mb-2 flex items-center">
                                      <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                          viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                          stroke-linecap="round" stroke-linejoin="round"
                                          class="h-5 w-5 text-primary mr-2">
                                          <path d="M5.5 8.5 9 12l-3.5 3.5L2 12l3.5-3.5Z" />
                                          <path d="m12 2 3.5 3.5L12 9 8.5 5.5 12 2Z" />
                                          <path d="M18.5 8.5 22 12l-3.5 3.5L15 12l3.5-3.5Z" />
                                          <path d="m12 15 3.5 3.5L12 22l-3.5-3.5L12 15Z" />
                                      </svg>
                                      Quality Issues
                                  </h5>
                                  <ul class="text-gray-600 space-y-1 text-sm">
                                      <li class="flex items-start">
                                          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                              viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                              stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                              class="h-4 w-4 text-primary mr-2 mt-0.5">
                                              <polyline points="20 6 9 17 4 12" />
                                          </svg>
                                          <span class="flex-1">Products that don't meet our performance
                                              standards</span>
                                      </li>
                                      <li class="flex items-start">
                                          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                              viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                              stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                              class="h-4 w-4 text-primary mr-2 mt-0.5">
                                              <polyline points="20 6 9 17 4 12" />
                                          </svg>
                                          <span class="flex-1">Damaged or defective items</span>
                                      </li>
                                      <li class="flex items-start">
                                          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                              viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                              stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                              class="h-4 w-4 text-primary mr-2 mt-0.5">
                                              <polyline points="20 6 9 17 4 12" />
                                          </svg>
                                          <span class="flex-1">Items that don't function as advertised</span>
                                      </li>
                                  </ul>
                              </div>

                              <div>
                                  <h5 class="font-medium mb-2 flex items-center">
                                      <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                          viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                          stroke-linecap="round" stroke-linejoin="round"
                                          class="h-5 w-5 text-primary mr-2">
                                          <path d="M5.5 8.5 9 12l-3.5 3.5L2 12l3.5-3.5Z" />
                                          <path d="m12 2 3.5 3.5L12 9 8.5 5.5 12 2Z" />
                                          <path d="M18.5 8.5 22 12l-3.5 3.5L15 12l3.5-3.5Z" />
                                          <path d="m12 15 3.5 3.5L12 22l-3.5-3.5L12 15Z" />
                                      </svg>
                                      Delivery Issues
                                  </h5>
                                  <ul class="text-gray-600 space-y-1 text-sm">
                                      <li class="flex items-start">
                                          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                              viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                              stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                              class="h-4 w-4 text-primary mr-2 mt-0.5">
                                              <polyline points="20 6 9 17 4 12" />
                                          </svg>
                                          <span class="flex-1">Missing items or components</span>
                                      </li>
                                      <li class="flex items-start">
                                          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                              viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                              stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                              class="h-4 w-4 text-primary mr-2 mt-0.5">
                                              <polyline points="20 6 9 17 4 12" />
                                          </svg>
                                          <span class="flex-1">Incorrect items or models</span>
                                      </li>
                                      <li class="flex items-start">
                                          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                              viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                              stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                              class="h-4 w-4 text-primary mr-2 mt-0.5">
                                              <polyline points="20 6 9 17 4 12" />
                                          </svg>
                                          <span class="flex-1">Late deliveries (beyond the promised window)</span>
                                      </li>
                                  </ul>
                              </div>
                          </div>
                      </div>

                      <h3 class="text-xl font-bold text-gray-900 mb-4">Return Process</h3>
                      <p class="mb-4">To initiate a return or request a refund, please follow these steps:</p>
                      <ol class="list-decimal ps-5 mb-4 space-y-1">
                          <li><strong>Contact us within 30 days</strong> of receiving your order</li>
                          <li>Report the issue through your account dashboard or by contacting customer service
                          </li>
                          <li>Provide your order number and details about the issue</li>
                          <li>Photos of the problematic items can help expedite the process (though not required)
                          </li>
                      </ol>

                      <p class="mb-4">For most electronics, you'll need to return the product in its original
                          packaging with all accessories. Our customer service team will provide return
                          instructions and may issue a prepaid shipping label.</p>

                      <h3 class="text-xl font-bold text-gray-900 mb-4">Refund Process</h3>
                      <p>Once your return or refund request is approved, we will process it according to the
                          following
                          guidelines:</p>

                      <div class="my-6">
                          <div class="grid grid-cols-1 md:grid-cols-3 md:gap-6 gap-4">
                              <!-- Full Refund -->
                              <div class="border rounded-lg overflow-hidden">
                                  <div class="bg-green-100 text-green-700 px-4 py-2 font-medium">Full Refund</div>
                                  <div class="p-4 text-gray-600 text-sm">
                                      <p class="mb-2">We offer a full refund when:</p>
                                      <ul class="space-y-1">
                                          <li class="flex items-start">
                                              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                  viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                  stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                                  class="h-4 w-4 text-green-600 mr-1 mt-0.5">
                                                  <polyline points="20 6 9 17 4 12" />
                                              </svg>
                                              <span class="flex-1">Items are DOA or defective</span>
                                          </li>
                                          <li class="flex items-start">
                                              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                  viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                  stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                                  class="h-4 w-4 text-green-600 mr-1 mt-0.5">
                                                  <polyline points="20 6 9 17 4 12" />
                                              </svg>
                                              <span class="flex-1">Performance is significantly below specs</span>
                                          </li>
                                          <li class="flex items-start">
                                              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                  viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                  stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                                  class="h-4 w-4 text-green-600 mr-1 mt-0.5">
                                                  <polyline points="20 6 9 17 4 12" />
                                              </svg>
                                              <span class="flex-1">Delivery is extremely late</span>
                                          </li>
                                      </ul>
                                  </div>
                              </div>

                              <!-- Partial Refund -->
                              <div class="border rounded-lg overflow-hidden">
                                  <div class="bg-yellow-100 text-yellow-700 px-4 py-2 font-medium">Partial Refund
                                  </div>
                                  <div class="p-4 text-gray-600 text-sm">
                                      <p class="mb-2">We may offer a partial refund when:</p>
                                      <ul class="space-y-1">
                                          <li class="flex items-start">
                                              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                  viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                  stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                                  class="h-4 w-4 text-yellow-600 mr-1 mt-0.5">
                                                  <polyline points="20 6 9 17 4 12" />
                                              </svg>
                                              <span class="flex-1">Minor cosmetic damage exists</span>
                                          </li>
                                          <li class="flex items-start">
                                              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                  viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                  stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                                  class="h-4 w-4 text-yellow-600 mr-1 mt-0.5">
                                                  <polyline points="20 6 9 17 4 12" />
                                              </svg>
                                              <span class="flex-1">Product is opened but unused</span>
                                          </li>
                                          <li class="flex items-start">
                                              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                  viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                  stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                                  class="h-4 w-4 text-yellow-600 mr-1 mt-0.5">
                                                  <polyline points="20 6 9 17 4 12" />
                                              </svg>
                                              <span class="flex-1">Missing non-essential accessories</span>
                                          </li>
                                      </ul>
                                  </div>
                              </div>

                              <!-- Store Credit -->
                              <div class="border rounded-lg overflow-hidden">
                                  <div class="bg-blue-100 text-blue-700 px-4 py-2 font-medium">Store Credit</div>
                                  <div class="p-4 text-gray-600 text-sm">
                                      <p class="mb-2">We may offer store credit when:</p>
                                      <ul class="space-y-1">
                                          <li class="flex items-start">
                                              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                  viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                  stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                                  class="h-4 w-4 text-blue-600 mr-1 mt-0.5">
                                                  <polyline points="20 6 9 17 4 12" />
                                              </svg>
                                              <span class="flex-1">Customer prefers store credit</span>
                                          </li>
                                          <li class="flex items-start">
                                              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                  viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                  stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                                  class="h-4 w-4 text-blue-600 mr-1 mt-0.5">
                                                  <polyline points="20 6 9 17 4 12" />
                                              </svg>
                                              <span class="flex-1">Returns after 30-day window</span>
                                          </li>
                                          <li class="flex items-start">
                                              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                  viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                  stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                                  class="h-4 w-4 text-blue-600 mr-1 mt-0.5">
                                                  <polyline points="20 6 9 17 4 12" />
                                              </svg>
                                              <span class="flex-1">Often includes a bonus (10% extra)</span>
                                          </li>
                                      </ul>
                                  </div>
                              </div>
                          </div>
                      </div>

                      <p class="mb-4">Refunds are processed within 1-3 business days of receiving and inspecting
                          the returned item, though it may take 5-10 business days for the refund to appear in
                          your account, depending on your payment method and financial institution.</p>

                      <h3 class="text-xl font-bold text-gray-900 mb-4">Non-Returnable Items</h3>
                      <p class="mb-4">For safety and security reasons, certain items cannot be returned after
                          delivery unless they arrive
                          damaged or defective:</p>
                      <ul class="list-disc ps-5 mb-4 space-y-1">
                          <li>Software with opened/activated license keys</li>
                          <li>Custom-built or personalized electronics</li>
                          <li>Memory cards and storage devices that have been used</li>
                      </ul>

                      <div class="bg-primary/10 md:p-6 p-4 rounded-lg mt-6 ltr:border-l-4 rtl:border-r-4 border-primary">
                          <h4 class="font-semibold mb-2">Contact Our Customer Service Team</h4>
                          <p class="mb-4">If you have any questions about our return policy or need
                              assistance with a
                              return, our customer service team is here to help.</p>
                          <div class="flex flex-wrap gap-4">
                              <a href="tel:+15551234567"
                                  class="inline-flex items-center bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-md transition text-sm font-medium">
                                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                      viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                      stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4 mr-2">
                                      <path
                                          d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z" />
                                  </svg>
                                  (555) 123-4567
                              </a>
                              <a href="mailto:support@greentic.com"
                                  class="inline-flex items-center bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-md transition text-sm font-medium">
                                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                      viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                      stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4 mr-2">
                                      <rect width="20" height="16" x="2" y="4" rx="2" />
                                      <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7" />
                                  </svg>
                                  support@greentic.com
                              </a>
                              <a href="contact.html"
                                  class="inline-flex items-center bg-primary hover:bg-primary/80 text-white px-4 py-2 rounded-md transition-all duration-300 text-sm font-medium border">
                                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                      viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                      stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4 mr-2">
                                      <path d="M14 9a2 2 0 0 1-2 2H6l-4 4V4c0-1.1.9-2 2-2h8a2 2 0 0 1 2 2v5Z" />
                                      <path d="M18 9h2a2 2 0 0 1 2 2v11l-4-4h-6a2 2 0 0 1-2-2v-1" />
                                  </svg>
                                  Live Chat
                              </a>
                          </div>
                      </div>
                  </div>
              </div>
          </div>
      </div>
  </section>
    @endif
</main>
@endsection