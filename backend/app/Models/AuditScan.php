<?php

namespace App\Models;

use App\Enums\ScanStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids; 
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuditScan extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'user_id',
        'ip_address',
        'source_type',
        'repo_name',
        'status',
        'health_score',
        'completed_at'
    ];

    protected function casts(): array
    {
        return [
            'status'       => ScanStatus::class,
            'health_score' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo 
    {
        return $this->belongsTo(User::class);
    }

    public function vulnerabilities(): HasMany
    {
        return $this->hasMany(Vulnerability::class, 'scan_id');
    }
}
