<?php

namespace Workdo\EMailBox\app\Models;

class DemoMessage
{
    private $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function getMsgn()
    {
        return $this->data['id'];
    }

    public function getFlags()
    {
        $flags = collect();
        if ($this->data['is_starred']) $flags->put('flagged', true);
        if ($this->data['is_read']) $flags->put('seen', true);
        return $flags;
    }

    public function getFrom()
    {
        return [(object)['mailbox' => explode('@', $this->data['from'])[0]]];
    }

    public function getSubject()
    {
        return $this->data['subject'];
    }

    public function getTextBody()
    {
        return strip_tags($this->data['body']);
    }
}