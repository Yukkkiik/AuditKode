<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiRefactoring extends Model
{
    use HasFactory;

    protected $fillable = [
        'vulnerability_id',
        'original_code',
        'suggested_code',
        'explanation',
        'prompt_tokens',
        'fallback_mode',
    ];

    public function casts(): array
    {
        return [
            'fallback_mode' => 'boolean',
            'prompt_tokens' => 'integer',
        ];
    }

    public function vulnerabilities(): BelongsTo
    {
        return $this->belongsTo(Vulnerability::class);
    }
}
