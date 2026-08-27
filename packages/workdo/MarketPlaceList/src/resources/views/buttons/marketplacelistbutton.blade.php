@php
    $lang = app()->getLocale();
@endphp
@if ($lang == 'ar' || $lang == 'he')
    <style>
        .market-place-list-button {
            position: fixed;
            top: 160px;
            left: 0px;
            padding: 17.5px 17.5px;
            z-index: 7;
            box-shadow: 0 9px 9px -1px rgba(81, 69, 157, 0.3);
            font-size: 29px;
            border-radius: 0 8px 8px 0;
        }
    </style>
@else
    <style>
        .market-place-list-button {
            position: fixed;
            top: 160px;
            right: 0px;
            padding: 17.5px 17.5px;
            z-index: 7;
            box-shadow: 0 9px 9px -1px rgba(81, 69, 157, 0.3);
            font-size: 29px;
            border-radius: 8px 0 0 8px;
        }
    </style>
@endif
@if (isset($setting['market_place_list_enable']) && $setting['market_place_list_enable'] == 'on')
   <x-header-button :slug="$slug" />
@endif