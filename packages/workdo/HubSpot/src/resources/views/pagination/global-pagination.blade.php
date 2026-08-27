
@if ($paginator->hasPages() || $paginator->total() > 0) <!-- Ensures pagination is shown if there are any results -->
    <nav class="d-flex justify-items-center justify-content-between">
        <div class="pagination-wrp flex-sm-fill d-flex align-items-center justify-content-between">
            <div>
                <p class="small text-muted mb-0">
                    {!! __('Showing') !!}
                    <span class="fw-semibold">{{ $paginator->firstItem() }}</span>
                    {!! __('to') !!}
                    <span class="fw-semibold">{{ $paginator->lastItem() }}</span>
                    {!! __('of') !!}
                    <span class="fw-semibold">{{ $paginator->total() }}</span>
                    {!! __('results') !!}
                </p>
            </div>

            <ul class="pagination">
                {{-- Previous Page Link --}}
                @if ($paginator->onFirstPage())
                    <li class="page-item disabled" aria-disabled="true" aria-label="@lang('pagination.previous')">
                        <span class="page-link" aria-hidden="true">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M11.7267 12L12.6667 11.06L9.61335 8L12.6667 4.94L11.7267 4L7.72669 8L11.7267 12Z" fill="#060606"/>
                                <path d="M7.33332 12L8.27332 11.06L5.21998 8L8.27331 4.94L7.33331 4L3.33332 8L7.33332 12Z" fill="#060606"/>
                            </svg>
                        </span>
                    </li>
                @else
                    <li class="page-item">
                        <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="@lang('pagination.previous')">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M11.7267 12L12.6667 11.06L9.61335 8L12.6667 4.94L11.7267 4L7.72669 8L11.7267 12Z" fill="#060606"/>
                                <path d="M7.33332 12L8.27332 11.06L5.21998 8L8.27331 4.94L7.33331 4L3.33332 8L7.33332 12Z" fill="#060606"/>
                            </svg>
                        </a>
                    </li>
                @endif

                {{-- Pagination Elements --}}
                @foreach ($elements as $element)
                    {{-- "Three Dots" Separator --}}
                    @if (is_string($element))
                        <li class="page-item disabled" aria-disabled="true"><span class="page-link">{{ $element }}</span></li>
                    @endif

                    {{-- Array Of Links --}}
                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <li class="page-item active" aria-current="page"><span class="page-link">{{ $page }}</span></li>
                            @else
                                <li class="page-item"><a class="page-link" href="{{ $url }}">{{ $page }}</a></li>
                            @endif
                        @endforeach
                    @endif
                @endforeach

                {{-- Next Page Link --}}
                @if ($paginator->hasMorePages())
                    <li class="page-item">
                        <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="@lang('pagination.next')">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M4.27337 4L3.33337 4.94L6.38671 8L3.33337 11.06L4.27337 12L8.27337 8L4.27337 4Z" fill="#060606"/>
                                <path d="M8.66668 4L7.72668 4.94L10.78 8L7.72668 11.06L8.66668 12L12.6667 8L8.66668 4Z" fill="#060606"/>
                            </svg>
                        </a>
                    </li>
                @else
                    <li class="page-item disabled" aria-disabled="true" aria-label="@lang('pagination.next')">
                        <span class="page-link" aria-hidden="true">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M4.27337 4L3.33337 4.94L6.38671 8L3.33337 11.06L4.27337 12L8.27337 8L4.27337 4Z" fill="#060606"/>
                                <path d="M8.66668 4L7.72668 4.94L10.78 8L7.72668 11.06L8.66668 12L12.6667 8L8.66668 4Z" fill="#060606"/>
                            </svg>
                        </span>
                    </li>
                @endif
            </ul>
        </div>
    </nav>
@endif


@push('css')
<style>

.pagination-wrp {
    flex-wrap: wrap;
    gap: 15px;
    width: 100%;
    font-size: 18px;
}
.pagination {
    gap: 5px;
    margin: 0;
    border-radius: 0 !important;
}
.pagination li span,
.pagination li a {
    display: flex;
    align-items: center;
    justify-content: center;
    color: #000;
    font-size: 14px;
    height: 35px;
    width: 35px;
    padding: 0;
    border-radius: 4px !important;
}
.pagination .page-item:not(:first-child) .page-link {
    margin: 0;
}

body.theme-1   .arrow-btn svg path,
body.theme-1   .btn svg path {
    fill: var(--theme1-color);
}
body.theme-2   .arrow-btn svg path,
body.theme-2   .btn svg path{
    fill: var(--theme2-color);
}
body.theme-3   .arrow-btn svg path,
body.theme-3   .btn svg path {
    fill: var(--theme3-color);
}
body.theme-4   .arrow-btn svg path,
body.theme-4   .btn svg path {
    fill: var(--theme4-color);
}
body.theme-5   .arrow-btn svg path,
body.theme-5   .btn svg path {
    fill: var(--theme5-color);
}
body.theme-6   .arrow-btn svg path,
body.theme-6   .btn svg path {
    fill: var(--theme6-color);
}
body.theme-7   .arrow-btn svg path,
body.theme-7   .btn svg path {
    fill: var(--theme7-color);
}
body.theme-8   .arrow-btn svg path,
body.theme-8   .btn svg path {
    fill: var(--theme8-color);
}
body.theme-9   .arrow-btn svg path,
body.theme-9   .btn svg path {
    fill: var(--theme9-color);
}
body.theme-10   .arrow-btn svg path,
body.theme-10   .btn svg path {
    fill: var(--theme10-color);
}
body.custom-color   .arrow-btn svg path,
body.custom-color   .btn svg path {
    fill: var(--color-customColor);
}

body.theme-1   .btn {
    color: var(--theme1-color);
    border-radius: 4px;
}
body.theme-2   .btn {
    color: var(--theme2-color);
    border-radius: 4px;
}
body.theme-3   .btn {
    color: var(--theme3-color);
    border-radius: 4px;
}
body.theme-4   .btn {
    color: var(--theme4-color);
    border-radius: 4px;
}
body.theme-5   .btn {
    color: var(--theme5-color);
    border-radius: 4px;
}
body.theme-6   .btn {
    color: var(--theme6-color);
    border-radius: 4px;
}
body.theme-7   .btn {
    color: var(--theme7-color);
    border-radius: 4px;
}
body.theme-8   .btn {
    color: var(--theme8-color);
    border-radius: 4px;
}
body.theme-9   .btn {
    color: var(--theme9-color);
    border-radius: 4px;
}
body.theme-10   .btn {
    color: var(--theme10-color);
    border-radius: 4px;
}
body.custom-color   .btn {
    color: var(--color-customColor);
    border-radius: 4px;
}


  .btn:hover {
    color: var(--bs-btn-color) !important;
}

  .btn:not(.arrow-btn):hover svg path {
    fill: #fff !important;
}
</style>
@endpush
