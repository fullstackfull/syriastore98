{{--
    Pixel fields, on the design system.

    Same row-action defect as the webhook panel: each icon button's only text was a `title`,
    which Bootstrap's tooltip MOVES to `data-bs-original-title` and blanks on init — so the
    accessible name was empty by the time a user reached it. Visually-hidden text now, which no
    plugin rewrites, and it names the ROW as well as the action so "Edit" is not announced five
    times identically.

    The panel also shipped an inline `<style>` forcing `white-space: nowrap` on every cell of
    `.table.dataTable-table` — a GLOBAL selector from a tab body, so it applied to every other
    table on the screen too, for as long as this tab had ever been opened. The design system's
    table scrolls instead.
--}}
<div class="ss-card" id="Pixel_Setting">
    <div class="ss-card__header">
        <div class="ss-page-header__text">
            <h2 class="ss-card__title">{{ __('Pixel Fields Settings') }}</h2>
            <p class="ss-page-header__subtitle">{{ __('Enter Your Pixel Fields Settings') }}</p>
        </div>
        <button type="button" class="ss-btn ss-btn--primary ss-btn--sm" data-ajax-popup="true"
                data-url="{{ route('pixel-setting.create') }}"
                data-title="{{ __('Create New Pixel') }}">
            <i class="ti ti-plus" aria-hidden="true"></i>
            <span class="ss-visually-hidden">{{ __('Add New Pixel') }}</span>
        </button>
    </div>

    <div class="ss-card__body ss-card__body--flush">
        <div class="ss-table-wrap">
            <table class="ss-table" id="pc-dt-simple">
                <thead>
                    <tr>
                        <th scope="col">{{ __('Platform') }}</th>
                        <th scope="col">{{ __('Pixel Id') }}</th>
                        <th scope="col" class="text-end">{{ __('Action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($PixelFields as $PixelField)
                        <tr>
                            <td class="text-capitalize">{{ $PixelField->platform }}</td>
                            <td>{{ $PixelField->pixel_id }}</td>
                            <td>
                                <div class="d-flex gap-1 justify-content-end">
                                    <button type="button" class="ss-btn ss-btn--secondary ss-btn--sm ss-btn--icon"
                                            data-url="{{ route('pixel-setting.edit', $PixelField->id) }}" data-size="md"
                                            data-ajax-popup="true" data-title="{{ __('Edit') }}">
                                        <i class="ti ti-pencil" aria-hidden="true"></i>
                                        <span class="ss-visually-hidden">
                                            {{ __('Edit') }} — {{ $PixelField->platform }}
                                        </span>
                                    </button>

                                    {!! Form::open([
                                        'method' => 'DELETE',
                                        'route' => ['pixel-setting.destroy', $PixelField->id],
                                        'class' => 'd-inline',
                                    ]) !!}
                                    <button type="submit" class="ss-btn ss-btn--danger-quiet ss-btn--sm ss-btn--icon show_confirm">
                                        <i class="ti ti-trash" aria-hidden="true"></i>
                                        <span class="ss-visually-hidden">
                                            {{ __('Delete') }} — {{ $PixelField->platform }}
                                        </span>
                                    </button>
                                    {!! Form::close() !!}
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3">
                                <x-ss.empty :title="__('No pixels yet')" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
