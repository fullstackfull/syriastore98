<span class="d-flex gap-1 justify-content-end">
    @if (($customer && $customer->id) || $activityLogEntry)
    <a href="{{ route('customer.timeline', $customer->id) }}"
        class="btn btn-sm btn-icon btn-warning"
        data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Show') }}">
        <i class="ti ti-eye"></i>
    </a>
    @endif
    @permission('Show Customer')
    <a href="{{ route('customer.show', $customer->id) }}"
        class="btn btn-sm btn-icon btn-info" data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Cart') }}">
        <i class="ti ti-shopping-cart"></i>
    </a>
    @endpermission
    @if (module_is_active('RewardClubPoint'))
    @include('reward-club-point::admin.clubPointHistoryBtn', ['customerId' => $customer->id])
    @endif
</span>