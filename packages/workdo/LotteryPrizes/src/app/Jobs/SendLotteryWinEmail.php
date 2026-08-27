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
class SendLotteryWinEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $templateName;
    protected $email;
    protected $firstname;
    protected $data;
    protected $user;
    protected $store;

    /**
     * Create a new job instance.
     */
    public function __construct($templateName, $email, $firstname, $data, $user, $store)
    {
        $this->templateName = $templateName;
        $this->email = $email;
        $this->firstname = $firstname;
        $this->data = $data;
        $this->user = $user;
        $this->store = $store;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        LotteryPrizesController::sendOwnerEmailTemplate(
            $this->templateName,
            $this->email,
            $this->firstname,
            $this->data,
            $this->user,
            $this->store
        );
    }
}
