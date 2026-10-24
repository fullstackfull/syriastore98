{{--
    Validation summary. It COMPLEMENTS the inline field errors; it never
    replaces them, and focus moves here only after a failed submit.

    Each item links to its own field, so a keyboard user can get from the
    summary to the thing that needs fixing in one keystroke. That requires the
    field's id to match the error bag key, which is the convention in
    resources/views -- where it is not, pass `$fieldIds` to map them.
--}}
@props(['errors', 'title' => null, 'fieldIds' => []])

@if ($errors->any())
    {{-- role="alert" announces it on arrival; tabindex="-1" lets the script
         move focus here without adding a tab stop. --}}
    <div class="ss-errorsummary" role="alert" tabindex="-1"
         data-ss-error-summary
         {{ $attributes }}>
        <h2 class="ss-errorsummary__title">
            {{ $title ?? trans_choice('There is a problem with :count field|There is a problem with :count fields', $errors->count(), ['count' => $errors->count()]) }}
        </h2>
        <ul class="ss-errorsummary__list">
            @foreach ($errors->keys() as $key)
                <li>
                    <a class="ss-errorsummary__link" href="#{{ $fieldIds[$key] ?? $key }}">
                        {{ $errors->first($key) }}
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
@endif
