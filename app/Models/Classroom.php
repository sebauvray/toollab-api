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
    ];

    public function roles(): morphToMany
    {
        return $this->morphToMany(Role::class, 'roleable');
    }
    
    public function school(): belongsTo
    {
        return $this->belongsTo(School::class);
    }
}
