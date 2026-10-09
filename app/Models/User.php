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
            'password' => 'hashed',
        ];
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
