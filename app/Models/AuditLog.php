<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Journal des actions sensibles (rôles, retraits, suppressions, passations…).
 * Écrit uniquement via App\Support\Audit.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_id', 'actor_label', 'impersonation_id', 'action', 'school_id',
        'subject_type', 'subject_id', 'subject_label', 'meta', 'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
