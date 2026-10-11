<?php

namespace App\Services;

use App\Models\CurrencyRate;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StoreWallet;
use Illuminate\Support\Facades\DB;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

class StoreCurrencyConversionService
{
    public function convertAmount(
        float $amount,
        string $fromCurrency,
        string $toCurrency,
        int $storeId
    ): float {
        $fromCurrency = strtoupper(trim($fromCurrency));
        $toCurrency = strtoupper(trim($toCurrency));

        if ($fromCurrency === $toCurrency) {
            return round($amount, 2);
        }

        $fromRate = $this->getRate($fromCurrency, $storeId);
        $toRate = $this->getRate($toCurrency, $storeId);

        // 🔍 تسجيل دقيق لمعرفة سبب الخطأ
        Log::info('💱 Currency Conversion Debug', [
            'amount' => $amount,
            'from_currency' => $fromCurrency,
            'from_rate' => $fromRate,
            'to_currency' => $toCurrency,
            'to_rate' => $toRate,
            'store_id' => $storeId,
            'formula' => "($amount / $fromRate) * $toRate",
            'expected_result' => ($amount / $fromRate) * $toRate
        ]);

        $amountInUsd = $amount / $fromRate;
        $convertedAmount = $amountInUsd * $toRate;

        return round($convertedAmount, 2);
    }

    public function getRate(string $currencyCode, int $storeId): float
    {
        $currencyCode = strtoupper(trim($currencyCode));

        if ($currencyCode === 'USD') {
            return 1.0;
        }

        $currency = CurrencyRate::where('store_id', $storeId)
            ->where('currency_code', $currencyCode)
            ->where('is_active', 1)
            ->first();

        if (!$currency) {
            Log::error('❌ Currency rate NOT FOUND', [
                'currency' => $currencyCode,
                'store_id' => $storeId
            ]);
            throw new \Exception(__('Exchange rate not found for currency') . ': ' . $currencyCode);
        }

        $rate = (float) $currency->rate_per_usd;

        // 🔍 تسجيل القيمة التي تم جلبها فعلياً من قاعدة البيانات
        Log::info('✅ Currency rate FOUND', [
            'currency' => $currencyCode,
            'rate_per_usd' => $rate,
            'store_id' => $storeId
        ]);

        if ($rate <= 0) {
            throw new \Exception(__('Invalid exchange rate for currency') . ': ' . $currencyCode);
        }

        return $rate;
    }

