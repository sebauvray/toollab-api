<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use \Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Scopes\VisibleUntilYearClosedScope;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Family extends Model
{
    use HasFactory, BelongsToSchool, SoftDeletes;

    protected $fillable = [];

    protected $casts = [
        'purged_at' => 'datetime',
    ];

    /**
     * Archivée puis supprimée définitivement : la ligne survit en base mais
     * l'application ne la restaure plus et ne la liste plus dans l'archive.
     */
    public function isPurged(): bool
    {
        return $this->purged_at !== null;
    }

    /**
     * Remplace le scope de soft delete standard par sa variante consciente de
     * l'année consultée : une famille supprimée reste visible dans les années
     * déjà clôturées au moment de sa suppression.
     *
     * Une méthode définie dans la classe prend le pas sur celle du trait, et
     * VisibleUntilYearClosedScope hérite de SoftDeletingScope : withTrashed(),
     * onlyTrashed() et restore() continuent de fonctionner.
     */
    public static function bootSoftDeletes(): void
    {
        static::addGlobalScope(new VisibleUntilYearClosedScope);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function userRoles()
    {
        return $this->morphMany(UserRole::class, 'roleable');
    }

    public function users()
    {
        return $this->morphToMany(User::class, 'roleable', 'user_roles', 'roleable_id', 'user_id')
            ->withPivot('role_id')
            ->wherePivot('roleable_type', 'family')
            // Une relation pivot interroge user_roles en SQL brut : le global scope
            // du modèle UserRole ne s'applique pas ici, on y rejoue la même règle
            // de visibilité (voir VisibleUntilYearClosedScope).
            ->where(visibleRolesFilter('user_roles'));
    }

    public function responsibles()
    {
        return $this->morphToMany(User::class, 'roleable', 'user_roles', 'roleable_id', 'user_id')
            ->withPivot('role_id')
            ->wherePivot('roleable_type', 'family')
            ->where(visibleRolesFilter('user_roles'))
            ->whereHas('roles', function ($query) {
                $query->where('roleable_type', 'family')
                    ->where('roleable_id', $this->id)
                    ->whereHas('role', function ($q) {
                        $q->where('slug', 'responsible');
                    });
            });
    }

    public function students()
    {
        return $this->morphToMany(User::class, 'roleable', 'user_roles', 'roleable_id', 'user_id')
            ->withPivot('role_id')
            ->wherePivot('roleable_type', 'family')
            ->where(visibleRolesFilter('user_roles'))
            ->whereHas('roles', function ($query) {
                $query->where('roleable_type', 'family')
                    ->where('roleable_id', $this->id)
                    ->whereHas('role', function ($q) {
                        $q->where('slug', 'student');
                    });
            });
    }

    public function studentClassrooms()
    {
        return $this->hasMany(StudentClassroom::class);
    }
}
