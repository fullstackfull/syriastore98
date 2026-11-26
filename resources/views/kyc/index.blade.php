{{--
    GAP-194 / KYC-001 … KYC-003 — THE MERCHANT'S OWN VERIFICATION SCREEN.

    ONE CARD PER AUTHORITY, WHICH IS KYC-003 ON SCREEN. The approved sentence asks that Store
    Syria's own verification be SEPARATE from payment-provider and government-licence verification,
    so there is no single "verified" badge anywhere on this page: three cards, three statuses, three
    document lists. A combined badge is the conflation the requirement forbids, and it would be the
    first thing a well-meaning redesign added.

    `@extends` + `@section`, NEVER the `<x-*-layout>` slot shape. `layouts/app.blade.php` is the
    vendored WorkDo layout and contains zero `$slot` references, so a view in the component shape
    renders perfect chrome, HTTP 200 and an EMPTY content area — measured, in all four
    language/theme combinations. `tests/Unit/Ui/SlotLayoutViewsRenderTest` fails on that shape
    because the failure is otherwise invisible.

    NOT TAILWIND. The merchant admin is Blade plus the vendored Bootstrap 5 WorkDo theme plus
    `public/css/store-syria-ds.css`; `@vite` appears in zero views and there is no build, so a
    utility class written here would simply not paint.

    BILINGUAL BY CONSTRUCTION: every string is `__()` and direction comes from the layout's own
    `dir`. No hard-coded direction and no directional margin, so the page mirrors with the locale.

    THE STORAGE NAME IS NEVER PRINTED. It is 64 hex characters of entropy and it is the only thing
    standing between a stranger and the document on this host, where the document root is the
    repository root and the deny rules are not active. Nothing here renders `$document->storage_name`
    and `KycPrivateStorageTest` censuses this directory for any code that could reach the disk.

    AND NO DOCUMENT LIST IS INVENTED. `config('kyc.document_kinds')` ships EMPTY because which
    papers are required is an owner decision (`D-P6-46`); with none configured the upload form says
    so plainly instead of offering a guess.
--}}
@extends('layouts.app')

@section('page-title')
    {{ __('Verification') }}
@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">
        {{ __('Verification') }}
    </li>
@endsection

@section('content')
@php
    $toneOf = [
        \App\Models\KycVerification::APPROVED => 'success',
        \App\Models\KycVerification::REJECTED => 'danger',
        \App\Models\KycVerification::EXPIRED => 'warning',
        \App\Models\KycVerification::SUBMITTED => 'info',
        \App\Models\KycVerification::IN_REVIEW => 'info',
        \App\Models\KycVerification::UNSUBMITTED => 'neutral',
    ];

    $authorityLabels = [
        \App\Services\Kyc\KycAuthority::STORE_SYRIA => __('Store Syria verification'),
        \App\Services\Kyc\KycAuthority::PAYMENT_PROVIDER => __('Payment provider verification'),
        \App\Services\Kyc\KycAuthority::GOVERNMENT_LICENCE => __('Government licence'),
    ];

    $statusLabels = [
        \App\Models\KycVerification::UNSUBMITTED => __('Not submitted'),
        \App\Models\KycVerification::SUBMITTED => __('Submitted'),
        \App\Models\KycVerification::IN_REVIEW => __('In review'),
        \App\Models\KycVerification::APPROVED => __('Approved'),
        \App\Models\KycVerification::REJECTED => __('Rejected'),
        \App\Models\KycVerification::EXPIRED => __('Expired'),
    ];

    $documentStatusLabels = [
        \App\Models\KycDocument::PENDING => __('Awaiting review'),
        \App\Models\KycDocument::APPROVED => __('Approved'),
        \App\Models\KycDocument::REJECTED => __('Rejected'),
        \App\Models\KycDocument::SUPERSEDED => __('Replaced'),
        \App\Models\KycDocument::EXPIRED => __('Expired'),
    ];
@endphp

<div class="row mb-4">
    <div class="col-12">
        <p class="ss-muted">
            {{ __('Each kind of verification is kept separately. Store Syria\'s own check, a payment provider\'s check and a government licence are three different records, and approving one does not approve another.') }}
        </p>
        <p class="ss-muted">
            {{ __('Documents you upload here are private. They are never published at a web address, and every time anyone opens one it is recorded in that document\'s access log.') }}
        </p>
    </div>
</div>

@if ($dueForRenewal->isNotEmpty())
    <div class="row mb-4">
        <div class="col-12">
            <x-ss.card :title="__('Due for renewal')">
                <ul class="list-unstyled">
                    @foreach ($dueForRenewal as $document)
                        <li>
                            {{ $authorityLabels[$document->authority] ?? $document->authority }}
                            &mdash;
                            {{ \App\Services\Kyc\KycAuthority::kindLabel($document->document_kind) }}
                            &mdash;
                            {{ __('expires :date', ['date' => optional($document->expires_on)->toDateString()]) }}
                        </li>
                    @endforeach
                </ul>
            </x-ss.card>
        </div>
    </div>
@endif

