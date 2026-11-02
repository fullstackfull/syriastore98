{{--
    Webhooks, on the design system.
    =============================================================================================

    THE TABLE HAD NO ROWS. The `@foreach` wrapped the `<tbody>` rather than a `<tr>`, so the
    markup was one `<tbody>` per webhook, each containing bare `<td>`s with NO `<tr>` at all.
    Browsers repair that, but what they repair it INTO is not something a screen reader's table
    navigation can follow, and `<tbody>` repeated is not the same document as one `<tbody>` of
    rows. One tbody, one tr per webhook.

    THE ROW ACTIONS HAD NO ACCESSIBLE NAME. Each was an icon inside a button whose only text was
    a `title`, and Bootstrap's tooltip MOVES `title` to `data-bs-original-title` and blanks it on
    init — so by the time a user reaches the button there is nothing left to announce. That is
    the same defect F106 recorded on the stock report, where five links measured an empty
    accessible name. The names are visually-hidden text now, which no plugin rewrites.

    The delete button was `type="button"` inside the DELETE form, so it submits nothing on its
    own; `show_confirm` does it. Left as it is — that handler is shared across the admin and
    changing it is not this slice's work — but the button is `type="submit"` so the form still
    works with JavaScript off.
--}}
<div class="ss-card" id="Webhook_Setting">
    <div class="ss-card__header">
        <div class="ss-page-header__text">
            <h2 class="ss-card__title">{{ __('Webhook Settings') }}</h2>
            <p class="ss-page-header__subtitle">{{ __('Edit your Webhook Settings') }}</p>
        </div>
        <button type="button" class="ss-btn ss-btn--primary ss-btn--sm" data-ajax-popup="true"
                data-url="{{ route('webhook.create') }}"
                data-title="{{ __('Create New Webhook') }}">
            <i class="ti ti-plus" aria-hidden="true"></i>
            <span class="ss-visually-hidden">{{ __('Add Webhook') }}</span>
        </button>
    </div>

    <div class="ss-card__body ss-card__body--flush">
        <div class="ss-table-wrap">
            <table class="ss-table" id="pc-dt-simple">
                <thead>
                    <tr>
                        <th scope="col">{{ __('module') }}</th>
                        <th scope="col">{{ __('url') }}</th>
                        <th scope="col">{{ __('method') }}</th>
                        <th scope="col" class="text-end">{{ __('Action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($webhooks as $webhook)
                        <tr>
                            <td>{{ $webhook->module }}</td>
                            <td>{{ $webhook->url }}</td>
                            <td>{{ $webhook->method }}</td>
                            <td>
                                <div class="d-flex gap-1 justify-content-end">
                                    <button type="button" class="ss-btn ss-btn--secondary ss-btn--sm ss-btn--icon"
                                            data-url="{{ route('webhook.edit', $webhook->id) }}" data-size="md"
                                            data-ajax-popup="true" data-title="{{ __('Edit webhook') }}">
                                        <i class="ti ti-pencil" aria-hidden="true"></i>
                                        <span class="ss-visually-hidden">
                                            {{ __('Edit') }} — {{ $webhook->module }}
                                        </span>
                                    </button>

                                    {!! Form::open([
                                        'method' => 'DELETE',
                                        'route' => ['webhook.destroy', $webhook->id],
                                        'class' => 'd-inline',
                                    ]) !!}
                                    <button type="submit" class="ss-btn ss-btn--danger-quiet ss-btn--sm ss-btn--icon show_confirm" data-confirm="{{ __('Are You Sure?') }}" data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}">
                                        <i class="ti ti-trash" aria-hidden="true"></i>
                                        <span class="ss-visually-hidden">
                                            {{ __('Delete') }} — {{ $webhook->module }}
                                        </span>
                                    </button>
                                    {!! Form::close() !!}
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4">
                                <x-ss.empty :title="__('No webhooks yet')" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
