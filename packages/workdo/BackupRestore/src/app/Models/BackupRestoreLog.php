<?php

namespace Workdo\BackupRestore\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BackupRestoreLog extends Model
{
    use HasFactory, Cachable;

    protected $table = 'backup_restore_logs';
    
    protected $fillable = [
        'file_name',
        'type',
        'created_by',
        'store_id'
    ];

    public $timestamps = true;
}
