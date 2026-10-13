<?php

namespace Workdo\LynomiaChat\database\seeders;

use App\Models\AddOnManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

/**
 * Registers the module in the add-on registry.
 *
 * PRICED AT ZERO, DELIBERATELY. module.json carries monthly_price/yearly_price fields, but
 * nothing in this codebase charges for a module -- access comes from the plan's `modules`
 * CSV, and no purchase flow for an add-on exists. Putting a non-zero price here would
 * display a charge that is never collected. Commercial packaging of this integration is a
 * later phase and belongs in the plan builder, not here.
 */
class MarketPlaceSeederTableSeeder extends Seeder
{
    public function run(): void
    {
        Model::unguard();

        AddOnManager::updateOrCreate(
            ['module' => 'LynomiaChat'],
            [
                'name' => 'Lynomia Chat',
                'monthly_price' => 0,
                'yearly_price' => 0,
                'is_enable' => 1,
                'package_name' => 'lynomia-chat',
            ]
        );

        Model::reguard();
    }
}
