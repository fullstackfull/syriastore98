<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ZidStore extends Model
{
    use HasFactory;

    /**
     * الحقول المسموح بتعبئتها جماعياً (Mass Assignment)
     */
    protected $fillable = [
        'store_id',
        'authorization',
        'access_token',
        'refresh_token',
        'expires_at',
    ];

    /**
     * تحويل حقل expires_at إلى كائن Carbon تلقائياً
     * لتسهيل المقارنة والحسابات الزمنية
     */
    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * إخفاء الحقول الحساسة عند تحويل الموديل إلى Array أو JSON
     * لحماية البيانات عند عرضها في API أو Logs
     */
    protected $hidden = [
        'authorization',
        'access_token',
        'refresh_token',
    ];

    /**
     * دالة مساعدة لجلب التوكن المفكوك تشفيره بأمان
     * استخدام هذه الدالة أفضل من كتابة decrypt() يدوياً في كل مكان
     */
    public function getDecryptedAccessTokenAttribute(): ?string
    {
        return $this->access_token ? decrypt($this->access_token) : null;
    }

    public function getDecryptedAuthorizationAttribute(): ?string
    {
        return $this->authorization ? decrypt($this->authorization) : null;
    }

    public function getDecryptedRefreshTokenAttribute(): ?string
    {
        return $this->refresh_token ? decrypt($this->refresh_token) : null;
    }

    /**
     * التحقق مما إذا كانت التوكنات منتهية الصلاحية
     */
    public function isTokenExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
}