<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Models\Customer;
use App\Traits\ApiResponser;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Mail\SendOTP;
use App\Mail\OTPVerify;
use App\Models\Coupon;
use App\Models\DeliveryAddress;
use App\Models\UserAdditionalDetail;
use App\Models\Utility;
use App\Models\Store;
use App\Models\Setting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Cache;

class AuthController extends Controller
{
    use ApiResponser;

    public function register(Request $request,$slug)
    {
        $store = getStore($slug);

        $rules = [
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'token' => 'nullable',
            'device_type' => 'nullable',
        ];

        $email = $request->email;
        $request->register_type = !empty($request->register_type) ? $request->register_type : 'email';
       
        if( $request->register_type == 'email' ) {
            $rules['email'] = 'required|string|email|unique:customers,email';
        } elseif(empty($request->email)) {
            $email = $request->email = $request->first_name.rand(0,1000).'@example.com';
        }

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ],$messages->first());
        }

        $user                   = new Customer();
        if(!empty($request->email) && $request->register_type != 'email') {
            if( $request->register_type == 'facebook') {

                if(empty($email) && empty($request->mobile)) {
                    return $this->error(['message' => __('Email or mobile required in facebook login.')], __('Email or mobile required in facebook login.'));
                }

                $user_query = Customer::query();
                if(!empty($email)) {
                    $user = $user_query->where('email', $email)->first();
                }
                if(!empty($email)) {
                    $user = $user_query->where('mobile', $request->mobile)->first();
                }
            } else {
                $user = Customer::where('email', $request->email)->first();
            }

            if(empty($user)) {
                $user                   = new Customer();
            }
        }

        $user->first_name       = $request->first_name ?? null;
        $user->last_name        = $request->last_name ?? null;
        $user->email            = $request->email ?? null;
        $user->type             = 'customer';
        $user->mobile           = $request->mobile ?? null;
        $user->firebase_token   = $request->token ?? null;
        $user->device_type      = $request->device_type ?? null;
        $user->register_type    = $request->register_type ?? null;
        $user->created_by       = $store->created_by;
        $user->store_id         = $store->id;


        if ($request->register_type == 'email') {
            $rules = [
                'password' => 'required|string|min:6',
                'mobile' => 'required',
            ];
            $validator = \Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return $this->error(['message' => $messages->first()], $messages->first());
            }
            $user->password         = bcrypt($request->password);
			$user->save();
        } else {
            $flag = 0;

            if ($request->register_type == 'google' && !empty($request->google_id) ) {
                $user->google_id       = $request->google_id;
                $flag = 1;
            }
            if ($request->register_type == 'apple' && !empty($request->apple_id) ) {
                $user->apple_id        = $request->apple_id;
                $flag = 1;
            }
            if ($request->register_type == 'facebook' && !empty($request->facebook_id) ) {
                $user->facebook_id     = $request->facebook_id;
                $flag = 1;
            }

            if ($flag == 0) {
                $message = $request->register_type . ' id is missing.';
                return $this->error(['message' => $message], $message);
            }
			$user->save();
        }

        auth('customers')->login($user);
        $token = $user->createToken('auth_token')->plainTextToken;
        $user->token = $token;
        $user->token_type = 'Bearer';

        $user_array = $user->toArray();
        $user_data = User::find($user->id);
        $user_array['image'] = !empty($user_data->profile_image) ? $user_data->profile_image : "themes/style/uploads/require/user.png";
        return $this->success($user_array, __('Register successfully.'));
    }

    public function login(Request $request,$slug='')
    {
        $store = getStore($slug);
        
        $rules = [
            'email' => 'nullable|email',
            'token' => 'nullable',
            'device_type' => 'nullable',
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ], $messages->first());
        }

        if (!empty($request->password)) {

            $user = Auth::guard('customers')->attempt(['email' => $request->email, 'password' => $request->password]);
            if (!$user) {
                return $this->error(['message' => __('Invalid login details')],__('Invalid login details'));
            }
            $user = auth('customers')->user();
            $user->firebase_token = $request->token;
            $user->save();

        } elseif (!empty($request->google_id) || !empty($request->facebook_id) || !empty($request->apple_id)) {

            $User_query = Customer::query();
            if (!empty($request->google_id)) {
                $User_query->where('google_id', $request->google_id);
            } elseif (!empty($request->facebook_id)) {
                $User_query->where('facebook_id', $request->facebook_id);
            } elseif (!empty($request->apple_id)) {
                $User_query->where('apple_id', $request->apple_id);
            }

            $user = $User_query->first();

            if (!empty($user)) {
                $user->firebase_token = $request->token;
                $user->save();
            } else {
                return $this->error(['message' => __('Invalid login details.')], __('Invalid login details.'));
            }
        } else {
            return $this->error(['message' => __('Invalid login details')], __('Invalid login details'));
        }

        $user_data = Customer::find($user->id);
        $DeliveryAddress = DeliveryAddress::where('customer_id', $user->id)->where('default_address', 1)->first();

        $user_array['id'] = $user_data->id;
        $user_array['first_name'] = $user_data->first_name;
        $user_array['last_name'] = $user_data->last_name;
        $user_array['image'] = !empty($user_data->profile_image) ? $user_data->profile_image : "themes/style/uploads/require/user.png";
        $user_array['name'] = $user_data->name;
        $user_array['email'] = $user_data->email;
        $user_array['mobile'] = $user_data->mobile;
        $user_array['company_name'] = !empty($DeliveryAddress->company_name) ? $DeliveryAddress->company_name : '';
        $user_array['country_id'] = !empty($DeliveryAddress->country_id) ? $DeliveryAddress->country_id : '';
        $user_array['state_id'] = !empty($DeliveryAddress->state_id) ? $DeliveryAddress->state_id : '';
        $user_array['city'] = !empty($DeliveryAddress->city) ? $DeliveryAddress->city : '';
        $user_array['address'] = !empty($DeliveryAddress->address) ? $DeliveryAddress->address : '';
        $user_array['postcode'] = !empty($DeliveryAddress->postcode) ? $DeliveryAddress->postcode : '';

        auth('customers')->login($user);
        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;
        $user_array['token'] = $token;
        $user_array['token_type'] = 'Bearer';
        return $this->success($user_array, __('Login successfully.'));
    }

    public function logout(Request $request,$slug='')
    {
        if (!auth('customers')->user()) {
            $rules = [
                'customer_id' => 'required',
            ];

            $customer_id = $request->customer_id;
        } else {
            $rules = [

            ];
            $customer_id = auth('customers')->user()->id;
        }
        $validator = \Validator::make( $request->all(), $rules );
        if ( $validator->fails() ) {
            $messages = $validator->getMessageBag();
            return $this->error( [
                'message' => $messages->first()
            ], $messages->first());
        }

        $customer = Customer::find($customer_id);
        if (!empty($customer)) {
            return $this->success([
                'message' => __('User Logout.'),
                'logout' => $customer->tokens()->delete()
            ], __('User Logout'));
        } else {
            return $this->error(['message' => __('Unauthenticated.')], __('Unauthenticated.'), 401 );
        }
    }

    public function forgot_password_send_otp(Request $request,$slug='')
    {

        $store = getStore($slug);

        // TENANT BINDING. This resolved the customer by email ALONE, so a customer of store A was
        // resettable through store B's slug. Every lookup in this flow is now scoped to the slug's
        // store, and an unresolvable slug is refused rather than defaulted.
        if (empty($store)) {
            return $this->error([
                'message' => __("We can't find a user with that email address.")
            ], __("We can't find a user with that email address."));
        }

        $email = $request->email;
        $has_email = Customer::where('email', $email)->where('store_id', $store->id)->first();
        if(empty($has_email)) {
            return $this->error([
                'message' => __("We can't find a user with that email address.")
            ], __("We can't find a user with that email address."));
        }

        if($has_email->register_type != 'email') {
            return $this->error([
                'message' => __("You can't login because you used social login.")
            ], __("You can't login because you used social login."));
        }

        $OTP = Utility::generateNumericOTP(\App\Services\CustomerPasswordResetService::OTP_DIGITS);

        // THE OTP IS NO LONGER WRITTEN TO THE CUSTOMER ROW. It was stored in plaintext in
        // `customers.password_otp`, so any reader of that table held a live reset credential. It
        // now lives only as a HASH in the cache, keyed by store AND customer, with an expiry and
        // an attempt counter -- see App\Services\CustomerPasswordResetService for why the cache
        // rather than that column (it is declared integer() and cannot hold a hash on MySQL).
        // `password_otp_datetime` is still stamped because the web flow and any UI that reads it
        // are untouched by this change.
        //
        // THE SEND IS CAPPED PER ACCOUNT PER WINDOW, and that cap is what gives the five-guess
        // attempt limit any force. An independent review of the first fix found the limit bound
        // one CODE rather than the account: five wrong guesses, re-send, five more, for the price
        // of one extra request -- so a four-digit code was exhaustible. issueOtp() now returns
        // false when the budget is spent and the request is refused.
        if (! app(\App\Services\CustomerPasswordResetService::class)->issueOtp($has_email, (int) $store->id, (string) $OTP)) {
            return $this->error([
                'message' => __('Too many password reset requests. Please try again later.')
            ], __('Too many password reset requests. Please try again later.'));
        }

        $has_email->update(['password_otp' => null, 'password_otp_datetime' => date('Y-m-d H:i:s') ]);

        $settings = Setting::where('store_id',$store->id)->pluck('value', 'name')->toArray();

        try {
            config(
                [
                    'mail.driver' => $settings['MAIL_DRIVER'],
                    'mail.host' => $settings['MAIL_HOST'],
                    'mail.port' => $settings['MAIL_PORT'],
                    'mail.encryption' => $settings['MAIL_ENCRYPTION'],
                    'mail.username' => $settings['MAIL_USERNAME'],
                    'mail.password' => $settings['MAIL_PASSWORD'],
                    'mail.from.address' => $settings['MAIL_FROM_ADDRESS'],
                    'mail.from.name' => $settings['MAIL_FROM_NAME'],
                ]
            );

            $email = $has_email->email;
            Mail::to($email)->send(new SendOTP($OTP));
            return $this->success([
                'message' => __('We have emailed your OTP!.'),
                'infomation' => __('OTP is valid for 10 minutes.')
            ], __("We have emailed your OTP!."));
        } catch (\Throwable $th) {
            return $this->error([
                'message' => __('E-Mail has been not sent due to SMTP configuration.')
            ], __("E-Mail has been not sent due to SMTP configuration."));
        }
    }

    public function forgot_password_verify_otp(Request $request,$slug='')
    {

        $store = getStore($slug);
       
        if (empty($store)) {
            return $this->error([
                'message' => __("OTP has been not matched.")
            ], __("OTP has been not matched."));
        }

        $email = $request->email;
        $request_otp = $request->otp;

        // Scoped to the slug's store -- see send_otp.
        $has_email = Customer::where('email', $email)->where('store_id', $store->id)->first();
        if(empty($has_email)) {
            return $this->error([
                'message' => __("We can't find a user with that email address.")
            ], __("We can't find a user with that email address."));
        }

        // THE FIXED PASSWORD IS GONE, AND IT WAS THE WHOLE DEFECT. This block used to read:
        //
        //     $password = 'hello@1234';
        //     $has_email->password = bcrypt($password);
        //     $has_email->save();
        //     ... Mail::to(...)->send(new OTPVerify($has_email, $password));
        //
        // A correct OTP therefore set the account's password to a literal COMMITTED TO THIS
        // REPOSITORY and mailed it. Because the value is fixed and public, anyone who knew a
        // target had used the recovery flow could sign in as them WITHOUT ever seeing the OTP,
        // and the customer was never asked to choose a password at all.
        //
        // Verification now proves control of the mailbox and NOTHING ELSE: it mints a single-use,
        // expiring, customer- and store-bound reset token and writes no password. The password is
        // chosen by the caller at forgot-password-save, which consumes that token.
        //
        // One answer for every failure -- absent, expired, wrong, or too many attempts -- so the
        // response cannot be used to tell them apart. The attempt limit is what makes a 4-digit
        // OTP defensible: 10,000 values would otherwise be exhaustible inside its 10 minutes.
        $resetService = app(\App\Services\CustomerPasswordResetService::class);
        $resetToken = $resetService->verifyOtp($has_email, (int) $store->id, $request_otp === null ? null : (string) $request_otp);

        if ($resetToken === null) {
            return $this->error([
                'message' => __("OTP has been not matched.")
            ], __("OTP has been not matched."));
        }

        $has_email->password_otp = null;
        $has_email->password_otp_datetime = null;
        $has_email->save();

        $settings = Setting::where('store_id',$store->id)->pluck('value', 'name')->toArray();

        config(
            [
                'mail.driver' => $settings['MAIL_DRIVER'],
                'mail.host' => $settings['MAIL_HOST'],
                'mail.port' => $settings['MAIL_PORT'],
                'mail.encryption' => $settings['MAIL_ENCRYPTION'],
                'mail.username' => $settings['MAIL_USERNAME'],
                'mail.password' => $settings['MAIL_PASSWORD'],
                'mail.from.address' => $settings['MAIL_FROM_ADDRESS'],
                'mail.from.name' => $settings['MAIL_FROM_NAME'],
            ]
        );
        // THE "HERE IS YOUR NEW PASSWORD" MAIL IS NOT SENT, because there is no longer a
        // password to send. App\Mail\OTPVerify exists only to deliver one and is left in place
        // untouched for the web flow; emailing a credential was itself part of the defect.
        // The mail configuration above is still applied, so the SMTP behaviour of this endpoint
        // is otherwise unchanged.

        $DeliveryAddress = DeliveryAddress::where('customer_id', $has_email->id)->where('default_address', 1)->first();
        $user_array = [];
        $user_array['id'] = $has_email->id;
        $user_array['first_name'] = $has_email->first_name;
        $user_array['last_name'] = $has_email->last_name;
        $user_array['image'] = !empty($has_email->profile_image) ? $has_email->profile_image : "themes/style/uploads/require/user.png";
        $user_array['name'] = $has_email->name;
        $user_array['email'] = $has_email->email;
        $user_array['mobile'] = $has_email->mobile;
        $user_array['company_name'] = !empty($DeliveryAddress->company_name) ? $DeliveryAddress->company_name : '';
        $user_array['country_id'] = !empty($DeliveryAddress->country_id) ? $DeliveryAddress->country_id : '';
        $user_array['state_id'] = !empty($DeliveryAddress->state_id) ? $DeliveryAddress->state_id : '';
        $user_array['city'] = !empty($DeliveryAddress->city) ? $DeliveryAddress->city : '';
        $user_array['address'] = !empty($DeliveryAddress->address) ? $DeliveryAddress->address : '';
        $user_array['postcode'] = !empty($DeliveryAddress->postcode) ? $DeliveryAddress->postcode : '';
        $accessToken = $has_email->createToken('auth_token');
        $token = $accessToken->plainTextToken;
        $user_array['token'] = $token;
        $user_array['token_type'] = 'Bearer';

        // THE COMPATIBILITY BEARER IS REGISTERED, NOT INFERRED, and that is the difference
        // between a password-reset credential and an ordinary login session.
        //
        // forgot-password-save used to accept ANY unexpired Sanctum token belonging to a Customer
        // of the store. So a shopper's long-lived login token -- the one their phone has held
        // since they signed in, with no OTP anywhere in the story -- set that account's password
        // with no knowledge of the current one. One stolen token was a permanent takeover and the
        // OTP step was optional. Registering THIS token id against THIS recovery round means the
        // fallback accepts the session recovery itself just minted and nothing else, and that it
        // is spent on first use like the reset token beside it.
        $resetService->registerRecoveryBearer(
            (int) $accessToken->accessToken->id,
            (int) $has_email->id,
            (int) $store->id
        );

        // ADDITIVE ONLY. Every field this endpoint returned before is still returned, including
        // the Sanctum token, so a client that reads the old shape is unaffected. `reset_token` is
        // the new authorisation for forgot-password-save; a client that does not send it can
        // still authorise that call with the Bearer token above, which it already receives.
        $user_array['reset_token'] = $resetToken;
        $user_array['message'] = __("OTP has been successfully matched.");
        return $this->success($user_array, __("OTP has been successfully matched."));
    }

    public function forgot_password_save(Request $request,$slug='')
    {
        $store = getStore($slug);
        
        $email      = $request->email;
        $password   = $request->password;

        $rules = [
            'email' => 'required|email',
            'password' => 'required',
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ], $messages->first());
        }

        // ============================================================================
        // THE DEFECT THIS REPLACES, stated in full because it was the most severe finding in
        // this project. The body of this method was:
        //
        //     $has_email = User::where('email', $email)->first();   // the ADMIN model, unscoped
        //     $has_email->password = bcrypt($request->password);
        //     $has_email->save();
        //
        // The route carries ['APILog', 'throttle:auth'] and NO authentication middleware
        // (routes/api.php:52). So any anonymous caller who knew an email address set that
        // account's password -- including the platform super admin. It never read the OTP, and it
        // wrote to `users` while the OTP was issued and verified against `customers`, so the flow
        // could not even serve its stated purpose: a customer who entered the correct OTP never
        // got their own password changed.
        //
        // WHAT IT DOES NOW. It resets a CUSTOMER -- the model the rest of this flow uses -- in the
        // slug's store, and only on proof that the caller completed the OTP step. It can no
        // longer write to `users` at all; administrative password reset is a separate,
        // session-authenticated surface and was never this endpoint's job.
        //
        // TWO ACCEPTED AUTHORISATIONS, so a client that has not been updated still works:
        //   reset_token  the single-use, expiring, customer- and store-bound token minted by
        //                forgot-password-verify-otp and returned in its response.
        //   a Bearer token on the `customers` guard  which verify-otp ALSO already returns, so a
        //                client that simply forwards it authorises this call with no body change.
        // Anything else is refused. The request body contract (email + password) is unchanged.
        // ============================================================================
        if (empty($store)) {
            return $this->error([
                'message' => __("We can't find a user with that email address.")
            ], __("We can't find a user with that email address."));
        }

        $email = $request->email;
        $has_email = Customer::where('email', $email)->where('store_id', $store->id)->first();
        if(empty($has_email)) {
            return $this->error([
                'message' => __("We can't find a user with that email address.")
            ], __("We can't find a user with that email address."));
        }

        $resetService = app(\App\Services\CustomerPasswordResetService::class);
        $authorisation = $resetService->consumeResetToken($request->reset_token, (int) $store->id);

        if ($authorisation === null) {
            // FALLBACK for a client that forwards the Bearer token verify-otp already gave it.
            //
            // The token is resolved HERE rather than read off a guard, because this route carries
            // ['APILog','throttle:auth'] and NOT `custom.auth` -- so nothing populates
            // auth('customers') and reading it would always return null, making the whole
            // compatibility path dead code. Measured: the first version of this fallback did
            // exactly that and the test for it failed.
            //
            // Adding `custom.auth` to the route instead would have made authentication MANDATORY
            // and answered 401 to a client that sends no token, and repo B's network.dart turns
            // any 401 into a forced logout. Resolving the token in the handler keeps the route's
            // middleware byte-identical while giving the fallback real effect.
            //
            // Sanctum's own findToken() is used, the same call App\Http\Middleware\CustomAuth
            // makes: it splits on `|`, finds by id, and does the timing-safe hash_equals against
            // the stored hash.
            //
            // WHAT AN INDEPENDENT REVIEW CHANGED HERE. This used to accept the token on
            // `$tokenable instanceof Customer` plus a store match -- which is true of EVERY
            // customer login token. An ordinary session was therefore a password-reset
            // authorisation: no OTP, no current password, no expiry (this project configures none
            // for Sanctum), and reusable without limit, so the single-use reset token beside it
            // guaranteed nothing. The token must now have been REGISTERED by the verify step of a
            // live recovery round, and consuming it retires that round. A login token, a
            // merchant's token and a driver's token have no such record and authorise nothing.
            $bearer = $request->bearerToken();

            if (! empty($bearer)) {
                $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($bearer);

                if ($accessToken
                    && (! $accessToken->expires_at || ! $accessToken->expires_at->isPast())
                    && $accessToken->tokenable instanceof Customer) {
                    $authorisation = $resetService->consumeRecoveryBearer(
                        (int) $accessToken->id,
                        (int) $store->id
                    );
                }
            }
        }

        // The authorisation must name THIS customer. A token for one account cannot reset another,
        // which is what makes the earlier "any email you can name" behaviour impossible now.
        if ($authorisation === null || (int) $authorisation['customer_id'] !== (int) $has_email->id) {
            return $this->error([
                'message' => __("This password reset is not authorized.")
            ], __("This password reset is not authorized."));
        }

        $has_email->password = bcrypt($request->password);
        $has_email->password_otp = null;
        $has_email->password_otp_datetime = null;
        $has_email->save();

        // EVERY OTHER SESSION ON THE ACCOUNT ENDS HERE. A recovery reset is the one moment the
        // platform has positive evidence that control of the account was in doubt, and leaving
        // the pre-existing tokens alive means the owner recovers their password while an attacker
        // keeps their session -- the reset accomplishes nothing against the actor it is aimed at.
        //
        // The session this very recovery round minted is kept when the caller is holding it, so a
        // client that signed in at verify-otp and then saved a password is not logged out
        // mid-flow. Everything older goes.
        $keep = $authorisation['keep_token_id'];

        $has_email->tokens()
            ->when($keep !== null, fn ($query) => $query->where('id', '!=', $keep))
            ->delete();

        return $this->success([
            'message' => __("Password changed successfully.")
        ], __("Password changed successfully."));

    }
}
