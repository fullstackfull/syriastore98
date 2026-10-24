<?php

namespace App\Http\Controllers;

use App\Models\CurrencyRate;
use Illuminate\Http\Request;

class CurrencyRateController extends Controller
{
    public function index()
    {
$storeId = getCurrentStore();

$currencies = CurrencyRate::where('store_id', $storeId)
    ->orderBy('currency_code')
    ->get();
        return view('currency_rates.index', compact('currencies'));
    }

    public function update(Request $request, $id)
    {
        // P1-SEC-27 (third review). The web twin of Api\DashboardController::updateCurrencyRateApi / toggleCurrencyStatusApi, which
        // answer 403 to a user without `Manage Setting`: a rate or an inactive flag changes what the owner's next currency change
        // converts to, and an inactive USD row makes the Settings screen's own currency form fail.
        if (! \App\Services\StoreSettingsAccess::permits(auth()->user())) {
            return back()->with('error', __('Permission denied.'));
        }

$currency = CurrencyRate::where('id', $id)
    ->where('store_id', getCurrentStore())
    ->firstOrFail();
        $request->validate([
            'rate_per_usd' => 'required|numeric|min:0.00000001',
            'is_active' => 'nullable|boolean',
        ]);

        /*
         * USD لازم يضل دائماً = 1
         */
        if ($currency->currency_code === 'USD') {
            $currency->rate_per_usd = 1;
        } else {
            $currency->rate_per_usd = $request->rate_per_usd;
        }

        $currency->is_active = $request->boolean('is_active');
        $currency->created_by = auth()->id();

        $currency->save();

        return back()->with(
            'success',
            __('Currency rate updated successfully.')
        );
    }
}