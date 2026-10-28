@extends('layouts.app')

@section('page-title')
    {{ __('Create Customer') }}
@endsection

@section('breadcrumb')
    <li class="ss-breadcrumb__item"><a class="ss-breadcrumb__link" href="{{ route('customer.index') }}">{{ __('Customer') }}</a></li>
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Create Customer') }}</li>
@endsection

@section('action-button')
    <div class="text-end">
        <a href="{{ route('customer.index') }}" class="btn btn-sm btn-primary">
            <i class="ti ti-arrow-left"></i> {{ __('Back') }}
        </a>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-sm-12">
            <div class="card">
                <div class="card-header">
                    <h5>{{ __('Customer Information') }}</h5>
                </div>
                <div class="card-body">
                    {{ Form::open(['route' => 'customer.store', 'method' => 'POST']) }}
                    <div class="row">
                        <!-- الاسم الأول -->
                        <div class="col-md-6">
                            <div class="form-group">
                                {{ Form::label('first_name', __('First Name'), ['class' => 'form-label']) }}
                                {{ Form::text('first_name', null, ['class' => 'form-control', 'placeholder' => __('Enter First Name'), 'required' => 'required']) }}
                                @error('first_name')
                                    <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                @enderror
                            </div>
                        </div>

                        <!-- الاسم الأخير -->
                        <div class="col-md-6">
                            <div class="form-group">
                                {{ Form::label('last_name', __('Last Name'), ['class' => 'form-label']) }}
                                {{ Form::text('last_name', null, ['class' => 'form-control', 'placeholder' => __('Enter Last Name'), 'required' => 'required']) }}
                                @error('last_name')
                                    <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                @enderror
                            </div>
                        </div>

                        <!-- البريد الإلكتروني -->
                        <div class="col-md-6">
                            <div class="form-group">
                                {{ Form::label('email', __('Email'), ['class' => 'form-label']) }}
                                {{ Form::email('email', null, ['class' => 'form-control', 'placeholder' => __('Enter Email'), 'required' => 'required']) }}
                                @error('email')
                                    <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                @enderror
                            </div>
                        </div>

                        <!-- رقم الهاتف -->
                        <div class="col-md-6">
                            <div class="form-group">
                                {{ Form::label('mobile', __('Mobile'), ['class' => 'form-label']) }}
                                {{ Form::text('mobile', null, ['class' => 'form-control', 'placeholder' => __('Enter Mobile Number')]) }}
                                @error('mobile')
                                    <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                @enderror
                            </div>
                        </div>

                        <!-- كلمة المرور -->
                        <div class="col-md-6">
                            <div class="form-group">
                                {{ Form::label('password', __('Password'), ['class' => 'form-label']) }}
                                {{ Form::password('password', ['class' => 'form-control', 'placeholder' => __('Enter Password'), 'required' => 'required']) }}
                                @error('password')
                                    <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                @enderror
                            </div>
                        </div>

                        <!-- الحالة -->
                        <div class="col-md-6">
                            <div class="form-group">
                                {{ Form::label('status', __('Status'), ['class' => 'form-label']) }}
                                <select name="status" class="form-control select2" required>
                                    <option value="1" {{ old('status') == '1' ? 'selected' : '' }}>{{ __('Active') }}</option>
                                    <option value="0" {{ old('status') == '0' ? 'selected' : '' }}>{{ __('Inactive') }}</option>
                                </select>
                                @error('status')
                                    <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                @enderror
                            </div>
                        </div>

                        <!-- زر الحفظ -->
                        <div class="col-md-12 text-end mt-3">
                            {{ Form::submit(__('Create Customer'), ['class' => 'btn btn-primary']) }}
                            <a href="{{ route('customer.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                        </div>
                    </div>
                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>
@endsection