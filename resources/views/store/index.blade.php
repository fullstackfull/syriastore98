@extends('layouts.app')

@section('page-title', __('Users'))

@section('action-button')
    <div class="text-end d-flex flex-wrap all-button-box align-items-center btn-badge justify-content-md-end justify-content-center gap-1">
        <a href="{{ route('store.subdomain') }}" class="btn btn-sm btn-primary btn-icon" data-bs-toggle="tooltip"
            data-bs-placement="top" title="{{ __('Sub Domain') }}">{{ __('Sub Domain') }}</a>

        <a href="{{ route('store.customdomain') }}" class="btn btn-sm btn-primary btn-badge btn-icon" data-bs-toggle="tooltip"
            data-bs-placement="top" title="{{ __('Custom Domain') }}">{{ __('Custom Domain') }}</a>

        <a href="{{ route('store.list') }}" data-bs-toggle="tooltip" data-bs-original-title="{{ __('List View') }}" class="btn btn-sm btn-primary btn-icon ">
            <i class="ti ti-list"></i>
        </a>
        <a href="javascript::void(0)" class="btn btn-sm btn-primary btn-badge btn-icon" data-ajax-popup="true" data-size="md"
            data-title="{{ __('Create New User') }}" data-url="{{ route('store.user.create') }}" data-bs-toggle="tooltip"
            title="{{ __('Add New User') }}">
            <i class="ti ti-plus"></i>
        </a>
         <!-- Search Input -->
         <input type="text" id="user-search" class="form-control btn-badge user-search" placeholder="{{ __('Search Users') }}" style="width: 200px;">
    </div>
@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Users') }}</li>
@endsection

