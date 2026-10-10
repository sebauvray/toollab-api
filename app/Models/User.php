<?php

namespace App\Models;

use App\Notifications\CustomResetPasswordNotification;
use Illuminate\Auth\Passwords\CanResetPassword;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, CanResetPassword;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'access',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $appends = ['is_super_admin'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'became_staff_at' => 'datetime',
            'disabled_at' => 'datetime',
            'access' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * L'outil est réservé au staff des écoles. Les familles (responsables, élèves)
     * n'y ont pas d'espace pour l'instant, même si elles ont un compte.
     */
    private function schoolRoles()
    {
        return $this->roles()->whereIn('roleable_type', ['school', School::class]);
    }

    /** Staff d'au moins une école (adhésion acceptée). */
    public function isStaff(): bool
    {
        return $this->schoolRoles()->whereNotNull('accepted_at')->exists();
    }

    /** Écoles où l'utilisateur est staff (adhésion acceptée). */
    public function staffSchoolIds(): \Illuminate\Support\Collection
    {
        return $this->schoolRoles()->whereNotNull('accepted_at')->pluck('roleable_id')->unique()->values();
    }

    /** À appeler après une acceptation d'adhésion faite en requête de masse (sans événement Eloquent). */
    public function markAsStaff(): void
    {
        static::whereKey($this->id)->whereNull('became_staff_at')->update(['became_staff_at' => now()]);
    }

    public function isStaffOf(int $schoolId): bool
    {
        return $this->schoolRoles()->where('roleable_id', $schoolId)->whereNotNull('accepted_at')->exists();
    }

    /**
     * Super-admin, staff actuel ou ancien (il verra « aucune affectation »), ou
     * invité staff qui doit pouvoir se connecter pour accepter. Les comptes
     * purement famille (responsable, élève) n'ont pas accès à l'outil.
     */
    public function canLogIn(): bool
    {
        return $this->is_super_admin
            || $this->became_staff_at !== null
            || $this->schoolRoles()->exists();
    }

    public function getIsSuperAdminAttribute(): bool
    {
        return in_array($this->email, config('toollab.super_admin_emails', []), true);
    }

    /**
     * Permissions cumulées de tous les rôles acceptés de l'utilisateur dans
     * l'école. Une invitation en attente (accepted_at null) ne donne rien.
     * Mémorisées sur la requête HTTP, pas sur le modèle : une instance de User
     * peut survivre à un changement de rôles (tests, workers longs).
     */
    public function permissionKeysIn(int $schoolId): array
    {
        $cacheKey = "permissions.{$this->id}.{$schoolId}";
        $attributes = request()->attributes;

        if (!$attributes->has($cacheKey)) {
            $attributes->set($cacheKey, Permission::query()
                ->whereHas('roles.userRoles', fn ($q) => $q
                    ->where('user_id', $this->id)
                    ->whereIn('roleable_type', ['school', School::class])
                    ->where('roleable_id', $schoolId)
                    ->whereNotNull('accepted_at'))
                ->pluck('key')
                ->all());
        }

        return $attributes->get($cacheKey);
    }

    /** Vrai si l'utilisateur a au moins une des permissions dans l'école. */
    public function hasPermissionIn(int $schoolId, string ...$keys): bool
    {
        if ($this->is_super_admin) {
            return true;
        }

        return array_intersect($keys, $this->permissionKeysIn($schoolId)) !== [];
    }

    public function infos()
    {
        return $this->hasMany(UserInfo::class);
    }

    public function roles()
    {
        return $this->hasMany(UserRole::class);
    }

    // visibleRolesFilter : ces relations lisent user_roles en SQL brut, le global
    // scope du modèle UserRole ne s'y applique pas — on y rejoue la même règle.
    public function schools()
    {
        return $this->belongsToMany(School::class, 'user_roles')
            ->withPivot('role_id')
            ->where(visibleRolesFilter('user_roles'));
    }

    public function families()
    {
        return $this->belongsToMany(Family::class, 'user_roles')
            ->withPivot('role_id')
            ->where(visibleRolesFilter('user_roles'));
    }

    public function classrooms()
    {
        return $this->belongsToMany(Classroom::class, 'user_roles')
            ->withPivot('role_id')
            ->where(visibleRolesFilter('user_roles'));
    }

    public function studentClassrooms()
    {
        return $this->hasMany(StudentClassroom::class, 'student_id');
    }

    public function sendPasswordResetNotification($token)
    {
        $this->notify(new CustomResetPasswordNotification($token));
    }
}
