<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Models\Customer;
use App\Scopes\StoreScope;
use App\Services\SocialIdentity\SocialIdentityVerificationException;
use App\Services\SocialIdentity\SocialIdentityVerifier;
use Illuminate\Support\Facades\Log;
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

    /**
     * Resolved from the container rather than injected, because this controller has no constructor
     * and adding one would change how every other action in it is built. The container binding is
     * what lets a test swap in a verifier without reaching the network.
     */
    protected function socialIdentityVerifier(): SocialIdentityVerifier
    {
        return app(SocialIdentityVerifier::class);
    }

    public function register(Request $request,$slug)
    {
        $store = getStore($slug);

        // A bogus slug reached `$store->created_by` below and produced a 500 for everyone. The
        // store is also the tenant predicate for the lookup added further down, so it has to
        // resolve before anything else happens.
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __('Store not found.'));
        }

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

        // ====================================================================================
        // AUTHZ-1. THE ONLY UNAUTHENTICATED ROW IN THIS PACKAGE, so the caller here is the open
        // internet: routes/api.php:46 carries ['APILog'] alone, and APILog is `return
        // $next($request)`.
        //
        // THE DEFECT. `register_type == 'email'` applies 'unique:customers,email', so that path
        // can only create. Every OTHER value skipped that rule and took a lookup branch that was:
        //
        //     $user = Customer::where('email', $request->email)->first();   // no store predicate
        //
        // and then overwrote first_name, last_name, email, mobile, created_by and store_id on
        // whatever row that found, saved it, logged it in and returned a Bearer token. So anyone
        // who knew a customer's email address posted `register_type=google` with any `google_id`
        // and received a working token for that account -- in any store, because the lookup had no
        // tenant predicate, and the victim was moved into the attacker's chosen store as a side
        // effect. The victim's password was never touched, so nothing told them.
        //
        // WHAT IT DOES NOW. The lookup is by PROVIDER ID within THIS STORE. Three consequences:
        //
        //   - A row created by email registration carries no provider id, so a social request can
        //     never adopt a password account.
        //   - An existing row's store_id and created_by are left alone rather than re-written, so
        //     an account can no longer be re-parented into the caller's chosen store. Note the
        //     store predicate is NOT a second control: the slug is caller input and public, so an
        //     attacker simply names the victim's store. The provider-id match is the only control
        //     here.
        //   - An existing row's email is left alone too; it is already that account's.
        //
        // If the provider id does not match but the EMAIL already exists in this store, the
        // request is refused rather than adopting the row or silently creating a duplicate that
        // would make the password-reset lookup ambiguous. That is a new refusal on a journey that
        // "worked" before, and it is deliberate: what it did before was the takeover.
        //
        // RESIDUAL, NOT CLOSED HERE. This endpoint verifies no provider token -- `google_id` is a
        // posted string. An attacker must now know the victim's provider id rather than their
        // email address, and can never touch a password account, but a known provider id is still
        // sufficient to sign in as that account AND to overwrite its first_name, last_name,
        // mobile, device_type and firebase_token -- the last of which redirects that shopper's
        // push notifications to the attacker's device. Closing it needs real
        // Google/Apple/Facebook token verification, a new outbound dependency that is not this
        // package's to add. The route also carries no throttle, where the forgot-password routes
        // beside it carry `throttle:auth`. Both recorded in the security queue.
        //
        // COMPATIBILITY. Path, method and the success payload are unchanged. Every refusal uses
        // the error envelope this handler already returns (HTTP 200 with status 0), so no new 401
        // appears on an unauthenticated path.
        // ====================================================================================
        $providerColumns = [
            'google'   => 'google_id',
            'apple'    => 'apple_id',
            'facebook' => 'facebook_id',
        ];

        $user = new Customer();

        if ($request->register_type != 'email') {
            $providerColumn = $providerColumns[$request->register_type] ?? null;

            if ($providerColumn === null) {
                $message = $request->register_type . ' id is missing.';
                return $this->error(['message' => $message], $message);
            }

            // ================================================================================
            // P01-SEC-14 WORK PACKAGE A. THE PROVIDER IDENTITY IS NOW ESTABLISHED, NOT ASSERTED.
            //
            // This block used to read the provider id out of the request body and match an account
            // on it. The previous package scoped that lookup to the store and stopped it adopting a
            // password account, which reduced the attack from "know a victim's email address" to
            // "know a victim's provider id" -- but a posted string is an assertion, never evidence,
            // so anyone holding that id could still sign in as them and could rewrite their
            // profile, including `firebase_token`, which redirects their push notifications.
            //
            // The caller must now present the provider's own credential, and the server verifies it
            // against the provider: an RS256 ID token checked against Google's or Apple's published
            // key set, or a Facebook token inspected through `debug_token` with this store's app
            // access token. See App\Services\SocialIdentity\SocialIdentityVerifier -- in
            // particular its note that the AUDIENCE check, not the signature, is the control.
            //
            // `$request->{$providerColumn}` is deliberately ignored from here on. It is still
            // accepted so a client that keeps sending it is not rejected, exactly as `customer_id`
            // is treated across the storefront API; it simply no longer decides anything. The
            // account key is the verified subject.
            //
            // FAILS CLOSED. Every failure -- no token, no credential configured for this store,
            // unreachable provider keys, bad signature, expired token, wrong audience, wrong
            // issuer, no subject -- returns the one refusal below. The specific reason goes to the
            // log and not to the caller, so this endpoint cannot be used to probe which providers a
            // store has configured or whether a given token is live.
            // ================================================================================
            try {
                $identity = $this->socialIdentityVerifier()->verify(
                    $request->register_type,
                    $request->input(SocialIdentityVerifier::TOKEN_FIELDS[$request->register_type]),
                    $store
                );
            } catch (SocialIdentityVerificationException $e) {
                Log::warning('register: social identity verification refused', [
                    'provider' => $request->register_type,
                    'reason' => $e->reason,
                    'store_id' => $store->id,
                ]);

                return $this->error(
                    ['message' => __('Could not verify your sign-in with this provider.')],
                    __('Could not verify your sign-in with this provider.')
                );
            }

            $providerId = $identity->providerId;

            // THE EMAIL IS ALSO TAKEN FROM THE PROVIDER WHEN THE PROVIDER VOUCHED FOR IT.
            //
            // Without this, a caller presenting their OWN valid token could still post
            // `email=victim@example.com` and have a new account created holding somebody else's
            // address -- not a takeover, but email squatting, and it points that address's
            // password-recovery mail at an account the victim never made. A provider-asserted
            // address that the provider says it VERIFIED is evidence; the posted one is not.
            //
            // Only `email_verified === true` counts. An unverified provider email is exactly how a
            // social sign-in becomes a way to claim a password account, so it is ignored.
            //
            // This cannot move an EXISTING account's address: the block further down writes `email`
            // only when `$isNewAccount`.
            if ($identity->emailVerified && $identity->email !== null) {
                $email = $request->email = $identity->email;
            }

            // REMOVED HERE, and recorded rather than quietly dropped: a gate that refused a
            // facebook registration when both `$email` and `mobile` were empty. It could never
            // fire, because `$email` had already been replaced with a synthetic
            // `<first_name><rand>@example.com` address further up whenever the request carried no
            // email. It tested a variable that was never empty by the time it ran.

            // withoutGlobalScope(StoreScope) and NOT withoutGlobalScopes(): the plural form also
            // strips SoftDeletingScope, so `$existing` could be a TRASHED customer -- the handler
            // would overwrite its fields, never clear `deleted_at`, log it in and return a token
            // that 401s on every later request, because custom.auth resolves the principal through
            // $accessToken->tokenable and that DOES apply the soft-delete scope. Dropping only the
            // tenant scope keeps a deleted account deleted.
            $existing = Customer::withoutGlobalScope(StoreScope::class)
                ->where('store_id', $store->id)
                ->where($providerColumn, $providerId)
                ->first();

            if ($existing !== null) {
                $user = $existing;
            } elseif (! empty($request->email)) {
                // Soft-deleted rows DO count as taken here, deliberately: `customers` has a
                // table-wide unique index on email for the register_type=email path, and letting a
                // social registration create a live duplicate of a trashed address would make the
                // password-recovery lookup ambiguous.
                $emailTaken = Customer::withoutGlobalScope(StoreScope::class)
                    ->withTrashed()
                    ->where('store_id', $store->id)
                    ->where('email', $request->email)
                    ->exists();

                if ($emailTaken) {
                    return $this->error(
                        ['message' => __('An account already exists for this email address.')],
                        __('An account already exists for this email address.')
                    );
                }
            }
        }

        $isNewAccount = ! $user->exists;

        $user->first_name       = $request->first_name ?? null;
        $user->last_name        = $request->last_name ?? null;
        $user->type             = 'customer';
        $user->mobile           = $request->mobile ?? null;
        $user->firebase_token   = $request->token ?? null;
        $user->device_type      = $request->device_type ?? null;
        $user->register_type    = $request->register_type ?? null;

        // An existing account's email, tenant and owner are its own. Only a new row takes them
        // from the request and the resolved store.
        if ($isNewAccount) {
            $user->email        = $request->email ?? null;
            $user->created_by   = $store->created_by;
            $user->store_id     = $store->id;
        }


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

            // The VERIFIED subject is written, not the posted id. $providerId was set by the
            // verification above, so by the time control reaches here a provider id exists and has
            // been proved -- which is why these no longer test the request at all. $flag's
            // "provider id is missing" arm is consequently unreachable for a supported provider;
            // it is left in place because an unsupported register_type still returns above it.
            if ($request->register_type == 'google') {
                $user->google_id       = $providerId;
                $flag = 1;
            }
            if ($request->register_type == 'apple') {
                $user->apple_id        = $providerId;
                $flag = 1;
            }
            if ($request->register_type == 'facebook') {
                $user->facebook_id     = $providerId;
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
        // Was `User::find($user->id)` -- the ADMIN model keyed by a customers.id. Those are
        // independent sequences, so the path returned to a shopper was whichever MERCHANT shared
        // their integer. The image belongs to the customer that was just registered.
        $user_array['image'] = !empty($user->profile_image) ? $user->profile_image : "themes/style/uploads/require/user.png";
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

    /**
     * A SIGNED-IN SHOPPER ENDS THEIR OWN SESSIONS. Nothing else.
     *
     * THE FOURTH INSTANCE OF ONE PATTERN, after Api\ApiController::change_password,
     * Api\DeliveryBoyApiController::resetPassword and AccountProfileController::store. The body
     * was:
     *
     *     if (!auth('customers')->user()) { $customer_id = $request->customer_id; }
     *     else                            { $customer_id = auth('customers')->user()->id; }
     *     $customer = Customer::find($customer_id);        // no store predicate
     *     $customer->tokens()->delete();
     *
     * `custom.auth` logs a token's owner into whichever guard matches its class, so a MERCHANT's
     * or a DRIVER's token passed the middleware and left auth('customers') null -- taking the
     * first branch, where `customer_id` was read straight off the request. Any such token
     * therefore revoked any customer's sessions, in any store, by numeric id.
     *
     * That is a denial of service rather than a takeover, which is why it is the least severe of
     * the four -- but it is the same defect, and a shopper repeatedly logged out of a storefront
     * they are trying to buy from is a real cost to the merchant.
     *
     * The $rules juggling was also inert: the 'customer_id' => 'required' rule only existed on the
     * branch that had already read the value.
     */
    public function logout(Request $request,$slug='')
    {
        $customer = auth('customers')->user();

        // A merchant, an operator or a driver holds a valid token and passes `custom.auth`, but is
        // not a shopper and has no business ending a shopper's sessions.
        if (! $customer instanceof Customer) {
            return $this->error(['message' => __('Unauthenticated.')], __('Unauthenticated.'), 401 );
        }

        // `customer_id` from the request is deliberately ignored. The only sessions this endpoint
        // can end are the presented token's own.
        return $this->success([
            'message' => __('User Logout.'),
            'logout' => $customer->tokens()->delete()
        ], __('User Logout'));
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

        // The digit count is configuration, not a literal -- see config/auth.php
        // ('auth.customer_password_reset.otp_digits') and the service's otpDigits(), which clamps
        // it so a misconfiguration cannot shorten the code. The default is unchanged at 4.
        $resetService = app(\App\Services\CustomerPasswordResetService::class);
        $OTP = Utility::generateNumericOTP($resetService->otpDigits());

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
        if (! $resetService->issueOtp($has_email, (int) $store->id, (string) $OTP)) {
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

        // AND THE SESSIONS SANCTUM DOES NOT KNOW ABOUT.
        //
        // An independent review of the previous package found the revocation above covered only
        // API tokens, leaving two other live credentials behind:
        //
        //   remember_token  the storefront login calls auth('customers')->login($customer, true)
        //                   when "remember me" is ticked, and Laravel's remember cookie is good
        //                   for five years by default. A reset that leaves it in place lets
        //                   whoever had the account walk straight back in with a cookie.
        //   the web OTP flow's session flags  'password_otp_verified' plus its customer and store
        //                   ids are a standing re-reset authorisation held in the caller's own
        //                   session (Customer\Auth\CustomerLoginController:419-423). They are not
        //                   this request's session to clear -- an API caller has no web session --
        //                   but the OTP state they were minted from IS cleared below, and the web
        //                   flow re-checks it, so the flag cannot be spent twice.
        //
        // Rotating remember_token invalidates every outstanding remember cookie for this account,
        // because Laravel compares the cookie's token against this column.
        $has_email->forceFill(['remember_token' => \Illuminate\Support\Str::random(60)])->save();

        return $this->success([
            'message' => __("Password changed successfully.")
        ], __("Password changed successfully."));

    }
}
