{{--
    OPS-001 — THE PLATFORM OPERATOR'S DATABASE, QUEUE AND INTEGRATION MEASUREMENTS.

    `@extends` + `@section`, NEVER the `<x-*-layout>` slot shape. `layouts/app.blade.php` is the
    vendored WorkDo layout and contains zero `$slot` references, so a view written in the component
    shape renders perfect chrome, HTTP 200 and an EMPTY content area — measured, in all four
    language/theme combinations. tests/Unit/Ui/SlotLayoutViewsRenderTest fails on that shape
    because the failure is otherwise invisible.

    NOTHING HERE MEASURES, FORMATS, ROUNDS OR JUDGES. Every figure, period, hint, source and
    unavailability sentence comes from `SaasPlatformOperations`. In particular this file must never
    be "improved" with a colour, a badge or a threshold: a red queue-depth figure would be this
    platform stating an operational policy that nobody has set, and OPS-003 — incidents, alerts and
    diagnosis — is where a threshold belongs, with WHAT it is and WHO is notified an owner decision
    that has not been taken. Both halves are pinned: the producer may carry no verdict key, and the
    measurement block below may contain no `text-danger`/`bg-warning`-style judgement.

    NOT TAILWIND. The merchant admin is Blade plus the vendored Bootstrap 5 WorkDo theme plus
    `public/css/store-syria-ds.css`; `@vite` appears in zero views and there is no build, so a
    utility class written here would simply not paint.

    BILINGUAL BY CONSTRUCTION: every string is `__()` and direction comes from the layout's own
    `dir`, which `layouts/app.blade.php` forces to rtl for an `ar` or `he` principal. There is no
    hard-coded direction and no directional margin in this file, so it mirrors with the page.
--}}
@extends('layouts.app')

@section('page-title')
    {{ __('Platform operations') }}
@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">
        {{ __('Platform operations') }}
    </li>
@endsection

@section('content')
@php
    /*
     * ONE SENTENCE PER FIGURE, assembled in the order a reader needs it: what went wrong (only
     * when something did), what the figure means, and what measured it. The pieces are separate in
     * the producer because the tests assert them separately — a reason must exist for every
     * withheld figure — and are joined here because the stat row has one visible hint slot. The
     * closure is `platform_resources.blade.php`'s, so the two screens read identically.
     */
    $hintOf = function (array $row): string {
        $parts = [];

        if ($row['reason'] !== null) {
            $parts[] = $row['reason'];
        }

        $parts[] = $row['hint'];
        $parts[] = __('Measured with :source.', ['source' => $row['source']]);

        return implode(' ', $parts);
    };
@endphp

<div class="row mb-4">
    <div class="col-12">
        <p class="ss-muted">
            {{ __('What this deployment\'s database, queues and integrations report about themselves, read at the moment this page was built. Nothing here is cached, and nothing here is judged: the figures are stated and no threshold is applied to them.') }}
        </p>

        <p class="ss-muted">
            {{ __('Measured at :time.', ['time' => $measured['context']['measured_at']->toDateTimeString()]) }}
        </p>

        <p class="ss-muted mb-0">
            {{ __('The machine itself — load, memory and disk — is measured on a separate screen.') }}
            <a class="ss-link" href="{{ route('platform.resources') }}">{{ __('Platform resources') }}</a>
        </p>
    </div>
</div>

