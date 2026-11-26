{{--
    GAP-194 / KYC-002's FOURTH CLAUSE — سجلات الوصول. WHO LOOKED AT THIS DOCUMENT, AND WHEN.

    THIS IS THE SCREEN THE REQUIREMENT ASKS FOR, from the merchant's side: their own identity paper,
    and the record of every access to it — including accesses by the platform, which are the ones a
    merchant cannot otherwise see.

    THE STORAGE NAME IS NOT ON THIS PAGE, and neither is anything that could be used to fetch the
    file outside this application. The name is 64 hex characters of entropy and on this host it is
    the only thing standing between a stranger and the document (the document root is the repository
    root and the deny rules are not active), so the access-log writer refuses a `storage_name` detail
    outright and `KycAccessLogTest` asserts no row contains it.

    THE ACTOR IS A NAME OR "the platform", NEVER AN ID OR AN EMAIL. A merchant does not need the
    operator's account details to know that the platform opened their passport scan, and printing
    them would turn a compliance screen into a directory of staff addresses.

    `@extends` + `@section`, never the `<x-*-layout>` slot shape; see `kyc/index.blade.php`.
    Not Tailwind. Every string `__()`; no hard-coded direction.
--}}
@extends('layouts.app')

@section('page-title')
    {{ __('Document access log') }}
@endsection

@section('breadcrumb')
    <li class="ss-breadcrumb__item">
        <a href="{{ route('kyc.index') }}">{{ __('Verification') }}</a>
    </li>
    <li aria-current="page" class="ss-breadcrumb__item">
        {{ __('Document access log') }}
    </li>
@endsection

@section('content')
@php
    $actionLabels = [
        \App\Services\Kyc\KycAccessLog::UPLOADED => __('Uploaded'),
        \App\Services\Kyc\KycAccessLog::READ => __('Opened'),
        \App\Services\Kyc\KycAccessLog::REFUSED => __('Refused'),
        \App\Services\Kyc\KycAccessLog::APPROVED => __('Approved'),
        \App\Services\Kyc\KycAccessLog::REJECTED => __('Rejected'),
        \App\Services\Kyc\KycAccessLog::EXPIRED => __('Expired'),
        \App\Services\Kyc\KycAccessLog::SUPERSEDED => __('Replaced'),
    ];
@endphp

<div class="row mb-4">
    <div class="col-12">
        <x-ss.card :title="\App\Services\Kyc\KycAuthority::kindLabel($document->document_kind)">
            <p class="ss-muted">{{ $document->original_name }}</p>
            <p class="ss-muted">
                {{ __('Added :when', ['when' => optional($document->created_at)->toDateTimeString()]) }}
            </p>
        </x-ss.card>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <x-ss.card :title="__('Every access to this document')">
            @if ($entries->isEmpty())
                <x-ss.empty
                    :title="__('No recorded access')"
                    :body="__('Nothing has opened this document.')"
                    icon="eye-off" />
            @else
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('When') }}</th>
                                <th scope="col">{{ __('What happened') }}</th>
                                <th scope="col">{{ __('Who') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($entries as $entry)
                                <tr>
                                    <td>{{ optional($entry->created_at)->toDateTimeString() }}</td>
                                    <td>{{ $actionLabels[$entry->action] ?? $entry->action }}</td>
                                    {{--
                                        THE LABEL IS DECIDED IN `KycAccessLog::actorLabel()`, NOT
                                        HERE, and that is not tidiness. The first version of this
                                        cell printed `$actor->name` for any non-operator, and a
                                        REFUSED access by a stranger is filed against the VICTIM's
                                        store with the stranger's user id — so this screen would
                                        have told one merchant the full name of a user in another
                                        tenant. The rule has a tenancy test in it, so it belongs
                                        where a test can drive it.
                                    --}}
                                    <td>{{ \App\Services\Kyc\KycAccessLog::actorLabel($entry->user_id, (int) $document->store_id) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ss.card>
    </div>
</div>
@endsection
