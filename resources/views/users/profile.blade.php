{{--
    P2-05-5 — the merchant's own profile and change-password screen, on the design system.

    THE LABELS HERE WERE ALREADY CORRECT, which is worth stating because the three sibling forms
    in this slice were not: `users/create.blade.php` had `for="Email"` against `id="email"`
    (F132) and `roles/create.blade.php` had `..._General` against `..._general` (F134). Every
    `Form::label()` on this screen names its own field, so `for` resolves. Checked, not assumed.

    What changes is presentation: `col-form-label` -> `ss-label`, `form-control` -> `ss-input`,
    the submit buttons -> the design system's, and the two upload affordances off the vendored
    `bg-primary` fill. The routes, field names, `@csrf`, the `@error` blocks, the
    `#Personal_Info` / `#Change_Password` anchors and the `@stack('profileTab')` extension points
    are untouched — the tab script and any module that pushes onto those stacks depend on them.

    NO `data-ss-theme="light"` HERE, and the difference from the modal forms is the point: this
    screen renders in the PAGE, not inside the host's Bootstrap modal, so its surface really does
    follow the admin's theme. Declaring it light would have been wrong in dark mode.
--}}
@extends('layouts.app')

@php
    $profile = asset(Storage::url('uploads/profile/'));
@endphp

@section('page-title')
    {{ __('Profile') }}
@endsection

@section('title')
    <div class="d-inline-block">
        <h5 class="h4 d-inline-block text-white font-weight-400 mb-0"> {{ __('Profile') }}</h5>
    </div>
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item active" aria-current="page">{{ __('Profile') }}</li>
@endsection

@section('action-btn')
@endsection