    public function convertStoreCurrentValues(
        int $storeId,
        string $fromCurrency,
        string $toCurrency
    ): void {
        $fromCurrency = strtoupper(trim($fromCurrency));
        $toCurrency = strtoupper(trim($toCurrency));

        Log::info('🚀 Starting Store Currency Conversion', [
            'store_id' => $storeId,
            'from' => $fromCurrency,
            'to' => $toCurrency
        ]);

        if ($fromCurrency === $toCurrency) {
            return;
        }

        DB::transaction(function () use ($storeId, $fromCurrency, $toCurrency) {
            
            // 1. Products
            $products = Product::where('store_id', $storeId)->lockForUpdate()->get();
            foreach ($products as $product) {
                $product->price = $this->convertAmount((float) $product->price, $fromCurrency, $toCurrency, $storeId);
                $product->sale_price = $this->convertAmount((float) $product->sale_price, $fromCurrency, $toCurrency, $storeId);
                $product->cost_price = $this->convertAmount((float) $product->cost_price, $fromCurrency, $toCurrency, $storeId);
                $product->save();
            }

            // 2. Product Variants
            $variants = ProductVariant::where('store_id', $storeId)->lockForUpdate()->get();
            foreach ($variants as $variant) {
                $variant->price = $this->convertAmount((float) $variant->price, $fromCurrency, $toCurrency, $storeId);
                if (!is_null($variant->variation_price)) {
                    $variant->variation_price = $this->convertAmount((float) $variant->variation_price, $fromCurrency, $toCurrency, $storeId);
                }
                $variant->save();
            }

            // 3. Current Wallet
            $wallet = StoreWallet::where('store_id', $storeId)->lockForUpdate()->first();
            if ($wallet) {
                $wallet->available_balance = $this->convertAmount((float) $wallet->available_balance, $fromCurrency, $toCurrency, $storeId);
                $wallet->pending_balance = $this->convertAmount((float) $wallet->pending_balance, $fromCurrency, $toCurrency, $storeId);
                $wallet->withdrawal_pending_balance = $this->convertAmount((float) $wallet->withdrawal_pending_balance, $fromCurrency, $toCurrency, $storeId);
                $wallet->total_income = $this->convertAmount((float) $wallet->total_income, $fromCurrency, $toCurrency, $storeId);
                $wallet->total_withdrawn = $this->convertAmount((float) $wallet->total_withdrawn, $fromCurrency, $toCurrency, $storeId);
                $wallet->currency_code = $toCurrency;
                $wallet->save();
            }

            // 4. Orders (الطلبات المعلقة)
            $orders = Order::where('store_id', $storeId)
                ->where('payment_type', 'cod')
                ->where('delivered_status', 0)
                ->lockForUpdate()
                ->get();

            $convertedOrdersCount = 0;

            // ✅ جلب رمز العملة الجديدة مرة واحدة لتجنب تكرار الاستعلام داخل الحلقة
            $newCurrencyRate = \App\Models\CurrencyRate::where('store_id', $storeId)
                ->where('currency_code', $toCurrency)
                ->where('is_active', 1)
                ->first();
            
            $newCurrencySymbol = $newCurrencyRate ? $newCurrencyRate->symbol : '$';

            foreach ($orders as $order) {
                $newProductPrice = 0.0;
                
                if (!empty($order->product_json)) {
                    $productsJson = json_decode($order->product_json, true);
                    
                    if (is_array($productsJson)) {
                        if (isset($productsJson['product_id']) || isset($productsJson['id'])) {
                            $productsJson = [$productsJson];
                        }

                        foreach ($productsJson as &$item) {
                            $qty = (int) ($item['qty'] ?? $item['quantity'] ?? 1);
                            if ($qty <= 0) $qty = 1;
                            
                            $originalUnitPrice = 0.0;
                            if (isset($item['orignal_price']) && $item['orignal_price'] !== '') {
                                $originalUnitPrice = (float) $item['orignal_price'];
                            } elseif (isset($item['price']) && $item['price'] !== '') {
                                $originalUnitPrice = (float) $item['price'];
                            } elseif (isset($item['final_price']) && $item['final_price'] !== '') {
                                $originalUnitPrice = (float) $item['final_price'];
                            }

                            if ($originalUnitPrice > 0) {
                                $convertedUnitPrice = $this->convertAmount($originalUnitPrice, $fromCurrency, $toCurrency, $storeId);
                                $itemTotal = round($convertedUnitPrice * $qty, 2); // ✅ تقريب فوري لمنع أخطاء الـ Float
                                
                                $item['price'] = (string) $convertedUnitPrice;
                                $item['orignal_price'] = (string) $convertedUnitPrice;
                                $item['total_orignal_price'] = (string) $itemTotal;
                                $item['final_price'] = (string) $itemTotal;
                                
                                $newProductPrice += $itemTotal;
                            }
                        }
                        unset($item);
                        $order->product_json = json_encode($productsJson, JSON_UNESCAPED_UNICODE);
                    }
                }

                $order->product_price = round($newProductPrice, 2);
                $order->delivery_price = $this->convertAmount((float) $order->delivery_price, $fromCurrency, $toCurrency, $storeId);
                $order->tax_price = $this->convertAmount((float) $order->tax_price, $fromCurrency, $toCurrency, $storeId);
                $order->coupon_price = $this->convertAmount((float) $order->coupon_price, $fromCurrency, $toCurrency, $storeId);
                $order->return_price = $this->convertAmount((float) $order->return_price, $fromCurrency, $toCurrency, $storeId);

                $calculatedFinalPrice = ($order->product_price + $order->delivery_price + $order->tax_price) - $order->coupon_price - $order->return_price;
                $order->final_price = round(max(0, $calculatedFinalPrice), 2);
                
                // ✅✅✅ هنا يتم حفظ كود ورمز العملة الجديدة للطلب ✅✅✅
                $order->currency_code = $toCurrency;
                $order->currency_symbol = $newCurrencySymbol;
                
                $order->save();
                $convertedOrdersCount++;
            }

            Log::info('✅ Currency conversion completed successfully', [
                'store_id' => $storeId,
                'orders_converted' => $convertedOrdersCount,
                'new_currency' => $toCurrency,
                'new_symbol' => $newCurrencySymbol
            ]);
        });
    }}