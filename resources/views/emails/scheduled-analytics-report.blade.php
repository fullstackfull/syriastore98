{{--
    ANA-003 — the scheduled analytics export's covering message.

    EVERY CLAIM HERE IS ONE THE FILE ALSO MAKES. The period and the run time are repeated from the
    attachment so the message alone is honest; the figures themselves are NOT restated, because a
    number in the body and a number in the file are two places for one figure to drift.

    Bilingual by construction: every string is `__()`, and the layout follows the recipient's locale.
--}}
<p>{{ __('Hello,') }}</p>

<p>
    {{ __('Here is the analytics export you scheduled for :store.', ['store' => $storeName]) }}
</p>

<ul>
    <li>{{ __('Schedule') }}: {{ $scheduleName }}</li>
    <li>{{ __('Period') }}: {{ $periodLabel }}</li>
    <li>{{ __('Taken at') }}: {{ $runAtLabel }}</li>
</ul>

<p>
    {{ __('Every row in the attached file states the period it covers and what it leaves out. Please read those notes before comparing a figure with anything else.') }}
</p>

<p>
    {{ __('You can change or stop this schedule on the Analytics screen.') }}
</p>
