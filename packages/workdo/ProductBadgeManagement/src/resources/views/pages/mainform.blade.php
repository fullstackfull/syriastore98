<style>
    .badge-view {
        background-color: {{ $setting['bgcolor'] ?? '#000000' }};
        color: {{ $setting['textcolor'] ?? '#ffffff' }};
        padding-top: {{ $setting['padding_top'] ?? '1' }}px;
        padding-left: {{ $setting['padding_left'] ?? '1' }}px;
        padding-bottom: {{ $setting['padding_bottom'] ?? '1' }}px;
        padding-right: {{ $setting['padding_right'] ?? '1' }}px;
        top: {{ $setting['position_top'] ?? '1' }}px;
        left: {{ $setting['position_left'] ?? '1' }}px;
        border-top-left-radius: {{ $setting['radius_top_left'] ?? '1' }}px;
        border-bottom-left-radius: {{ $setting['radius_bottom_left'] ?? '1' }}px;
        border-top-right-radius: {{ $setting['radius_top_right'] ?? '1' }}px;
        border-bottom-right-radius: {{ $setting['radius_bottom_right'] ?? '1' }}px;
        opacity: {{ $setting['opacity'] ?? '1' }};
        z-index: 10;
    }

    .img-badge-view {
        padding-top: {{ $existingImageBadge['padding_top'] ?? '1' }}px;
        padding-left: {{ $existingImageBadge['padding_left'] ?? '1' }}px;
        padding-bottom: {{ $existingImageBadge['padding_bottom'] ?? '1' }}px;
        padding-right: {{ $existingImageBadge['padding_right'] ?? '1' }}px;
        top: {{ $existingImageBadge['position_top'] ?? '1' }}px;
        left: {{ $existingImageBadge['position_left'] ?? '1' }}px;
        width: {{ $existingImageBadge['width'] ?? '100' }}px;
        opacity: {{ $existingImageBadge['opacity'] ?? '1' }};
        z-index: 10;
    }

    #bg-preview {
        border-top-left-radius: {{ $existingImageBadge['radius_top_left'] ?? '1' }}px;
        border-bottom-left-radius: {{ $existingImageBadge['radius_bottom_left'] ?? '1' }}px;
        border-top-right-radius: {{ $existingImageBadge['radius_top_right'] ?? '1' }}px;
        border-bottom-right-radius: {{ $existingImageBadge['radius_bottom_right'] ?? '1' }}px;
    }
</style>


@if ($badgesetting != null)
    @if ($badgesetting->storage_setting == 'textbadge')
        <div class="relative">
            <div class="badge-view absolute ml-0 z-10">
                <span>{{ $setting['badgetxt'] ?? 'test' }}</span>
            </div>
        </div>
    @elseif ($badgesetting->storage_setting == 'imagebadge')
        <div class="relative">
            <div class="img-badge-view absolute ml-0 z-10">
                <img id="bg-preview" src="{{ get_file($existingImageBadge->filepath ?? '') }}"
                     alt="Uploaded Background Image" class="p-0">
            </div>
        </div>
    @endif
@endif