<div class="ss-operations-measurements">
    @foreach ([
        ['key' => 'database', 'heading' => __('Database'), 'caption' => __('What the database engine reports about itself.')],
        ['key' => 'queue', 'heading' => __('Queues and background work'), 'caption' => __('What is waiting, what a worker is holding, and what failed.')],
        ['key' => 'stores', 'heading' => __('Cache and session stores'), 'caption' => __('Where the cache and signed-in sessions are kept, and how much each holds.')],
        ['key' => 'integrations', 'heading' => __('Integrations'), 'caption' => __('Which integrations are configured and switched on, and the whole of the failure history this product keeps.')],
    ] as $group)
        <div class="row mb-4">
            <div class="col-12">
                <h2 class="h5 mb-3">{{ $group['heading'] }}</h2>

                <div class="table-responsive">
                    <table class="table">
                        <caption class="ss-visually-hidden">{{ $group['caption'] }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Measurement') }}</th>
                                <th scope="col">{{ __('Figure') }}</th>
                                <th scope="col">{{ __('Period') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($measured[$group['key']] as $row)
                                <tr>
                                    <th scope="row">{{ $row['label'] }}</th>
                                    <td>
                                        @if ($row['value'] === null)
                                            {{ __('Not available') }}
                                        @else
                                            {{ $row['value'] }}
                                        @endif
                                    </td>
                                    <td>{{ $row['period'] }}</td>
                                </tr>
                                <tr>
                                    <td colspan="3" class="ss-stat__hint">{{ $hintOf($row) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endforeach

    {{--
        PAYMENT GATEWAYS, the summary and then the per-gateway table.

        THE SUMMARY COMES FIRST AND CARRIES THE FULL POPULATION, because the table below lists only
        the gateways somebody has actually switched on — all 52 would be rows of zero — and a filter
        with no stated population conceals exactly as much as it clarifies.
    --}}
    <div class="row mb-4">
        <div class="col-12">
            <h2 class="h5 mb-3">{{ __('Payment gateways') }}</h2>

            <div class="table-responsive">
                <table class="table">
                    <caption class="ss-visually-hidden">
                        {{ __('Payment gateway enablement across every store on the platform. No credential is read or shown; only whether a value is present.') }}
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Measurement') }}</th>
                            <th scope="col">{{ __('Figure') }}</th>
                            <th scope="col">{{ __('Period') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($measured['gateways']['rows'] as $row)
                            <tr>
                                <th scope="row">{{ $row['label'] }}</th>
                                <td>
                                    @if ($row['value'] === null)
                                        {{ __('Not available') }}
                                    @else
                                        {{ $row['value'] }}
                                    @endif
                                </td>
                                <td>{{ $row['period'] }}</td>
                            </tr>
                            <tr>
                                <td colspan="3" class="ss-stat__hint">{{ $hintOf($row) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @if ($measured['gateways']['detail'] !== [])
        <div class="row mb-4">
            <div class="col-12">
                <h3 class="h6 mb-1">{{ __('Gateways switched on somewhere') }}</h3>

                <p class="ss-muted mb-3">
                    {{ __('One row per gateway at least one store has switched on. A gateway nobody has switched on is not listed; the count of all the gateways this product integrates is in the table above.') }}
                </p>

                <div class="table-responsive">
                    <table class="table">
                        <caption class="ss-visually-hidden">
                            {{ __('Per-gateway enablement. Complete means every field the gateway\'s own registry entry marks required has a value stored for that store.') }}
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Gateway') }}</th>
                                <th scope="col">{{ __('Stores with it switched on') }}</th>
                                <th scope="col">{{ __('…with every required credential stored') }}</th>
                                <th scope="col">{{ __('…with one missing') }}</th>
                                <th scope="col">{{ __('Required credentials') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($measured['gateways']['detail'] as $gateway)
                                <tr>
                                    <th scope="row">
                                        <code class="ss-code">{{ $gateway['id'] }}</code>
                                        @if ($gateway['retired'])
                                            <span class="ss-field__hint d-block">
                                                {{ __('Withdrawn by the operator: it is overridden where it is read, so nothing is offered to a shopper and these rows survive the withdrawal.') }}
                                            </span>
                                        @endif
                                    </th>
                                    <td>{{ number_format($gateway['enabled_stores']) }}</td>
                                    <td>{{ number_format($gateway['complete_stores']) }}</td>
                                    <td>{{ number_format($gateway['incomplete_stores']) }}</td>
                                    <td>{{ number_format($gateway['required']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
</div>
<!-- ss-operations-measurements-end -->

{{--
    WHAT THIS SCREEN DOES NOT MEASURE, said on the screen itself rather than only in a document.
    Two of these are decisions nobody has taken and the third needs an outbound connection from a
    web request, which is a decision of its own; an operator who cannot see that stated here would
    reasonably read this page as the whole of platform monitoring.
--}}
<div class="row">
    <div class="col-12">
        <p class="ss-stat__hint">
            {{ __('No threshold is applied to any figure here and nothing on this platform sends an alert: what counts as too deep a queue, too full a disk or too many failures, and who should be told, are decisions that have not been taken.') }}
        </p>

        <p class="ss-stat__hint">
            {{ __('No integration is contacted to produce this page. Whether a payment gateway, mail host or SMS provider can be reached right now would need an outbound request from this screen, which is a separate decision; what is shown is what this deployment has configured and switched on.') }}
        </p>

        <p class="ss-stat__hint mb-0">
            {{ __('Only queued background work records its own failures. A payment gateway, mail transport or SMS provider that fails leaves nothing durable behind in this product, so the failure figures here are not a complete list of integration failures and must not be read as one.') }}
        </p>
    </div>
</div>
@endsection
