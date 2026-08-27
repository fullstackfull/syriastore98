<?php

namespace Workdo\GoogleAuthentication\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Models\User;
use App\Models\userActiveModule;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class GoogleAuthenticationController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }
     /**
     * Generate 2FA secret key
     */


    public function generate2faSecret(Request $request)
    {
        if (Auth::user() && Auth::user()->isAbleTo('Enable Google Authentication')) {
            $user = User::find(Auth::user()->id);
            $google2fa = (new \PragmaRX\Google2FAQRCode\Google2FA());
            $user->google2fa_enable = 0;
            $user->google2fa_secret = $google2fa->generateSecretKey();
            $user->save();

            if ($user->type == 'admin') {
                User::where('created_by', $user->id)
                ->update([
                    'google2fa_enable' => $user->google2fa_enable,
                    'google2fa_secret'=> $user->google2fa_secret
                ]);
            }
            return redirect()->route('profile')->with('success', __('Secret key is generated.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }
    /**
     * Enable 2FA
     */
    public function enable2fa(Request $request)
    {
        if (Auth::user() && Auth::user()->isAbleTo('Enable Google Authentication')) {
            $user = User::find(Auth::user()->id);
            $google2fa = (new \PragmaRX\Google2FAQRCode\Google2FA());
            $secret = $request->input('secret');
            $valid = $google2fa->verifyKey($user->google2fa_secret, $secret);
            if ($valid) {
                $user->google2fa_enable = 1;
                $user->save();

                if ( $user->type == 'admin') {
                    User::where('created_by', $user->id)
                    ->update(['google2fa_enable' => $user->google2fa_enable]);
                }
                return redirect()->route('profile')->with('success', __('2FA is enabled successfully.'));
            } else {
                return redirect()->route('profile')->with('error',"Invalid verification Code, Please try again.");
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }
    /**
     * Disable 2FA
     */
    public function disable2fa(Request $request)
    {
        if (Auth::user() && Auth::user()->isAbleTo('Disable Google Authentication')) {
            $validatedData = $request->validate([
                'current-password' => 'required',
            ]);
            if (!(Hash::check($request->get('current-password'), Auth::user()->password))) {
                // The passwords matches
                return redirect()->route('profile')->with('error', __('Your password does not matches with your account password. Please try again.'));
            }
            $user = User::find(Auth::user()->id);
            $user->google2fa_enable = 0;
            $user->save();

            if ( $user->type == 'admin') {
              User::where('created_by', $user->id)
              ->update(['google2fa_enable' => $user->google2fa_enable]);
            }
            return redirect()->route('profile')->with('success',"2FA is now disabled.");
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('googleauthentication::index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('googleauthentication::create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        //
    }

    /**
     * Show the specified resource.
     */
    public function show($id)
    {
        return view('googleauthentication::show');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        return view('googleauthentication::edit');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id): RedirectResponse
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        //
    }
}
