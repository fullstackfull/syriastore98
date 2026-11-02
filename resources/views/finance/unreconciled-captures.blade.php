@extends('layouts.app')

{{--
    D4 / D-29.4 — the review surface for a collected payment that has no order.

    IT SHOWS AND IT DOES NOT ACT, and that is the design. The owner's decision forbids declaring a
    capture successful, creating an order, releasing the replay claim or moving stock without
    gateway evidence, and states that a merchant's own statement is not evidence. So there is no
    form, no button and no action column on this page — an empty action column would invite
    somebody to fill it in.

    Every string goes through __() so Arabic and English both read correctly, and the layout
    inherits the shared RTL/LTR direction rather than setting its own.
--}}

@section('page-title')
    {{ __('Payments Awaiting Review') }}
@endsection

@section('breadcrumb')
    <li class="ss-breadcrumb__item">
        <a class="ss-breadcrumb__link" href="{{ route('finance.index') }}">{{ __('Finance') }}</a>
    </li>

    <li aria-current="page" class="ss-breadcrumb__item">
        {{ __('Payments Awaiting Review') }}
    </li>
@endsection

@section('content')

<div class="card">

    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center">

            <h5 class="mb-0">
                {{ __('Payments Awaiting Review') }}
            </h5>

            <a href="{{ route('finance.index') }}" class="btn btn-sm btn-light">
                {{ __('Back to Finance') }}
            </a>

        </div>
    </div>

    <div class="card-body">

        <p class="ss-text-muted">
            {{ __('These payments were collected but no order was recorded against them. They need to be checked with the payment provider before anything is settled.') }}
        </p>

        @if (! $tableIsAvailable)
            {{-- The honest empty state. An absent table is not "nothing to review". --}}
            <div class="alert alert-warning" role="status">
                {{ __('The payment reference record is not installed on this environment, so this list cannot be complete. An empty list here is not evidence that nothing needs review.') }}
            </div>
        @endif

        @if ($captures->isEmpty())
            <div class="ss-empty-state" role="status">
                <p class="mb-0">{{ __('No payments are awaiting review.') }}</p>
            </div>
        @else
            <div class="table-responsive">
                <table class="table ss-table">
                    <caption class="visually-hidden">
                        {{ __('Payments collected with no order recorded against them') }}
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Payment Provider') }}</th>
                            <th scope="col">{{ __('Provider Reference') }}</th>
                            <th scope="col">{{ __('Amount') }}</th>
                            <th scope="col">{{ __('Status') }}</th>
                            <th scope="col">{{ __('First Seen') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($captures as $capture)
                            <tr>
                                <td>{{ $capture['gateway'] }}</td>
                                <td><code>{{ $capture['reference'] }}</code></td>
                                <td>{{ $capture['amount'] }}</td>
                                <td>
                                    {{-- "Needs follow-up", which D-29.4 asks for by name. Never "paid". --}}
                                    <span class="badge bg-warning text-dark">
                                        {{ __('Needs follow-up') }}
                                    </span>
                                </td>
                                <td>{{ $capture['seen_at'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="ss-text-muted mt-3 mb-0">
                {{ __('Nothing on this page changes an order, a payment status or stock. Settling one of these requires confirmation from the payment provider.') }}
            </p>
        @endif

    </div>

</div>

@endsection
