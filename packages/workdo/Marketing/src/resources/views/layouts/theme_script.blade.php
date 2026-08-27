@php
    use Workdo\Marketing\app\Models\Appoinment;
@endphp

<script
    src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/js/jquery.qrcode.min.js') }}"></script>

<script src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/js/picker.js') }}"></script>
<script src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/js/picker.date.js') }}"></script>
<script
    src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/js/emojionearea.min.js') }}"></script>
<script
    src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/libs/bootstrap-notify/bootstrap-notify.min.js') }}">
    </script>
<script src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/js/socialSharing.js') }}"></script>
<script
    src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/js/socialSharing.min.js') }}"></script>
@if ($enablePWABusiness == 'on')
    <script type="text/javascript">
        const container = document.querySelector("body")

        const coffees = [];

        if ("serviceWorker" in navigator) {
            window.addEventListener("load", function () {
                navigator.serviceWorker
                    .register("{{ asset('packages/workdo/Marketing/src/resources/assets/js/serviceWorker.js') }}")
                    .then(res => console.log("service worker registered"))
                    .catch(err => console.log("service worker not registered", err))

            })
        }
    </script>
@endif
<script>
    $(".imagepopup").on("click", function (e) {
        var imgsrc = $(this).children(".imageresource").attr("src");
        $('.imagepreview').attr('src',
            imgsrc); // here asign the image to the modal when the user click the enlarge link
        $("#gallerymodel").addClass("active");
        $("body").toggleClass("no-scroll");
        $('html').addClass('modal-open');
        $('#gallerymodel').css("background", 'rgb(0 0 0 / 50%)')
    });

    $(".imagepopup1").on("click", function () {
        var imgsrc1 = $(this).children(".imageresource1").attr("src");
        $('.imagepreview').attr('src',
            imgsrc1); // here asign the image to the modal when the user click the enlarge link
        $("#gallerymodel").addClass("active");
        $("body").toggleClass("no-scroll");
        $('html').addClass('modal-open');
        $('#gallerymodel').css("background", 'rgb(0 0 0 / 50%)')
    });

    $(".videopop").on("click", function () {
        var videosrc = $(this).children('video').children(".videoresource").attr("src");
        $('.videopreview').attr('src',
            videosrc); // here asign the image to the modal when the user click the enlarge link
        $("#videomodel").addClass("active");
        $("body").toggleClass("no-scroll");
        $('html').addClass('modal-open');
        $('#videomodel').css("background",
            'rgb(0 0 0 / 50%)'
        ) // imagemodal is the id attribute assigned to the bootstrap modal, then i use the show function
    });

    $(".videopop1").on("click", function () {
        var videosrc1 = $(this).children('video').children(".videoresource1").attr("src");
        $('.videopreview').attr('src',
            videosrc1); // here asign the image to the modal when the user click the enlarge link
        $("#videomodel").addClass("active");
        $("body").toggleClass("no-scroll");
        $('html').addClass('modal-open');
        $('#videomodel').css("background",
            'rgb(0 0 0 / 50%)'
        ) // imagemodal is the id attribute assigned to the bootstrap modal, then i use the show function
    });

    $(".close-model").on("click", function () {
        $("#gallerymodel").removeClass("active");
        $("body").removeClass("no-scroll");
        $('html').removeClass('modal-open');
        $('#gallerymodel').css("background", '')
    });

    $(".close-model1").on("click", function () {
        $("#videomodel").removeClass("active");
        $("body").removeClass("no-scroll");
        $('html').removeClass('modal-open');
        $('#videomodel').css("background", '')
    });


    $(document).ready(function () {

        var date = new Date();
        $('.datepicker_min').pickadate({
            min: date,
        })

    });
    //Password Check
    @if (!Auth::check())

        let ispassword;
        var ispassenable = '{{ Appoinment::Marketing("enable_password") }}';
        var business_password = '{{ Appoinment::Marketing("password") }}';
        if (ispassenable == 'on') {
            $('.password-submit').click(function () {

                ispassword = 'true';
                passwordpopup('true');
            });

            function passwordpopup(type) {
                if (type == 'false') {

                    $("#passwordmodel").addClass("active");
                    $("body").toggleClass("no-scroll");
                    $('html').addClass('modal-open');
                    $('#passwordmodel').css("background", 'rgb(0 0 0 / 50%)')
                } else {

                    var password_val = $('.password_val').val();

                    if (password_val == business_password) {
                        $("#passwordmodel").removeClass("active");
                        $("body").removeClass("no-scroll");
                        $('html').removeClass('modal-open');
                        $('#passwordmodel').css("background", '')
                    } else {

                        $(`.span-error-password`).text("{{ __('*Please enter correct password') }}");
                        passwordpopup('false');

                    }
                }
            }

            if (ispassword == undefined) {
                passwordpopup('false');
            }
        }
    @endif


    function downloadURI(uri, name) {
        var link = document.createElement("a");
        link.download = name;
        link.href = uri;
        R
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        delete link;
    };


    $(document).ready(function () {


        $(".emojiarea").emojioneArea();
        $(`.span-error-date`).text("");
        $(`.span-error-time`).text("");
        $(`.span-error-name`).text("");
        $(`.span-error-email`).text("");
        $(`.span-error-contactname`).text("");
        $(`.span-error-contactemail`).text("");
        $(`.span-error-contactphone`).text("");
        $(`.span-error-contactmessage`).text("");


        var slug = '{{ $store->slug }}';
        var url_link = `{{ route('get.vcard', ['slug' => '__SLUG__']) }}`.replace('__SLUG__', slug);


        $(`.qr-link`).text(url_link);
        var foreground_color =
            `{{ isset($qr_detail->foreground_color) ? $qr_detail->foreground_color : '#000000' }}`;
        var background_color =
            `{{ isset($qr_detail->background_color) ? $qr_detail->background_color : '#ffffff' }}`;
        var radius = `{{ isset($qr_detail->radius) ? $qr_detail->radius : 26 }}`;
        var qr_type = `{{ isset($qr_detail->qr_type) ? $qr_detail->qr_type : 0 }}`;
        var qr_font = `{{ isset($qr_detail->qr_text) ? $qr_detail->qr_text : 'Marketing' }}`;
        var qr_font_color = `{{ isset($qr_detail->qr_text_color) ? $qr_detail->qr_text_color : '#f50a0a' }}`;
        var size = `{{ isset($qr_detail->size) ? $qr_detail->size : 9 }}`;

        $('.shareqrcode').empty().qrcode({
            render: 'image',
            size: 500,
            ecLevel: 'H',
            minVersion: 3,
            quiet: 1,
            text: url_link,
            fill: foreground_color,
            background: background_color,
            radius: .01 * parseInt(radius, 10),
            mode: parseInt(qr_type, 10),
            label: qr_font,
            fontcolor: qr_font_color,
            image: $("#image-buffers")[0],
            mSize: .01 * parseInt(size, 10)
        });
    });


    $(`.rating_preview`).attr('id');
    var from_$input = $('#input_from').pickadate(),
        from_picker = from_$input.pickadate('picker')


    var to_$input = $('#input_to').pickadate(),
        to_picker = to_$input.pickadate('picker')

    var is_enabled = "{{ Appoinment::EnableOrNot($businesshours, $business_hours) }}";
    if (is_enabled) {
        $('#business-hours-div').show();
    } else {
        $('#business-hours-div').hide();
    }

    var is_contact_enable = "{{ Appoinment::EnableOrNot($contactinfo, $contactinfo_content) }}";
    if (is_contact_enable) {
        $('#contact-div').show();
    } else {
        $('#contact-div').hide();
    }

    var is_enable_appoinment = "{{ Appoinment::EnableOrNot($appoinment, $appoinment_hours) }}";
    if (is_enable_appoinment) {
        $('#appointment-div').show();
    } else {
        $('#appointment-div').hide();
    }

    var is_enable_service = "{{ Appoinment::EnableOrNot($services, $services_content) }}";
    if (is_enable_service) {
        $('#services-div').show();
    } else {
        $('#services-div').hide();
    }
    var is_enable_product = "{{ Appoinment::EnableOrNot($products, $products_content) }}";
    if (is_enable_product) {
        $('#product-div').show();
    } else {
        $('#product-div').hide();
    }

    var is_enable_testimonials = "{{ Appoinment::EnableOrNot($testimonials, $testimonials_content) }}";
    if (is_enable_testimonials) {
        $('#testimonials-div').show();
    } else {
        $('#testimonials-div').hide();
    }

    var is_enable_sociallinks = "{{ Appoinment::EnableOrNot($sociallinks, $social_content) }}";
    if (is_enable_sociallinks) {
        $('#social-div').show();
    } else {
        $('#social-div').hide();
    }

    var is_enable_gallery = "{{ Appoinment::EnableOrNot($gallery, $gallery_contents) }}";
    if (is_enable_gallery) {
        $('#gallery-div').show();
    } else {
        $('#gallery-div').hide();
    }
    var is_payment = "{{ Appoinment::EnableOrNot($cardPayment, $cardPayment_content) }}";
    if (is_payment) {
        $('#payment-section').show();
    } else {
        $('#payment-section').hide();
    }

    var is_custom_html_enable = "{{ $custom_html }}";
    if (is_custom_html_enable) {
        $('.custom_html_text').show();
    } else {
        $('.custom_html_text').hide();
    }
    var is_branding_enable = "{{ $is_branding_enabled }}";
    if (is_branding_enable) {
        $('.branding_text').show();
    } else {
        $('.branding_text').hide();
    }
    $(`#makeappointment`).click(function () {

        var name = $(`.app_name`).val();
        var email = $(`.app_email`).val();
        var date = $(`.datepicker_min`).val();
        var phone = $(`.app_phone`).val();
        var time = $(".app_time").data('id');
        var business_id = '{{ $business->id }}';
        var slug = '{{ $store->slug }}';
        var appointmentStoreUrl = '{{ route('appoinment.store', ['slug' => $store->slug]) }}';
        function formatDate(date) {
            var d = new Date(date),
                month = '' + (d.getMonth() + 1),
                day = '' + d.getDate(),
                year = d.getFullYear();

            if (month.length < 2)
                month = '0' + month;
            if (day.length < 2)
                day = '0' + day;

            return [year, month, day].join('-');
        }
        $(`.span-error-date`).text("");
        $(`.span-error-time`).text("");
        $(`.span-error-name`).text("");
        $(`.span-error-email`).text("");

        if (date == "") {

            $(`.span-error-date`).text("{{ __('*Please choose date') }}");
            $(".close-search1").trigger({
                type: "click"
            });
        } else if (document.querySelectorAll('input[type="radio"][name="time"]:checked').length < 1) {

            $(`.span-error-time`).text("{{ __('*Please choose time') }}");
            $(".close-search1").trigger({
                type: "click"
            });
        } else if (name == "") {

            $(`.span-error-name`).text("{{ __('*Please enter your name') }}");
        } else if (email == "") {

            $(`.span-error-email`).text("{{ __('*Please enter your email') }}");
        } else if (phone == "") {

            $(`.span-error-phone`).text("{{ __('*Please enter your phone no') }}");
        } else {

            $(`.span-error-date`).text("");
            $(`.span-error-time`).text("");
            $(`.span-error-name`).text("");
            $(`.span-error-email`).text("");

            date = formatDate(date);
            $.ajax({
                url: appointmentStoreUrl,
                type: 'POST',
                data: {
                    "name": name,
                    "email": email,
                    "phone": phone,
                    "date": date,
                    "time": time,
                    "business_id": business_id,
                    "slug": slug, // Add slug to data
                    "_token": "{{ csrf_token() }}",
                },

                success: function (data) {
                    if (data.flag == false) {
                        $(".close-search1").trigger({
                            type: "click"
                        });
                        market_theme_toastr('Error', data.msg, 'error');
                    } else {
                        $(".close-search1").trigger({
                            type: "click"
                        });
                        setTimeout(function () {
                            location.reload();
                        }, 2000);
                        market_theme_toastr('Success',
                            "{{ __('Thank you for booking an appointment.') }}", 'success');
                    }
                },
                error: function (data) {
                    alert(data);
                }
            });
        }
    });

    $(`#makecontact`).click(function () {
        var name = $(`.contact_name`).val();
        var email = $(`.contact_email`).val();
        var phone = $(`.contact_phone`).val();
        var message = $(`.contact_message`).val();
        var business_id = '{{ $business->id }}';
        var slug = '{{ $store->slug }}';
        var marketcontecturl = '{{ route('marketing.contacts.store', ['slug' => $store->slug]) }}';

        $(`.span-error-contactname`).text("");
        $(`.span-error-contactemail`).text("");
        $(`.span-error-contactphone`).text("");
        $(`.span-error-contactmessage`).text("");

        if (name == "") {
            $(`.span-error-contactname`).text("{{ __('*Please enter your name') }}");
        } else if (email == "") {

            $(`.span-error-contactemail`).text("{{ __('*Please enter your email') }}");
        } else if (phone == "") {

            $(`.span-error-contactphone`).text("{{ __('*Please enter your phone no.') }}");
        } else if (message == "") {
            $(`.span-error-contactmessage`).text("{{ __('*Please enter your message.') }}");
        } else {

            $(`.span-error-contactname`).text("");
            $(`.span-error-contactemail`).text("");
            $(`.span-error-contactphone`).text("");
            $(`.span-error-contactmessage`).text("");

            $.ajax({
                url: marketcontecturl,
                type: 'POST',
                data: {
                    "name": name,
                    "email": email,
                    "phone": phone,
                    "message": message,
                    "business_id": business_id,
                    "slug": slug, // Add slug to data
                    "_token": "{{ csrf_token() }}",
                },
                success: function (data) {
                    $(".close-search2").trigger({
                        type: "click"
                    });
                    market_theme_toastr('Success', "{{ __('Your contact details has been noted.') }}",
                        'success');
                    setTimeout(function () {
                        location.reload();
                    }, 2000);

                }
            });
        }
    });
