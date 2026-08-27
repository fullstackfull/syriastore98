<a href="{{ route('shippinglabel.pdf', [
    'id' => \Illuminate\Support\Facades\Crypt::encrypt($order['id']),
    'template' => 'template1',
    'color' => '#fff',
]) }}"
    target="_blank" class="btn btn-sm btn-primary btn-icon d-flex align-items-center" data-bs-toggle="tooltip"
    data-bs-placement="top" title="Print" aria-label="Print">
    <i class="ti ti-printer" style="font-size:20px"></i>
</a>
