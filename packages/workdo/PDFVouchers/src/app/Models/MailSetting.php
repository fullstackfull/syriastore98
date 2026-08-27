<?php

namespace Workdo\PDFVouchers\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Workdo\PDFVouchers\Database\factories\MailSettingFactory;

class MailSetting extends Model
{
    use HasFactory, Cachable;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = ['store_id',  'subject', 'from', 'content'];

    protected static function newFactory(): MailSettingFactory
    {
        //return MailSettingFactory::new();
    }
}
