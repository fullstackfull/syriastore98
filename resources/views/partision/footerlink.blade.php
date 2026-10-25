@php
    if(auth()->user() && auth()->user()->type == 'admin') {
        $setting = getAdminAllSetting();
    } else {
        $setting = getSuperAdminAllSetting();
    }
 @endphp
 <footer class="dash-footer">
     <div class="footer-wrapper">
         <div class="py-1">
             <span class="text-muted">  @if(isset($setting['footer_text']) && (strpos($setting['footer_text'], "©") === false && strpos($setting['footer_text'], "&copy;") === false))
                    &copy;
                @endif

                {{ date('Y') }}
                {{ isset($setting['footer_text']) ? $setting['footer_text'] : config('app.name', 'E-CommerceGo') }}  </span>
         </div>
     </div>
 </footer>
                                                                                    
<!-- Required Js -->


<script src="{{ asset('js/jquery-3.6.0.min.js') }}"></script>
<script src="{{ asset('assets/js/plugins/popper.min.js') }}"></script>
<script src="{{ asset('assets/js/plugins/simplebar.min.js') }}"></script>
<script src="{{ asset('assets/js/plugins/bootstrap.min.js') }}"></script>
<script src="{{ asset('assets/js/plugins/feather.min.js') }}"></script>
<script src="{{ asset('assets/js/plugins/bootstrap-switch-button.min.js')}}"></script>
@if(module_is_active('SidebarCustomization'))
    <script src="{{ asset('assets/js/side-dash.js') }}"></script>
@else
    <script src="{{ asset('assets/js/dash.js') }}"></script>
@endif
{{-- P2-01-11: sidebar behaviour (workspace switcher, rail persistence).

     OUTSIDE the branch, and the first version got that wrong. It sat in the
     @else arm only, so on a tenant with SidebarCustomization active the chip
     still rendered — sidebar.blade.php includes it unconditionally — with no
     script to un-hide its listbox. A multi-store merchant saw a "Switch store"
     button that did nothing at all, and the rail lost its persistence and its
     aria-pressed state. The sidebar is rendered on both branches, so its
     behaviour belongs on both.

     Still loaded AFTER the branch, because the order is load-bearing: whichever
     of dash.js / side-dash.js ran owns the #vertical-nav-toggle click that adds
     or removes `body.minimenu` (dash.js:71, side-dash.js:68); this file only
     persists the result. Listeners on one element fire in registration order,
     so this must be registered second or it reads the class before the host
     has changed it.

     Not part of store-syria-ds.js on purpose: that bundle is included
     per-screen, and the sidebar is on every screen. --}}
<script src="{{ asset('js/store-syria-nav.js') }}?v=p02-2"></script>
<script src="{{ asset('assets/js/plugins/apexcharts.min.js') }}"></script>
<script src="{{ asset('assets/js/plugins/simple-datatables.js') }}"></script>
<script src="{{ asset('assets/js/plugins/notifier.js') }}"></script>
<script src="{{ asset('assets/js/pages/ac-notification.js') }}"></script>
<script src="{{ asset('assets/js/plugins/sweetalert2.all.min.js') }}"></script>
<script src="{{ asset('assets/js/plugins/choices.min.js') }}{{ "?".time() }}"></script>
<script src="{{ asset('assets/css/summernote/summernote-bs4.js')}}"></script>
<script src="{{ asset('assets/js/plugins/flatpickr.min.js') }}"></script>
<script src="{{ asset('js/socialSharing.js') }}"></script>
<script src="{{ asset('js/custom.js') }}{{ "?".time() }}"></script>
<script src="{{ asset('js/jquery.form.js') }}"></script>
<script src="{{ asset('js/jspdf.umd.min.js') }}"></script>
<script src="{{ asset('public/assets/js/plugins/dropzone.min.js') }}"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pickadate.js/3.5.3/picker.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pickadate.js/3.5.3/picker.date.js"></script>
{{-- select2 --}}

<script src="{{ asset('public/assets/js/plugins/select2.min.js') }}"></script>
<script src="{{ asset('js/loader.js') }}"></script>
<script src="{{ asset('js/emojionearea.min.js') }}"></script>
<script src="{{ asset('js/calendar.js') }}"></script>
<script src="{{ asset('js/moment/min/moment.min.js') }}"></script>

