<?php

namespace App\Models;

use App\Services\SchoolRoleProvisioner;
use App\Support\PermissionCatalog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class School extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        // Chaque école naît avec ses propres rôles staff. Sans modèles globaux
        // (RoleSeeder pas encore passé), rien à copier.
        static::created(function (School $school) {
            if (Role::global()->whereIn('slug', PermissionCatalog::STAFF_SLUGS)->exists()) {
                app(SchoolRoleProvisioner::class)->provision($school->id);
            }
        });
    }

    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'zipcode',
        'city',
        'country',
        'logo',
        'access',
        'siret',
        'vat_mode',
        'vat_number',
    ];

    /** Directeur en poste (adhésion acceptée), ou null. */
    public function director(): ?User
    {
        return $this->userRoles()
            ->whereNotNull('accepted_at')
            ->whereHas('role', fn ($q) => $q->where('slug', 'director'))
            ->with('user')
            ->first()
            ?->user;
    }

    public function isSuspended(): bool
    {
        return ! $this->access;
    }

    protected function casts(): array
    {
        return [
            'access' => 'boolean',
            'suspended_at' => 'datetime',
        ];
    }

    public function userRoles()
    {
        return $this->morphMany(UserRole::class, 'roleable');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_roles')
            ->withPivot('role_id')
            // Le global scope de UserRole ne s'applique pas sur un pivot.
            ->where(visibleRolesFilter('user_roles'));
    }

    public function classrooms(): HasMany
    {
        return $this->hasMany(Classroom::class);
    }
}
