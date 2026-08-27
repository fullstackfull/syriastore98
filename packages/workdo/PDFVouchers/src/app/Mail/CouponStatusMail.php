<?php

namespace Workdo\PDFVouchers\app\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Workdo\PDFVouchers\app\Models\MailSetting;

class CouponStatusMail extends Mailable
{


    use Queueable, SerializesModels;

    public $data;
    public $pdfPath;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($data, $pdfPath)
    {
        $this->data = $data;
        $this->pdfPath = $pdfPath;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $store = getCurrentStore();
        $setting = MailSetting::where('store_id', $store)->first();
        return $this->view('pdfvouchers::mail_setting.coupen_mail')
            ->with(['data' => $this->data], compact("setting"))
            ->attach($this->pdfPath, [
                'as' => 'coupons.pdf',
                'mime' => 'application/pdf',
            ]);
    }
}

