<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use App\Traits\TrackChangesTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchoolYear extends Model
{
    use HasFactory, BelongsToSchool, TrackChangesTrait;

    protected $fillable = [
        'label',
        'opened_at',
        'closed_at',
        'is_active',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function classrooms(): HasMany
    {
        return $this->hasMany(Classroom::class);
    }

    public function tarifs(): HasMany
    {
        return $this->hasMany(Tarif::class);
    }

    public function reductionFamiliales(): HasMany
    {
        return $this->hasMany(ReductionFamiliale::class);
    }

    public function reductionMultiCursuses(): HasMany
    {
        return $this->hasMany(ReductionMultiCursus::class);
    }

    public function paiements(): HasMany
    {
        return $this->hasMany(Paiement::class);
    }

    public function isOpen(): bool
    {
        return $this->is_active && $this->closed_at === null;
    }
}
