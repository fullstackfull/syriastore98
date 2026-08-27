@extends('front_end.layouts.app')

@section('page-title')
{{ __('Collections Page') }}
@endsection

@section('content')
<main>
    @if ($themeSettings['contact_us_banner_status'] && $themeSettings['contact_us_banner_status'] == '1')
    <!-- Common Banner Section -->
    <section class="banner-section relative lg:py-16 py-10 bg-cover bg-center"
        style="background-image: url('{{ get_file($themeSettings['contact_us_banner_image'] ?? 'themes/techzonix/assets/images/common-banner.png') }}');">
        <div class="md:container w-full mx-auto px-4">
            <div class="text-center relative z-[2]">
                <h2 class="text-[26px] sm:text-4xl lg:text-5xl font-bold mb-4 capitalize">
                    {{ $themeSettings['contact_us_banner_title'] ?? __('Contact Us') }}</h2>
            </div>
        </div>
    </section>
    @endif

    @if ($themeSettings['contact_us_form_status'] && $themeSettings['contact_us_form_status'] == '1')
      <!-- Contact Information -->
    <section class="lg:py-20 py-10 bg-white">
      <div class="md:container w-full mx-auto px-4">
        <div class="grid grid-cols-1 md:grid-cols-3 lg:gap-8 gap-5">
          <!-- Contact Method 1: Location -->
          <div class="text-center lg:p-6 p-4 border rounded-lg shadow-sm hover:shadow-md transition">
            <div
              class="inline-flex items-center justify-center bg-primary bg-opacity-10 text-primary h-16 w-16 rounded-full mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" fill="none" viewBox="0 0 24 24"
                stroke="currentColor" stroke-width="2">
                <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z" />
                <circle cx="12" cy="10" r="3" />
              </svg>
            </div>
            <h3 class="font-heading font-semibold text-xl mb-2">{{ __('Our Location')}}</h3>
            <p class="text-gray-600 mb-2">{{ $themeSettings['contact_us_form_title'] ?? __('123 Organic Avenue') }}</p>
            <a href="https://maps.google.com" target="_blank"
              class="text-primary hover:text-primary-dark font-medium">{{ __('View on Map')}}</a>
          </div>

          <!-- Contact Method 2: Phone -->
          <div class="text-center lg:p-6 p-4 border rounded-lg shadow-sm hover:shadow-md transition">
            <div
              class="inline-flex items-center justify-center bg-primary bg-opacity-10 text-primary h-16 w-16 rounded-full mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" fill="none" viewBox="0 0 24 24"
                stroke="currentColor" stroke-width="2">
                <path
                  d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z" />
              </svg>
            </div>
            <h3 class="font-heading font-semibold text-xl mb-2">{{ __('Phone Number')}}</h3>
            <p class="text-gray-600 mb-1">{{ __('Customer Service')}}:</p>
            <p class="text-gray-600 mb-2">
              <a href="tel:5551234567" class="text-primary hover:text-primary-dark">{{ $themeSettings['contact_us_form_call_us'] ?? __('(555) 123-4567') }}</a>
            </p>
            <p class="text-sm text-gray-500">{{ $themeSettings['contact_us_form_open_store'] ?? __('Mon–Fri: 8am–8pm, Sat–Sun: 9am–6pm')}}</p>
          </div>

          <!-- Contact Method 3: Email -->
          <div class="text-center lg:p-6 p-4 border rounded-lg shadow-sm hover:shadow-md transition">
            <div
              class="inline-flex items-center justify-center bg-primary bg-opacity-10 text-primary h-16 w-16 rounded-full mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" fill="none" viewBox="0 0 24 24"
                stroke="currentColor" stroke-width="2">
                <rect width="20" height="16" x="2" y="4" rx="2" />
                <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7" />
              </svg>
            </div>
            <h3 class="font-heading font-semibold text-xl mb-2">{{ __('Email')}}</h3>
            <p class="text-gray-600 mb-1">{{ __('General Inquiries')}}:</p>
            <p class="mb-2">
              <a href="mailto:info@greentic.com" class="text-primary hover:text-primary-dark">{{ $themeSettings['contact_us_form_email'] ?? __('info@greentic.com') }}</a>
            </p>
            <p class="text-sm text-gray-500">{{ __('We aim to respond within 24 hours') }}</p>
          </div>
        </div>
      </div>
    </section>
    @endif

    <!-- Contact Form and Map -->
    @if (isset($themeSettings['contact_us_map_status']) && $themeSettings['contact_us_map_status'] == 1)

    <section class="lg:pb-20 pb-10">
      <div class="container mx-auto px-4">
        <div class="flex flex-col lg:flex-row md:gap-8 gap-5 items-stretch">
          <!-- Contact Form -->
          <div class="lg:w-1/2 flex flex-col">
            <h2 class="font-bold text-2xl md:text-3xl md:mb-6 mb-4">{{ __('Send Us a Message')}}</h2>
            <div class="flex-grow">
                <form class="md:gap-6 gap-4 flex flex-col h-full" method="POST"
                        action="{{ route('contact.submit', ['storeSlug' => $slug]) }}">
                        @csrf
                <div class="grid grid-cols-1 md:grid-cols-2 md:gap-6 gap-4">
                  <div>
                    <label for="first-name" class="block mb-2 font-medium md:text-base text-sm">{{ __('First Name')}} <span
                        class="text-red-500">*</span></label>
                    <input type="text" id="first-name" placeholder="Enter first name" name="first_name"
                      class="w-full rounded-md border border-gray-300 focus:ring-primary focus:border-primary px-3 py-2 outline-none ltr:text-left rtl:text-right"
                      required />
                  </div>
                  <div>
                    <label for="last-name" class="block mb-2 font-medium md:text-base text-sm">{{ __('Last Name')}}<span
                        class="text-red-500">*</span></label>
                    <input type="text" id="last-name" placeholder="Enter last name" name="last_name"
                      class="w-full rounded-md border border-gray-300 focus:ring-primary focus:border-primary px-3 py-2 outline-none ltr:text-left rtl:text-right"
                      required />
                  </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 md:gap-6 gap-4">
                  <div>
                    <label for="email" class="block mb-2 font-medium md:text-base text-sm">{{ __('Email')}} <span class="text-red-500">*</span></label>
                    <input type="email" id="email" placeholder="Enter email address" name="email"
                      class="w-full rounded-md border border-gray-300 focus:ring-primary focus:border-primary px-3 py-2 outline-none ltr:text-left rtl:text-right"
                      required />
                  </div>
                  <div>
                    <label for="phone" class="block mb-2 font-medium md:text-base text-sm">{{ __('Phone Number')}}</label>
                    <input type="tel" id="phone" placeholder="Enter phone number" name="contact"
                      class="w-full rounded-md border border-gray-300 focus:ring-primary focus:border-primary px-3 py-2 outline-none ltr:text-left rtl:text-right" />
                  </div>
                </div>

                <div>
                  <label for="subject" class="block mb-2 font-medium md:text-base text-sm">{{ __('Subject')}} <span
                      class="text-red-500">*</span></label>
                  <select id="subject"
                    class="w-full rounded-md border border-gray-300 focus:ring-primary focus:border-primary p-3 outline-none" name="subject"
                    required>
                    <option value="">{{ __('Select a subject')}}</option>
                    <option value="order">{{ __('Order Inquiry')}}</option>
                    <option value="product">{{ __('Product Information')}}</option>
                    <option value="delivery">{{ __('Delivery Question')}}</option>
                    <option value="feedback">{{ __('General Feedback')}}</option>
                    <option value="partnership">{{ __('Business Partnership')}}</option>
                    <option value="other">{{ __('Other')}}</option>
                  </select>
                </div>

                <div>
                  <label for="message" class="block mb-2 font-medium md:text-base text-sm">{{ __('Message')}} <span
                      class="text-red-500">*</span></label>
                  <textarea id="message" rows="5" placeholder="Write your message here..." name="description"
                    class="w-full rounded-md border border-gray-300 focus:ring-primary focus:border-primary p-3 outline-none resize-none"
                    required></textarea>
                </div>

                <div>
                  <div class="flex items-start gap-2">
                    <input type="checkbox" id="privacy"
                      class="rounded border-gray-300 text-primary focus:ring-primary mt-1" required />
                    <label for="privacy" class="text-sm text-gray-600 cursor-pointer">
                      {{ __('I agreee to the')}} <a href="{{ route('privacy_page', ['storeSlug' => $slug]) }}" class="text-primary hover:underline">{{ __('Privacy Policy')}}</a>
                      {{ __('and consent to processing my data for the purpose of handling my inquiry.')}}
                    </label>
                  </div>
                </div>

                <div>
                  <button type="submit" class="btn-primary">
                    {{ __('Send Message')}}
                  </button>
                </div>
              </form>
            </div>
          </div>

          <!-- Map -->
          <div class="lg:w-1/2 flex flex-col">
            <h2 class="font-heading font-bold text-2xl md:text-3xl md:mb-6 mb-4">{{ $themeSettings['contact_us_map_title'] ?? __('Visit Our Store') }}</h2>
          
              @if (!empty($themeSettings['contact_us_map_location']))
                  @if (strpos($themeSettings['contact_us_map_location'], "<iframe") !== false)
                      {!! $themeSettings['contact_us_map_location'] !!}
                  @else
                      <iframe class="w-full h-full"
                          src="{{ $themeSettings['contact_us_map_location'] }}"
                          style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade">
                      </iframe>
                  @endif
              @else
                  <div class="flex-grow rounded-lg overflow-hidden shadow-md bg-gray-200 lg:min-h-[500px] h-60">
                      <iframe class="w-full h-full"
                          src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d193596.26002810575!2d-74.14431235114544!3d40.69728463488439!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x89c24fa5d33f083b%3A0xc80b8f06e177fe62!2sNew%20York%2C%20NY%2C%20USA!5e0!3m2!1sen!2sin!4v1746009273607!5m2!1sen!2sin"
                          style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade">
                      </iframe>
                  </div>
              @endif
            </div>
          </div>
        </div>
      </div>
    </section>
    @endif
</main>
@endsection