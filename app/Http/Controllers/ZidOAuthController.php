<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Http, DB, Crypt, Log};

class ZidOAuthController extends Controller
{
    public function redirect()
    {
        $state = \Illuminate\Support\Str::random(40);
        session(['ds_state' => $state]);

        return redirect()->away('https://merchant.deliverysyria.com/connect/zid?state=' . $state);
    }

    public function connectReturn(\Illuminate\Http\Request $request)
    {
        $state = session()->pull('ds_state');
        if (! $state || ! hash_equals($state, (string) $request->query('state'))) {
            abort(400, 'انتهت الجلسة، ابدأ التثبيت من زد من جديد');
        }

        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'X-Api-Key' => config('services.deliverysyria.api_key'),
        ])->acceptJson()->post(config('services.deliverysyria.url') . '/api/connect/zid/exchange', [
            'code' => (string) $request->query('code'),
        ]);

        if (! $response->successful() || ! $response->json('success')) {
            \Illuminate\Support\Facades\Log::warning('DS exchange failed', [
                'status'  => $response->status(),
                'message' => $response->json('message'),
            ]);
            abort(400, 'تعذر ربط حساب Delivery Syria، حاول من جديد');
        }

        session([
            'ds_link' => [
                'merchant_id' => $response->json('data.merchant_id'),
                'secret'      => \Illuminate\Support\Facades\Crypt::encryptString($response->json('data.security_id')),
            ],
        ]);

        return $this->goToZid();
    }

    private function goToZid()
    {
        return redirect()->away('https://oauth.zid.sa/oauth/authorize?' . http_build_query([
            'client_id'     => config('services.zid.client_id'),
            'redirect_uri'  => config('services.zid.redirect_uri'),
            'response_type' => 'code',
        ]));
    }

    public function callback(Request $request)
    {
        abort_unless($request->code, 400, 'Missing code');

        $dsLink = session('ds_link');
        abort_unless($dsLink, 400, 'لازم تربط حساب Delivery Syria أولاً، ابدأ التثبيت من زد من جديد');

        $res = Http::asForm()->post('https://oauth.zid.sa/oauth/token', [
            'grant_type'    => 'authorization_code',
            'client_id'     => config('services.zid.client_id'),
            'client_secret' => config('services.zid.client_secret'),
            'redirect_uri'  => config('services.zid.redirect_uri'),
            'code'          => $request->code,
        ]);
        abort_unless($res->ok(), 400, 'Token exchange failed');
        $t = $res->json();

        $headers = [
            'Authorization'   => 'Bearer ' . $t['authorization'],
            'X-Manager-Token' => $t['access_token'],
            'Accept-Language' => 'en',
        ];

        $profile = Http::withHeaders($headers)->get('https://api.zid.sa/v1/managers/account/profile')->json();
        $storeId = data_get($profile, 'user.store.id');

        DB::table('zid_stores')->updateOrInsert(
            ['store_id' => $storeId],
            [
                'authorization'  => Crypt::encryptString($t['authorization']),
                'access_token'   => Crypt::encryptString($t['access_token']),
                'refresh_token'  => Crypt::encryptString($t['refresh_token']),
                'expires_at'     => now()->addSeconds($t['expires_in']),
                'ds_merchant_id' => $dsLink['merchant_id'],
                'ds_secret'      => $dsLink['secret'],
                'updated_at'     => now(),
                'created_at'     => now(),
            ]
        );

        session()->forget('ds_link');

        $events = ['order.create', 'order.status.update', 'order.payment_status.update'];
        foreach ($events as $event) {
            $r = Http::withHeaders($headers)->post('https://api.zid.sa/v1/managers/webhooks', [
                'event'       => $event,
                'target_url'  => 'https://storesyria.com/zid/events',
                'original_id' => (string) config('services.zid.client_id'),
                'conditions'  => (object) [],
                'username'    => config('services.zid.hook_user'),
                'password'    => config('services.zid.hook_pass'),
            ]);
            Log::info("Zid subscribe $event", ['status' => $r->status(), 'body' => $r->json()]);
        }
        return 'تم ربط متجرك بنجاح ✅';
    }
}