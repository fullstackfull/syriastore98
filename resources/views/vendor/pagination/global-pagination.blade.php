
@if ($paginator->hasPages())
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

            <div class="nex-prev-btn-wrp d-flex align-items-center justify-content-center">
                @if ($paginator->onFirstPage())
                <a href="javascript:void(0);" class="btn btn-primary arrow-btn">
                    <svg width="18" height="12" viewBox="0 0 18 12" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M0.469669 5.46967C0.176777 5.76256 0.176777 6.23744 0.469669 6.53033L5.24264 11.3033C5.53553 11.5962 6.01041 11.5962 6.3033 11.3033C6.59619 11.0104 6.59619 10.5355 6.3033 10.2426L2.06066 6L6.3033 1.75736C6.59619 1.46447 6.59619 0.989593 6.3033 0.696699C6.01041 0.403806 5.53553 0.403806 5.24264 0.696699L0.469669 5.46967ZM18 5.25L1 5.25V6.75L18 6.75V5.25Z" fill="#10B77F"/>
                        </svg>
                </a>
                @else
                <a href="{{ $paginator->previousPageUrl() }}" class="btn btn-primary d-flex align-items-center ">
                    <svg width="18" height="12" viewBox="0 0 18 12" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M0.469669 5.46967C0.176777 5.76256 0.176777 6.23744 0.469669 6.53033L5.24264 11.3033C5.53553 11.5962 6.01041 11.5962 6.3033 11.3033C6.59619 11.0104 6.59619 10.5355 6.3033 10.2426L2.06066 6L6.3033 1.75736C6.59619 1.46447 6.59619 0.989593 6.3033 0.696699C6.01041 0.403806 5.53553 0.403806 5.24264 0.696699L0.469669 5.46967ZM18 5.25L1 5.25V6.75L18 6.75V5.25Z" fill="#10B77F"/>
                        </svg>
                    {{ __('Prev Page') }}
                </a>
                @endif
                @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" class="btn btn-primary d-flex align-items-center">
                    {{__('Next Page')}}
                    <svg width="18" height="12" viewBox="0 0 18 12" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M17.5303 6.53033C17.8232 6.23744 17.8232 5.76256 17.5303 5.46967L12.7574 0.696699C12.4645 0.403806 11.9896 0.403806 11.6967 0.696699C11.4038 0.989593 11.4038 1.46447 11.6967 1.75736L15.9393 6L11.6967 10.2426C11.4038 10.5355 11.4038 11.0104 11.6967 11.3033C11.9896 11.5962 12.4645 11.5962 12.7574 11.3033L17.5303 6.53033ZM0 6.75H17V5.25H0V6.75Z" fill="white"/>
                    </svg>
                </a>
                @else
                    <a href="javascript:void(0);" class="btn btn-primary arrow-btn">
                        <svg width="18" height="12" viewBox="0 0 18 12" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M17.5303 6.53033C17.8232 6.23744 17.8232 5.76256 17.5303 5.46967L12.7574 0.696699C12.4645 0.403806 11.9896 0.403806 11.6967 0.696699C11.4038 0.989593 11.4038 1.46447 11.6967 1.75736L15.9393 6L11.6967 10.2426C11.4038 10.5355 11.4038 11.0104 11.6967 11.3033C11.9896 11.5962 12.4645 11.5962 12.7574 11.3033L17.5303 6.53033ZM0 6.75H17V5.25H0V6.75Z" fill="white"/>
                        </svg>
                    </a>
                @endif
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

<style>

.pagination-wrp {
    flex-wrap: wrap;
    gap: 15px;
    width: 100%;
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
.nex-prev-btn-wrp {
    gap: 10px;
}
.nex-prev-btn-wrp .btn {
    gap: 8px;
    background: transparent;
}

.nex-prev-btn-wrp svg path {
    fill: #fff;
}
.nex-prev-btn-wrp .arrow-btn {
    padding: 0.575rem;
    background: transparent !important;
}

body.theme-1 .nex-prev-btn-wrp .arrow-btn svg path,
body.theme-1 .nex-prev-btn-wrp .btn svg path {
    fill: var(--theme1-color);
}
body.theme-2 .nex-prev-btn-wrp .arrow-btn svg path,
body.theme-2 .nex-prev-btn-wrp .btn svg path{
    fill: var(--theme2-color);
}
body.theme-3 .nex-prev-btn-wrp .arrow-btn svg path,
body.theme-3 .nex-prev-btn-wrp .btn svg path {
    fill: var(--theme3-color);
}
body.theme-4 .nex-prev-btn-wrp .arrow-btn svg path,
body.theme-4 .nex-prev-btn-wrp .btn svg path {
    fill: var(--theme4-color);
}
body.theme-5 .nex-prev-btn-wrp .arrow-btn svg path,
body.theme-5 .nex-prev-btn-wrp .btn svg path {
    fill: var(--theme5-color);
}
body.theme-6 .nex-prev-btn-wrp .arrow-btn svg path,
body.theme-6 .nex-prev-btn-wrp .btn svg path {
    fill: var(--theme6-color);
}
body.theme-7 .nex-prev-btn-wrp .arrow-btn svg path,
body.theme-7 .nex-prev-btn-wrp .btn svg path {
    fill: var(--theme7-color);
}
body.theme-8 .nex-prev-btn-wrp .arrow-btn svg path,
body.theme-8 .nex-prev-btn-wrp .btn svg path {
    fill: var(--theme8-color);
}
body.theme-9 .nex-prev-btn-wrp .arrow-btn svg path,
body.theme-9 .nex-prev-btn-wrp .btn svg path {
    fill: var(--theme9-color);
}
body.theme-10 .nex-prev-btn-wrp .arrow-btn svg path,
body.theme-10 .nex-prev-btn-wrp .btn svg path {
    fill: var(--theme10-color);
}
body.custom-color .nex-prev-btn-wrp .arrow-btn svg path,
body.custom-color .nex-prev-btn-wrp .btn svg path {
    fill: var(--color-customColor);
}

body.theme-1 .nex-prev-btn-wrp .btn {
    color: var(--theme1-color);
    border-radius: 4px;
}
body.theme-2 .nex-prev-btn-wrp .btn {
    color: var(--theme2-color);
    border-radius: 4px;
}
body.theme-3 .nex-prev-btn-wrp .btn {
    color: var(--theme3-color);
    border-radius: 4px;
}
body.theme-4 .nex-prev-btn-wrp .btn {
    color: var(--theme4-color);
    border-radius: 4px;
}
body.theme-5 .nex-prev-btn-wrp .btn {
    color: var(--theme5-color);
    border-radius: 4px;
}
body.theme-6 .nex-prev-btn-wrp .btn {
    color: var(--theme6-color);
    border-radius: 4px;
}
body.theme-7 .nex-prev-btn-wrp .btn {
    color: var(--theme7-color);
    border-radius: 4px;
}
body.theme-8 .nex-prev-btn-wrp .btn {
    color: var(--theme8-color);
    border-radius: 4px;
}
body.theme-9 .nex-prev-btn-wrp .btn {
    color: var(--theme9-color);
    border-radius: 4px;
}body.theme-10 .nex-prev-btn-wrp .btn {
    color: var(--theme10-color);
    border-radius: 4px;
}
body.custom-color .nex-prev-btn-wrp .btn {
    color: var(--color-customColor);
    border-radius: 4px;
}


.nex-prev-btn-wrp .btn:hover {
    color: var(--bs-btn-color) !important;
}

.nex-prev-btn-wrp .btn:not(.arrow-btn):hover svg path {
    fill: #fff !important;
}
</style>