<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use \Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Family extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'identifier'
    ];

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
            ->wherePivot('roleable_type', 'family');
    }

    public function responsibles()
    {
        return $this->morphToMany(User::class, 'roleable', 'user_roles', 'roleable_id', 'user_id')
            ->withPivot('role_id')
            ->wherePivot('roleable_type', 'family')
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
