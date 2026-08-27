@if (isset($storeCustom->class_json) && !empty($storeCustom->class_json))
    @if(((request()->route('storeSlug') == $slug) && $storeCustom->is_publish == 'on') || (request()->route('storeSlug') != $slug))
        @php
            // Decode the JSON string into an associative array
            $cssVariables = json_decode($storeCustom->class_json, true);
        @endphp

        <style>
            :root {
                @foreach($cssVariables as $key => $value)
                    {{ $key }}: {{ $value }};
                @endforeach
            }
        </style>
    @endif
@endif