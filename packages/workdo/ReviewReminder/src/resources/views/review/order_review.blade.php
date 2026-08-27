<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>{{ __('Order Review Form') }}</title>
    <link rel="stylesheet" href="{{ asset('packages/workdo/ReviewReminder/src/resources/assets/main-style.css') }}">
    <link rel="stylesheet" href="{{ asset('packages/workdo/ReviewReminder/src/resources/assets/responsive.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/fonts/fontawesome.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/plugins/notifier.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/plugins/bootstrap-switch-button.min.css') }}">
    <meta name="base-url" content="{{ URL::to('/') }}">

    <style>
        /* Rating Star Widgets Style */
        .rating-stars ul {
            list-style-type: none;
            padding: 0;

            -moz-user-select: none;
            -webkit-user-select: none;
        }

        .rating-stars ul>li.star {
            display: inline-block;
        }

        /* Idle State of the stars */
        .rating-stars ul>li.star>i.fa {
            font-size: 2.5em;
            /* Change the size of the stars */
            color: #ccc;
            /* Color on idle state */
        }

        /* Hover state of the stars */
        .rating-stars ul>li.star.hover>i.fa {
            color: #FFCC36;
        }

        /* Selected state of the stars */
        .rating-stars ul>li.star.selected>i.fa {
            color: #FF912C;
        }

        .rating-stars {
            width: auto;
            display: inline-block;
            padding: 4px;
        }

        .rating-stars .stars {
            display: inline-block;
        }
    </style>

</head>

<body>
    <script src="{{ asset('js/jquery-3.6.0.min.js') }}"></script>
    <script src="{{ asset('js/custom.js') }}"></script>
    <script src="{{ asset('assets/js/plugins/notifier.js') }}"></script>
    @if ($message = Session::get('success'))
        <script type="text/javascript">
            show_toastr('success', '{!! $message !!}', 'success')
        </script>
    @endif

    @if ($message = Session::get('error'))
        <script type="text/javascript">
            show_toastr('error', '{!! $message !!}', 'error')
        </script>
    @endif

    <div class="review-page-sec">
        <div class="section-title text-center">
            <h2>{{ ucfirst($storeName) }}</h2>
        </div>
        <div class="review-page-sec-inner">
            <div class="review-form-wrp">
                <div class="row">
                    <div class="col-md-6 col-12">
                        {{ Form::open(['route' => ['orderreview.store', $slug, \Crypt::encrypt($orderId)], 'method' => 'post', 'enctype' => 'multipart/form-data']) }}

                        <input type="hidden" name="store_id" value="{{ $storeId }}">
                        <div class='rating-stars form-group'>
                            {{ Form::label('rating', __('Rating'), ['class' => 'form-label', 'required' => 'required']) }}
                            <ul id='stars'>
                                <li class='star' title='Poor' data-value='1'>
                                    <i class='fa fa-star fa-fw'></i>
                                </li>
                                <li class='star' title='Fair' data-value='2'>
                                    <i class='fa fa-star fa-fw'></i>
                                </li>
                                <li class='star' title='Good' data-value='3'>
                                    <i class='fa fa-star fa-fw'></i>
                                </li>
                                <li class='star' title='Excellent' data-value='4'>
                                    <i class='fa fa-star fa-fw'></i>
                                </li>
                                <li class='star' title='WOW!!!' data-value='5'>
                                    <i class='fa fa-star fa-fw'></i>
                                </li>
                            </ul>
                            {!! Form::hidden('rating', null, ['id' => 'rating']) !!}
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    {{ Form::label('name', __('Name'), ['class' => 'form-label']) }}
                                    {{ Form::text('name', null, ['class' => 'form-control', 'placeholder' => 'Enter Name', 'required' => 'required']) }}
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    {{ Form::label('email_id', __('Emai Id'), ['class' => 'form-label']) }}
                                    {{ Form::email('email_id', null, ['class' => 'form-control', 'placeholder' => 'Enter Emai Id', 'required' => 'required']) }}
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            {{ Form::label('description', __('Review'), ['class' => 'form-label']) }}
                            {{ Form::textarea('description', null, ['class' => 'form-control', 'placeholder' => 'Enter Review', 'rows' => '10', 'required' => 'required']) }}
                        </div>
                        <div class="form-footer">
                            <input type="submit" value="{{ __('Save') }}" class="btn btn-sm btn-primary">
                        </div>
                        {{ Form::close() }}
                    </div>
                    <div class="col-md-6 col-12">
                        <div class="form-right-image">
                            <div class="form-wrp-image">
                                <img src="{{ asset('packages/workdo/ReviewReminder/src/resources/assets/images/form-wrp-img.png') }}"
                                    alt="form-image">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>

<script src="{{ asset('packages/workdo/ReviewReminder/src/resources/assets/jquery.min.js') }}"></script>
<script>
    $(document).ready(function() {
        $('#stars li').on('click', function() {
            var selectedValue = $(this).data('value');
            $('#rating').val(selectedValue);
        });
    });
</script>
<script>
    $(document).ready(function() {

        /* 1. Visualizing things on Hover - See next part for action on click */
        $(document).on('mouseover', '#stars li', function() {
            var onStar = parseInt($(this).data('value'), 10); // The star currently mouse on

            // Now highlight all the stars that's not after the current hovered star
            $(this).parent().children('li.star').each(function(e) {
                if (e < onStar) {
                    $(this).addClass('hover');
                } else {
                    $(this).removeClass('hover');
                }
            });

        }).on('mouseout', function() {
            $(this).parent().children('li.star').each(function(e) {
                $(this).removeClass('hover');
            });
        });


        /* 2. Action to perform on click */
        $(document).on('click', '#stars li', function() {
            var onStar = parseInt($(this).data('value'), 10); // The star currently selected
            var stars = $(this).parent().children('li.star');
            for (i = 0; i < stars.length; i++) {
                $(stars[i]).removeClass('selected');
            }

            for (i = 0; i < onStar; i++) {
                $(stars[i]).addClass('selected');
            }

            $('#rating_no').val(onStar);
            // JUST RESPONSE (Not needed)
            var ratingValue = parseInt($('#stars li.selected').last().data('value'), 10);
            var msg = "";
            if (ratingValue > 1) {
                msg = '{{ __("Thanks! You rated this :stars stars.", ["stars" => "' + ratingValue + '"]) }}';
            } else {
                msg = '{{ __("We will improve ourselves. You rated this :stars stars.", ["stars" => "' + ratingValue + '"]) }}';
            }
            responseMessage(msg);

        });
    });

    function responseMessage(msg) {
        $('.success-box').fadeIn(200);
        $('.success-box div.text-message').html("<span>" + msg + "</span>");
    }
</script>


</html>