</script>
@if (isset($is_slug))
    <script>
        function market_theme_toastr(title, message, type) {
            var o, i;
            var icon = '';
            var cls = '';
            if (type == 'success') {
                icon = 'fas fa-check-circle';
                cls = 'theme-toaster-success';
            } else {
                icon = 'fas fa-times-circle';
                cls = 'theme-toaster-danger';
            }

            $.notify({
                icon: icon,
                title: " " + title,
                message: message,
                url: ""
            }, {
                element: "body",
                type: cls,
                allow_dismiss: !0,
                placement: {
                    from: 'top',
                    align: 'right'
                },
                offset: {
                    x: 15,
                    y: 15
                },
                spacing: 10,
                z_index: 1080,
                delay: 2500000,
                timer: 2000,
                url_target: "_blank",
                mouse_over: !1,
                animate: {
                    enter: o,
                    exit: i
                },
                template: '<div class="alert theme-toaster theme-toaster-success alert-{0} alert-icon theme-toaster-danger  theme-toaster-success  alert-group alert-notify" data-notify="container" role="alert"><div class="alert-group-prepend alert-content"></div><div class="alert-content"><strong data-notify="title">{1}</strong><div data-notify="message">{2}</div></div><button type="button" class="close" data-notify="dismiss" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>'
            });
        }

        if ($(".datepicker").length) {
            $('.datepicker').daterangepicker({
                singleDatePicker: true,
                format: 'yyyy-mm-dd',
            });
        }
    </script>
