@extends('layouts.app')

@section('page-title')
    {{ __('Customer Segments') }}
@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Customer Segments') }}</li>
@endsection

@section('content')
    @include('layouts.includes.store-syria-ds')

    {{--
        MKT-004 -- ONE SCREEN FOR BOTH KINDS OF SEGMENT.

        A segment is a named set of this store's customers. A STATIC one is a list the merchant
        curates; a DYNAMIC one is a rule evaluated when it is read. They are listed together on
        purpose: they are one concept, and a second screen for "audiences" is how a product ends up
        with two half-features that disagree about what a segment is.

        THE COUNT IS COMPUTED, NEVER STORED, and for a dynamic segment it cannot be. A cached
        `member_count` column would be the thing that drifts, and a merchant reading a stale count
        against the list they can open is the defect.

        NOTHING HERE SENDS ANYTHING. Who may be messaged, how often and on what consent is MKT-007
        and the D8 consent work, whose enforcement is off by owner decision. A "send to segment"
        button here would route around that, so there is not one.
    --}}

    <div class="row mb-4">
        <div class="col-12">
            <div class="ss-card">
                <h2 class="h5 mb-1">{{ __('Your segments') }}</h2>

                <p class="ss-muted">
                    {{ __('A segment is a named group of your customers. You can curate a list by hand, or describe a rule and let the group follow it.') }}
                </p>

                @if (count($segments) > 0)
                    <div class="table-responsive">
                        <table class="table ss-table">
                            <caption class="ss-visually-hidden">{{ __('Your segments') }}</caption>
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('Name') }}</th>
                                    <th scope="col">{{ __('Kind') }}</th>
                                    <th scope="col">{{ __('Customers') }}</th>
                                    <th scope="col">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($segments as $segment)
                                    <tr>
                                        <th scope="row">{{ $segment->name }}</th>
                                        <td>{{ __($kinds[$segment->kind] ?? $segment->kind) }}</td>
                                        <td>
                                            {{-- `ss-num` so the figure stays left-to-right on an Arabic page. --}}
                                            <span class="ss-num">{{ number_format($counts[$segment->id] ?? 0) }}</span>

                                            @if (in_array($segment->id, $staleRules, true))
                                                {{-- A DYNAMIC SEGMENT WHOSE RULE NO LONGER PARSES matches nothing, and
                                                     a bare 0 would read as "no such customers" rather than "no rule".
                                                     The stored rule is re-sanitised on read against the vocabulary this
                                                     version knows, so a row written by a later version, restored from a
                                                     dump or edited by hand can arrive with nothing left. --}}
                                                <span class="ss-stat__hint">{{ __('No rule is set, so this segment is empty.') }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            <a href="{{ route('customer-segments.show', $segment->id) }}"
                                               class="ss-btn ss-btn--secondary ss-btn--sm">{{ __('Open') }}</a>

                                            <form method="POST" action="{{ route('customer-segments.destroy', $segment->id) }}"
                                                  style="display:inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="ss-btn ss-btn--secondary ss-btn--sm">{{ __('Delete') }}</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p>{{ __('You have no segments yet.') }}</p>
                @endif
            </div>
        </div>
    </div>

    {{--
        THE TWO KINDS SHARE ONE FORM, and the rules are disclosed rather than hidden behind the
        choice: a merchant deciding which kind to make should be able to see what a rule can say.

        NO RULE SHIPS A NUMBER. Every value is one the merchant types -- "at least how many orders"
        is a marketing judgement, and this project does not invent those.
    --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="ss-card">
                <h2 class="h5 mb-1">{{ __('New segment') }}</h2>

                <form method="POST" action="{{ route('customer-segments.store') }}" class="mt-2">
                    @csrf

                    <div class="row g-2 align-items-end">
                        <div class="col-sm-5">
                            {{-- The design system's required marker, `aria-hidden` because what
                                 announces the constraint to assistive technology is the control's
                                 own `required` attribute (`RequiredFieldMarkerTest`). --}}
                            <label class="ss-field__label" for="segment_name">{{ __('Name') }}</label><span class="ss-label__required" aria-hidden="true">*</span>
                            <input class="form-control" type="text" id="segment_name" name="name" maxlength="60"
                                   placeholder="{{ __('e.g. Regular buyers') }}" required>
                        </div>

                        <div class="col-sm-4">
                            <label class="ss-field__label" for="segment_kind">{{ __('Kind') }}</label>
                            <select class="form-control" id="segment_kind" name="kind">
                                @foreach ($kinds as $value => $words)
                                    <option value="{{ $value }}">{{ __($words) }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-sm-3">
                            <button type="submit" class="ss-btn ss-btn--secondary w-100">{{ __('Create') }}</button>
                        </div>
                    </div>

                    <fieldset class="mt-3">
                        <legend class="ss-field__label">{{ __('Rules (only used by a rule-based segment)') }}</legend>

                        <div class="row g-2">
                            @foreach ($rules as $ruleKey => $ruleSpec)
                                <div class="col-sm-6">
                                    @if ($ruleSpec['value'] === null)
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" value="1"
                                                   id="rule_{{ $ruleKey }}" name="rules[{{ $ruleKey }}]">
                                            <label class="form-check-label" for="rule_{{ $ruleKey }}">
                                                {{ __($ruleSpec['label']) }}
                                            </label>
                                        </div>
                                    @else
                                        <label class="ss-field__label" for="rule_{{ $ruleKey }}">
                                            {{ __($ruleSpec['label']) }}
                                        </label>
                                        <input class="form-control {{ $ruleSpec['value'] === 'int' ? 'ss-num' : '' }}"
                                               type="{{ $ruleSpec['value'] === 'int' ? 'number' : 'date' }}"
                                               @if ($ruleSpec['value'] === 'int') min="0" step="1" @endif
                                               id="rule_{{ $ruleKey }}" name="rules[{{ $ruleKey }}]"
                                               placeholder="{{ __('Not used') }}">
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </fieldset>

                    <p class="ss-stat__hint mt-2">
                        {{ __('Rules you fill in all have to be true at once. A rule you leave blank is not used, and a rule-based segment needs at least one.') }}
                    </p>

                    <p class="ss-stat__hint">
                        {{ __('Orders are counted in this store only, and baskets bought as a guest are not counted towards a customer.') }}
                    </p>

                    <p class="ss-stat__hint">
                        {{ __('There is no rule about how much a customer has spent. Spending is held in whichever currency each order was taken in, and there is no exchange rate here, so such a rule would quietly leave people out.') }}
                    </p>
                </form>
            </div>
        </div>
    </div>
@endsection
