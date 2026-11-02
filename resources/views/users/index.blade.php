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
                            <h6 class="mb-0">
                                <div class="badge p-2 px-3 rounded-1 bg-primary">{{ ucfirst($user->type) }}</div>
                            </h6>
                        </div>
                        <div class="card-header-right">
                            <div class="btn-group card-option">
                                <button type="button" class="btn dropdown-toggle" data-bs-toggle="dropdown"
                                    aria-haspopup="true" aria-expanded="false">
                                    <i class="feather icon-more-vertical"></i>
                                </button>
                                <div class="dropdown-menu dropdown-menu-end">
                                    @permission('Edit User')
                                    <a href="#" class="dropdown-item"
                                        data-url="{{ route('users.edit', $user->id) }}" data-size="md"
                                        data-ajax-popup="true" data-title="{{ __('Update User') }}">
                                        <i class="ti ti-edit"></i>
                                        <span> {{ __('Edit') }}</span>
                                    </a>
                                    @endpermission
                                    @permission('Reset Password')
                                    <a href="#" class="dropdown-item"
                                        data-url="{{ route('stores.reset.password', \Crypt::encrypt($user->id)) }}"
                                        data-ajax-popup="true" data-size="md" data-title="{{ __('Change Password') }}">
                                        <i class="ti ti-key"></i>
                                        <span> {{ __('Reset Password') }}</span>
                                    </a>
                                    @endpermission
                                    @permission('Delete User')
                                    {!! Form::open(['method' => 'DELETE', 'route' => ['users.destroy', $user->id], 'class' => 'd-inline']) !!}
                                    <a href="#" class="bs-pass-para dropdown-item show_confirm" data-confirm="{{ __('Are You Sure?') }}"
                                        data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}"
                                        data-confirm-yes="delete-form-{{ $user->id }}"><i class="ti ti-trash"></i>
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
                                        <i class="ti ti-road-sign"></i>
                                        <span class="text-danger"> {{ __('Login Disable') }}</span>
                                    </a>
                                    @elseif ($user->is_enable_login == 0 && $user->password == null)
                                    <a href="{{ route('users.enable.login', \Crypt::encrypt($user->id)) }}"
                                        class="dropdown-item">
                                        <i class="ti ti-road-sign"></i>
                                        <span class="text-success"> {{ __('Login Enable') }}</span>
                                    </a>
                                    @else
                                    <a href="{{ route('users.enable.login', \Crypt::encrypt($user->id)) }}"
                                        class="dropdown-item">
                                        <i class="ti ti-road-sign"></i>
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
                                <img src="{{ !empty($user->profile_image) ? asset('uploads/profile/' . $user->profile_image) : asset('storage/uploads/profile/avatar.png') }}"
                                    class="img-user wid-100 round-img rounded-circle">
                            </a>
                        </div>
                        <h4 class="mt-2 text-primary">{{ $user->name }}</h4>
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
                    <i class="ti ti-plus"></i>
                </div>
                <h6 class="mt-4 mb-2">{{ __('Create New User') }}</h6>
                <p class="text-muted text-center">{{ __('Click here to Create New User') }}</p>
            </a>
            @endpermission
        </div>
    </div>
</div>
{!! $users->links('layouts.global-pagination') !!}
@endsection