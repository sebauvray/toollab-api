<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Session « se connecter en tant que » d'un super-admin (lecture seule).
 */
class Impersonation extends Model
{
    public const TOKEN_NAME = 'impersonation';

    public const DURATION_MINUTES = 60;

    protected $fillable = [
        'admin_id', 'target_user_id', 'token_id', 'reason', 'ip_address', 'user_agent',
        'blocked_writes', 'started_at', 'expires_at', 'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
