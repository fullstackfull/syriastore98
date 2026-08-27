<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('customers', 'affiliate_code')) {

            Schema::table('customers', function (Blueprint $table) {
                $table->integer('affiliate_code')->default(0)->after('regiester_date');
                $table->integer('used_affiliate_code')->default(0)->after('affiliate_code');
                $table->integer('commission_amount')->default(0)->after('used_affiliate_code');
            });
        }
        if (Schema::hasColumn('customers', 'affiliate_code')){
            $users = DB::table('customers')->where('type', 'customer')->get();
            foreach($users as $user)
            {
                do {
                    $code = rand(100000, 999999);
                } while (DB::table('customers')->where('affiliate_code', $code)->exists());
                DB::table('customers')->where('type','customer')->where('id' , $user->id)->update(['affiliate_code' => $code]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            //
        });
    }
};
