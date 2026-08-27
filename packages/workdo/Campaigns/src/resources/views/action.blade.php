<div class="action-btn-wrp d-flex align-items-center gap-1">
    @if (Auth::user()->type != 'admin')
        @if ($campaigns->approval == 1 && $campaigns->status != '2')
            <div class="form-check form-switch custom-switch-v1">
                <input type="checkbox" name="campaigns_status"
                    class="form-check-input input-primary campaigns_status_active" value="1"
                    data-id="{{ $campaigns->id }}" data-name="campaigns" {{ $campaigns->status == '1' ? 'checked' : '' }}>
                <label class="form-check-label" for="campaigns_status"></label>
            </div>
        @endif
        @if (is_null($campaigns->approval) && !is_null($campaigns->total_cost))
            <a href="#" class="btn btn-sm btn-icon btn-warning btn-badge"
                data-url="{{ route('view.status.campaigns', $campaigns->id) }}" data-size="md" data-ajax-popup="true"
                data-title="{{ __('Change Status') }}" title="{{ __('Status') }}" data-bs-toggle="tooltip"
                data-bs-placement="top">
                <i class="ti ti-caret-right"></i></a>
        @endif
        <a class="btn btn-sm btn-icon btn-primary btn-badge" data-bs-toggle="tooltip" data-bs-placement="top"
            data-bs-original-title="{{ __('Preview') }}" href="{{ url('/' . $campaigns->stores->slug . '/home') }}"
            target="_blank"><i class="ti ti-eye"></i></a>
    @endif
    <a href="{{ route('campaigns.analytics', $campaigns->id) }}" class="btn btn-sm btn-icon btn-info btn-badge"
        data-bs-toggle="tooltip" data-bs-original-title="{{ __('Analytics') }}">
        <i class="ti ti-brand-google-analytics"></i></a>
</div>
