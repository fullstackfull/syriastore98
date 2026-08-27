@if (module_is_active('AdditionalCustomFields') && !empty($productFields))
    <button type="button"
        class="tab-button md:border-b-2 border-transparent font-semibold md:px-6 md:py-4 p-3 text-gray-500 hover:text-gray-700 focus:outline-none transition-all duration-300 min-w-fit outline-none"
        data-tab="product_AdditionalCustomField_tab">
        {{ __('Additional Fields') }}
    </button>
@endif
