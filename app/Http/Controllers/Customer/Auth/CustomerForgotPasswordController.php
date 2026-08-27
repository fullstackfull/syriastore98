<?php

namespace App\Http\Controllers\Student\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;


class CustomerForgotPasswordController extends Controller
{
    public function __construct()
    {
        if(Auth::check())
        {
            \App::setLocale(\Auth::user()->lang);
        }
    }

}
