<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtpIntegration extends Model
{
    protected $table = 'otp_integrations';

protected $fillable = [
    'store_id',
    'base_url',
    'api_secret',
    'otp_channel',
    'sms_mode',
    'sms_device',
    'sms_gateway',
    'sms_sim',
    'sms_priority',
    'whatsapp_account',
    'is_active',
];

protected $casts = [
    'api_secret' => 'encrypted',
    'is_active' => 'boolean',
    'sms_sim' => 'integer',
    'sms_priority' => 'integer',
];



}