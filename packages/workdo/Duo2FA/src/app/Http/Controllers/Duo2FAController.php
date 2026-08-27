<?php

namespace Workdo\Duo2FA\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Validator;
use App\Models\{Store, User};
use Duo\Web;
use Workdo\Duo2FA\LaravelDuo\LaravelDuo;
use App\Providers\RouteServiceProvider;
use App\Models\Plan;
use Illuminate\Support\Facades\Cache;

class Duo2FAController extends Controller
{
    protected $client;
    protected $_laravelDuo;

    public function __construct(Client $client = null, LaravelDuo $_laravelDuo)
    {
        $this->client = $client ?: new Client();
        $this->_laravelDuo = $_laravelDuo;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('duo2fa::index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('duo2fa::create');
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
        return view('duo2fa::show');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        return view('duo2fa::edit');
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

    public static function showDuoForm()
    {
        $store = getStoreById(getCurrentStore());
        $user = auth()->user();
        if (module_is_active('Duo2FA')) {
            $ikey = (new self(null, new LaravelDuo()))->_laravelDuo->get_ikey($store);
            $skey = (new self(null, new LaravelDuo()))->_laravelDuo->get_skey($store);
            $akey = (new self(null, new LaravelDuo()))->_laravelDuo->get_akey($store);
            $plan = Plan::where('id', $user->plan_id)->first();
            if (!$user) {
                return redirect()->route('login');
            }
            $store_user = User::find($store->created_by);
            $store_plan = Plan::where('id', $store_user->plan_id)->first();
            if (isset($store->duo_setting_enabled) && $store->duo_setting_enabled == 'on' && $ikey && $skey && $akey) {
                if ($plan && strpos($plan->modules, 'Duo2FA') !== false) {
                    $data = [
                        'HOST' => (new self(null, new LaravelDuo()))->_laravelDuo->get_host($store),
                        'POST' => url('duologin'),
                        'USER' => $user->email,
                        'SIG' => (new self(null, new LaravelDuo()))->_laravelDuo->signRequest((new self(null, new LaravelDuo()))->_laravelDuo->get_ikey($store), (new self(null, new LaravelDuo()))->_laravelDuo->get_skey($store), (new self(null, new LaravelDuo()))->_laravelDuo->get_akey($store), $user->email)
                    ];
                    return view('duo2fa::pages.duo', compact('data'));
                } elseif ($store_plan && strpos($store_plan->modules, 'Duo2FA') !== false && $user->created_by == $store_user->id) {
                    $data = [
                        'HOST' => (new self(null, new LaravelDuo()))->_laravelDuo->get_host($store),
                        'POST' => url('duologin'),
                        'USER' => $user->email,
                        'SIG' => (new self(null, new LaravelDuo()))->_laravelDuo->signRequest((new self(null, new LaravelDuo()))->_laravelDuo->get_ikey($store), (new self(null, new LaravelDuo()))->_laravelDuo->get_skey($store), (new self(null, new LaravelDuo()))->_laravelDuo->get_akey($store), $user->email)
                    ];
                    return view('duo2fa::pages.duo', compact('data'));
                } else {
                    session()->put('move_dashboard', 'true');
                    return redirect()->route('dashboard');
                }
            }
        }
        if (auth()->user()) {
            session()->put('move_dashboard', 'true');
            return redirect()->route('dashboard');
        }
        return redirect()->route('login');

    }

    public function verifyDuo(Request $request)
    {
        $request->validate([
            'response' => 'required',
        ]);

        $username = auth()->user()->email;
        if ($this->verifyResponse($username, $request->input('response'))) {
            // Authentication successful
            return redirect()->intended('dashboard');
        } else {
            // Authentication failed
            return redirect()->route('duo2fa.form')->withErrors(['response' => __('Invalid response.')]);
        }
    }

    public function generateSignature($username, $duo_secret_key = null, $duo_integration_key = null, $duo_application_key = null)
    {
        $time = time();
        $signature = hash_hmac('sha1', $time . ':' . $username, $duo_secret_key);
        // Use the Duo PHP client library to sign the request
        return Web::signRequest($duo_integration_key, $duo_secret_key, $signature, $username);
    }

    public function verifyResponse($username, $response)
    {
        $store = getStoreById(getCurrentStore());
        // Verify the response with Duo's API
        try {
            $duo_api_host_name = $store->duo_api_host_name ?? null;
            $duo_integration_key = $store->duo_integration_key ?? null;
            $res = $this->client->post("https://{$duo_api_host_name}/auth/v2/auth", [
                'form_params' => [
                    'username' => $username,
                    'factor' => 'auto',
                    'response' => $response,
                    'integration_key' => $duo_integration_key,
                    'signature' => $this->generateSignature($username)
                ]
            ]);

            $result = json_decode($res->getBody()->getContents(), true);
            return $result['stat'] == __('OK');
        } catch (RequestException $e) {
            return false;
        }
    }

    public function duo2FASetting(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'duo_api_host_name' => 'required',
                'duo_integration_key' => 'required',
                'duo_secret_key' => 'required',
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }

        $post['duo_setting_enabled'] = $request->duo_setting_enabled;
        $post['duo_api_host_name'] = $request->duo_api_host_name;
        $post['duo_integration_key'] = $request->duo_integration_key;
        $post['duo_secret_key'] = $request->duo_secret_key;

        $store = getStoreById(getCurrentStore());
        if ($store) {
            $status = $store->update($post);
        }

        if (isset($status)) {
            return redirect()->back()->with('success', __('Duo2FA settings updated successfully.'));
        } else {
            return redirect()->back()->with('error', __('Something is wrong'));
        }
    }

    public function duologin(Request $request)
    {
        /**
         * Sent back from Duo
         */
        $response = $request->sig_response;
        $user = User::where('email', $request->user_email)->first();
        $store = Store::find($user->current_store);
        $U = (new self(null, new LaravelDuo()))->_laravelDuo->verifyResponse((new self(null, new LaravelDuo()))->_laravelDuo->get_ikey($store), (new self(null, new LaravelDuo()))->_laravelDuo->get_skey($store), (new self(null, new LaravelDuo()))->_laravelDuo->get_akey($store), $response);

        /**
         * Duo response returns USER field from Stage Two
         */
        if ($U) {

            /**
             * Get the id of the authenticated user from their email address
             */
            $user = User::where('email', $U)->first();
            /**Update user duo logged in value */
            $user->is_duo_loggedin = true;
            $user->save();
            /** */
            \Auth::loginUsingId($user->id);
            session()->put('is_login', 'true');
            return redirect()->intended(RouteServiceProvider::HOME);

            /**
             * Log the user in by their ID
             */

            /**
             * Check Auth worked, redirect to homepage if so
             */
        }
    }
}