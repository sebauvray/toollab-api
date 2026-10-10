<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\TrackChangesTrait;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use App\Models\Scopes\VisibleUntilYearClosedScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserRole extends Model
{
    use TrackChangesTrait, SoftDeletes;

    /**
     * Même règle que Family : les rattachements coupés aujourd'hui restent
     * visibles dans les années déjà clôturées, sinon la fiche d'une famille
     * consultée en archive s'afficherait sans ses membres.
     */
    protected static function booted(): void
    {
        // Trace durable du statut staff (cf. User::canLogIn) : posée à la première
        // adhésion école acceptée, jamais retirée.
        static::saved(function (UserRole $userRole) {
            if ($userRole->accepted_at !== null
                && in_array($userRole->roleable_type, ['school', School::class], true)) {
                User::whereKey($userRole->user_id)->whereNull('became_staff_at')
                    ->update(['became_staff_at' => now()]);
            }
        });
    }

    public static function bootSoftDeletes(): void
    {
        static::addGlobalScope(new VisibleUntilYearClosedScope);
    }

    protected $fillable = [
        'user_id',
        'role_id',
        'roleable_id',
        'roleable_type',
        'accepted_at',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function roleable()
    {
        return $this->morphTo();
    }
}
