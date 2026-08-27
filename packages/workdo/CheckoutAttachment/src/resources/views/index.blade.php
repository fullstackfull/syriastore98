<div class="col-xxl-5">
    <div class="card  p-4">
        <div class="card-header p-0 d-flex justify-content-between pb-0 border-0">
            <h5 class="mb-4">{{ __('Additional Order Attachment ') }}</h5>
        </div>
        <div class="card-body p-3 rounded" style="background: #f5f5f5">
            <div class="">
                <div class="">
                    @php
                        $image = $refund_requests['attachments'];
                        $cdecef = json_decode($image);
                    @endphp
                    <div class="d-flex align-items-center gap-4">
                        <p>{{ __('Refund Reason') }} :</p>
                        @if ($refund_requests->refund_reason != 'Other')
                            <p>{{ $refund_requests['refund_reason'] }}</p>
                        @else
                            <p>{{ $refund_requests['custom_refund_reason'] }}</p>
                        @endif
                    </div>
                    @if (!empty($refund_requests->attachments))
                        <div class="d-flex align-items-center gap-4">
                            <p>{{ __('Refund Image') }} :</p>
                            <p>
                                @foreach ($cdecef as $imagePath)
                                    <a href="{{ get_file($imagePath) }}" target="_blank">
                                        <img src="{{ get_file($imagePath) }}" alt="Image"
                                            class="refund-image">
                                    </a>
                                @endforeach
                            </p>
                        </div>
                    @endif
                    <input type="hidden" class="refund_id" value="{{ $refund_requests['id'] }}">
                </div>
            </div>
        </div>
    </div>
</div>