<script type="text/javascript" src="{{ asset('public/js/html2pdf.bundle.min.js') }}"></script>

<script>
    feather.replace();
    var pctoggle = document.querySelector("#pct-toggler");
    if (pctoggle) {
        pctoggle.addEventListener("click", function() {
            if (
                !document.querySelector(".pct-customizer").classList.contains("active")
            ) {
                document.querySelector(".pct-customizer").classList.add("active");
            } else {
                document.querySelector(".pct-customizer").classList.remove("active");
            }
        });
    }

    var themescolors = document.querySelectorAll(".themes-color > a");
    for (var h = 0; h < themescolors.length; h++) {
        var c = themescolors[h];

        c.addEventListener("click", function(event) {
            var targetElement = event.target;
            if (targetElement.tagName == "SPAN") {
                targetElement = targetElement.parentNode;
            }
            var temp = targetElement.getAttribute("data-value");
            removeClassByPrefix(document.querySelector("body"), "theme-");
            document.querySelector("body").classList.add(temp);
        });
    }

    var custthemebg = document.querySelector("#cust_theme_bg");
    if ($("#cust_theme_bg").length > 0) {
        custthemebg.addEventListener("click", function() {
            if (custthemebg.checked) {
                document.querySelector(".dash-sidebar").classList.add("transprent-bg");
                document
                    .querySelector(".dash-header:not(.dash-mob-header)")
                    .classList.add("transprent-bg");
            } else {
                document.querySelector(".dash-sidebar").classList.remove("transprent-bg");
                document
                    .querySelector(".dash-header:not(.dash-mob-header)")
                    .classList.remove("transprent-bg");
            }
        });
    }

    var logoDark = "{{ isset($setting['logo_dark']) && $setting['logo_dark'] ? asset($setting['logo_dark']) : asset('storage/uploads/logo/logo-dark.png') }}";
    var logoLight = "{{ isset($setting['logo_light']) && $setting['logo_light'] ? asset($setting['logo_light']) : asset('storage/uploads/logo/logo-light.png') }}";
    var custdarklayout = document.querySelector("#cust-darklayout");
    if ($("#cust-darklayout").length > 0) {
        custdarklayout.addEventListener("click", function() {
            var rtl = document.querySelector("#SITE_RTL");
            if (custdarklayout.checked) {
                document.querySelector(".m-header > .b-brand > .logo-lg").setAttribute("src",logoLight);
                    if (rtl.checked) {
                        document.querySelector("#main-style-link").setAttribute("href", "{{ asset('assets/css/rtl-style-dark.css') }}");
                        document.querySelector("#main-style-custom-link").setAttribute("href", "{{ asset('css/rtl-custom.css') }}");
                        document.querySelector('#html-dir-tag').setAttribute('dir', 'rtl');
                    } else if (!rtl.checked) {
                        document.querySelector("#main-style-link").setAttribute("href", "{{ asset('assets/css/style-dark.css') }}");
                        document.querySelector("#main-style-custom-link").setAttribute("href", "{{ asset('css/custom.css') }}");
                        document.querySelector('#html-dir-tag').setAttribute('dir', '');
                    } else {
                        document.querySelector("#main-style-link").setAttribute("href", "{{ asset('assets/css/style.css') }}");
                        document.querySelector("#main-style-custom-link").setAttribute("href", "{{ asset('css/custom.css') }}");
                        document.querySelector('#html-dir-tag').setAttribute('dir', '');
                    }

            } else {
                document.querySelector(".m-header > .b-brand > .logo-lg").setAttribute("src",logoDark);
                    if (rtl.checked) {
                        document.querySelector("#main-style-link").setAttribute("href", "{{ asset('assets/css/style-rtl.css') }}");
                        document.querySelector("#main-style-custom-link").setAttribute("href", "{{ asset('css/rtl-custom.css') }}");
                        document.querySelector('#html-dir-tag').setAttribute('dir', 'rtl');
                    } else if (!rtl.checked) {
                        document.querySelector("#main-style-link").setAttribute("href", "{{ asset('assets/css/style.css') }}");
                        document.querySelector("#main-style-custom-link").setAttribute("href", "{{ asset('css/custom.css') }}");
                        document.querySelector('#html-dir-tag').setAttribute('dir', '');
                    } else {
                        document.querySelector("#main-style-link").setAttribute("href", "{{ asset('assets/css/style.css') }}");
                        document.querySelector("#main-style-custom-link").setAttribute("href", "{{ asset('css/custom.css') }}");
                        document.querySelector('#html-dir-tag').setAttribute('dir', '');
                    }
            }
        });
    }

    $(document).on('click', '#cust_theme_bg',function() {
        if ($('#cust_theme_bg').is(":checked")) {
            document.querySelector(".dash-sidebar").classList.add("transprent-bg");
            document
                .querySelector(".dash-header:not(.dash-mob-header)")
                .classList.add("transprent-bg");
        } else {
            document.querySelector(".dash-sidebar").classList.remove("transprent-bg");
            document
                .querySelector(".dash-header:not(.dash-mob-header)")
                .classList.remove("transprent-bg");
        }
    });

    $(document).on('click', '#cust-darklayout',function() {
        var rtl = document.querySelector("#SITE_RTL");
            if ($('#cust-darklayout').is(":checked")) {
                document.querySelector(".m-header > .b-brand > .logo-lg").setAttribute("src",logoLight);
                    if (rtl.checked) {
                        document.querySelector("#main-style-link").setAttribute("href", "{{ asset('assets/css/rtl-style-dark.css') }}");
                        document.querySelector("#main-style-custom-link").setAttribute("href", "{{ asset('css/rtl-custom.css') }}");
                        document.querySelector('#html-dir-tag').setAttribute('dir', 'rtl');
                    } else if (!rtl.checked) {
                        document.querySelector("#main-style-link").setAttribute("href", "{{ asset('assets/css/style-dark.css') }}");
                        document.querySelector("#main-style-custom-link").setAttribute("href", "{{ asset('css/custom.css') }}");
                        document.querySelector('#html-dir-tag').setAttribute('dir', '');
                    } else {
                        document.querySelector("#main-style-link").setAttribute("href", "{{ asset('assets/css/style.css') }}");
                        document.querySelector("#main-style-custom-link").setAttribute("href", "{{ asset('css/custom.css') }}");
                        document.querySelector('#html-dir-tag').setAttribute('dir', '');
                    }

            } else {
                document.querySelector(".m-header > .b-brand > .logo-lg").setAttribute("src",logoDark);
                    if (rtl.checked) {
                        document.querySelector("#main-style-link").setAttribute("href", "{{ asset('assets/css/style-rtl.css') }}");
                        document.querySelector("#main-style-custom-link").setAttribute("href", "{{ asset('css/rtl-custom.css') }}");
                        document.querySelector('#html-dir-tag').setAttribute('dir', 'rtl');
                    } else if (!rtl.checked) {
                        document.querySelector("#main-style-link").setAttribute("href", "{{ asset('assets/css/style.css') }}");
                        document.querySelector("#main-style-custom-link").setAttribute("href", "{{ asset('css/custom.css') }}");
                        document.querySelector('#html-dir-tag').setAttribute('dir', '');
                    } else {
                        document.querySelector("#main-style-link").setAttribute("href", "{{ asset('assets/css/style.css') }}");
                        document.querySelector("#main-style-custom-link").setAttribute("href", "{{ asset('css/custom.css') }}");
                        document.querySelector('#html-dir-tag').setAttribute('dir', '');
                    }
            }
    });

    function removeClassByPrefix(node, prefix) {
        for (let i = 0; i < node.classList.length; i++) {
            let value = node.classList[i];
            if (value.startsWith(prefix)) {
                node.classList.remove(value);
            }
        }
    }


    var searchInput = document.getElementById("searchInput");
    if (searchInput) {
        searchInput.addEventListener("input", function() {
        var input, filter, ul, li, a, i, txtValue;
        input = document.getElementById("searchInput");
        filter = input.value.toUpperCase();
        div = document.getElementById("storeList");
        a = div.getElementsByTagName("a");
        for (i = 0; i < a.length; i++) {
            txtValue = a[i].textContent || a[i].innerText;
            if (txtValue.toUpperCase().indexOf(filter) > -1) {
                a[i].style.display = "";
            } else {
                a[i].style.display = "none";
            }
        }
    });
    }

</script>
