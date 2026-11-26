{{--
    ADM-002 / GAP-232 — STORE 360°: THE ROSTER.
    =============================================================================================

    THE OPERATOR'S LIST OF STORES, AND IT IS NOT `stores.index`. That screen lists type-`admin`
    USER rows — its own breadcrumb reads `Users`, its figure is `User::countCompany()`, and the
    operator dashboard's card above it is labelled `Total Merchants` for exactly that reason. One
    merchant owns N stores, so a merchant list cannot be a store list, and ADM-002 asks for `كل
    متجر` — each STORE.

    `@extends` + `@section`, NEVER the `<x-*-layout>` slot shape. `layouts/app.blade.php` is the
    vendored WorkDo layout and contains zero `$slot` references, so a view written in the component
    shape renders perfect chrome, HTTP 200 and an EMPTY content area — measured, in all four
    language/theme combinations. `tests/Unit/Ui/SlotLayoutViewsRenderTest` fails on that shape
    because the failure is otherwise invisible.

    NOTHING HERE QUERIES, COUNTS OR FORMATS. Every row comes from
    `App\Support\Operator\StoreThreeSixty::roster()`.

    THIS SCREEN IS DELIBERATELY CROSS-TENANT and says so rather than implying a predicate it does
    not have: `roster()` reads `stores` whole, because listing every store IS the requirement.
    `Store` carries no `store_id` and is absent from `config/tenancy.php`'s `scoped_models`, so
    there is nothing to narrow and nothing that would narrow it. What confines this to the platform
    operator is the route's `PlatformOperatorOnly` and the handler's own check, not a query. The
    PER-STORE screen is the one where every read names its store.

    BILINGUAL BY CONSTRUCTION: every string is `__()` and direction comes from the layout's own
    `dir`. There is no hard-coded direction and no directional margin in this file.
--}}
@extends('layouts.app')

@section('page-title')
    {{ __('Store 360') }}
@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">
        {{ __('Store 360') }}
    </li>
@endsection

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <p class="ss-muted">
            {{ __('Every store on the platform. Open one to review its state and reach its own systems.') }}
        </p>
        <p class="ss-muted">
            {{ __('This is a list of stores, not of merchants. One merchant can own several stores, so a merchant appears once for each store they own.') }}
        </p>
    </div>
</div>

<div class="row">
    <div class="col-12">
        @if ($stores === [])
            <x-ss.empty :title="__('No stores yet')"
                        :body="__('When a merchant creates their first store it appears here.')"
                        icon="building-store" />
        @else
            <x-ss.card :title="__('Stores')">
                <div class="table-responsive">
                    <table class="table">
                        <caption class="ss-visually-hidden">
                            {{ __('Every store on the platform, with its owner and whether its storefront is live.') }}
                        </caption>
                        <thead>
                            <tr>
                                {{-- `__('Store name')`, NOT `__('Store')`: the vendored
                                     Arabic for the bare word is `مخزن` -- a WAREHOUSE -- while
                                     `Store name` is already declared as `اسم المتجر`. Reusing the
                                     wrong one would have put "warehouse" at the head of the
                                     operator's store list, and fixing the vendored entry is
                                     another workstream's call. --}}
                                <th scope="col">{{ __('Store name') }}</th>
                                <th scope="col">{{ __('Address') }}</th>
                                <th scope="col">{{ __('Owner') }}</th>
                                <th scope="col">{{ __('Storefront') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($stores as $row)
                                <tr>
                                    <th scope="row">
                                        <a class="ss-link" href="{{ route('platform.stores.show', $row['id']) }}">
                                            {{ $row['name'] }}
                                        </a>
                                    </th>
                                    <td>{{ $row['slug'] }}</td>
                                    <td>
                                        @if ($row['owner_missing'])
                                            {{-- NOT BLANK AND NOT A DASH. A store whose owner row is
                                                 gone is the one thing on this screen an operator
                                                 must act on, and `stores.created_by` is ON DELETE
                                                 CASCADE, so this state means the row survived a
                                                 delete it should not have. --}}
                                            <x-ss.status-badge tone="danger" :label="__('Owner record missing')" />
                                        @elseif ($row['owner_name'] === null)
                                            <x-ss.status-badge tone="warning" :label="__('No owner recorded')" />
                                        @else
                                            {{ $row['owner_name'] }}
                                            <span class="ss-field__hint d-block">{{ $row['owner_email'] }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($row['is_active'])
                                            <x-ss.status-badge tone="success" :label="__('Active')" />
                                        @else
                                            <x-ss.status-badge tone="neutral" :label="__('Inactive')" />
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ss.card>
        @endif
    </div>
</div>
@endsection
