<?php

namespace Workdo\EMailBox\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EmailboxCredentail extends Model
{
    use HasFactory, Cachable;

    protected $tabel = "emailbox_credentails";
    protected $fillable = [
        "emailbox_mail_driver",
        "emailbox_mail_host",
        "emailbox_outgoing_port",
        "emailbox_incoming_port",
        "emailbox_mail_username",
        "emailbox_mail_from_address",
        "emailbox_mail_password",
        "emailbox_mail_encryption",
        "emailbox_mail_from_name",
        "store_id",
        "created_by"
    ];
}
