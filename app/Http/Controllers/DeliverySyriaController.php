<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeliverySyriaController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();

        if (
            !$user ||
            empty(trim((string) $user->deliverysyria_secret))
        ) {
            return redirect()
                ->route('dashboard')
                ->with('error', __('Delivery Syria is not configured.'));
        }

        /*
         * visible:
         * 0 = لم يتم التحويل أو الخصم بعد
         * 1 = تم التحويل والخصم
         */
        $visible = $request->integer('visible', 0);

        if (!in_array($visible, [0, 1], true)) {
            $visible = 0;
        }

        $page = max(1, $request->integer('page', 1));

        $secret = trim((string) $user->deliverysyria_secret);

        try {
            $response = Http::acceptJson()
                ->withToken($secret)
                ->withHeaders([
                    'X-Platform' => 'storesyria',
                ])
                ->connectTimeout(10)
                ->timeout(30)
                ->retry(
                    times: 2,
                    sleepMilliseconds: 500,
                    when: null,
                    throw: false
                )
                ->get(
                    'https://jingle-name-buddhism.ngrok-free.dev/api/parcel/list-with-security',
                    [
                        'visible' => $visible,
                        'page' => $page,
                    ]
                );
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Delivery Syria parcels connection failed', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return view('delivery-syria.index', [
                'parcels' => [],
                'wallet' => [],
                'meta' => [],
                'links' => [],
                'visible' => $visible,
                'errorMessage' => __('Could not connect to Delivery Syria.'),
            ]);
        } catch (\Throwable $e) {
            Log::error('Delivery Syria parcels request failed', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return view('delivery-syria.index', [
                'parcels' => [],
                'wallet' => [],
                'meta' => [],
                'links' => [],
                'visible' => $visible,
                'errorMessage' => __('An unexpected error occurred.'),
            ]);
        }

        $responseData = $response->json();

        if ($response->failed()) {
            Log::error('Delivery Syria parcels API error', [
                'user_id' => $user->id,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return view('delivery-syria.index', [
                'parcels' => [],
                'wallet' => [],
                'meta' => [],
                'links' => [],
                'visible' => $visible,
                'errorMessage' => is_array($responseData)
                    ? ($responseData['message'] ?? __('Could not retrieve parcels.'))
                    : __('Could not retrieve parcels.'),
            ]);
        }

        $parcels = data_get($responseData, 'data.parcels', []);
        $wallet = data_get($responseData, 'data.wallet', []);
        $meta = data_get($responseData, 'meta', []);
        $links = data_get($responseData, 'links', []);

        return view('delivery-syria.index', compact(
            'parcels',
            'wallet',
            'meta',
            'links',
            'visible'
        ));
    }
}