<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Classroom extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'years',
        'type',
        'size',
        'school_id',
        'cursus_id',
        'level_id',
        'gender'
    ];

    public function roles(): MorphToMany
    {
        return $this->morphToMany(Role::class, 'roleable');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function cursus(): BelongsTo
    {
        return $this->belongsTo(Cursus::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(CursusLevel::class, 'level_id');
    }

    public function userRoles()
    {
        return $this->morphMany(UserRole::class, 'roleable');
    }
}
