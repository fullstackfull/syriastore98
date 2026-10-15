<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE COMMERCIAL SIDE OF MARKETING SENDS (P5G): what a package includes, and what a merchant
 * can buy on top of it.
 *
 * THE SAME SHAPE AS `lynomia_agent_seats`, deliberately. Null is not zero -- null means this
 * plan says nothing about marketing sends, which is every plan that exists today and the only
 * honest starting value. A default of 0 would stop every merchant's campaigns the moment
 * enforcement arrived, and any other default would be a number nobody chose.
 *
 * ADD-ONS ARE A CATALOGUE AND A LEDGER, NOT A COLUMN. A merchant who needs more sends this
 * month buys a bundle; next month they may not. Adding to the plan's own number would change
 * it for every merchant on that plan, and adding a column to the store would lose the history
 * of what was bought and when. So: `lynomia_marketing_addons` is what an operator offers, and
 * `lynomia_store_addons` is what a given store holds, each row carrying its own validity.
 *
 * A TRIAL IS AN ADD-ON THAT EXPIRES, which is why there is no separate trial table. "Two
 * thousand free sends until the end of next month" and "a bundle of two thousand sends" differ
 * only in what was paid, and `expires_at` already says when a grant stops counting. One
 * mechanism with one expiry rule is one thing to get right.
 *
 * NO PRICES ARE CHARGED HERE. `price_cents` records what an add-on costs so an operator can
 * see it; the payment rail is Store Syria's existing plan-order machinery and is not touched.
 * An add-on is granted by an operator, which is also the only safe thing to ship while every
 * payment gateway in this installation is switched off.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('plans') && ! Schema::hasColumn('plans', 'lynomia_campaign_sends')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->unsignedInteger('lynomia_campaign_sends')->nullable()->after('lynomia_agent_seats');
            });
        }

        if (! Schema::hasTable('lynomia_marketing_addons')) {
            Schema::create('lynomia_marketing_addons', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                // What one unit of this add-on adds to a store's period allowance.
                $table->unsignedInteger('campaign_sends');
                // Recorded, never charged here. The payment rail is the platform's own.
                $table->unsignedInteger('price_cents')->default(0);
                $table->string('currency', 8)->default('USD');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index('is_active');
            });
        }

        if (! Schema::hasTable('lynomia_store_addons')) {
            Schema::create('lynomia_store_addons', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('store_id');
                $table->unsignedBigInteger('addon_id');
                $table->unsignedInteger('quantity')->default(1);
                // NULL means it does not expire. A trial is a row with a date here.
                $table->timestamp('expires_at')->nullable();
                // Who granted it, so a ledger entry can be traced to a person.
                $table->unsignedBigInteger('granted_by')->nullable();
                $table->string('note', 255)->nullable();
                $table->timestamps();

                // The merchant screen's query: everything this store holds, newest first.
                $table->index(['store_id', 'expires_at'], 'lynomia_store_addons_scope_idx');
            });

            Schema::table('lynomia_store_addons', function (Blueprint $table) {
                $table->foreign('addon_id')->references('id')->on('lynomia_marketing_addons')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lynomia_store_addons');
        Schema::dropIfExists('lynomia_marketing_addons');

        if (Schema::hasTable('plans') && Schema::hasColumn('plans', 'lynomia_campaign_sends')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->dropColumn('lynomia_campaign_sends');
            });
        }
    }
};
