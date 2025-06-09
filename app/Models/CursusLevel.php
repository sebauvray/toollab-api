<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CursusLevel extends Model
{
    use HasFactory;

    protected $fillable = [
        'cursus_id',
        'name',
        'order',
    ];


    public function cursus(): BelongsTo
    {
        return $this->belongsTo(Cursus::class);
    }


    public function classrooms(): HasMany
    {
        return $this->hasMany(Classroom::class, 'level_id');
    }
}
