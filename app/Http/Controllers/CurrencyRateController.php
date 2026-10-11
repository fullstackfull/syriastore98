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