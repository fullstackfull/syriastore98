<?php

namespace Workdo\Helpdesk\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class HelpdeskConversion extends Model
{
    use HasFactory, Cachable;

    protected $table = 'helpdesk_conversions';
    protected $fillable = ['ticket_id', 'description', 'attachments', 'sender'];

    public function replyBy()
    {
        if ($this->sender == 'user') {
            return $this->ticket;
        } else {
            return $this->hasOne('App\Models\User', 'id', 'sender')->first();
        }
    }

    public function ticket()
    {
        return $this->hasOne(HelpdeskTicket::class, 'id', 'ticket_id');
    }
}
