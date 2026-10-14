<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many Lynomia agent seats a Store Syria plan INCLUDES.
 *
 * NO DEFAULT, AND NULL IS NOT ZERO. Null means "this plan says nothing about Lynomia seats", which
 * is every plan that exists today and the only honest starting value: a default of 0 would silently
 * lock every merchant out of their own inbox the moment enforcement arrived, and a default of 5
 * would be a number nobody chose. The approved rules say the seat allocation is configurable per
 * plan and must not be hardcoded, so the column starts empty and an operator fills it in.
 *
 * ON THE PLAN, NOT ON THE STORE. A merchant's allowance follows their package: change the package,
 * change the allowance, for every store on it at once. Putting it on the store would make an
 * operator maintain the same number in as many places as the merchant has stores.
 *
 * WHAT IT IS NOT. It is not a price, not a counter and not the enforced ceiling. Lynomia remains
 * the enforcement authority -- this number is pushed there as a grant and composed with whatever
 * else that account has (an independent subscription, an operator override), which is why a plan
 * saying 5 does not mean a merchant paying Lynomia for 10 drops to 5.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('plans') || Schema::hasColumn('plans', 'lynomia_agent_seats')) {
            return;
        }

        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('lynomia_agent_seats')->nullable()->after('max_users');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('plans') || ! Schema::hasColumn('plans', 'lynomia_agent_seats')) {
            return;
        }

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('lynomia_agent_seats');
        });
    }
};
