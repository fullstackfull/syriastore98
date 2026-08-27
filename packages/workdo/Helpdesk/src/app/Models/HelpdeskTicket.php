<?php

namespace Workdo\Helpdesk\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class HelpdeskTicket extends Model
{
    use HasFactory, Cachable;

    protected $table = 'helpdesk_tickets';
    protected $fillable = [
        'ticket_id', 'name', 'email', 'category_id', 'status_id', 'subject', 
        'attachments', 'description', 'note', 'user_id', 'business_id', 'created_by'
    ];

    public function getTicketCategory(){
        return $this->hasOne(HelpdeskCategory::class,'id','category_id');
    }

    public function getTicketStatus(){
        return $this->hasOne(HelpdeskStatus::class,'id','status_id');
    }

    public function createdBy(){
        return $this->hasOne(User::class,'id','created_by');
    }

    public function conversions()
    {
        return $this->hasMany(HelpdeskConversion::class, 'ticket_id', 'id')->orderBy('id');
    }
}
