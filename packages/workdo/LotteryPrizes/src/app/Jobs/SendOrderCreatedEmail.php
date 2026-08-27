<?php

namespace Workdo\LotteryPrizes\app\Jobs;

use App\Mail\CommonEmailTemplate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Workdo\LotteryPrizes\app\Http\Controllers\LotteryPrizesController;
class SendOrderCreatedEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $orderEmail;
    protected $orderId;
    protected $owner;
    protected $store;

    /**
     * Create a new job instance.
     */
    public function __construct($orderEmail, $orderId, $owner, $store)
    {
        $this->orderEmail = $orderEmail;
        $this->orderId = $orderId;
        $this->owner = $owner;
        $this->store = $store;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        LotteryPrizesController::sendOwnerEmailTemplate(
            'Order Created',
            $this->orderEmail,
            '',
            ['order_id' => $this->orderId],
            $this->owner,
            $this->store
        );
    }
}
