<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FamilyImport extends Model
{
    protected $fillable = [
        'school_id',
        'school_year_id',
        'user_id',
        'original_filename',
        'stored_path',
        'status',
        'message',
        'summary',
        'errors',
        'error_count',
        'errors_truncated',
        'errors_limit',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'errors' => 'array',
            'errors_truncated' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
