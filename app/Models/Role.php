<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = [
        'school_id',
        'name',
        'description',
        'slug',
        'is_locked',
    ];

    protected $casts = [
        'is_locked' => 'boolean',
    ];

    public function userRoles()
    {
        return $this->hasMany(UserRole::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }

    /** Rôles globaux : modèles staff et rôles famille (student, responsible). */
    public function scopeGlobal(Builder $query): Builder
    {
        return $query->whereNull('school_id');
    }

    public function scopeForSchool(Builder $query, int $schoolId): Builder
    {
        return $query->where('school_id', $schoolId);
    }

    /**
     * Rôle staff propre à une école. Les rôles staff sont copiés par école :
     * un Role::where('slug', …) seul renverrait le modèle global ou la copie
     * d'une autre école.
     */
    public static function staffFor(int $schoolId, string $slug): self
    {
        return static::forSchool($schoolId)->where('slug', $slug)->firstOrFail();
    }
}
