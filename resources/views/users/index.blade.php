@extends('layouts.app')

@section('page-title')
{{ __('Users') }}
@endsection

@php
$logo = asset(Storage::url('uploads/profile/'));
@endphp

@section('breadcrumb')
<li aria-current="page" class="ss-breadcrumb__item">{{ __('Users') }}</li>
@endsection

@section('action-button')
<div class="text-end d-flex gap-1 all-button-box justify-content-md-end justify-content-center">
    {{-- P2-05-5. `data-bs-original-title` is the tooltip plugin's own storage, not an accessible
         name, and there was no `title` here at all -- so this control was unnamed from the
         moment it rendered rather than only after the plugin initialised. --}}
    <a href="{{ route('user.list') }}"
        class="ss-btn ss-btn--secondary ss-btn--sm ss-btn--icon"
        data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('List View') }}"
        aria-label="{{ __('List View') }}">
        <i class="ti ti-list" aria-hidden="true"></i>
    </a>
    @permission('Create User')
    <a href="#" class="ss-btn ss-btn--primary ss-btn--sm" data-ajax-popup="true" data-size="md"
        data-title="{{ __('Add User') }}"
        data-url="{{ route('users.create') }}"
        data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Add User') }}">
        <i class="ti ti-plus" aria-hidden="true"></i>
        <span>{{ __('Add User') }}</span>
    </a>
    @endpermission
</div>
@endsection

