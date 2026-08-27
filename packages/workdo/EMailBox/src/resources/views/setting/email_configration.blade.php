@extends('layouts.app')
@section('page-title')
    {{ __('EMail Box Configuration') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('EMail Box Configuration') }}</li>
@endsection
@php
    $company_settings = getAdminAllSetting();
@endphp

@section('content')
    <!-- [ Main Content ] start -->
    <div class="row">
        <!-- [ sample-page ] start -->
        <div class="col-sm-12">
            <div class="row">
                <div class="col-xl-12">
                    <div class="card" id="mailbox-sidenav">

                        {{ Form::open(['route' => 'emailbox.configuration.store', 'enctype' => 'multipart/form-data']) }}
                        <div class="card-header">
                            <div class="row">
                                <div class="col-lg-10 col-md-10 col-sm-10">
                                    <h5 class="">{{ __('EMail Box Configuration') }}</h5>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="form-group col-md-4">
                                    <label class="form-label ">{{ __('Mail Driver') }}</label> <br>
                                    <input class="form-control" placeholder="{{ __('Enter Mail Driver') }}"
                                        name="emailbox_mail_driver" type="text" id="emailbox_mail_driver"
                                        value="{{ isset($mail_credentail->emailbox_mail_driver) ? $mail_credentail->emailbox_mail_driver : 'smtp' }}"
                                        readonly="readonly" style="background-color: rgb(233, 236, 239);">
                                </div>
                                <div class="form-group col-md-4">
                                    <label class="form-label ">{{ __('Mail Host') }}</label> <br>
                                    <input class="form-control" placeholder="{{ __('Mail Host') }}"
                                        name="emailbox_mail_host" type="text"
                                        value="{{ isset($mail_credentail->emailbox_mail_host) ? $mail_credentail->emailbox_mail_host : '' }}"
                                        id="emailbox_mail_host">
                                </div>
                                <div class="form-group col-md-4">
                                    <label class="form-label ">{{ __('Outgoing Port') }}</label> <br>
                                    <input class="form-control" placeholder="{{ __('Enter Outgoing Port') }}"
                                        name="emailbox_outgoing_port" type="text"
                                        value="{{ isset($mail_credentail->emailbox_outgoing_port) ? $mail_credentail->emailbox_outgoing_port : '' }}"
                                        id="emailbox_outgoing_port">
                                </div>
                                <div class="form-group col-md-4">
                                    <label class="form-label ">{{ __('Incoming Port') }}</label> <br>
                                    <input class="form-control" placeholder="{{ __('Enter Incoming Port') }}"
                                        name="emailbox_incoming_port" type="text"
                                        value="{{ isset($mail_credentail->emailbox_incoming_port) ? $mail_credentail->emailbox_incoming_port : '' }}"
                                        id="emailbox_incoming_port">
                                </div>
                                <div class="form-group col-md-4">
                                    <label class="form-label ">{{ __('Mail Username') }}</label> <br>
                                    <input class="form-control" placeholder="{{ __('Enter Mail Username') }}"
                                        name="emailbox_mail_username" type="text"
                                        value="{{ isset($mail_credentail->emailbox_mail_username) ? $mail_credentail->emailbox_mail_username : '' }}"
                                        id="emailbox_mail_username">
                                </div>
                                <div class="form-group col-md-4">
                                    <label class="form-label ">{{ __('Mail Password') }}</label> <br>
                                    <input class="form-control" placeholder="{{ __('Enter Mail Password') }}"
                                        name="emailbox_mail_password" type="text"
                                        value="{{ isset($mail_credentail->emailbox_mail_password) ? $mail_credentail->emailbox_mail_password : '' }}"
                                        id="emailbox_mail_password">
                                </div>
                                <div class="form-group col-md-4">
                                    <label class="form-label ">{{ __('Mail Encryption') }}</label> <br>
                                    <input class="form-control" placeholder="{{ __('Enter Mail Encryption') }}"
                                        name="emailbox_mail_encryption" type="test"
                                        value="{{ isset($mail_credentail->emailbox_mail_encryption) ? $mail_credentail->emailbox_mail_encryption : '' }}"
                                        id="emailbox_mail_encryption">
                                </div>
                                <div class="form-group col-md-4">
                                    <label class="form-label ">{{ __('Mail From Address') }}</label> <br>
                                    <input class="form-control" placeholder="{{ __('Enter Mail From Address') }}"
                                        name="emailbox_mail_from_address" type="text"
                                        value="{{ isset($mail_credentail->emailbox_mail_from_address) ? $mail_credentail->emailbox_mail_from_address : '' }}"
                                        id="emailbox_mail_from_address">
                                </div>
                                <div class="form-group col-md-4">
                                    <label class="form-label ">{{ __('Mail From Name') }}</label> <br>
                                    <input class="form-control" placeholder="{{ __('Enter Mail From Name') }}"
                                        name="emailbox_mail_from_name" type="text"
                                        value="{{ isset($mail_credentail->emailbox_mail_from_name) ? $mail_credentail->emailbox_mail_from_name : '' }}"
                                        id="emailbox_mail_from_name">
                                </div>
                            </div>
                        </div>
                        <div class="card-footer text-end">
                            <input class="btn btn-print-invoice  btn-primary m-r-10" type="submit"
                                value="{{ __('Save Changes') }}">
                        </div>
                        {{ Form::close() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
