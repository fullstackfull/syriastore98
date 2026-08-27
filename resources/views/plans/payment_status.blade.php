<span class="d-flex gap-1 justify-content-center">
    @if ($order->payment_status == 'succeeded')
        <i class="mdi mdi-circle text-primary"></i>
        {{ ucfirst($order->payment_status) }}
    @else
        <i class="mdi mdi-circle text-danger"></i>
        {{ ucfirst($order->payment_status) }}
    @endif
</span>