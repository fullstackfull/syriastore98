<div id="search-popup-container" class="fixed flex items-center justify-center inset-0 z-50 hidden">
      <div class="search-popup relative bg-white rounded-lg shadow-xl max-w-2xl w-full mx-4 md:p-6 p-4">
        <form id="mobile-search-form">
      <div class="flex justify-between items-center md:mb-5 mb-4">
        <h3 class="text-xl font-bold text-gray-800">{{ __('Search Products') }}</h3>
        <button type="button" id="close-search" class="text-gray-500 hover:text-primary transition-colors">
          <i class="fas fa-times text-xl"></i>
        </button>
      </div>
      <div class="relative mb-6">
        <input type="text" id="search-input" placeholder="What are you looking for?" class="w-full form-input pe-10">
        <button type="button" class="absolute ltr:right-4 rtl:left-4  top-3 flex">
          <i class="fas fa-search text-gray-400"></i>
        </button>
      </div>
      
      <div class="search-results mt-3"></div>
      <button type="submit" class="w-full btn-primary">
        {{ __('Search Now') }}
      </button>
    </form>
    </div>
  </div>