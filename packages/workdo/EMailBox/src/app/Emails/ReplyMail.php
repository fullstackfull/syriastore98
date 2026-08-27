<?php

namespace Workdo\EMailBox\app\Emails;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;


class ReplyMail extends Mailable
{

    use Queueable, SerializesModels;

    public $subject;
    public $content;
    public $to_mail;
    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($subject, $content,$to_mail)
    {
        $this->subject = $subject;
        $this->content = $content;
        $this->to_mail = $to_mail;
    }

    public function build()
    {

        return $this->to($this->to_mail)->subject($this->subject)
        ->withSwiftMessage(function ($message) {
            $message->getHeaders()->addTextHeader('In-Reply-To', '<'.$this->to_mail.'>');
            $message->getHeaders()->addTextHeader('References', '<'.$this->to_mail.'>');
        })
        ->markdown('email-box::mail.email_template', ['content' => $this->content]);
    
    }
}
