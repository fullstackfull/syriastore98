<style>
    .comment-spam {
        position: relative;
        font-size: 14px;
    }

    .comment-spam .comment-spam-btn {
        position: relative;
        border-radius: 50%;
        box-shadow: 1px 1px 4px rgba(60, 60, 60, .4);
        transition: box-shadow .2s;
        cursor: pointer;
        overflow: hidden;
        height: 60px;
        width: 60px;
        background-color: #1a73e8;
        display: flex;
        justify-content: center;
        align-items: center;
    }

    .comment-spam .comment-spam-btn svg {

        width: 25px;
        height: auto;
        fill: #fff;
    }

    .comment-spam:hover .comment-spam-btn {
        box-shadow: 1px 2px 8px rgba(60, 60, 60, .4)
    }

    .comment-spam .comment-spam-popup {
        border-radius: 6px;
        position: absolute;
        overflow: hidden;
        padding: 0;
        box-shadow: 1px 2px 8px rgba(60, 60, 60, .25);
        width: 0;
        height: 0;
        bottom: 0;
        opacity: 0;
        transition: bottom .1s ease-out, opacity .2s ease-out;
        transform-origin: bottom;
        right: 0;
        background-color: #fff;
    }

    .comment-spam .comment-spam-popup.active {
        padding: 0 12px 12px;
        width: 300px;
        height: auto;
        bottom: 53px;
        opacity: 1;
        z-index: 1;
    }

    .comment-spam .comment-spam-popup .comment-spam-head {
        color: #fff;
        margin: 0 -15px 10px;
        padding: 6px 12px;
        display: flex;
        justify-content: space-between;
        cursor: pointer;
        background-color: #4787db;
    }

    .comment-spam .comment-spam-popup .product-select .lbl {
        color: #000000;
        margin-bottom: 4px;
        display: block;
    }

    .comment-spam .comment-spam-popup .product-select .nice-select {
        background-color: #fff;
        border: 1px solid #ccc;
        font-size: 12px;
        width: 100%;
        color: #000000;
        border-radius: 5px;
    }

    .comment-spam .comment-spam-popup .product-select .nice-select .list {
        background-color: #fff;
        height: 73px;
        overflow-y: scroll;
    }

    .comment-spam .comment-chatcontainer {
        position: relative;
        z-index: 10;
    }

    .comment-spam .comment-spam-popup .chatbox-container {
        overflow-y: auto;
        height: 150px;
        padding: 10px;
        position: relative;
        overflow-x: hidden;
        background-color: #fff;
        margin-bottom: 10px;
        border: 1px solid #ccc;
        border-radius: 5px;
        color: #000;
    }

    .comment-spam .comment-spam-popup .chatbox-container::-webkit-scrollbar {
        width: 3px;
    }

    .comment-spam .comment-spam-popup .chatbox-container::-webkit-scrollbar-track {
        box-shadow: inset 0 0 5px grey;
    }

    .comment-spam .comment-spam-popup .chatbox-container::-webkit-scrollbar-thumb {
        background: #4787db;
    }

    .comment-spam .comment-spam-popup .chatbox-container .chat-message {
        display: flex;
        position: relative;
        gap: 5px;
    }

    .comment-spam .comment-spam-popup .chatbox-container .message-come {
        justify-content: flex-start;
    }

    .comment-spam .comment-spam-popup .chatbox-container .message-send {
        justify-content: flex-end;
    }

    .comment-spam .comment-spam-popup .chatbox-container .chat-icon {
        border-radius: 50%;
        width: 35px;
        height: 35px;
        display: inline-block;
        overflow: hidden;
        background-color: #4787db;
    }

    .comment-spam .comment-spam-popup .chatbox-container .chat-icon img {
        width: 100%;
        object-fit: scale-down;
    }

    .comment-spam .comment-spam-popup .chatbox-container .custom-chat {
        text-align: left;
        margin-bottom: 5px;
        -webkit-font-smoothing: antialiased;
        min-height: 20px;
        max-width: calc(100% - 50px);
        ;
        overflow-wrap: break-word;
        white-space: pre-wrap;
        color: rgb(38, 50, 56);
        background: rgb(176, 238, 243);
        display: -webkit-box;
        padding: 8px 14px;
        border-radius: 5px;
        line-height: 19px;
        min-width: 30px;
        word-break: break-word;
    }

    .comment-spam .comment-spam-popup .chatbox-container .message-come .custom-chat {
        background: rgb(176, 238, 243);
        color: #000;
    }

    .comment-spam .comment-spam-popup .chatbox-container .message-send .custom-chat {
        background: rgb(64 68 70 / 87%);
        color: #fff;
        text-align: right;
    }

    .comment-spam .comment-spam-popup .chatbox-container .timeslot-send {
        font-size: 11px;
        padding: 0px 0px 8px 0px;
        color: rgb(85, 85, 85);
        text-align: right;
    }

    .comment-spam .comment-spam-popup .chatbox-container .timeslot-bot {
        font-size: 11px;
        padding: 0px 0px 8px 44px;
        color: rgb(85, 85, 85);
    }

    .comment-spam .comment-spam-popup .spam-send-inputs {
        z-index: 1;
        display: block;
        left: 0;
        right: 0;
        bottom: 0;
        height: 140px;
        color: #000;
    }

    .comment-spam .comment-spam-input-message {
        background-color: #fff;
        margin: 10px -15px -15px;
        padding: 0 15px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .comment-spam .comment-spam-input-message textarea {
        border: 1px solid #ccc;
        border-radius: 4px;
        box-shadow: none;
        padding: 8px;
        margin: 10px 0;
        width: 100%;
        max-width: 100%;
        font-family: inherit;
        font-size: inherit;
        resize: none
    }

    .comment-spam .comment-spam-btn-send {
        font-size: 0;
        cursor: pointer
    }

    .refresh-button {
        padding: 8px;
    }

    .refresh-button svg {
        margin: 0;
    }

    [lang="ar"] .comment-spam .comment-spam-popup,
    [lang="he"] .comment-spam .comment-spam-popup {
        left: 0;
        right: auto;
    }

    [lang="ar"] .comment-spam .comment-spam-popup .chatbox-container .timeslot-send,
    [lang="he"] .comment-spam .comment-spam-popup .chatbox-container .timeslot-send {
        text-align: left;
    }

    [lang="ar"] .comment-spam .comment-spam-popup .chatbox-container .timeslot-bot,
    [lang="he"] .comment-spam .comment-spam-popup .chatbox-container .timeslot-bot {
        padding: 0px 44px 8px 0px;
    }

    [lang="ar"] .comment-spam .comment-spam-popup .chatbox-container .custom-chat,
    [lang="he"] .comment-spam .comment-spam-popup .chatbox-container .custom-chat {
        text-align: right;
    }

    [lang="ar"] .comment-spam .comment-spam-popup .chatbox-container .message-send .custom-chat,
    [lang="he"] .comment-spam .comment-spam-popup .chatbox-container .message-send .custom-chat {
        text-align: left;
    }

    [lang="ar"] .comment-spam .comment-spam-btn-send svg,
    [lang="he"] .comment-spam .comment-spam-btn-send svg {
        transform: scaleX(-1)
    }
</style>
<div class="comment-spam">
    <div class="comment-spam-btn">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
            <path
                d="M123.6 391.3c12.9-9.4 29.6-11.8 44.6-6.4c26.5 9.6 56.2 15.1 87.8 15.1c124.7 0 208-80.5 208-160s-83.3-160-208-160S48 160.5 48 240c0 32 12.4 62.8 35.7 89.2c8.6 9.7 12.8 22.5 11.8 35.5c-1.4 18.1-5.7 34.7-11.3 49.4c17-7.9 31.1-16.7 39.4-22.7zM21.2 431.9c1.8-2.7 3.5-5.4 5.1-8.1c10-16.6 19.5-38.4 21.4-62.9C17.7 326.8 0 285.1 0 240C0 125.1 114.6 32 256 32s256 93.1 256 208s-114.6 208-256 208c-37.1 0-72.3-6.4-104.1-17.9c-11.9 8.7-31.3 20.6-54.3 30.6c-15.1 6.6-32.3 12.6-50.1 16.1c-.8 .2-1.6 .3-2.4 .5c-4.4 .8-8.7 1.5-13.2 1.9c-.2 0-.5 .1-.7 .1c-5.1 .5-10.2 .8-15.3 .8c-6.5 0-12.3-3.9-14.8-9.9c-2.5-6-1.1-12.8 3.4-17.4c4.1-4.2 7.8-8.7 11.3-13.5c1.7-2.3 3.3-4.6 4.8-6.9c.1-.2 .2-.3 .3-.5z" />
        </svg>
    </div>
    <div class="comment-spam-popup active">
        <div class="comment-spam-head"><span>{{ __('Add Your Comments') }}</span><strong class="close">×</strong></div>
        <div class="comment-chatcontainer">
            <div class="chatbox-container" id="chatbox-container">
                @foreach ($comments as $comment)
                    <input type="hidden" value="{{ $comment->store_id }}" class="store_id">
                    @if ($comment->type == 'admin')
                        <div class="chat-message message-come">
                            <span class="chat-icon">
                                <img alt="#" style="height:inherit;"
                                    src="{{ !empty(auth()->user()->profile_image) ? asset(auth()->user()->profile_image) : asset(Storage::url('uploads/profile/avatar.png')) }}"
                                    class="header-avtar">
                            </span>
                            <div class="custom-chat">{{ $comment->comment }}</div>
                        </div>
                        <div class="timeslot-bot">{{ $comment->created_at->format('D h:i A') }}</div>
                    @else
                        <div class="">
                            <div class="chat-message message-send">
                                <div class="custom-chat">{{ $comment->comment }}</div>
                            </div>
                            <div class="timeslot-send">{{ $comment->created_at->format('D h:i A') }}</div>
                        </div>
                    @endif
                @endforeach
                <div class="customer-comment">
                </div>
            </div>
            <div class="spam-send-inputs">
                <div class="product-select">
                    {!! Form::label('', __('Select product'), ['class' => 'form-label']) !!}
                    {!! Form::select('product_id', $products, null, [
                        'class' => 'form-control product_id',
                        'data-role' => 'tagsinput',
                        'id' => 'product_id',
                    ]) !!}
                </div>
                <div class="comment-spam-input-message">
                    {{ Form::textarea('product_comment', null, ['class' => 'form-control font-style product_comment', 'rows' => 2, 'placeholder' => __('Write a Comment.......'), 'required' => 'required']) }}
                    <input type="hidden" value="{{ auth('customers')->user()->id }}" class="customer_id">
                    <div class="comment-spam-btn-send" type="submit"><svg xmlns="http://www.w3.org/2000/svg"
                            xmlns:xlink="http://www.w3.org/1999/xlink" style="isolation:isolate" viewBox="0 0 20 18"
                            width="20" height="18">
                            <defs>
                                <clipPath id="_clipPath_fgX00hLzP9PnAfCkGQoSPsYB7aEGkj1G">
                                    <rect width="20" height="18"></rect>
                                </clipPath>
                            </defs>
                            <g clip-path="url(#_clipPath_fgX00hLzP9PnAfCkGQoSPsYB7aEGkj1G)">
                                <path d=" M 0 0 L 0 7.813 L 16 9 L 0 10.188 L 0 18 L 20 9 L 0 0 Z "
                                    fill="rgb(46,46,46)"></path>
                            </g>
                        </svg>
                    </div>
                    <div class="btn refresh-button"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
                            <path
                                d="M142.9 142.9c62.2-62.2 162.7-62.5 225.3-1L327 183c-6.9 6.9-8.9 17.2-5.2 26.2s12.5 14.8 22.2 14.8H463.5c0 0 0 0 0 0H472c13.3 0 24-10.7 24-24V72c0-9.7-5.8-18.5-14.8-22.2s-19.3-1.7-26.2 5.2L413.4 96.6c-87.6-86.5-228.7-86.2-315.8 1C73.2 122 55.6 150.7 44.8 181.4c-5.9 16.7 2.9 34.9 19.5 40.8s34.9-2.9 40.8-19.5c7.7-21.8 20.2-42.3 37.8-59.8zM16 312v7.6 .7V440c0 9.7 5.8 18.5 14.8 22.2s19.3 1.7 26.2-5.2l41.6-41.6c87.6 86.5 228.7 86.2 315.8-1c24.4-24.4 42.1-53.1 52.9-83.7c5.9-16.7-2.9-34.9-19.5-40.8s-34.9 2.9-40.8 19.5c-7.7 21.8-20.2 42.3-37.8 59.8c-62.2 62.2-162.7 62.5-225.3 1L185 329c6.9-6.9 8.9-17.2 5.2-26.2s-12.5-14.8-22.2-14.8H48.4h-.7H40c-13.3 0-24 10.7-24 24z" />
                        </svg>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@push('page-script')
    <script>
        function scrollToBottom() {
            var chatboxContainer = document.getElementById('chatbox-container');
            chatboxContainer.scrollTop = chatboxContainer.scrollHeight;
        }
        $(document).ready(function() {
            var spamBtn = document.querySelector('.comment-spam-btn');
            $(".comment-spam-popup").removeClass("active");
            spamBtn.addEventListener('click', function() {
                $(".comment-spam-popup").addClass("active");
            });
            $(".close").click(function() {
                $(".comment-spam-popup").removeClass("active");
            });
            window.onload = scrollToBottom;
        });
        $(".comment-spam-btn-send").click(function() {
            var product_id = $('.product_id').val();
            var comment = $('.product_comment').val();
            var customer_id = $('.customer_id').val();
            var data = {
                product_id: product_id,
                comment: comment,
                customer_id: customer_id
            }
            $.ajax({
                url: '{{ route('spamprevent.store', $store->slug) }}',
                method: 'POST',
                data: data,
                context: this,
                success: function(response) {
                    $('#loader').fadeOut();
                    var createdAt = new Date(response.message.created_at);
                    var options = {
                        weekday: 'short',
                        hour: 'numeric',
                        minute: 'numeric',
                        hour12: true
                    };
                    var formattedDate = createdAt.toLocaleString('en-US', options);
                    if (response.status == 'error') {
                        show_toastr('{{ __('Error') }}', 'Comment fiels is required.', 'error')
                    } else {
                        var html = `
                            <div class="chat-message message-send">
                                <div class="custom-chat">${response.message.comment}</div>
                            </div>
                            <div class="timeslot-send">${formattedDate}</div>
                            <input type="hidden" class="store_id" value="${response.message.store_id}">
                        `;
                        $('.customer-comment').append(html);
                        scrollToBottom();
                        $('.product_comment').val('');
                    }
                }
            });
        });

        $('.refresh-button').click(function() {
            var customerId = '{{ auth('customers')->user()->id }}';
            var storeId = '{{ $store->id }}';
            $.ajax({
                url: '{{ route('fetch.comments') }}',
                method: 'GET',
                data: {
                    customer_id: customerId,
                    store_id: storeId
                },
                success: function(response) {
                    $('#loader').fadeOut();
                    $('.chatbox-container').empty();
                    response.forEach(function(comment) {
                        var createdAt = new Date(comment.created_at);
                        var options = {
                            weekday: 'short',
                            hour: 'numeric',
                            minute: 'numeric',
                            hour12: true
                        };
                        var formattedDate = createdAt.toLocaleString('en-US', options);
                        var html = `
                                <input type="hidden" class="store_id" value="${comment.store_id}"></input>
                                ${comment.type == 'admin' ? `
                                    <div class="chat-message message-come">
                                        <span class="chat-icon">
                                            <img alt="#" style="height:inherit;"
                                            src="{{ !empty(auth()->user()->profile_image) ? asset(auth()->user()->profile_image) : asset(Storage::url('uploads/profile/avatar.png')) }}"
                                            class="header-avtar">
                                        </span>
                                        <div class="custom-chat">${comment.comment}</div>
                                    </div>
                                    <div class="timeslot-bot">${formattedDate}</div>
                                    ` : `
                                    <div class="chat-message message-send">
                                        <div class="custom-chat">${comment.comment}</div>
                                    </div>
                                        <div class="timeslot-send">${formattedDate}</div>
                                    `}
                        `;
                        $('.chatbox-container').append(html);
                    });
                    var customerCommentHtml = `<div class="customer-comment"></div>`;
                    $('.chatbox-container').append(customerCommentHtml);
                },
                error: function(xhr, status, error) {
                    $('#loader').fadeOut();
                }
            });
        });
    </script>
@endpush
