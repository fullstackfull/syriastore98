<?php

namespace Workdo\SpinToWinReward\database\seeders;

use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;
use Workdo\SpinToWinReward\app\Models\SpinToWinLabels;

class SpinToWinLabelSeederDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $staticLabels = [
            'Not Lucky',
            'Next Time!',
            'No Luck Today!',
            'So Close',
            'Almost There',
            'You Got It!',
            'Better Luck Next Time'
        ];

        $labelQuery = SpinToWinLabels::query();

        foreach ($staticLabels as $staticLabel) {
            $exist = (clone $labelQuery)->where('name', $staticLabel)->where('store_id', 1)->first();
            if (!$exist) {
                (clone $labelQuery)->create([
                    'name' => $staticLabel,
                    'store_id' => 1
                ]);
            }
        }
    }
}
