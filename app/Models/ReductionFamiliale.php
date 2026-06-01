<?php

namespace App\Models;

use App\Traits\BelongsToSchoolYear;
use App\Traits\TrackChangesTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReductionFamiliale extends Model
{
    use HasFactory, TrackChangesTrait, BelongsToSchoolYear;

    protected $fillable = [
        'cursus_id',
        'nombre_eleves_min',
        'pourcentage_reduction',
        'actif',
            ];

    protected $casts = [
        'pourcentage_reduction' => 'decimal:2',
        'actif' => 'boolean'
    ];

    public function cursus()
    {
        return $this->belongsTo(Cursus::class);
    }
}