@section('content')
<div class="delivery-user-cards mb-4">
    <div class="row">
        <div class="col-xxl-12">
            <div class="row" id="user-list">
                @foreach ($users as $user)
                    <div class="col-xl-3 col-md-4 col-sm-6 col-12 user-card">
                        <div class="card text-center card-2 mb-0 h-100">
                            <div class="card-inner">
                                <div class="card-header border-0 pb-0">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <h6 class="mb-0">
                                            @if (auth()->user()->type == 'super admin')
                                                @if (!empty($user->currentPlan))
                                                    <div class="badge bg-primary p-2 px-3 rounded-1">
                                                        {{ !empty($user->currentPlan) ? $user->currentPlan->name : '' }}
                                                    </div>
                                                @endif
                                            @else
                                                <div class="badge bg-primary p-2 px-3 rounded-1">
                                                    {{ ucfirst($user->type) }}
                                                </div>
                                            @endif
                                        </h6>
                                    </div>
                                    <div class="card-header-right">
                                        <div class="btn-group card-option">
                                            <button type="button" class="btn dropdown-toggle" data-bs-toggle="dropdown"
                                                aria-haspopup="true" aria-expanded="false">
                                                <i class="ti ti-dots-vertical"></i>
                                            </button>

                                            <div class="dropdown-menu dropdown-menu-end">

                                                @if (auth()->user()->type == 'super admin')
                                                    <a href="#!" data-size="md"
                                                        data-url="{{ route('users.edit', $user->id) }}" data-ajax-popup="true"
                                                        class="dropdown-item" data-title="{{ __('Edit User') }}"
                                                        title="{{ \Auth::user()->type == 'super admin' ? __('Edit User') : __('Edit User') }}">
                                                        <i class="ti ti-pencil"></i>
                                                        <span>{{ __('Edit') }}</span>
                                                    </a>
                                                @endif

                                                @if (auth()->user()->type == 'super admin')
                                                    {!! Form::open(['method' => 'DELETE', 'route' => ['users.destroy', $user->id], 'class' => 'd-inline']) !!}
                                                    <a href="#" class="dropdown-item bs-pass-para show_confirm" data-confirm="{{ __('Are You Sure?') }}"
                                                        data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}" 
                                                        data-confirm-yes="delete-form-{{ $user->id }}">
                                                        <i class="ti ti-trash"></i>
                                                        <span> {{ __('Delete') }}</span>
                                                    </a>
                                                    {!! Form::close() !!}
                                                @endif

                                                @if (auth()->user()->type == 'super admin')
                                                    <a href="{{ route('login.with.admin', encrypt($user->id)) }}" class="dropdown-item"
                                                        data-bs-original-title="{{ __('Login As Company') }}">
                                                        <i class="ti ti-replace"></i>
                                                        <span> {{ __('Login As Admin') }}</span>
                                                    </a>
                                                @endif

                                                <a href="#" class="dropdown-item"
                                                    data-url="{{ route('stores.link', encrypt($user->id)) }}" data-size="md"
                                                    data-ajax-popup="true" data-title="{{ __('Store Links') }}">
                                                    <i class="ti ti-unlink py-1" data-bs-toggle="tooltip"
                                                        title="Store Links"></i>
                                                    <span> {{ __('Store Link') }}</span>
                                                </a>


                                                <a href="#"
                                                    data-url="{{ route('stores.reset.password', \Crypt::encrypt($user->id)) }}"
                                                    data-ajax-popup="true" data-size="md" class="dropdown-item"
                                                    data-bs-original-title="{{ __('Reset Password') }}"
                                                    data-title="{{ __('Reset Password') }}"
                                                    title="{{ __('Reset Password') }}">
                                                    <i class="ti ti-adjustments"></i>
                                                    <span> {{ __('Reset Password') }}</span>
                                                </a>

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

                                            </div>

                                        </div>
                                    </div>
                                </div>
                                <div class="card-body full-card">
                                    <div class="img-fluid rounded-circle card-avatar">
                                        <img src="{{ !empty($user->profile_image) ? asset($user->profile_image) : asset('storage/uploads/profile/avatar.png') }}"
                                            class="img-user wid-80 round-img rounded-circle">
                                    </div>
                                    <h4 class=" mt-3 text-primary">{{ $user->name }}</h4>
                                    <small class="text-primary">{{ $user->email }}</small>
                                    <p></p>
                                    <div class="text-center" data-bs-toggle="tooltip" title="{{ __('Last Login') }}">

                                    </div>
                                    @if (\Auth::user()->type == 'super admin')
                                        <div class="mt-4">
                                            <div class="row justify-content-between align-items-center">
                                                <div class="col-12 d-flex flex-wrap align-items-center gap-2 justify-content-center">
                                                    <div>
                                                        <a href="#" data-url="{{ route('plan.upgrade', $user->id) }}"
                                                            data-size="lg" data-ajax-popup="true"
                                                            class="btn btn-outline-primary btn-badge"
                                                            data-title="{{ __('Upgrade Plan') }}">{{ __('Upgrade Plan') }}</a>
                                                    </div>
                                                    <div>
                                                        <a href="#" data-url="{{ route('user.info', $user->id) }}"
                                                            data-size="lg" data-ajax-popup="true"
                                                            class="btn btn-outline-primary btn-badge"
                                                            data-title="{{ __('Company Info') }}">{{ __('AdminHub') }}</a>
                                                    </div>
                                                </div>
                                                <div class="col-12">
                                                    <hr class="my-3">
                                                </div>
                                                <div class="col-12 text-center pb-2">
                                                    <span class="text-dark">{{ __('Plan Expired : ') }}
                                                        {{ !empty($user->plan_expire_date)? auth()->user()->dateFormat($user->plan_expire_date): __('Lifetime') }}</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row mt-3">
                                            <div class="col-6">
                                                <p class="text-muted text-sm mb-0" data-bs-toggle="tooltip"
                                                    title="{{ __('Users') }}"><i
                                                        class="ti ti-users card-icon-text-space"></i>{{ $user->totalStoreUser($user->id) }}
                                                </p>
                                            </div>
                                            <div class="col-6">
                                                <p class="text-muted text-sm mb-0" data-bs-toggle="tooltip"
                                                    title="{{ __('Customers') }}"><i
                                                        class="ti ti-users card-icon-text-space"></i>{{ $user->totalStoreCustomer($user->current_store) }}
                                                </p>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                            
                        </div>
                    </div>
                @endforeach

                @auth('web')
                    <div class="col-xl-3 col-md-4 col-sm-6 col-12">
                        @permission('Create User')
                            <a class="btn-addnew-project" data-url="{{ route('store.user.create') }}" data-title="{{ __('Create New User') }}" data-ajax-popup="true" style="cursor: pointer;" >
                                <div class="btn btn-sm btn-primary btn-badge btn-icon" data-bs-toggle="tooltip" data-bs-placement="top" title="{{ __('Add New User') }}">
                                    <i class="ti ti-plus"></i>
                                </div>
                                <h6 class="mt-4 mb-2">{{ __('Create New User') }}</h6>
                                <p class="text-muted text-center">{{ __('Click here to Add New User') }}</p>
                            </a>
                        @endpermission
                    </div>
                @endauth
            </div>
        </div>
    </div>
</div>
{!! $users->links('layouts.global-pagination') !!}
@endsection

@push('custom-script')
<script type="text/javascript">
    function myFunction(id) {
        var copyText = document.getElementById(id);
        copyText.select();
        copyText.setSelectionRange(0, 99999)
        document.execCommand("copy");
        show_toastr('Success', "{{ __('Link copied') }}", 'success');
    }

    $(document).on('change', '.active-store-index',function() {
        var status = $(this).prop('checked') == true ? 1 : 0;
        var id = $(this).data('id');
        $.ajax({
            {{-- P2-SEC-F12(b): this toggles a storefront offline, so it posts with a token. --}}
            type: "POST",
            dataType: "json",
            url: "{{ route('store.active.status') }}",
            data: {'status': status, 'id': id, '_token': '{{ csrf_token() }}'},
            success: function(data){
                $('#loader').fadeOut();
                if (data.status != 'success') {
                    show_toastr('Error', data.message, 'error');
                } else {
                    show_toastr('Success', data.message, 'success');
                }
            }
        });
    });

    $(document).on('keyup', '#user-search', function() {
        var searchValue = $(this).val().toLowerCase();
        
        $('#user-list .user-card').filter(function() {
            $(this).toggle($(this).find('.card-body h4').text().toLowerCase().indexOf(searchValue) > -1 || 
                           $(this).find('.card-body small').text().toLowerCase().indexOf(searchValue) > -1);
        });
    });
</script>
@endpush