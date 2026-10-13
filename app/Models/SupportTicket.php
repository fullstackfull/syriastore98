<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupportTicket extends Model
{
    use HasFactory, Cachable, BelongsToStore;

    protected $fillable = [
        'order_id',
        'ticket_id',
        'customer_id',
        'title',
        'description',
        'attachment',
        'answer',
        'status',
        
        'store_id',
        'created_by'
    ];

    public function UserData()
    {
        return $this->hasOne(Customer::class, 'id',
        'customer_id');
    }

    public function conversions()
    {
        return $this->hasMany(SupportConversion::class, 'ticket_id', 'id')->orderBy('id');
    }

    public function orderData()
    {
        return $this->hasOne(Order::class, 'id',
        'order_id');
    }

    public static function ticket_status_change($date = [])
    {
        $ticket_id = $date['ticket_id'];
        $ticket_status = $date['ticket_status'];

        $ticket = SupportTicket::find($ticket_id);
        if(!empty($ticket)) {
            if($ticket_status == 'open' ) {
                $ticket->status = $ticket_status;
                $ticket->save();

                $return['status'] = 'success';
                $return['message'] = 'Ticket status changed.';
                return $return;
            }
            if($ticket_status == 'In Progress') {
                $ticket->status = $ticket_status;
                $ticket->save();

                $return['status'] = 'success';
                $return['message'] = 'Ticket status changed.';
                return $return;
            }

            if($ticket_status ==  'Solved')
            {

                $ticket->status = $ticket_status;
                $ticket->save();

                $return['status'] = 'success';
                $return['message'] = 'Ticket status changed.';
                return $return;
            }
        } else {
            $return['status'] = 'error';
            $return['message'] = 'Ticket not found.';
            return $return;
        }
    }
}