@section('content')
<div class="row">
    <div class="col-sm-12">
        <div class="row">
            <div class="col-xl-3">
                <div class="card sticky-top" style="top:30px">
                    <div class="list-group list-group-flush" id="useradd-sidenav">
                        <a href="#Personal_Info" id="Personal_Info_tab"
                            class="list-group-item list-group-item-action border-0">{{ __('Personal Info') }} <div
                                class="float-end"><i class="ti ti-chevron-right"></i></div>
                        </a>

                        <a href="#Change_Password" id="Change_Password_tab"
                            class="list-group-item list-group-item-action border-0">{{__('Change Password')}}<div
                                class="float-end"><i class="ti ti-chevron-right"></i></div>
                        </a>

                        @stack('profileTab')
                    </div>
                </div>
            </div>
            <div class="col-xl-9">
                    <div class="active" id="Personal_Info">
                        {{Form::model($userDetail,array('route' => array('update.account'), 'method' => 'POST', 'enctype' => "multipart/form-data"))}}
                        @csrf
                        <div class="row">
                            <div class="col-lg-12 col-sm-12 col-md-12">
                                <div class="card">
                                    <div class="card-header">
                                        <h5>{{ __('Personal Info') }}</h5>
                                    </div>
                                    <div class="card-body pb-0">
                                        <div class=" setting-card">
                                            <div class="row">
                                                <div class="col-lg-4 col-sm-6 col-md-6">
                                                    <div class="card-body pt-0 text-center">
                                                        <div class=" setting-card">
                                                            <h4>{{__('Picture')}}</h4>
                                                            <div class="logo-content mt-4 d-flex justify-content-center">
                                                                    <img src="{{(!empty($userDetail->profile_image)) && file_exists($userDetail->profile_image) ? get_file($userDetail->profile_image ) : $profile.'/avatar.png'}}"
                                                                    class=" rounded-circle-avatar" id="blah" width="100px">
                                                            </div>
                                                            <div class="choose-files mt-4">
                                                                <label for="file-1">
                                                                    <div class="ss-btn ss-btn--secondary ss-btn--sm profile_update" style="max-width: 100% !important;"> <i
                                                                            class="ti ti-upload px-1"></i>{{ __('Choose File Here') }}
                                                                    </div>
                                                                    <input type="file" class="ss-input file" name="profile_image" id="file-1"
                                                                        data-filename="profile_update" onchange="document.getElementById('blah').src = window.URL.createObjectURL(this.files[0])">
                                                                </label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-lg-8 col-sm-6 col-md-6">
                                                    <div class="card-body pt-0">
                                                        <div class="col-md-12">
                                                            <div class="form-group">
                                                                {{Form::label('name',__('Name'),array('class'=>'ss-label')) }}
                                                                {{Form::text('name',null,array('class'=>'ss-input font-style','placeholder'=>__('Enter User Name')))}}
                                                                @error('name')
                                                                <span class="invalid-name" role="alert">
                                                                        <strong class="text-danger">{{ $message }}</strong>
                                                                    </span>
                                                                @enderror
                                                            </div>
                                                        </div>
                                                        <div class="col-md-12">
                                                            {{Form::label('email',__('Email'),array('class'=>'ss-label')) }}
                                                            {{Form::text('email',null,array('class'=>'ss-input','placeholder'=>__('Enter User Email')))}}
                                                            @error('email')
                                                            <span class="invalid-email" role="alert">
                                                                    <strong class="text-danger">{{ $message }}</strong>
                                                                </span>
                                                            @enderror
                                                        </div>
                                                        <div class="col-md-12">
                                                            {{Form::label('mobile',__('Mobile No.'),array('class'=>'ss-label')) }}
                                                            {{Form::text('mobile',null,array('class'=>'ss-input','placeholder'=>__('Enter User Mobile')))}}
                                                            @error('mobile')
                                                            <span class="invalid-mobile" role="alert">
                                                                    <strong class="text-danger">{{ $message }}</strong>
                                                                </span>
                                                            @enderror
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="card-footer">
                                        <div class="col-sm-12 ">
                                            <div class="text-end">
                                                {{ Form::submit(__('Save Changes'), ['class' => 'ss-btn ss-btn--primary ss-btn--sm']) }}
                                            </div>
                                        </div>
                                    </div>

                                </div>
                            </div>
                        </div>
                        {{Form::close()}}
                    </div>

                    <div class="" id="Change_Password">
                        {!! Form::model(Auth::guard()->user(), [
                            'route' => ['user.password.update'],
                            'method' => 'PUT',
                        ]) !!}
                        @csrf
                        <div class="row">
                            <div class="col-lg-12 col-sm-12 col-md-12">
                                <div class="card">
                                    <div class="card-header">
                                        <h5>{{ __('Change Password') }}</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    {{Form::label('old_password',__('Current Password'),array('class'=>'ss-label')) }}
                                                    {{Form::password('old_password',array('class'=>'ss-input','placeholder'=>__('Enter Current Password')))}}
                                                    @error('old_password')
                                                    <span class="invalid-old_password" role="alert">
                                                         <strong class="text-danger">{{ $message }}</strong>
                                                    </span>
                                                    @enderror
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    {{Form::label('new_password',__('New Password'),array('class'=>'ss-label')) }}
                                                    {{Form::password('new_password',array('class'=>'ss-input','placeholder'=>__('Enter New Password')))}}
                                                    @error('new_password')
                                                    <span class="invalid-new_password" role="alert">
                                                            <strong class="text-danger">{{ $message }}</strong>
                                                        </span>
                                                    @enderror
                                                </div>
                                            </div>
                                            <div class="form-group">
                                                {{Form::label('new_password_confirmation',__('Re-type New Password'),array('class'=>'ss-label')) }}
                                                {{Form::password('new_password_confirmation',array('class'=>'ss-input','placeholder'=>__('Enter Re-type New Password')))}}
                                                @error('new_password_confirmation')
                                                <span class="invalid-new_password_confirmation" role="alert">
                                                        <strong class="text-danger">{{ $message }}</strong>
                                                    </span>
                                                @enderror
                                            </div>
                                        </div>
                                    </div>
                                    {!! Form::hidden('type', Auth::guard()->user()->type) !!}
                                    <div class="card-footer">
                                        <div class="col-sm-12 ">
                                            <div class="text-end">
                                                {{ Form::submit(__('Save Changes'), ['class' => 'ss-btn ss-btn--primary ss-btn--sm']) }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        {!! Form::close() !!}
                    </div>

                    @stack('profileTabForm')
            </div>
        </div>
        <!-- [ sample-page ] end -->
    </div>
</div>

@endsection
@push('custom-script')
    <script>
            $(document).on('click', '.list-group-item', function() {
                $('.list-group-item').removeClass('active');
                $('.list-group-item').removeClass('text-primary');
                setTimeout(() => {
                    $(this).addClass('active').removeClass('text-primary');
                }, 10);
            });
            var type = window.location.hash.substr(1);
            $('.list-group-item').removeClass('active');
            $('.list-group-item').removeClass('text-primary');
            if (type != '') {
                $('a[href="#' + type + '"]').addClass('active').removeClass('text-primary');
            } else {
                $('.list-group-item:eq(0)').addClass('active').removeClass('text-primary');
            }
            var scrollSpy = new bootstrap.ScrollSpy(document.body, {
                target: '#useradd-sidenav',
                offset: 300
            })
        document.addEventListener('DOMContentLoaded', function() {
            // Select all sidebar links
            const links = document.querySelectorAll('#useradd-sidenav .list-group-item');
            // Add scroll event listener
            window.addEventListener('scroll', function() {
                // Define an offset to ensure the active class is applied before the section is fully reached
                const offset = 200; // Adjust this value if necessary
                links.forEach(link => {
                    // Get the target section's ID from the link's href attribute
                    const sectionId = link.getAttribute('href').substring(1);
                    const section = document.getElementById(sectionId);
                    if (section) {
                        // Get the position of the section
                        const sectionTop = section.offsetTop - offset;
                        const sectionBottom = sectionTop + section.offsetHeight;
                        // Check if the scroll position is within the section boundaries
                        if (window.scrollY >= sectionTop && window.scrollY < sectionBottom) {
                            // Add active class to the link using your existing CSS classes
                            link.classList.add('active'); // or any other classes you've defined for active state
                        } else {
                            // Remove active class from the link
                            link.classList.remove('active');
                        }
                    }
                });
            });
        });
    </script>
@endpush
