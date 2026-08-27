@extends('front_end.layouts.app')

@section('page-title')
{{ __('Address Page') }}
@endsection

@section('content')
 <main>
    @if ($themeSettings['address_banner_status'] && $themeSettings['address_banner_status'] == '1')
    <!-- Common Banner Section -->
    <section class="banner-section relative lg:py-16 py-10 bg-cover bg-center" style="background-image: url('{{ get_file($themeSettings['address_banner_image'] ?? '') }}');">
      <div class="md:container w-full mx-auto px-4">
        <div class="text-center relative z-[2]">
          <h2 class="text-[26px] sm:text-4xl lg:text-5xl font-bold mb-4 capitalize">{{ $themeSettings['address_banner_title'] ?? __('Address') }}</h2>
        </div>
      </div>
    </section>
    @endif
    
    @if ($themeSettings['address_status'] && $themeSettings['address_status'] == '1')
    <section class="lg:py-20 py-10">
        <div class="md:container w-full mx-auto px-4">
            <div class="flex flex-col lg:flex-row md:gap-8 gap-6">
                @include('front_end.common.account-tab')

                <!-- Main Content -->
                <div class="lg:w-3/4">
                    <div class="bg-gray-50 border md:p-6 p-4 rounded-lg shadow-sm">
                        <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
                            <h2 class="font-bold md:text-2xl text-xl">{{ $themeSettings['address_title'] ?? __('Your Addresses') }}</h2>
                           
                            <button class="btn-primary" id="add-address-btn" data-ajax-popup="true" data-size="xl" data-title="{{ __('Add Address') }}" data-url="{{ route('address-form', $store->slug) }}" data-toggle="tooltip" title="{{ __('Add Address') }}">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round" class="h-4 w-4">
                                    <path d="M5 12h14" />
                                    <path d="M12 5v14" />
                                </svg>
                                {{ $themeSettings['address_add_button'] ?? __('Add New Address') }}
                            </button>
                        </div>

                        <!-- Addresses List -->
                        <div class="grid grid-cols-1 md:grid-cols-2 md:gap-6 gap-4" id="addresses-list">
                           @foreach ($addresses as $address)
                            <div class="bg-white border rounded-lg md:p-5 p-4 relative">
                                @if($address->default_address)
                                <span class="inline-block bg-primary text-white text-xs px-2 py-1 rounded-md absolute right-4 rtl:left-4 rtl:right-auto top-4">{{ $themeSettings['address_label'] ??  __('Default') }}</span>
                                @endif
                                <h3 class="font-medium text-lg mb-2">{{ $address->address_type }}</h3>
                                <p class="text-gray-700 mb-3">
                                    {{ $address->full_name }}<br>
                                    {{ $address->address }}<br>
                                    
                                    {{ $address->city_name }}, {{ $address->state_name }} {{ $address->postcode }}<br>
                                    {{ $address->country_name }}
                                </p>
                                <p class="text-gray-700 mb-4">
                                    {{ __('Phone') }}: {{ $address->phone }}
                                </p>
                                <div class="flex sm:gap-3 gap-1">
                                    <button class="text-primary hover:text-primary-dark font-medium md:text-base text-sm edit-address-btn" 
                                        data-ajax-popup="true" 
                                        data-size="xl" 
                                        data-title="{{ __('Edit Address') }}" 
                                        data-url="{{ route('address-form', ['storeSlug' => $store->slug, 'id' => $address->id]) }}">
                                        {{ $themeSettings['address_edit_button'] ??  __('Edit') }}
                                    </button>                                   
                                    @if(!$address->default_address)
                                    <span class="text-gray-300 font-medium">|</span>
                                    <button class="text-red-500 hover:text-red-600 font-medium md:text-base text-sm delete-address-btn" data-id="{{ $address->id }}">
                                        {{ $themeSettings['address_remove_button'] ??  __('Remove') }}
                                    </button>
                                    <span class="text-gray-300 font-medium">|</span>
                                    <button class="text-primary hover:text-primary-dark font-medium md:text-base text-sm set-default-btn" data-id="{{ $address->id }}">
                                        {{ $themeSettings['address_default_text'] ??  __('Set as Default') }}
                                    </button>
                                    @endif
                                </div>
                            </div>
                            @endforeach
                        </div>
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
    $(document).ready(function() {
        // Handle form submission
        $(document).on('submit', '#address-form', function(e) {
            e.preventDefault();
            var form = $(this);
            var url = form.attr('action');
            var formData = new FormData(this);

            $.ajax({
                url: url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.status === true) {
                        show_toastr('Success', response.message, 'success');
                        $('#commonModal').addClass('hidden');
                        
                        // If this is a new address
                        if (!formData.get('id')) {
                            // Add the new address to the list
                            var newAddressHtml = `
                                <div class="bg-white border rounded-lg md:p-5 p-4 relative" data-id="${response.address_id}">
                                    ${formData.get('default_address') === 'on' ? '<span class="inline-block bg-primary text-white text-xs px-2 py-1 rounded-md absolute right-4 top-4">{{ __("Default") }}</span>' : ''}
                                    <h3 class="font-medium text-lg mb-2">${formData.get('address_type')}</h3>
                                    <p class="text-gray-700 mb-3">
                                        ${formData.get('full_name')}<br>
                                        ${formData.get('address')}<br>
                                        ${$('#city option:selected').text()}, ${$('#state option:selected').text()} ${formData.get('postcode')}<br>
                                        ${$('#country option:selected').text()}
                                    </p>
                                    <p class="text-gray-700 mb-4">
                                        {{ __("Phone") }}: ${formData.get('phone')}
                                    </p>
                                    <div class="flex sm:gap-3 gap-1">
                                        <button class="text-primary hover:text-primary-dark font-medium md:text-base text-sm edit-address-btn" 
                                            data-ajax-popup="true" 
                                            data-size="xl" 
                                            data-title="{{ __("Edit Address") }}" 
                                            data-url="{{ route('address-form', ['storeSlug' => $store->slug, 'id' => '']) }}${response.address_id}">
                                            {{ __("Edit") }}
                                        </button>
                                        <span class="text-gray-300 font-medium">|</span>
                                        <button class="text-red-500 hover:text-red-600 font-medium md:text-base text-sm delete-address-btn" data-id="${response.address_id}">
                                            {{ __("Remove") }}
                                        </button>
                                        ${formData.get('default_address') !== 'on' ? `
                                            <span class="text-gray-300 font-medium">|</span>
                                            <button class="text-primary hover:text-primary-dark font-medium md:text-base text-sm set-default-btn" data-id="${response.address_id}">
                                                {{ __("Set as Default") }}
                                            </button>
                                        ` : ''}
                                    </div>
                                </div>
                            `;
                            $('#addresses-list').append(newAddressHtml);
                        } else {
                            // Update existing address
                            var addressItem = $(`.bg-white.border.rounded-lg[data-id="${formData.get('id')}"]`);
                            var newHtml = `
                                ${formData.get('default_address') === 'on' ? '<span class="inline-block bg-primary text-white text-xs px-2 py-1 rounded-md absolute right-4 top-4">{{ __("Default") }}</span>' : ''}
                                <h3 class="font-medium text-lg mb-2">${formData.get('address_type')}</h3>
                                <p class="text-gray-700 mb-3">
                                    ${formData.get('full_name')}<br>
                                    ${formData.get('address')}<br>
                                    ${$('#city option:selected').text()}, ${$('#state option:selected').text()} ${formData.get('postcode')}<br>
                                    ${$('#country option:selected').text()}
                                </p>
                                <p class="text-gray-700 mb-4">
                                    {{ __("Phone") }}: ${formData.get('phone')}
                                </p>
                                <div class="flex sm:gap-3 gap-1">
                                    <button class="text-primary hover:text-primary-dark font-medium md:text-base text-sm edit-address-btn" 
                                        data-ajax-popup="true" 
                                        data-size="xl" 
                                        data-title="{{ __("Edit Address") }}" 
                                        data-url="{{ route('address-form', ['storeSlug' => $store->slug, 'id' => '']) }}${formData.get('id')}">
                                        {{ __("Edit") }}
                                    </button>
                                    <span class="text-gray-300 font-medium">|</span>
                                    <button class="text-red-500 hover:text-red-600 font-medium md:text-base text-sm delete-address-btn" data-id="${formData.get('id')}">
                                        {{ __("Remove") }}
                                    </button>
                                    ${formData.get('default_address') !== 'on' ? `
                                        <span class="text-gray-300 font-medium">|</span>
                                        <button class="text-primary hover:text-primary-dark font-medium md:text-base text-sm set-default-btn" data-id="${formData.get('id')}">
                                            {{ __("Set as Default") }}
                                        </button>
                                    ` : ''}
                                </div>
                            `;
                            addressItem.html(newHtml);
                        }

                        // If setting as default, update all other addresses
                        if (formData.get('default_address') === 'on') {
                            $('.bg-white.border.rounded-lg').each(function() {
                                if ($(this).data('id') !== formData.get('id')) {
                                    $(this).find('.inline-block.bg-primary').remove();
                                    if (!$(this).find('.set-default-btn').length) {
                                        $(this).find('.flex.sm\\:gap-3').append(`
                                            <span class="text-gray-300 font-medium">|</span>
                                            <button class="text-primary hover:text-primary-dark font-medium md:text-base text-sm set-default-btn" data-id="${$(this).data('id')}">
                                                {{ __("Set as Default") }}
                                            </button>
                                        `);
                                    }
                                }
                            });
                        }
                    } else {
                        show_toastr('Error', response.message, 'error');
                    }
                },
                error: function(error) {
                    show_toastr('Error', 'Something went wrong!', 'error');
                }
            });
        });

        // Handle delete address
        $(document).on('click', '.delete-address-btn', function() {
            var id = $(this).data('id');
            if (confirm('Are you sure you want to delete this address?')) {
                $.ajax({
                    url: '{{ route("remove.address", $store->slug) }}',
                    type: 'POST',
                    data: {
                        id: id,
                        _token: '{{ csrf_token() }}'
                    },
                    success: function(response) {
                        if (response.status === true) {
                            show_toastr('Success', response.message, 'success');
                            location.reload();
                        } else {
                            show_toastr('Error', response.message, 'error');
                        }
                    },
                    error: function(error) {
                        show_toastr('Error', 'Something went wrong!', 'error');
                    }
                });
            }
        });

        // Handle set default address
        $(document).on('click', '.set-default-btn', function() {
            var id = $(this).data('id');
            $.ajax({
                url: '{{ route("save-address", $store->slug) }}',
                type: 'POST',
                data: {
                    id: id,
                    default_address: true,
                    is_default: true,
                    _token: '{{ csrf_token() }}'
                },
                success: function(response) {
                    if (response.status === true) {
                        show_toastr('Success', response.message, 'success');
                        location.reload();
                    } else {
                        show_toastr('Error', response.message, 'error');
                    }
                },
                error: function(error) {
                    show_toastr('Error', 'Something went wrong!', 'error');
                }
            });
        });
    });
</script>
@endpush