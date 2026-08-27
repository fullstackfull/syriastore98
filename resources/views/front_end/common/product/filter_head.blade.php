<div class="product-heading-row row no-gutters">
    <div class="product-heading-column col-lg-3 col-md-4 col-1">
        <div class="filter-title">
            <h4 class="desk-only"> {{ __('Filters')}} </h4>
            <div class="filter-ic mobile-only">
                <svg class="icon icon-filter" aria-hidden="true" focusable="false" role="presentation"
                    xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none">
                    <path fill-rule="evenodd"
                        d="M4.833 6.5a1.667 1.667 0 1 1 3.334 0 1.667 1.667 0 0 1-3.334 0ZM4.05 7H2.5a.5.5 0 0 1 0-1h1.55a2.5 2.5 0 0 1 4.9 0h8.55a.5.5 0 0 1 0 1H8.95a2.5 2.5 0 0 1-4.9 0Zm11.117 6.5a1.667 1.667 0 1 0-3.334 0 1.667 1.667 0 0 0 3.334 0ZM13.5 11a2.5 2.5 0 0 1 2.45 2h1.55a.5.5 0 0 1 0 1h-1.55a2.5 2.5 0 0 1-4.9 0H2.5a.5.5 0 0 1 0-1h8.55a2.5 2.5 0 0 1 2.45-2Z"
                        fill="currentColor"></path>
                </svg>
            </div>
        </div>
    </div>
    <div class="product-heading-right-column col-lg-9 col-md-8 col-11">
        <div class="product-sorting-row d-flex align-items-center justify-content-between">
            <ul class="produdt-filter-cat d-flex align-items-center">
            </ul>
            <div class="filter-select-box d-flex align-items-center justify-content-end">
                <span class="sort-lbl">{{ __('Sort by')}}:</span>
                <select class="filter_product">
                    <option value="manual" selected="selected">{{ __('Featured')}}</option>
                    <option value="best-selling" {{ !empty($filter_product) && $filter_product == 'best-selling' ? 'selected="selected"' : '' }}>
                        {{ __('Best selling') }}
                    </option>
                    <option value="title-ascending"> {{ __('Alphabetically, A-Z')}} </option>
                    <option value="title-descending"> {{ __('Alphabetically, Z-A')}} </option>
                    <option value="price-ascending"> {{ __('Price, low to high')}} </option>
                    <option value="price-descending"> {{ __('Price, high to low')}} </option>
                    <option value="created-ascending"> {{ __('Date, old to new')}} </option>
                    <option value="created-descending"> {{ __('Date, new to old')}} </option>
                </select>
            </div>
        </div>
    </div>
</div>