{{--
    GAP-194 / KYC-002 — THE PLATFORM OPERATOR'S REVIEW QUEUE.

    OPERATOR ONLY, AT THE ROUTE AND AGAIN IN THE HANDLER. A merchant must never approve their own
    identity papers; that is a safety invariant, not a product preference, and it is the reason this
    screen is not reachable with a permission name — a merchant creates their own roles, so a
    permission is substantially self-assignable while `users.type` is not.

    ONE ROW PER (STORE, AUTHORITY), NEVER ONE PER STORE. KYC-003 asks that the three kinds of
    verification stay separate, so a queue that showed "store X: verify" with one button would be
    the conflation the requirement forbids.

    THE DECISION FORM NAMES NO STORE. There is no hidden `store_id` on this page: the handler takes
    the store from the ROW it loaded, because a tenant predicate built from caller-supplied input is
    not a tenant check — it only compares the caller's own two values.

    `@extends` + `@section`, never the `<x-*-layout>` slot shape; see `kyc/index.blade.php`.
    Not Tailwind. Every string `__()`; no hard-coded direction.
--}}
@extends('layouts.app')

@section('page-title')
    {{ __('Verification queue') }}
@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">
        {{ __('Verification queue') }}
    </li>
@endsection

@section('content')
@php
    $authorityLabels = [
        \App\Services\Kyc\KycAuthority::STORE_SYRIA => __('Store Syria verification'),
        \App\Services\Kyc\KycAuthority::PAYMENT_PROVIDER => __('Payment provider verification'),
        \App\Services\Kyc\KycAuthority::GOVERNMENT_LICENCE => __('Government licence'),
    ];
@endphp

<div class="row mb-4">
    <div class="col-12">
        <p class="ss-muted">
            {{ __('Records a merchant has submitted for review. Each row is one kind of verification for one store; approving one does not approve another.') }}
        </p>
        <p class="ss-muted">
            {{ __('Opening a document is recorded in that document\'s access log, together with who opened it and when.') }}
        </p>
    </div>
</div>

<div class="row mb-4">
    <div class="col-12">
        <form method="GET" action="{{ route('kyc.queue') }}">
            <label class="form-label" for="kyc-queue-authority">{{ __('Kind of verification') }}</label>
            <select class="form-select" id="kyc-queue-authority" name="authority" onchange="this.form.submit()">
                <option value="">{{ __('All kinds') }}</option>
                @foreach ($authorities as $authority)
                    <option value="{{ $authority }}" @selected($selectedAuthority === $authority)>
                        {{ $authorityLabels[$authority] ?? $authority }}
                    </option>
                @endforeach
            </select>
            <noscript><button type="submit" class="btn btn-secondary">{{ __('Filter') }}</button></noscript>
        </form>
    </div>
</div>

@if ($records->isEmpty())
    <x-ss.empty
        :title="__('Nothing is waiting for review')"
        :body="__('A record appears here once a merchant submits it.')"
        icon="inbox" />
@else
    @foreach ($records as $record)
        <div class="row mb-4">
            <div class="col-12">
                <x-ss.card :title="($stores[$record->store_id] ?? __('Store :id', ['id' => $record->store_id])) . ' — ' . ($authorityLabels[$record->authority] ?? $record->authority)">
                    <p class="ss-muted">
                        {{ $record->subject_type === \App\Models\KycVerification::COMPANY ? __('A company') : __('An individual') }}
                        @if ($record->submitted_at)
                            &mdash; {{ __('submitted :when', ['when' => $record->submitted_at->toDateTimeString()]) }}
                        @endif
                    </p>

                    @php $outstanding = \App\Services\Kyc\KycReview::missingKinds($record); @endphp

                    @if ($outstanding !== [])
                        <p class="ss-muted">
                            {{ __('Outstanding: :kinds', ['kinds' => implode(', ', array_map(fn ($k) => \App\Services\Kyc\KycAuthority::kindLabel($k), $outstanding))]) }}
                        </p>
                    @endif

                    <ul class="list-unstyled">
                        @foreach ($documents[$record->id] ?? [] as $document)
                            <li>
                                <span>{{ \App\Services\Kyc\KycAuthority::kindLabel($document->document_kind) }}</span>
                                <span class="ss-muted">{{ $document->status }}</span>
                                <a href="{{ route('kyc.documents.download', $document->id) }}">{{ __('Open') }}</a>
                                <a href="{{ route('kyc.documents.access-log', $document->id) }}">{{ __('Access log') }}</a>

                                <form method="POST" action="{{ route('kyc.documents.decide', $document->id) }}">
                                    @csrf
                                    <label class="form-label" for="kyc-doc-expires-{{ $document->id }}">{{ __('Expires on') }}</label>
                                    <input class="form-control" type="date" id="kyc-doc-expires-{{ $document->id }}" name="expires_on"
                                           value="{{ optional($document->expires_on)->toDateString() }}">

                                    <label class="form-label" for="kyc-doc-note-{{ $document->id }}">{{ __('Note') }}</label>
                                    <input class="form-control" type="text" id="kyc-doc-note-{{ $document->id }}" name="note" maxlength="1000">

                                    <button type="submit" name="decision" value="approve" class="btn btn-primary">{{ __('Approve document') }}</button>
                                    <button type="submit" name="decision" value="reject" class="btn btn-secondary">{{ __('Reject document') }}</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>

                    <form method="POST" action="{{ route('kyc.records.decide', $record->id) }}">
                        @csrf
                        <label class="form-label" for="kyc-record-expires-{{ $record->id }}">{{ __('Verification valid until') }}</label>
                        <input class="form-control" type="date" id="kyc-record-expires-{{ $record->id }}" name="expires_on"
                               value="{{ optional($record->expires_on)->toDateString() }}">

                        <label class="form-label" for="kyc-record-note-{{ $record->id }}">{{ __('Note to the merchant') }}</label>
                        <input class="form-control" type="text" id="kyc-record-note-{{ $record->id }}" name="note" maxlength="1000">

                        <button type="submit" name="decision" value="approve" class="btn btn-primary">{{ __('Approve verification') }}</button>
                        <button type="submit" name="decision" value="reject" class="btn btn-secondary">{{ __('Reject verification') }}</button>
                    </form>
                </x-ss.card>
            </div>
        </div>
    @endforeach
@endif
@endsection
