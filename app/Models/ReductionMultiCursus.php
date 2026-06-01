<?php

namespace App\Models;

use App\Traits\BelongsToSchoolYear;
use App\Traits\TrackChangesTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReductionMultiCursus extends Model
{
    use HasFactory, TrackChangesTrait, BelongsToSchoolYear;

    protected $fillable = [
        'cursus_beneficiaire_id',
        'cursus_requis_id',
        'pourcentage_reduction',
        'actif',
            ];

    protected $casts = [
        'pourcentage_reduction' => 'decimal:2',
        'actif' => 'boolean'
    ];

    public function cursusBeneficiaire()
    {
        return $this->belongsTo(Cursus::class, 'cursus_beneficiaire_id');
    }

    public function cursusRequis()
    {
        return $this->belongsTo(Cursus::class, 'cursus_requis_id');
    }
}
