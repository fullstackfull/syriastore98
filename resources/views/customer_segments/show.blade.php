@extends('layouts.app')

@section('page-title')
    {{ $segment->name }}
@endsection

@section('breadcrumb')
    <li class="ss-breadcrumb__item">
        <a class="ss-breadcrumb__link" href="{{ route('customer-segments.index') }}">{{ __('Customer Segments') }}</a>
    </li>
    <li aria-current="page" class="ss-breadcrumb__item">{{ $segment->name }}</li>
@endsection

@section('content')
    @include('layouts.includes.store-syria-ds')

    {{--
        MKT-004 -- ONE SEGMENT, ITS MEMBERS AND HOW THEY GOT THERE.

        THE SCREEN SAYS WHICH KIND IT IS AND WHAT THAT MEANS. A merchant who cannot tell a curated
        list from a rule cannot tell why somebody is in it, and for a rule-based segment the answer
        changes under them as customers order: that is correct behaviour and it has to be stated, or
        it reads as a bug.
    --}}

    <div class="row mb-4">
        <div class="col-12">
            <div class="ss-card">
                <h2 class="h5 mb-1">{{ $segment->name }}</h2>

                <p class="ss-muted">{{ __($kinds[$segment->kind] ?? $segment->kind) }}</p>

                @if ($segment->isDynamic())
                    @php($active = $segment->definition()->rules())

                    @if (count($active) > 0)
                        <ul>
                            @foreach ($active as $ruleKey => $ruleValue)
                                <li>
                                    {{ __($rules[$ruleKey]['label'] ?? $ruleKey) }}@if ($ruleValue !== true):
                                        <span class="ss-num">{{ $ruleValue }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>

                        <p class="ss-stat__hint">
                            {{ __('This list is worked out when you open it, so it changes as your customers order.') }}
                        </p>
                    @else
                        <p class="ss-stat__hint">
                            {{ __('No rule is set, so this segment is empty. Create it again with at least one rule.') }}
                        </p>
                    @endif
                @else
                    <p class="ss-stat__hint">
                        {{ __('This is a list you curate. Add or remove a customer and nothing else changes it.') }}
                    </p>

                    <form method="POST" action="{{ route('customer-segments.members.add', $segment->id) }}"
                          class="row g-2 align-items-end mt-2">
                        @csrf

                        <div class="col-sm-5">
                            <label class="ss-field__label" for="member_customer_id">{{ __('Customer ID') }}</label><span class="ss-label__required" aria-hidden="true">*</span>
                            {{-- An ID and not a name, for now: a customer picker over a real store's
                                 customer list is its own surface, and a free-text name search that
                                 matched two people would add the wrong one silently. The id is
                                 re-resolved inside this store, so one that names another store's
                                 customer does not resolve. --}}
                            <input class="form-control ss-num" type="number" min="1" step="1"
                                   id="member_customer_id" name="customer_id" required>
                        </div>

                        <div class="col-sm-3">
                            <button type="submit" class="ss-btn ss-btn--secondary w-100">{{ __('Add') }}</button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-12">
            <div class="ss-card">
                <h2 class="h5 mb-1">
                    {{ __('Customers') }}
                    (<span class="ss-num">{{ number_format($customers->total()) }}</span>)
                </h2>

                @if ($customers->total() > 0)
                    <div class="table-responsive">
                        <table class="table ss-table">
                            <caption class="ss-visually-hidden">{{ __('Customers in this segment') }}</caption>
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('Name') }}</th>
                                    <th scope="col">{{ __('Email') }}</th>
                                    @unless ($segment->isDynamic())
                                        <th scope="col">{{ __('Action') }}</th>
                                    @endunless
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($customers as $customer)
                                    <tr>
                                        <th scope="row">{{ trim($customer->first_name.' '.$customer->last_name) }}</th>
                                        <td>{{ $customer->email }}</td>
                                        @unless ($segment->isDynamic())
                                            <td>
                                                <form method="POST"
                                                      action="{{ route('customer-segments.members.remove', $segment->id) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <input type="hidden" name="customer_id" value="{{ $customer->id }}">
                                                    <button type="submit" class="ss-btn ss-btn--secondary ss-btn--sm">{{ __('Remove') }}</button>
                                                </form>
                                            </td>
                                        @endunless
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{ $customers->links() }}
                @else
                    <p>{{ __('No customer is in this segment.') }}</p>
                @endif
            </div>
        </div>
    </div>
@endsection
