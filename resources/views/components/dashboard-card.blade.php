@props(['count', 'label', 'icon', 'columnClass' => 'col-xxl-3 col-sm-6 col-12', 'url' => '#'])

<div class="{{ $columnClass }}">
    <div class="details-card">
        <div class="bg-img">
            <img src="{{ asset('images/details-card-bg.png') }}" alt="card-bg">
        </div>
        <div class="gap-3 d-flex align-items-center position-relative h-100">
            {{-- AREA D / P06-05. THE LINK HAD NO ACCESSIBLE NAME, measured six times over.
                 It contains `{!! $icon !!}` and nothing else -- an `<i class="ti ti-...">` glyph
                 contributes no text -- so `audit-merchant-screens.cjs` reported SIX nameless
                 controls on the operator dashboard in all four AR/EN x light/dark combinations,
                 every one of them this anchor.

                 The name was already here: `$label` is the card's own heading, rendered in the
                 `<h2>` a few lines below, so nothing is invented or newly translated. `aria-label`
                 and not a visually-hidden span because it adds NO pixels: this surface has never
                 been through owner visual acceptance, and a layout change would need one.

                 `DashboardCardIconLinkHasANameTest` holds it, including that a label carrying a
                 quote is escaped into the attribute rather than breaking out of it, and that all
                 six operator cards really do pass a label -- `$label ?? ''` means a card without
                 one would be nameless again. --}}
            <a href="{{ $url ?? route('order.index') }}" class="card-icon fs-4"
               aria-label="{{ $label ?? '' }}">
                 {!! $icon !!}
             
            </a>
            <div class="card-content">
                <h2 class="h5 mb-0 f-w-500">{{ $label ?? '' }}</h2>
                <h3 class="h3 m-0">{{ $count ?? 0 }} </h3>
            </div>

        </div>
    </div>
</div>