<?php

namespace Workdo\CouponEmail\app\Emails;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CouponEmailTemplate extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public $content;
    public $setting;
    public $subject;
    public function __construct($content, $setting,$subject)
    {
        $this->content  = $content;
        $this->setting  = $setting;
        $this->subject  = $subject;
    }

    /**
     * Build the message.
     */
    public function build(): self
    {
        $from = !empty($this->setting['MAIL_FROM_ADDRESS']) ? $this->setting['MAIL_FROM_ADDRESS'] : '';
        return
            $this
            ->from($this->setting['MAIL_FROM_ADDRESS'])
            ->subject($this->subject)
            ->markdown('email.common_email_template')
            ->with(
                [
                    'content' => $this->content,
                    'mail_header' => $this->setting['MAIL_FROM_ADDRESS'],
                ]
            );
    }
}
