<?php

namespace App\Models;

use App\Traits\TrackChangesTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LignePaiement extends Model
{
    use HasFactory, TrackChangesTrait;

    protected $table = 'lignes_paiement';

    /**
     * Pas de school_id ni d'année sur la ligne : on l'isole via son paiement,
     * dont les global scopes filtrent l'année et l'école courantes.
     * Sans école courante (console, jobs en file), aucun filtre.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('paiement_courant', function ($query) {
            if (currentSchoolId() !== null) {
                $query->whereHas('paiement');
            }
        });
    }

    protected $fillable = [
        'paiement_id',
        'type_paiement',
        'montant',
        'details',
    ];

    protected $casts = [
        'montant' => 'integer',
        'details' => 'array',
    ];

    public function paiement()
    {
        return $this->belongsTo(Paiement::class);
    }

    public function getChequeDetailsAttribute()
    {
        if ($this->type_paiement !== 'cheque' || ! $this->details) {
            return null;
        }

        return (object) $this->details;
    }
}
