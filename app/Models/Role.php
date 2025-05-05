<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Role extends Model
{
    protected $fillable = [
        'name',
        'description',
        'slug'
    ];

    public function userRoles()
    {
        return $this->hasMany(UserRole::class);
    }
}
