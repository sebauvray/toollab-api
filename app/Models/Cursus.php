<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cursus extends Model
{
    protected $table = 'cursus';

    use HasFactory;

    protected $fillable = [
        'name',
        'progression',
        'school_id',
        'levels_count'
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function levels(): HasMany
    {
        return $this->hasMany(CursusLevel::class);
    }

    public function classrooms(): HasMany
    {
        return $this->hasMany(Classroom::class);
    }
}