<div class="row">
    @foreach ($authorities as $authority)
        @php
            $record = $records[$authority] ?? null;
            $ownDocuments = $documents[$authority] ?? collect();
            $status = $record ? (string) $record->status : \App\Models\KycVerification::UNSUBMITTED;
        @endphp

        <div class="col-xl-4 col-lg-6 col-12 mb-4">
            <x-ss.card :title="$authorityLabels[$authority] ?? $authority">
                <p>
                    <x-ss.status-badge
                        :tone="$toneOf[$status] ?? 'neutral'"
                        :label="$statusLabels[$status] ?? $status" />
                </p>

                @if ($record && $record->expires_on)
                    <p class="ss-muted">
                        {{ __('Valid until :date', ['date' => $record->expires_on->toDateString()]) }}
                    </p>
                @endif

                @if ($record && $record->review_note)
                    <p class="ss-muted">{{ $record->review_note }}</p>
                @endif

                @if ($ownDocuments->isEmpty())
                    <x-ss.empty
                        :title="__('No documents yet')"
                        :body="__('Nothing has been supplied for this kind of verification.')"
                        icon="file-off" />
                @else
                    <ul class="list-unstyled">
                        @foreach ($ownDocuments as $document)
                            <li>
                                <span>{{ \App\Services\Kyc\KycAuthority::kindLabel($document->document_kind) }}</span>
                                <x-ss.status-badge
                                    :tone="$document->status === \App\Models\KycDocument::APPROVED ? 'success' : ($document->status === \App\Models\KycDocument::REJECTED ? 'danger' : 'neutral')"
                                    :label="$documentStatusLabels[$document->status] ?? $document->status" />
                                <a href="{{ route('kyc.documents.download', $document->id) }}">{{ __('Open') }}</a>
                                <a href="{{ route('kyc.documents.access-log', $document->id) }}">{{ __('Access log') }}</a>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($record && $status !== \App\Models\KycVerification::APPROVED && $ownDocuments->isNotEmpty())
                    <form method="POST" action="{{ route('kyc.submit', $record->id) }}">
                        @csrf
                        <button type="submit" class="btn btn-primary">{{ __('Submit for review') }}</button>
                    </form>
                @endif
            </x-ss.card>
        </div>
    @endforeach
</div>

<div class="row">
    <div class="col-12">
        <x-ss.card :title="__('Add a document')">
            @php
                $configured = [];

                foreach ($subjectTypes as $subjectType) {
                    foreach ($authorities as $authority) {
                        foreach (\App\Services\Kyc\KycAuthority::documentKinds($subjectType, $authority) as $kind) {
                            $configured[$subjectType][$authority][] = $kind;
                        }
                    }
                }
            @endphp

            @if ($configured === [])
                <x-ss.empty
                    :title="__('No document types are configured yet')"
                    :body="__('Store Syria has not yet published the list of documents it collects, so nothing can be uploaded here. Nothing is missing from your account.')"
                    icon="file-off" />
            @else
                <form method="POST" action="{{ route('kyc.documents.store') }}" enctype="multipart/form-data">
                    @csrf

                    <div class="mb-3">
                        <label class="form-label" for="kyc-subject-type">{{ __('You are verifying') }}</label><span class="ss-label__required" aria-hidden="true">*</span>
                        <select class="form-select" id="kyc-subject-type" name="subject_type" required>
                            <option value="individual">{{ __('An individual') }}</option>
                            <option value="company">{{ __('A company') }}</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="kyc-authority">{{ __('Kind of verification') }}</label><span class="ss-label__required" aria-hidden="true">*</span>
                        <select class="form-select" id="kyc-authority" name="authority" required>
                            @foreach ($authorities as $authority)
                                <option value="{{ $authority }}">{{ $authorityLabels[$authority] ?? $authority }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="kyc-kind">{{ __('Document type') }}</label><span class="ss-label__required" aria-hidden="true">*</span>
                        <select class="form-select" id="kyc-kind" name="document_kind" required>
                            @foreach ($configured as $subjectType => $byAuthority)
                                @foreach ($byAuthority as $authority => $kinds)
                                    @foreach ($kinds as $kind)
                                        <option value="{{ $kind }}">{{ \App\Services\Kyc\KycAuthority::kindLabel($kind) }}</option>
                                    @endforeach
                                @endforeach
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="kyc-file">{{ __('Document file') }}</label><span class="ss-label__required" aria-hidden="true">*</span>
                        <input class="form-control" type="file" id="kyc-file" name="document"
                               aria-describedby="kyc-file-help" required>
                        <x-ss.field-help for="kyc-file">
                            {{ __('Accepted: :types. Up to :size KB.', [
                                'types' => implode(', ', (array) config('kyc.accepted_extensions', [])),
                                'size' => (int) config('kyc.max_kilobytes', 8192),
                            ]) }}
                        </x-ss.field-help>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="kyc-issued">{{ __('Issued on') }}</label>
                        <input class="form-control" type="date" id="kyc-issued" name="issued_on">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="kyc-expires">{{ __('Expires on') }}</label>
                        <input class="form-control" type="date" id="kyc-expires" name="expires_on">
                    </div>

                    <button type="submit" class="btn btn-primary">{{ __('Upload document') }}</button>
                </form>
            @endif
        </x-ss.card>
    </div>
</div>
@endsection
