<?php

namespace Workdo\Helpdesk\database\seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;
use Workdo\Helpdesk\app\Models\HelpdeskCategory;
use Workdo\Helpdesk\app\Models\HelpdeskStatus;

class DefaultHelpdeskDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Model::unguard();

        $superAdmin = User::where('type', 'super admin')->first();
        $category = [
            'New Installation' => '#19a7d7',
            'Bug' => '#ff1414',
            'Support' => '#eea11b',
            'Questions' => '#7118dc',
        ];
        foreach ($category as $key => $value) {
            $check = HelpdeskCategory::where('name', $key)->exists();
            if ($check == false) {
                $category = HelpdeskCategory::create(
                    [
                        'name' => $key,
                        'color' => $value,
                        'created_by' => $superAdmin->id,
                        "created_at" => date('Y-m-d H:i:s'),
                        "updated_at" => date('Y-m-d H:i:s')
                    ]
                );
            }
        }

        $helpdeskStatus = [
            'Pending' => '#ffc107',
            'In Progress' => '#3da5d1',
            'On Hold' => '#f90606',
            'Close' => '#269733',
        ];

        foreach ($helpdeskStatus as $key => $value) {
            $checkStatus = HelpdeskStatus::where('status_name', $key)->exists();
            if ($checkStatus == false) {
                $category = HelpdeskStatus::create(
                    [
                        'status_name' => $key,
                        'status_color' => $value,
                        'created_by' => $superAdmin->id,
                        "created_at" => date('Y-m-d H:i:s'),
                        "updated_at" => date('Y-m-d H:i:s')
                    ]
                );
            }
        }
    }
}