@section('content')
<div class="delivery-user-cards">
    <div class="row mb-3">
        @foreach ($users as $user)
        <div class="col-xxl-3 col-lg-4 col-sm-6 col-md-4">
            <div class="card text-center">
                <div class="card-inner">
                    <div class="card-header border-0 pb-0">
                        <div class="d-flex justify-content-between align-items-center">
                            {{-- P2.5 staff-card-a11y (c). WAS `<h6 class="mb-0">`. The role badge is
                                 a STATUS BADGE, not a section heading, and wrapping it in an `h6`
                                 put it into the document outline ahead of the card's real subject:
                                 measured in Chromium, this screen emitted h2 "Users" then
                                 (h6 "Staff", h4 "<name>") per card -- a three-level SKIP followed
                                 by a REVERSAL. A `<div>` inside an `<h6>` is not valid either; a
                                 heading's content model is phrasing content.

                                 `.h6` IS LOAD-BEARING, not tidiness. Bootstrap's `.badge` is
                                 `font-size: 0.75em`, which resolves against its PARENT, so
                                 dropping the `h6` without keeping its type scale would have shrunk
                                 every role badge on the screen -- a visual regression smuggled in
                                 by a semantics fix. --}}
                            <div class="h6 mb-0">
                                <div class="badge p-2 px-3 rounded-1 bg-primary">{{ ucfirst($user->type) }}</div>
                            </div>
                        </div>
                        <div class="card-header-right">
                            <div class="btn-group card-option">
                                {{-- P2.5 staff-card-a11y (a). THE NAME WAS NOT MISSING, IT WAS JUNK,
                                     which is why nothing had caught it. This button carried no
                                     `aria-label`, no `title` and no text node, and its only child
                                     was `<i class="feather icon-more-vertical">` --
                                     `public/assets/fonts/feather.css:278` gives
                                     `.icon-more-vertical:before` the content `"\e89a"`. CSS
                                     generated content is step 2F of the accessible-name
                                     computation, so Chromium computed `name="U+E89A" len=1` on all
                                     three cards (measured over CDP against the live server): one
                                     Private-Use codepoint. Silence on VoiceOver, garbage on a
                                     braille display -- and NOT empty, so an "is the name
                                     non-empty" check passes while the control is unusable.

                                     This is the sole route to Edit, Reset Password, Delete and
                                     Login-enable, i.e. the most consequential control on the screen
                                     a merchant manages staff from.

                                     `aria-label` HERE, A VISUALLY-HIDDEN SPAN ELSEWHERE. WCAG 2.5.3
                                     Label in Name is what forced the additive span onto the header
                                     dropdowns: an `aria-label` that does not contain a control's
                                     VISIBLE text breaks it. This kebab has no visible text at all,
                                     so there is nothing for a label to contradict.

                                     AND IT NAMES THE STAFF MEMBER. Three kebabs all announced
                                     "Actions" satisfy SC 4.1.2 and are still unusable -- the same
                                     rule `ProductGalleryImageActionNamesTest` already pins for the
                                     gallery tiles.

                                     `aria-hidden` on the `<i>` is the second half: without it the
                                     glyph remains a name source behind the label. --}}
                                <button type="button" class="btn dropdown-toggle" data-bs-toggle="dropdown"
                                    aria-haspopup="true" aria-expanded="false"
                                    aria-label="{{ __('Actions for :name', ['name' => $user->name]) }}">
                                    {{-- P2.5-WAVE-4b. The glyph is now `ti ti-dots-vertical`: this was the LAST Feather
                                         icon in the merchant admin, and Feather is retired. The family changed;
                                         the defect above did NOT go away with it -- Tabler draws its glyphs the
                                         same way, through CSS `content`, so `aria-hidden` here and the
                                         `aria-label` on the button are still what gives this control a name. --}}
                                    <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                                </button>
                                <div class="dropdown-menu dropdown-menu-end">
                                    @permission('Edit User')
                                    <a href="#" class="dropdown-item"
                                        data-url="{{ route('users.edit', $user->id) }}" data-size="md"
                                        data-ajax-popup="true" data-title="{{ __('Update User') }}">
                                        <i class="ti ti-edit" aria-hidden="true"></i>
                                        <span> {{ __('Edit') }}</span>
                                    </a>
                                    @endpermission
                                    @permission('Reset Password')
                                    <a href="#" class="dropdown-item"
                                        data-url="{{ route('stores.reset.password', \Crypt::encrypt($user->id)) }}"
                                        data-ajax-popup="true" data-size="md" data-title="{{ __('Change Password') }}">
                                        <i class="ti ti-key" aria-hidden="true"></i>
                                        <span> {{ __('Reset Password') }}</span>
                                    </a>
                                    @endpermission
                                    @permission('Delete User')
                                    {!! Form::open(['method' => 'DELETE', 'route' => ['users.destroy', $user->id], 'class' => 'd-inline']) !!}
                                    <a href="#" class="bs-pass-para dropdown-item show_confirm" data-confirm="{{ __('Are You Sure?') }}"
                                        data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}"
                                        data-confirm-yes="delete-form-{{ $user->id }}"><i class="ti ti-trash" aria-hidden="true"></i>
                                        <span> {{ __('Delete') }}</span>
                                    </a>
                                    {!! Form::close() !!}
                                    @endpermission
                                    {{-- P2-05-5. WAS `@permission('Mange User Login')` -- a name that is
                                         SEEDED NOWHERE, in either spelling: "Mange" is a typo for
                                         "Manage", and `grep` finds the string exactly once in the whole
                                         codebase, here. Laratrust answers false for a name nothing holds,
                                         so this control was hidden from EVERY principal, the tenant owner
                                         included, and nothing reported an error. The P2-02-13 shape again.

                                         It is now the SAME CHECK THE HANDLER MAKES.
                                         `UserController::userLoginManage` gates on
                                         `can('update', $user)` -- a POLICY, not a permission name -- and
                                         `UserPolicy::update` requires `Edit User` plus the tenant and
                                         platform-admin rules. Mirroring it means the control appears
                                         exactly when it would work, rather than never.

                                         `@can` with a POLICY is not the `@can('Some Permission')` trap
                                         CLAUDE.md warns about: that one is always false because
                                         `permissions_as_gates => false` disables Laratrust's bridge into
                                         the Gate. A real policy ability is unaffected by that setting. --}}
                                    @can('update', $user)
                                    @if ($user->is_enable_login == 1)
                                    <a href="{{ route('users.enable.login', \Crypt::encrypt($user->id)) }}"
                                        class="dropdown-item">
                                        <i class="ti ti-road-sign" aria-hidden="true"></i>
                                        {{-- P2.5-WAVE-33. WAS `class="text-danger"`, which renders plain `red` -- 4.00:1 in light and
                                             3.36:1 on the dark dropdown, below AA in BOTH themes, on the word that says this
                                             account cannot sign in. The host utility cannot be overridden (see the
                                             .ss-text-danger note in store-syria-ds.css); the class comes off the markup. --}}
                                        <span class="ss-text-danger"> {{ __('Login Disable') }}</span>
                                    </a>
                                    @elseif ($user->is_enable_login == 0 && $user->password == null)
                                    <a href="{{ route('users.enable.login', \Crypt::encrypt($user->id)) }}"
                                        class="dropdown-item">
                                        <i class="ti ti-road-sign" aria-hidden="true"></i>
                                        <span class="text-success"> {{ __('Login Enable') }}</span>
                                    </a>
                                    @else
                                    <a href="{{ route('users.enable.login', \Crypt::encrypt($user->id)) }}"
                                        class="dropdown-item">
                                        <i class="ti ti-road-sign" aria-hidden="true"></i>
                                        <span class="text-success"> {{ __('Login Enable') }}</span>
                                    </a>
                                    @endif
                                    @endcan
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="img-fluid rounded-circle card-avatar pb-4   ">
                            <a href="{{ !empty($user->profile_image) ? asset('uploads/profile/' . $user->profile_image) : asset('storage/uploads/profile/avatar.png') }}"
                                target="_blank">
                                {{-- P2.5 staff-card-a11y (b). THIS `<img>` HAD NO `alt` ATTRIBUTE AT
                                     ALL -- not `alt=""`, absent -- and it is the ONLY content of
                                     the `<a target="_blank">` above, so the LINK had no accessible
                                     name either. Measured in Chromium over CDP: `name="" len=0
                                     from=` for the image and for all three links. A link with no
                                     accessible name fails SC 4.1.2 and SC 2.4.4.

                                     `alt=""` WOULD HAVE BEEN THE PLAUSIBLE WRONG REPAIR. It is
                                     correct markup for decoration and wrong here: it leaves the
                                     link nameless while *looking* deliberate, so the next reviewer
                                     reads it as considered. The name belongs in `alt` rather than
                                     in an `aria-label` on the anchor, because `alt` names BOTH the
                                     image and the link it is the sole content of; an anchor label
                                     would leave the image itself nameless in an image list.

                                     NOT REPAIRED HERE, reported instead: the anchor opens a raw
                                     image in a new tab with no warning to the user (SC 3.2.5
                                     advisory). That is a behaviour change, not a naming one. --}}
                                <img src="{{ !empty($user->profile_image) ? asset('uploads/profile/' . $user->profile_image) : asset('storage/uploads/profile/avatar.png') }}"
                                    alt="{{ __('Profile photo of :name', ['name' => $user->name]) }}"
                                    class="img-user wid-100 round-img rounded-circle">
                            </a>
                        </div>
                        {{-- P2.5 staff-card-a11y (c). WAS `<h4>`. The staff member's name is the
                             card's subject, so it is the card's heading -- at h3, directly under
                             the `<h2 class="h3 mb-0 ss-scope">` the shared layout emits for the page
                             title (`resources/views/layouts/app.blade.php:517`; the finding said
                             :498, which is the skip link). `class="h4"` keeps the rendered type
                             scale byte-identical to the old `<h4>`. --}}
                        <h3 class="h4 mt-2 text-primary">{{ $user->name }}</h3>
                        <small class="">{{ $user->email }}</small>
                    </div>
                </div>

            </div>
        </div>
        @endforeach
        <div class="col-lg-3 col-sm-6 col-md-6">
            @permission('Create User')
            <a class="btn-addnew-project" data-url="{{ route('users.create') }}" data-title="{{ __('Add User') }}"
                data-ajax-popup="true" style="cursor: pointer;">
                <div class="btn btn-sm btn-primary btn-badge" data-bs-toggle="tooltip" data-bs-placement="top" title="{{ __('Add User') }}">
                    <i class="ti ti-plus" aria-hidden="true"></i>
                </div>
                {{-- P2.5 staff-card-a11y (c), the half the finding did not mention. Repairing only
                     the staff cards would have left the outline h2, h3, h3, h3, h6 -- the skip
                     SHORTENED from four levels to three rather than removed. This tile is a sibling
                     of the staff cards, so it takes the same level; `class="h6"` keeps its size. --}}
                <h3 class="h6 mt-4 mb-2">{{ __('Create New User') }}</h3>
                <p class="text-muted text-center">{{ __('Click here to Create New User') }}</p>
            </a>
            @endpermission
        </div>
    </div>
</div>
{!! $users->links('layouts.global-pagination') !!}
@endsection