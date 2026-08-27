<style>
    .download-button {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background-color: rgba(255, 255, 255, 0.8);
        border-radius: 70%;
        padding: 5px;
        display: none;
        height: 30px;
        width: 30px;
    }

    .image-container:hover .download-button {
        display: block;
    }

    .image-list {
        list-style-type: none;
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
    }
</style>

<div class="card  p-0">
    <div class="card-header d-flex justify-content-between pb-0">
        <h4 class="mb-4">{{ __('Order Attachments') }}</h4>
    </div>
    <div class="card-body p-3 rounded">
        <div class="">
            @if ($checkoutAttachments->isEmpty() || $checkoutAttachments->contains('attachment', '=', null))
                <p>{{ __('No Attachments found.') }}</p>
            @else
                @foreach ($checkoutAttachments as $attachments)
                    <ul class="image-list">
                        @php
                            $file = explode(',', $attachments->attachment);
                        @endphp
                        @foreach ($file as $f)
                            @php
                                $cleanFilePath = str_replace(['&quot;', '\\', '"', '[', ';', ']'], '', $f);
                            @endphp
                            <li class="image-container" style="position: relative;">
                                <img src="{{ get_file($cleanFilePath) }}" alt="Attachment" height="100px" width="100px">
                                <a href="{{ get_file($cleanFilePath) }}" download class="download-button">
                                    <i class="ti ti-download"></i>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endforeach
            @endif
        </div>
    </div>
</div>
