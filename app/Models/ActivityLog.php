<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLog extends Model
{
    protected string $type;

    protected ?string $description = null;

    protected ?int $subject_id = null;

    protected ?string $subject_type = null;

    protected ?array $old_values = null;

    protected ?array $new_values = null;

    protected ?string $ip_address = null;

    protected string $created_at;

    protected string $updated_at;

    protected $fillable = [
        'user_id',
        'type',
        'subject_type',
        'subject_id',
        'description',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
