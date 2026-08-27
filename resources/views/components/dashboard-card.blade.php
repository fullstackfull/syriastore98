@props(['count', 'label', 'icon', 'columnClass' => 'col-xxl-3 col-sm-6 col-12', 'url' => '#'])

<div class="{{ $columnClass }}">
    <div class="details-card">
        <div class="bg-img">
            <img src="{{ asset('images/details-card-bg.png') }}" alt="card-bg">
        </div>
        <div class="gap-3 d-flex align-items-center position-relative h-100">
            <a href="{{ $url ?? route('order.index') }}" class="card-icon fs-4">
                 {!! $icon !!}
             
            </a>
            <div class="card-content">
                <h2 class="h5 mb-0 f-w-500">{{ $label ?? '' }}</h2>
                <h3 class="h3 m-0">{{ $count ?? 0 }} </h3>
            </div>

        </div>
    </div>
</div>