<?php

namespace Workdo\PartialPayments\app\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Models\{Setting ,Store,User};
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Workdo\PartialPayments\app\Http\Controllers\PartialPaymentsController;
use Workdo\PartialPayments\app\Models\OrderPartialPayments;

class Payment extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'partial:payment';

    /**
     * The console command description.
     */
    protected $description = 'Handles partial payments';

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
        $partialPayments = OrderPartialPayments::where('payment', 'Second Payment')
        ->where('payment_status', 'Pending payment')
        ->where('payment_type', '-')
        ->where('payment_amount', '!=', 0)
        ->whereNotNull('payment_amount')
        ->get();

        if(isset($partialPayments))
        {
            foreach ($partialPayments as $partialPayment)
            {
                $settings = Setting::where('store_id', $partialPayment->store_id)->pluck('value', 'name')->toArray();
                $previous_record = OrderPartialPayments::where('id', '<', $partialPayment->id)->orderBy('id','desc')->first();
                $createdAtDate = $partialPayment->created_at;
                $remainder_duration = $settings['remainder_duration_period'] ?? 0;
                $remainder_duration_type = $settings['remainder_duration_type'] ?? '';

                if ($remainder_duration_type == 'month') {
                    $nextDate = $createdAtDate->addMonths($remainder_duration);
                } elseif ($remainder_duration_type == 'year') {
                    $nextDate = $createdAtDate->addYears($remainder_duration);
                } else {
                    $nextDate = $createdAtDate;
                }
                $store = getStoreById($partialPayment->store_id);
                $owner= User::find($store->created_by);
                $nextDateFormatted = $nextDate->format('Y-m-d');
                $currentDate = Carbon::now()->format('Y-m-d');
                if ($nextDateFormatted === $currentDate) {
                    // Send email
                    try
                    {
                        $dArr  = [
                            'amount' => $previous_record->payment_amount,
                            'date' => $previous_record->created_at->format('Y-m-d'),
                            'remaining_amount' => $partialPayment->payment_amount,
                            'remaining_date' => $nextDateFormatted,
                            'customer_name' => $partialPayment->CustomerData->name
                        ];
                        $payment_id = Crypt::encrypt($partialPayment->id);

                        $resp = PartialPaymentsController::sendOwnerEmailTemplate('Payment Reminder', $partialPayment->CustomerData->email, $dArr, $owner, $store, $payment_id);
                        Log::info([$resp]);

                    }
                    catch(\Exception $e)
                    {
                        $smtp_error = __('E-Mail has been not sent due to SMTP configuration');
                    }

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
            // Define your arguments here if needed
        ];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            // Define your options here if needed
        ];
    }
}
