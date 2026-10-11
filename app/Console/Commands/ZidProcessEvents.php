<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ZidProcessEvents extends Command
{
    protected $signature = 'zid:process-events';
    protected $description = 'Save Zid order events into zid_orders and create Delivery Syria parcels';

    // أسماء المحافظات كما هي في Delivery Syria، وكل الكلمات اللي ممكن تجي من زد
    // ريف دمشق لازم يكون قبل دمشق
    private array $governorates = [
        'Rif Dimashq' => ['ريف دمشق', 'rif dimashq', 'rif damascus', 'damascus countryside'],
        'Damascus'    => ['دمشق', 'damascus'],
        'Aleppo'      => ['حلب', 'aleppo'],
        'Homs'        => ['حمص', 'homs'],
        'Hama'        => ['حماة', 'حماه', 'hama'],
        'Latakia'     => ['اللاذقية', 'اللاذقيه', 'latakia', 'lattakia'],
        'Tartus'      => ['طرطوس', 'tartus', 'tartous'],
        'Idlib'       => ['إدلب', 'ادلب', 'idlib'],
        'Deir ez-Zor' => ['دير الزور', 'deir ez-zor', 'deir ezzor', 'deir al-zor', 'deir ez zor'],
        'Raqqa'       => ['الرقة', 'الرقه', 'raqqa'],
        'Al-Hasakah'  => ['الحسكة', 'الحسكه', 'hasakah', 'hasaka'],
        'Daraa'       => ['درعا', 'daraa', 'deraa'],
        'As-Suwayda'  => ['السويداء', 'suwayda', 'sweida'],
        'Quneitra'    => ['القنيطرة', 'القنيطره', 'quneitra'],
    ];

    public function handle(): void
    {
        $saved = $this->saveNewEvents();
        $sent  = $this->sendPendingOrders();

        $this->info("Events saved: $saved | Parcels tried: $sent");
    }

    // 1) الأحداث الجديدة من زد ← جدول zid_orders
    private function saveNewEvents(): int
    {
        $events = DB::table('zid_events')->whereNull('processed_at')->orderBy('id')->limit(200)->get();

        foreach ($events as $e) {
            try {
                $o = json_decode($e->payload, true) ?: [];

                if (isset($o['id'], $o['order_status'])) {
                    $this->saveOrder($o);
                }

                DB::table('zid_events')->where('id', $e->id)->update(['processed_at' => now()]);
            } catch (\Throwable $ex) {
                Log::error('Zid event failed', ['event_id' => $e->id, 'error' => $ex->getMessage()]);
            }
        }

        return $events->count();
    }

    private function saveOrder(array $o): void
    {
        $parts = [
            data_get($o, 'shipping.address.formatted_address'),
            data_get($o, 'shipping.address.district'),
            data_get($o, 'shipping.address.street'),
            data_get($o, 'shipping.address.short_address'),
        ];
        $parts = array_filter($parts, fn ($p) => is_string($p) && trim($p) !== '' && strtoupper(trim($p)) !== 'N/A');
        $address = implode(' - ', array_unique(array_map('trim', $parts)));

        $data = [
            'store_id'        => (string) data_get($o, 'store_id'),
            'customer_name'   => data_get($o, 'consignee.name') ?: data_get($o, 'customer.name'),
            'customer_mobile' => data_get($o, 'consignee.mobile') ?: data_get($o, 'customer.mobile'),
            'city'            => data_get($o, 'shipping.address.city.name'),
            'address'         => $address,
            'total'           => data_get($o, 'order_total'),
            'payment_method'  => data_get($o, 'payment.method.code') ?: data_get($o, 'payment.method.name'),
            'status_code'     => strtolower((string) data_get($o, 'order_status.code')),
            'raw'             => json_encode($o, JSON_UNESCAPED_UNICODE),
            'updated_at'      => now(),
        ];

        $exists = DB::table('zid_orders')->where('zid_order_id', $o['id'])->exists();

        if ($exists) {
            // تحديث بيانات الطلب بدون لمس حالة الطرد
            DB::table('zid_orders')->where('zid_order_id', $o['id'])->update($data);
        } else {
            DB::table('zid_orders')->insert($data + [
                'zid_order_id'  => $o['id'],
                'parcel_status' => 'pending',
                'created_at'    => now(),
            ]);
        }
    }

    // 2) الطلبات اللي لسا ما إلها طرد ← Delivery Syria
    private function sendPendingOrders(): int
    {
        $orders = DB::table('zid_orders')
            ->where('parcel_status', 'pending')
            ->where('attempts', '<', 5)
            ->orderBy('id')
            ->limit(50)
            ->get();

        foreach ($orders as $order) {
            $this->sendOrder($order);
        }

        return $orders->count();
    }

    private function sendOrder(object $order): void
    {
        if (in_array($order->status_code, ['cancelled', 'canceled', 'refunded', 'voided', 'failed', 'expired'])) {
            $this->markFailed($order, 'الطلب ملغي أو غير مدفوع في زد، ما انعمل طرد');
            return;
        }

        $store = DB::table('zid_stores')->where('store_id', $order->store_id)->first();
        if (! $store || ! $store->ds_secret) {
            $this->markFailed($order, 'المتجر غير مربوط بحساب Delivery Syria');
            return;
        }

        $o = json_decode($order->raw, true) ?: [];

        $governorate = $this->findGovernorate((string) $order->city)
            ?? $this->findGovernorate((string) $order->address)
            ?? $this->findGovernorate((string) json_encode(data_get($o, 'shipping'), JSON_UNESCAPED_UNICODE));
        if (! $governorate) {
            $this->markFailed($order, 'المحافظة غير معروفة: ' . $order->city);
            return;
        }

        $weightKg = max(1, round(((float) data_get($o, 'weight', 0)) / 1000, 2));
        $cod = data_get($o, 'payment_status') === 'paid' ? 0 : (float) $order->total;

        try {
            $response = Http::withToken(Crypt::decryptString($store->ds_secret))
                ->withHeaders(['X-Platform' => 'storesyria'])
                ->acceptJson()
                ->timeout(30)
                ->post(config('services.deliverysyria.url') . '/api/parcel/create-with-security', [
                    'Governorate'      => $governorate,
                    'weight'           => $weightKg,
                    'customer_name'    => Str::limit($order->customer_name ?: 'عميل زد', 180, ''),
                    'customer_phone'   => Str::limit((string) $order->customer_mobile, 180, ''),
                    'customer_address' => Str::limit($order->address ?: $order->city, 180, ''),
                    'invoice_no'       => 'ZID-' . $order->zid_order_id,
                    'cod_amount2'      => $cod,
                    'selling_price'    => (float) $order->total,
                    'note'             => Str::limit((string) data_get($o, 'customer_note'), 180, ''),
                ]);
        } catch (\Throwable $ex) {
            // مشكلة اتصال: منجرّب مرة تانية بالدقيقة الجاية
            DB::table('zid_orders')->where('id', $order->id)->update([
                'attempts'     => $order->attempts + 1,
                'parcel_error' => 'اتصال: ' . Str::limit($ex->getMessage(), 250),
                'updated_at'   => now(),
            ]);
            return;
        }

        if ($response->successful() && $response->json('success')) {
            DB::table('zid_orders')->where('id', $order->id)->update([
                'parcel_status'   => 'sent',
                'parcel_tracking' => $response->json('tracking_number'),
                'parcel_error'    => $response->json('wallet_status') === 'pending_payment' ? 'رصيد المحفظة غير كافٍ، الطرد بانتظار الشحن' : null,
                'attempts'        => $order->attempts + 1,
                'updated_at'      => now(),
            ]);
            return;
        }

        if ($response->status() === 409) {
            // الطرد موجود من قبل بنفس رقم الطلب
            DB::table('zid_orders')->where('id', $order->id)->update([
                'parcel_status' => 'sent',
                'parcel_error'  => 'الطرد موجود مسبقاً',
                'attempts'      => $order->attempts + 1,
                'updated_at'    => now(),
            ]);
            return;
        }

        $message = (string) $response->json('message');
        $errors  = $response->json('errors');
        if ($errors) {
            $message .= ' ' . json_encode($errors, JSON_UNESCAPED_UNICODE);
        }

        if ($response->serverError()) {
            // خطأ مؤقت بـ Delivery Syria: منجرّب مرة تانية
            DB::table('zid_orders')->where('id', $order->id)->update([
                'attempts'     => $order->attempts + 1,
                'parcel_error' => Str::limit('HTTP ' . $response->status() . ': ' . $message, 500),
                'updated_at'   => now(),
            ]);
            return;
        }

        $this->markFailed($order, 'HTTP ' . $response->status() . ': ' . $message);
    }

    private function findGovernorate(string $text): ?string
    {
        $text = mb_strtolower($text);

        foreach ($this->governorates as $name => $words) {
            foreach ($words as $word) {
                if (str_contains($text, mb_strtolower($word))) {
                    return $name;
                }
            }
        }

        return null;
    }

    private function markFailed(object $order, string $reason): void
    {
        DB::table('zid_orders')->where('id', $order->id)->update([
            'parcel_status' => 'failed',
            'parcel_error'  => Str::limit($reason, 500),
            'attempts'      => $order->attempts + 1,
            'updated_at'    => now(),
        ]);
    }
}