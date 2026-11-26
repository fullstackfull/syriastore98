{{--
    One incident table. Shared by the live list and the closed one so the two cannot drift apart in
    their columns, which is how two lists of the same thing come to disagree about what it has.

    `<th scope="row">` ON THE REFERENCE, because the reference is what identifies the row and a
    screen reader moving across a cell needs it announced. A plain `<td>` leaves every figure in the
    row unattributed.
--}}
<div class="table-responsive">
    <table class="table">
        <caption class="ss-visually-hidden">
            {{ __('Platform incidents. Each row links to its own full history.') }}
        </caption>
        <thead>
            <tr>
                <th scope="col">{{ __('Reference') }}</th>
                <th scope="col">{{ __('What happened') }}</th>
                <th scope="col">{{ __('Severity') }}</th>
                <th scope="col">{{ __('State') }}</th>
                <th scope="col">{{ __('Began') }}</th>
                <th scope="col">{{ __('Recorded by') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($incidents as $incident)
                <tr>
                    <th scope="row">
                        <a class="ss-link" href="{{ route('platform.incidents.show', $incident->id) }}">
                            <code class="ss-code">{{ $incident->reference }}</code>
                        </a>
                    </th>
                    <td>{{ $incident->title }}</td>
                    <td>
                        <x-ss.status-badge :tone="$toneOf[$incident->severity] ?? 'neutral'"
                                           :label="$incident->severityLabel()" />
                    </td>
                    <td>{{ $incident->statusLabel() }}</td>
                    <td>
                        {{ $incident->detected_at ? $incident->detected_at->toDateTimeString() : __('Not recorded') }}
                    </td>
                    <td>{{ $incident->openedByName() ?? __('Not recorded') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
