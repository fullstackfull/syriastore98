@extends('layouts.app')

@section('page-title', __('Add-on Review Register'))

@section('breadcrumb')
    <li class="ss-breadcrumb__item"><a class="ss-breadcrumb__link" href="{{ route('module.index') }}">{{ __('Add-on Manager') }}</a></li>
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Review Register') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-1">{{ __('Uploaded add-ons and themes') }}</h5>
                    <small class="text-muted">
                        {{ __('An add-on or theme that was uploaded to this installation does not run until a decision is recorded here. Packages that shipped with the product are not listed and are not affected.') }}
                    </small>
                </div>
                <div class="card-body table-border-style">
                    <div class="table-responsive">
                        <table class="table datatable">
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('Package') }}</th>
                                    <th scope="col">{{ __('Type') }}</th>
                                    <th scope="col">{{ __('Declared version') }}</th>
                                    <th scope="col">{{ __('Review') }}</th>
                                    <th scope="col">{{ __('Uploaded') }}</th>
                                    <th scope="col" class="text-end">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($records as $record)
                                    <tr>
                                        <td>{{ $record->subject }}</td>
                                        <td>{{ $record->subject_type === 'theme' ? __('Theme') : __('Add-on') }}</td>
                                        <td>{{ $record->declared_version ?? __('not declared') }}</td>
                                        <td>
                                            {{-- The design system's own badge, not `badge bg-*`: `.ss-badge--*`
                                                 carries tokens defined for light AND dark and is
                                                 contrast-checked (store-syria-ds.css), and the component adds
                                                 a dot so colour alone does not carry the meaning (WCAG 1.4.1).
                                                 `badge bg-warning` appears nowhere else in the merchant tree. --}}
                                            @if ($record->review_state === 'approved')
                                                <x-ss.status-badge tone="success" :label="__('Approved')" />
                                            @elseif ($record->review_state === 'rejected')
                                                <x-ss.status-badge tone="danger" :label="__('Rejected')" />
                                            @else
                                                <x-ss.status-badge tone="warning" :label="__('Awaiting review')" />
                                            @endif
                                        </td>
                                        <td>{{ optional($record->created_at)->toDateString() }}</td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-outline-primary"
                                               href="{{ route('module.review.show', ['subject_type' => $record->subject_type, 'subject' => $record->subject]) }}">
                                                {{ __('Review') }}
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted">
                                            {{ __('Nothing has been uploaded to this installation.') }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
