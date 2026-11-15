{{--
    PHASE 03 M4-7 — THE VERSION PANEL: draft state, preview, publish, history, rollback.

    THIS IS WHERE THE M3 ENGINE BECOMES VISIBLE. `ThemePublishingService` carried publish, rollback,
    preview, history and optimistic concurrency from M3-D with no caller at all; M4-6 gave it HTTP
    endpoints and this gives those endpoints a merchant-facing surface.

    WHAT IT DELIBERATELY DOES NOT DO. It shows no control the merchant cannot actually use: if no
    draft exists, there is nothing to preview or publish and the panel says so rather than offering
    a disabled button whose meaning has to be guessed. The directive's own rule -- do not expose
    unsupported controls or imaginary functionality -- is easier to keep by rendering less.

    THE EXPECTED LIVE VERSION IS CARRIED IN A HIDDEN FIELD, and that is the whole optimistic-
    concurrency story: the service compares it to what is live at the moment of publishing, so two
    merchants with the editor open cannot silently overwrite each other. A form that omitted it
    would get the UNCHECKED behaviour, which is correct for a first publish and wrong here.
--}}
<div class="card mb-3 ss-version-panel" data-ss-version-panel>
    <div class="card-header p-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <h5 class="mb-0">{{ __('Version') }}</h5>

            @if ($liveVersion)
                <span class="badge bg-success" data-ss-live-badge>
                    {{ __('Live') }}: #{{ $liveVersion->version_no }}
                </span>
            @else
                <span class="badge bg-secondary">{{ __('Nothing published yet') }}</span>
            @endif

            @if ($latestDraft)
                <span class="badge bg-warning text-dark" data-ss-draft-badge>
                    {{ __('Unpublished draft') }}: #{{ $latestDraft->version_no }}
                </span>
            @endif
        </div>

        <div class="d-flex align-items-center gap-2 flex-wrap">
            {{-- SAVE AS DRAFT posts the SAME form as Save, to a different route. --}}
            <button type="submit" class="btn btn-sm btn-outline-primary"
                    form="setting-form"
                    formaction="{{ route('theme.version.draft', [$theme]) }}"
                    data-ss-save-draft>
                {{ __('Save as draft') }}
            </button>

            @if ($latestDraft && $storeSlug)
                <a class="btn btn-sm btn-outline-secondary"
                   href="{{ route('theme.draft.preview', [$storeSlug, $latestDraft->id]) }}"
                   target="_blank" rel="noopener"
                   data-ss-preview-draft>
                    {{ __('Preview draft') }}
                </a>
            @endif
        </div>
    </div>

    <div class="card-body p-3">
        @if ($latestDraft)
            <form method="POST" action="{{ route('theme.version.publish', [$theme]) }}"
                  class="d-flex flex-wrap align-items-center gap-2 mb-3" data-ss-publish-form>
                @csrf
                <input type="hidden" name="version_id" value="{{ $latestDraft->id }}">
                {{-- The optimistic check: what the merchant SAW as live when this page rendered. --}}
                <input type="hidden" name="expected_live_version_id"
                       value="{{ $liveVersion->id ?? '' }}">
                <button type="submit" class="btn btn-sm btn-primary" data-ss-publish>
                    {{ __('Publish draft') }} #{{ $latestDraft->version_no }}
                </button>
                <span class="text-muted small">
                    {{ __('Your shoppers see the live version until you publish.') }}
                </span>
            </form>
        @else
            <x-ss.empty
                :title="__('No draft yet')"
                :body="__('Change a setting and choose Save as draft. Your storefront will not change until you publish.')" />
        @endif

        @if (!empty($history))
            <h6 class="mt-2 mb-2">{{ __('Publication history') }}</h6>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" data-ss-history>
                    <caption class="visually-hidden">{{ __('Publication history for this theme') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Action') }}</th>
                            <th scope="col">{{ __('Version') }}</th>
                            <th scope="col">{{ __('When') }}</th>
                            <th scope="col"><span class="visually-hidden">{{ __('Restore') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($history as $event)
                            <tr>
                                <td>
                                    @switch($event->action)
                                        @case('publish')   {{ __('Published') }} @break
                                        @case('rollback')  {{ __('Rolled back') }} @break
                                        @case('baseline')  {{ __('Starting point') }} @break
                                        @default {{ $event->action }}
                                    @endswitch
                                </td>
                                <td>#{{ $event->version_id }}</td>
                                <td>{{ optional($event->created_at)->diffForHumans() }}</td>
                                <td class="text-end">
                                    @if (!$liveVersion || $event->version_id !== $liveVersion->id)
                                        <form method="POST"
                                              action="{{ route('theme.version.rollback', [$theme]) }}"
                                              class="d-inline" data-ss-rollback-form>
                                            @csrf
                                            <input type="hidden" name="version_id" value="{{ $event->version_id }}">
                                            <input type="hidden" name="expected_live_version_id"
                                                   value="{{ $liveVersion->id ?? '' }}">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                                    data-ss-rollback>
                                                {{ __('Restore this version') }}
                                            </button>
                                        </form>
                                    @else
                                        <span class="text-muted small">{{ __('Currently live') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
