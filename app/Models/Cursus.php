<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use App\Traits\TrackChangesTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cursus extends Model
{
    use HasFactory, TrackChangesTrait, BelongsToSchool;

    protected $table = 'cursus';

    protected $fillable = [
        'name',
        'progression',
        'levels_count',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function classrooms()
    {
        return $this->hasMany(Classroom::class);
    }

    public function levels()
    {
        return $this->hasMany(CursusLevel::class);
    }

    public function tarif()
    {
        return $this->hasOne(Tarif::class)->where('actif', true);
    }

    public function reductionsFamiliales()
    {
        return $this->hasMany(ReductionFamiliale::class)->where('actif', true)->orderBy('nombre_eleves_min');
    }

    public function reductionsMultiCursusBeneficiaire()
    {
        return $this->hasMany(ReductionMultiCursus::class, 'cursus_beneficiaire_id')->where('actif', true);
    }

    public function reductionsMultiCursusRequis()
    {
        return $this->hasMany(ReductionMultiCursus::class, 'cursus_requis_id')->where('actif', true);
    }
}
