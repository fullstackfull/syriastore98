<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Http, DB, Crypt, Log};

class ZidRefreshTokens extends Command
{
    // --force لتجديد كل المتاجر الآن (للتجربة)
    protected $signature = 'zid:refresh {--force}';

    public function handle()
    {
        $query = DB::table('zid_stores');
        if (!$this->option('force')) {
            // نجدد فقط المتاجر التي مر على توكنها 10 أشهر
            $query->where('updated_at', '<', now()->subMonths(10));
        }

        foreach ($query->get() as $s) {
            $res = Http::asForm()->post('https://oauth.zid.sa/oauth/token', [
                'grant_type'    => 'refresh_token',
                'refresh_token' => Crypt::decryptString($s->refresh_token),
                'client_id'     => config('services.zid.client_id'),
                'client_secret' => config('services.zid.client_secret'),
                'redirect_uri'  => config('services.zid.redirect_uri'),
            ]);

            if (!$res->ok()) {
                Log::error("Zid refresh failed for {$s->store_id}", ['body' => $res->body()]);
                $this->error("فشل تجديد المتجر {$s->store_id}");
                continue;
            }

            $t = $res->json();
            DB::table('zid_stores')->where('id', $s->id)->update([
                'authorization' => Crypt::encryptString($t['authorization']),
                'access_token'  => Crypt::encryptString($t['access_token']),
                'refresh_token' => Crypt::encryptString($t['refresh_token']),
                'expires_at'    => now()->addSeconds($t['expires_in']),
                'updated_at'    => now(),
            ]);
            $this->info("تم تجديد المتجر {$s->store_id} ✅");
        }
    }
}