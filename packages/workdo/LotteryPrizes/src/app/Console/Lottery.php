<?php

namespace Workdo\LotteryPrizes\app\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use App\Models\Store;
use App\Models\Product;
use App\Models\Customer;
use App\Models\User;
use App\Models\Utility;
use App\Models\Cart;
use App\Models\DeliveryAddress;
use App\Models\EmailTemplate;
use App\Models\UserEmailTemplate;
use Illuminate\Support\Facades\Crypt;
use Workdo\LotteryPrizes\app\Http\Controllers\LotteryPrizesController;
use Workdo\LotteryPrizes\app\Models\LotterySetting;

class Lottery extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'lottery:order';

    /**
     * The console command description.
     */
    protected $description = 'Command description.';

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        date_default_timezone_set('Asia/Kolkata');
        $currentDateTime = \Carbon\Carbon::now();
        $auctionProductIds = [];

        $lottery_setting = LotterySetting::get();
        foreach ($lottery_setting as $setting) {
            $startDate = \Carbon\Carbon::parse($setting->lottery_start_date . ' ' . $setting->lottery_start_time);
            $endDate = \Carbon\Carbon::parse($setting->lottery_end_date . ' ' . $setting->lottery_end_time);

            if ($endDate < $startDate) {
                $endDate->addDay();
            }
            $currentDateTime->setTimezone($startDate->getTimezone());

            if (
                $currentDateTime->year == $endDate->year &&
                $currentDateTime->month == $endDate->month &&
                $currentDateTime->day == $endDate->day &&
                $currentDateTime->hour == $endDate->hour &&
                $currentDateTime->minute == $endDate->minute
            ) {
                $lottery_products = Product::where('store_id', $setting->store_id)
                    ->where('id', $setting->lottery_products)
                    ->first();

                $Template = EmailTemplate::where('name', 'Lottery Remainder')->where('created_by', $lottery_products->created_by)->first();
                $UserEmailTemplate = UserEmailTemplate::where('user_id', $lottery_products->created_by)->where('template_id', $Template['id'])->first();
                $user = User::find($lottery_products->created_by);
                $store = Store::find($user->current_store);
                if (isset($UserEmailTemplate)) {
                    $dArr = [
                        'product_id' => $lottery_products->name,
                        'number_of_winner' => $setting->number_of_winner,
                    ];
                    $resp = LotteryPrizesController::sendOwnerEmailTemplate('Lottery Remainder', $user->email, $user->name, $dArr, $user, $store);
                }
            }
        }
    }

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['example', InputArgument::REQUIRED, 'An example argument.'],
        ];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['example', null, InputOption::VALUE_OPTIONAL, 'An example option.', null],
        ];
    }
}
