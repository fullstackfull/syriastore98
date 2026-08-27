<?php

namespace workdo\GoogleAuthentication\app\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Auth;
use App\Models\Plan;
use PragmaRX\Google2FAQRCode\Google2FA;

class Viewcomposer extends ServiceProvider
{

	public function boot()
	{
		view()->composer(['users.profile'], function ($view) {
			if (Auth::check()) {
				if (module_is_active('GoogleAuthentication') && Auth::user()->isAbleTo('Manage Google Authentication')) {
					$user = Auth::user();
					$google2fa_url = "";
					$secret_key = "";
					if ($user->google2fa_enable == 0 && $user->google2fa_secret != null) {
						$google2fa = (new Google2FA());
						$google2fa_url = $google2fa->getQRCodeInline(
							config('app.name'),
							$user->email,
							$user->google2fa_secret
						);
						$secret_key = $user->google2fa_secret;
					}
					$data = array(
						'user' => $user,
						'secret' => $secret_key,
						'google2fa_url' => $google2fa_url,
					);
					$plan = Plan::where('id', $user->plan_id)->first();
					if ($user->type == 'super admin' || $plan && strpos($plan->modules, 'GoogleAuthentication') !== false) {
						$view->getFactory()->startPush('profileTab', view('google-authentication::setting.tab'));
						$view->getFactory()->startPush('profileTabForm', view('google-authentication::setting.tab_form', compact('data')));
					}
				}
			}
		});
	}

	public function register(): void
	{
		//
	}

	/**
	 * Get the services provided by the provider.
	 */
	public function provides(): array
	{
		return [];
	}
}
