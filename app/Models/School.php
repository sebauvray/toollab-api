<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class School extends Model
{
    use HasFactory;

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

    public function userRoles()
    {
        return $this->morphMany(UserRole::class, 'roleable');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_roles')
            ->withPivot('role_id');
    }

    public function classrooms(): HasMany
    {
        return $this->hasMany(Classroom::class);
    }
}
