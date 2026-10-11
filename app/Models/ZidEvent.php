<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ZidEvent extends Model
{
    use HasFactory;

    /**
     * الحقول المسموح بتعبئتها جماعياً (Mass Assignment)
     */
    protected $fillable = [
        'store_id',
        'event',
        'payload',
        'processed_at',
    ];

    /**
     * تحويل حقل payload إلى Array تلقائياً عند القراءة
     * وتحويله إلى JSON عند الكتابة في قاعدة البيانات
     */
    protected $casts = [
        'payload'      => 'array',
        'processed_at' => 'datetime',
    ];

    /**
     * نطاق (Scope) لجلب الأحداث غير المعالجة فقط
     * استخدام النطاقات يجعل الكود أنظف وأكثر قابلية للقراءة
     */
    public function scopeUnprocessed($query)
    {
        return $query->whereNull('processed_at');
    }

    /**
     * نطاق لجلب أحداث متجر محدد ونوع حدث محدد
     */
    public function scopeForStoreAndEvent($query, string $storeId, string $eventName)
    {
        return $query->where('store_id', $storeId)->where('event', $eventName);
    }

    /**
     * دالة مساعدة لوضع علامة "تمت المعالجة" على الحدث
     * تضمن تحديث التاريخ بدقة باستخدام Carbon
     */
    public function markAsProcessed(): void
    {
        $this->update(['processed_at' => now()]);
    }

    /**
     * التحقق مما إذا كان الحدث قد تمت معالجته مسبقاً
     */
    public function isProcessed(): bool
    {
        return !is_null($this->processed_at);
    }
}