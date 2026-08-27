<style>
    .comment-spam .comment-chatcontainer {
        position: relative;
        z-index: 10;
    }

    .comment-spam .chatbox-container {
        overflow-y: auto;
        height: 338px;
        padding: 10px;
        position: relative;
        overflow-x: hidden;
        background-color: #fff;
        margin-bottom: 10px;
        border: 1px solid #ccc;
        border-radius: 5px;
    }

    .comment-spam .chatbox-container::-webkit-scrollbar {
        width: 3px;
    }

    .comment-spam .chatbox-container::-webkit-scrollbar-track {
        box-shadow: inset 0 0 5px grey;
    }

    .comment-spam .chatbox-container::-webkit-scrollbar-thumb {
        background: #4787db;
    }

    .comment-spam .chatbox-container .chat-message {
        display: flex;
        position: relative;
        gap: 5px;

    }

    .comment-spam .chatbox-container .message-come {
        justify-content: flex-start;
    }

    .comment-spam .chatbox-container .message-send {
        justify-content: flex-end;
    }

    .comment-spam .chatbox-container .chat-icon {
        border-radius: 50%;
        width: 35px;
        height: 35px;
        display: inline-block;
        overflow: hidden;
        background-color: #4787db;
    }

    .comment-spam .chatbox-container .chat-icon img {
        width: 100%;
        object-fit: scale-down;
    }

    .comment-spam .chatbox-container .custom-chat {
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

    .comment-spam .chatbox-container .message-come .custom-chat {
        background: rgb(176, 238, 243);
        color: #000;
    }

    .comment-spam .chatbox-container .message-send .custom-chat {
        background: rgb(64 68 70 / 87%);
        color: #fff;
        text-align: right;
    }

    .comment-spam .chatbox-container .timeslot-send {
        font-size: 11px;
        padding: 0px 0px 8px 0px;
        color: rgb(85, 85, 85);
        text-align: right;
    }

    .comment-spam .chatbox-container .timeslot-bot {
        font-size: 11px;
        padding: 0px 0px 8px 44px;
        color: rgb(85, 85, 85);
    }

    .comment-spam .spam-send-inputs {
        z-index: 1;
        display: block;
        left: 0;
        right: 0;
        bottom: 0;
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

    .comment-spam .comment-btn-send {
        font-size: 0;
        cursor: pointer
    }

    [lang="ar"] .comment-spam .chatbox-container .timeslot-send {
        text-align: left;
    }

    [lang="ar"] .comment-spam .chatbox-container .timeslot-bot {
        padding: 0px 44px 8px 0px;
    }

    [lang="ar"] .comment-spam .chatbox-container .custom-chat {
        text-align: right;
    }

    [lang="ar"] .comment-spam .chatbox-container .message-send .custom-chat {
        text-align: left;
    }

    [lang="ar"] .comment-spam .comment-btn-send svg {
        transform: scaleX(-1)
    }
</style>
<div class="comment-spam">
    <div class="comment-chatcontainer">
        <div class="chatbox-container" id="chatbox-container-slider">
            @foreach ($comments as $comment)
                @if ($comment->type == 'admin')
                    <div class="chat-message message-come">
                        <span class="chat-icon">
                            <img alt="#" style="height:inherit;"
                                src="{{ !empty(auth()->user()->profile_image) ? asset(auth()->user()->profile_image) : asset(Storage::url('uploads/profile/avatar.png')) }}"
                                class="header-avtar">
                        </span>
                        <div class="custom-chat">{{ $comment->comment }}</div>
                    </div>
                    <div class="timeslot-bot">{{ \Carbon\Carbon::parse($comment->created_at)->format('D h:i A') }}</div>
                @else
                    <div class="">
                        <div class="chat-message message-send">
                            <div class="custom-chat">{{ $comment->comment }}</div>
                        </div>
                        <div class="timeslot-send">{{ \Carbon\Carbon::parse($comment->created_at)->format('D h:i A') }}
                        </div>
                    </div>
                @endif
            @endforeach
            <div class="customer-comment">
            </div>
        </div>
        <div class="spam-send-inputs">
            <div class="comment-spam-input-message">
                {{ Form::textarea('product_comment', null, ['class' => 'form-control font-style product_comment', 'rows' => 2, 'placeholder' => __('Write a Comment.......'), 'required' => 'required']) }}
                <input type="hidden" value="{{ $customer_id }}" class="customer_id">
                <div class="comment-btn-send" type="submit"><svg xmlns="http://www.w3.org/2000/svg"
                        xmlns:xlink="http://www.w3.org/1999/xlink" style="isolation:isolate" viewBox="0 0 20 18"
                        width="20" height="18">
                        <defs>
                            <clipPath id="_clipPath_fgX00hLzP9PnAfCkGQoSPsYB7aEGkj1G">
                                <rect width="20" height="18"></rect>
                            </clipPath>
                        </defs>
                        <g clip-path="url(#_clipPath_fgX00hLzP9PnAfCkGQoSPsYB7aEGkj1G)">
                            <path d=" M 0 0 L 0 7.813 L 16 9 L 0 10.188 L 0 18 L 20 9 L 0 0 Z " fill="rgb(46,46,46)">
                            </path>
                        </g>
                    </svg>
                </div>
                <div class="btn btn-primary btn-sm refresh-button">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
                        <path
                            d="M142.9 142.9c62.2-62.2 162.7-62.5 225.3-1L327 183c-6.9 6.9-8.9 17.2-5.2 26.2s12.5 14.8 22.2 14.8H463.5c0 0 0 0 0 0H472c13.3 0 24-10.7 24-24V72c0-9.7-5.8-18.5-14.8-22.2s-19.3-1.7-26.2 5.2L413.4 96.6c-87.6-86.5-228.7-86.2-315.8 1C73.2 122 55.6 150.7 44.8 181.4c-5.9 16.7 2.9 34.9 19.5 40.8s34.9-2.9 40.8-19.5c7.7-21.8 20.2-42.3 37.8-59.8zM16 312v7.6 .7V440c0 9.7 5.8 18.5 14.8 22.2s19.3 1.7 26.2-5.2l41.6-41.6c87.6 86.5 228.7 86.2 315.8-1c24.4-24.4 42.1-53.1 52.9-83.7c5.9-16.7-2.9-34.9-19.5-40.8s-34.9 2.9-40.8 19.5c-7.7 21.8-20.2 42.3-37.8 59.8c-62.2 62.2-162.7 62.5-225.3 1L185 329c6.9-6.9 8.9-17.2 5.2-26.2s-12.5-14.8-22.2-14.8H48.4h-.7H40c-13.3 0-24 10.7-24 24z" />
                    </svg>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function scrollToBottom() {
        var chatboxContainer = document.getElementById('chatbox-container-slider');
        chatboxContainer.scrollTop = chatboxContainer.scrollHeight;
    }
    $(document).ready(function() {
        scrollToBottom();
        $('#commanModel').on('shown.bs.modal', function() {
            scrollToBottom();
        });
    });
    $(".comment-btn-send").click(function() {
        var product_id = $('.product_id').val();
        var comment = $('.product_comment').val();
        var customer_id = $('.customer_id').val();
        var data = {
            product_id: product_id,
            comment: comment,
            customer_id: customer_id
        }
        $.ajax({
            url: '{{ route('spamprevent.admin.store') }}',
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
                if (response.status == false) {
                    show_toastr('{{ __('Error') }}', response.message , 'error')
                } else {
                    var html = `
                        <div class="chat-message message-come">
                            <span class="chat-icon">
                                <img alt="#" style="height:inherit;"
                                src="{{ !empty(auth()->user()->profile_image) ? asset(auth()->user()->profile_image) : asset(Storage::url('uploads/profile/avatar.png')) }}"
                                class="header-avtar">
                            </span>
                            <div class="custom-chat">${response.message.comment}</div>
                        </div>
                        <div class="timeslot-bot">${formattedDate}</div>
                    `;
                    $('.customer-comment').append(html);
                    scrollToBottom();
                    $('.product_comment').val('');
                }
            }
        });
    });

    $('.refresh-button').click(function() {
        var customerId = '{{ $customer_id }}';
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
                console.error(error);
            }
        });
    });
</script>