@endif
@if (isset($is_slug))
@if ($message = Session::get('success'))
    <script>
        market_theme_toastr('Success', '{!! $message !!}', 'success');
    </script>
@endif
@if ($message = Session::get('error'))
    <script>
        market_theme_toastr('Error', '{!! $message !!}', 'error');
    </script>
@endif
@endif

<!-- Google Analytic Code -->
<script async src="https://www.googletagmanager.com/gtag/js?id={{ Appoinment::Marketing('google_analytic') }}"></script>
<script>
    window.dataLayer = window.dataLayer || [];

    function gtag() {
        dataLayer.push(arguments);
    }
    gtag('js', new Date());
    gtag('config', '{{ Appoinment::Marketing("google_analytic")}}');
</script>
<!-- Facebook Pixel Code -->
<script>
    ! function (f, b, e, v, n, t, s) {
        if (f.fbq) return;
        n = f.fbq = function () {
            n.callMethod ?
                n.callMethod.apply(n, arguments) : n.queue.push(arguments)
        };
        if (!f._fbq) f._fbq = n;
        n.push = n;
        n.loaded = !0;
        n.version = '2.0';
        n.queue = [];
        t = b.createElement(e);
        t.async = !0;
        t.src = v;
        s = b.getElementsByTagName(e)[0];
        s.parentNode.insertBefore(t, s)
    }(window, document, 'script',
        'https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', '{{ $store->fbpixel_code }}');
    fbq('track', 'PageView');
