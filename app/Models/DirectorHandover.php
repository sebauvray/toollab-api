<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use App\Traits\TrackChangesTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DirectorHandover extends Model
{
    use BelongsToSchool, TrackChangesTrait;

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    public const OUTGOING_ROLES = ['admin', 'registar', 'none'];

    public const TTL_DAYS = 7;

    protected $fillable = [
        'email',
        'outgoing_role',
        'remove_teacher_role',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected $casts = [
        'remove_teacher_role' => 'boolean',
        'expires_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isActionable(): bool
    {
        return $this->status === self::STATUS_PENDING && !$this->isExpired();
    }
}
