@for ($i = 0; $i < 5; $i++)
<i class="ti ti-star {{ $i < $review->rating_no ? 'text-warning' : '' }} "></i>
@endfor