</script>
<noscript><img height="1" width="1" style="display:none"
        src="https://www.facebook.com/tr?id=0000&ev=PageView&noscript={{  Appoinment::Marketing('fbpixel_code') }}" /></noscript>

<!-- Custom Code -->
<script type="text/javascript">
    {!!  Appoinment::Marketing('customjs') !!}
</script>
@if (isset($is_pdf))

    @include('marketing::store.script');
@endif

@if (isset($is_slug))
    @if ($is_gdpr_enabled)
    <script src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/js/cookieconsent.js') }}"></script>
        <script>
            let myVar = {!! json_encode($a) !!};
            let data = JSON.parse(myVar);
            let language_code = document.documentElement.getAttribute('lang');
            let languages = {};
            languages[language_code] = {
                consent_modal: {
                    title: 'hello',
                    description: 'description',
                    primary_btn: {
                        text: 'primary_btn text',
                        role: 'accept_all'
                    },
                    secondary_btn: {
                        text: 'secondary_btn text',
                        role: 'accept_necessary'
                    }
                },
                settings_modal: {
                    title: 'settings_modal',
                    save_settings_btn: 'save_settings_btn',
                    accept_all_btn: 'accept_all_btn',
                    reject_all_btn: 'reject_all_btn',
                    close_btn_label: 'close_btn_label',
                    blocks: [{
                        title: 'block title',
                        description: 'block description'
                    },

                    {
                        title: 'title',
                        description: 'description',
                        toggle: {
                            value: 'necessary',
                            enabled: true,
                            readonly: false
                        }
                    },
                    ]
                }
            };
        </script>
        <script>
            function setCookie(cname, cvalue, exdays) {
                const d = new Date();
                d.setTime(d.getTime() + (exdays * 24 * 60 * 60 * 1000));
                let expires = "expires=" + d.toUTCString();
                document.cookie = cname + "=" + cvalue + ";" + expires + ";path=/";
            }

            function getCookie(cname) {
                let name = cname + "=";
                let decodedCookie = decodeURIComponent(document.cookie);
                let ca = decodedCookie.split(';');
                for (let i = 0; i < ca.length; i++) {
                    let c = ca[i];
                    while (c.charAt(0) == ' ') {
                        c = c.substring(1);
                    }
                    if (c.indexOf(name) == 0) {
                        return c.substring(name.length, c.length);
                    }
                }
                return "";
            }


            // obtain plugin
            var cc = initCookieConsent();
            // run plugin with your configuration
            cc.run({
                current_lang: 'en',
                autoclear_cookies: true, // default: false
                page_scripts: true,
                // ...
                gui_options: {
                    consent_modal: {
                        layout: 'cloud', // box/cloud/bar
                        position: 'bottom center', // bottom/middle/top + left/right/center
                        transition: 'slide', // zoom/slide
                        swap_buttons: false // enable to invert buttons
                    },
                    settings_modal: {
                        layout: 'box', // box/bar
                        // position: 'left',           // left/right
                        transition: 'slide' // zoom/slide
                    }
                },

                onChange: function (cookie, changed_preferences) { },
                onAccept: function (cookie) {
                    if (!getCookie('cookie_consent_logged')) {
                        var cookie = cookie.level;
                        var slug = '{{ $store->slug }}';
                        $.ajax({
                            url: '{{ route('card-cookie-consent') }}',
                            datType: 'json',
                            data: {
                                cookie: cookie,
                                slug: slug,
                            },
                        })
                        setCookie('cookie_consent_logged', '1', 182, '/');
                    }
                },
                languages: {
                    'en': {
                        consent_modal: {
                            title: data.cookie_title,
                            description: data.cookie_description + ' ' +
                                '<button type="button" data-cc="c-settings" class="cc-link">Let me choose</button>',
                            primary_btn: {
                                text: "{{ __('Accept all') }}",
                                role: 'accept_all' // 'accept_selected' or 'accept_all'
                            },
                            secondary_btn: {
                                text: "{{ __('Reject all') }}",
                                role: 'accept_necessary' // 'settings' or 'accept_necessary'
                            },
                        },
                        settings_modal: {
                            title: "{{ __('Cookie preferences') }}",
                            save_settings_btn: "{{ __('Save settings') }}",
                            accept_all_btn: "{{ __('Accept all') }}",
                            reject_all_btn: "{{ __('Reject all') }}",
                            close_btn_label: "{{ __('Close') }}",
                            cookie_table_headers: [{
                                col1: 'Name'
                            },
                            {
                                col2: 'Domain'
                            },
                            {
                                col3: 'Expiration'
                            },
                            {
                                col4: 'Description'
                            }
                            ],
                            blocks: [{
                                title: data.cookie_title + ' ' + '📢',
                                description: data.cookie_description,
                            }, {
                                title: data.strictly_cookie_title,
                                description: data.strictly_cookie_description,
                                toggle: {
                                    value: 'necessary',
                                    enabled: true,
                                    readonly: true // cookie categories with readonly=true are all treated as "necessary cookies"
                                }
                            }, {
                                title: "{{ __('More information') }}",
                                description: data.more_information_description + ' ' +
                                    '<a class="cc-link" href="' + data.contactus_url + '">Contact Us</a>.',
                            }]
                        }
                    }
                }

            });
        </script>
    @endif
@endif
<script type="text/javascript">
    $(document).ready(function () {
        $('#copylink').on('click', function () {
            var value = $('.qr-link').text();
            var $temp = $("<input>");
            $(".share-card").append($temp);
            $temp.val(value).select();
            document.execCommand("copy");
            $(this).attr('title', 'copied');
            $temp.remove();

            return false;
        });
    });
</script>