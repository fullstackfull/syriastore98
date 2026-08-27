@extends('front_end.layouts.app')

@section('page-title')
{{ __('Order History') }}
@endsection

@section('content')
<main>
    @if ($themeSettings['order_banner_status'] && $themeSettings['order_banner_status'] == '1')
    <!-- Common Banner Section -->
    <section class="banner-section relative lg:py-16 py-10 bg-cover bg-center"
        style="background-image: url('{{ get_file($themeSettings['order_banner_image'] ?? '') }}');">
        <div class="md:container w-full mx-auto px-4">
            <div class="text-center relative z-[2]">
                <h2 class="text-[26px] sm:text-4xl lg:text-5xl font-bold mb-4 capitalize">
                    {{ $themeSettings['order_banner_title'] ?? '' }}</h2>
            </div>
        </div>
    </section>
    @endif

    @if ($themeSettings['order_list_status'] && $themeSettings['order_list_status'] == '1')
     <section class="lg:py-20 py-10">
      <div class="md:container w-full mx-auto px-4">
        <div class="flex flex-col lg:flex-row md:gap-8 gap-6">
          @include('front_end.common.account-tab')

          <!-- Main Content -->
          <div class="lg:w-3/4">
            <div class="bg-gray-50 border md:p-6 p-4 rounded-lg shadow-sm">
              <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
                <h2 class="font-bold md:text-2xl text-xl">{{ $themeSettings['order_list_title'] ?? '' }}</h2>
                <div class="flex items-center gap-2">
                  <span>{{ $themeSettings['order_list_filter'] ?? '' }}:</span>
                  <div class="relative">
                    <select class="block w-full border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary select2"
                        id="filter_order">
                        <option value="all" {{ empty($filter_order) || (!empty($filter_order) && $filter_order == 'all') ? 'selected="selected"' : '' }}>{{ __('All Orders') }}</option>
                        <option value="processing" {{ !empty($filter_order) && $filter_order == 'processing' ? 'selected="selected"' : '' }}>{{ __('Processing') }}</option>
                        <option value="delivered" {{ !empty($filter_order) && $filter_order == 'delivered' ? 'selected="selected"' : '' }}>{{ __('Delivered') }}</option>
                        <option value="cancelled" {{ !empty($filter_order) && $filter_order == 'cancelled' ? 'selected="selected"' : '' }}>{{ __('Cancelled') }}</option>
                        <option value="return" {{ !empty($filter_order) && $filter_order == 'return' ? 'selected="selected"' : '' }}>{{ __('Return') }}</option>
                        <option value="confirmed" {{ !empty($filter_order) && $filter_order == 'confirmed' ? 'selected="selected"' : '' }}>{{ __('Confirmed') }}</option>
                        <option value="picked" {{ !empty($filter_order) && $filter_order == 'picked' ? 'selected="selected"' : '' }}>{{ __('Picked Up') }}</option>
                        <option value="shipped" {{ !empty($filter_order) && $filter_order == 'shipped' ? 'selected="selected"' : '' }}>{{ __('Shipped') }}</option>
                        <option value="partiallyPaid" {{ !empty($filter_order) && $filter_order == 'partiallyPaid' ? 'selected="selected"' : '' }}>{{ __('Partially Paid') }}</option>
                        <option value="preOrder" {{ !empty($filter_order) && $filter_order == 'preOrder' ? 'selected="selected"' : '' }}>{{ __('Pre Order') }}</option>
                    </select>
                  </div>
                </div>
              </div>

              <div class="order_filter">
                
              </div>
              <!-- Orders List -->
              
            </div>
          </div>
        </div>
      </div>
    </section>
    @endif
</main>
@endsection

@push('page-script')
    <script>
      var storeSlug = '{{ $slug ?? '' }}';

      $(document).ready(function() {
          let urlParams = new URLSearchParams(window.location.search);
          $('#filter_order').val(urlParams.get('filter_order'));

          let initialPage = urlParams.get('page') || 1;
          order_page_filter(initialPage);
      });

      $(document).on('click', '.pagination a', function(e) {
          e.preventDefault();
          let page = $(this).attr('href').split('page=')[1] || 1;
          order_page_filter(page);
      });

      $("#filter_order").change(function() {
          order_page_filter(1);
      });

      function order_page_filter(page) {
          let filter_order = $('#filter_order').val() || 'all';

          let queryParams = new URLSearchParams({
              page,
              filter_order,
          });

          let queryString = queryParams.toString();

          history.replaceState(null, null, '?' + queryString);

          $.ajax({
              url: '{{ route("order.page.filter", $slug) }}?' + queryString,
              type: 'GET',
              success: function(response) {
                  $('.order_filter').html(response.html);
              },
              error: function(xhr, status, error) {
                  $('.order_filter').html(
                      '<div class="alert alert-danger">Error loading orders. Please try again.</div>');
                  console.error('AJAX error:', error, xhr.responseText);
              }
          });
      }
    </script>
@endpush