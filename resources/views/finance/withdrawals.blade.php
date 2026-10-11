@extends('layouts.app')

@section('page-title')
    {{ __('Withdrawal Requests') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item">
        <a href="{{ route('finance.index') }}">{{ __('Finance') }}</a>
    </li>

    <li class="breadcrumb-item">
        {{ __('Withdrawal Requests') }}
    </li>
@endsection


@section('content')

<div class="card">

    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center">

            <h5 class="mb-0">
                {{ __('Withdrawal Requests') }}
            </h5>

            <a href="{{ route('finance.index') }}"
               class="btn btn-sm btn-light">

                <i class="ti ti-arrow-left"></i>
                {{ __('Back to Finance') }}

            </a>

        </div>
    </div>


    <div class="card-body">

        <div class="table-responsive">

            <table class="table table-hover align-middle">

                <thead>
                    <tr>
                        <th>#</th>
                        <th>{{ __('Store') }}</th>
                        <th>{{ __('Amount') }}</th>
                        <th>{{ __('Payment Method') }}</th>
                        <th>{{ __('Payment Details') }}</th>
                        <th>{{ __('Requested At') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th width="220">{{ __('Action') }}</th>
                    </tr>
                </thead>

                <tbody>

                    @forelse($withdrawals as $withdrawal)

                        <tr>

                            <td>
                                #{{ $withdrawal->id }}
                            </td>


                            <td>
                                Store #{{ $withdrawal->store_id }}
                            </td>


                            <td>
                                <strong>
                                    {{ number_format($withdrawal->amount, 2) }}
                                </strong>
                            </td>


                            <td>
                                {{ ucwords(str_replace('_', ' ', $withdrawal->payment_method)) }}
                            </td>


                            <td style="min-width: 220px;">

                                <div>
                                    {{ $withdrawal->payment_details }}
                                </div>

                                @if($withdrawal->store_note)

                                    <div class="small text-muted mt-1">
                                        <strong>{{ __('Store Note') }}:</strong>
                                        {{ $withdrawal->store_note }}
                                    </div>

                                @endif

                                @if($withdrawal->admin_note)

                                    <div class="small text-danger mt-1">
                                        <strong>{{ __('Admin Note') }}:</strong>
                                        {{ $withdrawal->admin_note }}
                                    </div>

                                @endif

                            </td>


                            <td>
                                @if($withdrawal->requested_at)
                                    {{ $withdrawal->requested_at->format('Y-m-d H:i') }}
                                @else
                                    -
                                @endif
                            </td>


                            <td>

                                @if($withdrawal->status === 'pending')

                                    <span class="badge bg-warning">
                                        {{ __('Pending') }}
                                    </span>

                                @elseif($withdrawal->status === 'approved')

                                    <span class="badge bg-info">
                                        {{ __('Approved') }}
                                    </span>

                                @elseif($withdrawal->status === 'paid')

                                    <span class="badge bg-success">
                                        {{ __('Paid') }}
                                    </span>

                                @elseif($withdrawal->status === 'rejected')

                                    <span class="badge bg-danger">
                                        {{ __('Rejected') }}
                                    </span>

                                @elseif($withdrawal->status === 'cancelled')

                                    <span class="badge bg-secondary">
                                        {{ __('Cancelled') }}
                                    </span>

                                @endif

                            </td>


                            <td>

                                <div class="d-flex flex-wrap gap-2">


                                    {{-- Pending --}}
                                    @if($withdrawal->status === 'pending')

                                        <form method="POST"
                                              action="{{ route('finance.withdrawals.approve', $withdrawal->id) }}">

                                            @csrf

                                            <button type="submit"
                                                    class="btn btn-sm btn-success"
                                                    onclick="return confirm('{{ __('Approve this withdrawal request?') }}')">

                                                <i class="ti ti-check"></i>
                                                {{ __('Approve') }}

                                            </button>

                                        </form>


                                        <button type="button"
                                                class="btn btn-sm btn-danger"
                                                data-bs-toggle="modal"
                                                data-bs-target="#rejectWithdrawalModal{{ $withdrawal->id }}">

                                            <i class="ti ti-x"></i>
                                            {{ __('Reject') }}

                                        </button>

                                    @endif



                                    {{-- Approved --}}
                                    @if($withdrawal->status === 'approved')

                                        <form method="POST"
                                              action="{{ route('finance.withdrawals.pay', $withdrawal->id) }}">

                                            @csrf

                                            <button type="submit"
                                                    class="btn btn-sm btn-primary"
                                                    onclick="return confirm('{{ __('Confirm that this withdrawal has been paid?') }}')">

                                                <i class="ti ti-cash"></i>
                                                {{ __('Mark as Paid') }}

                                            </button>

                                        </form>


                                        <button type="button"
                                                class="btn btn-sm btn-danger"
                                                data-bs-toggle="modal"
                                                data-bs-target="#rejectWithdrawalModal{{ $withdrawal->id }}">

                                            <i class="ti ti-x"></i>
                                            {{ __('Reject') }}

                                        </button>

                                    @endif



                                    {{-- Final statuses --}}
                                    @if(
                                        in_array(
                                            $withdrawal->status,
                                            ['paid', 'rejected', 'cancelled']
                                        )
                                    )

                                        <span class="text-muted">
                                            {{ __('No actions available') }}
                                        </span>

                                    @endif

                                </div>


                                {{-- Reject Modal --}}
                                @if(in_array($withdrawal->status, ['pending', 'approved']))

                                    <div class="modal fade"
                                         id="rejectWithdrawalModal{{ $withdrawal->id }}"
                                         tabindex="-1">

                                        <div class="modal-dialog">

                                            <div class="modal-content">

                                                <form method="POST"
                                                      action="{{ route('finance.withdrawals.reject', $withdrawal->id) }}">

                                                    @csrf

                                                    <div class="modal-header">

                                                        <h5 class="modal-title">
                                                            {{ __('Reject Withdrawal') }}
                                                            #{{ $withdrawal->id }}
                                                        </h5>

                                                        <button type="button"
                                                                class="btn-close"
                                                                data-bs-dismiss="modal">
                                                        </button>

                                                    </div>


                                                    <div class="modal-body">

                                                        <div class="alert alert-warning">

                                                            {{ __('The amount will be returned to the store available balance.') }}

                                                        </div>


                                                        <div class="mb-3">

                                                            <label class="form-label">
                                                                {{ __('Admin Note') }}
                                                            </label>

                                                            <textarea
                                                                name="admin_note"
                                                                class="form-control"
                                                                rows="3"
                                                                placeholder="{{ __('Reason for rejection') }}"
                                                            ></textarea>

                                                        </div>

                                                    </div>


                                                    <div class="modal-footer">

                                                        <button type="button"
                                                                class="btn btn-secondary"
                                                                data-bs-dismiss="modal">

                                                            {{ __('Cancel') }}

                                                        </button>


                                                        <button type="submit"
                                                                class="btn btn-danger">

                                                            {{ __('Reject Withdrawal') }}

                                                        </button>

                                                    </div>

                                                </form>

                                            </div>

                                        </div>

                                    </div>

                                @endif

                            </td>

                        </tr>

                    @empty

                        <tr>
                            <td colspan="8"
                                class="text-center py-5">

                                {{ __('No withdrawal requests found.') }}

                            </td>
                        </tr>

                    @endforelse

                </tbody>

            </table>

        </div>


        <div class="mt-3">
            {{ $withdrawals->links() }}
        </div>

    </div>

</div>

@endsection