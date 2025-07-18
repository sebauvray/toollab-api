<?php

namespace App\Models;

use App\Traits\TrackChangesTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LignePaiement extends Model
{
    use HasFactory, TrackChangesTrait;

    protected $table = 'lignes_paiement';

    protected $fillable = [
        'paiement_id',
        'type_paiement',
        'montant',
        'details',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'montant' => 'integer',
        'details' => 'array'
    ];

    public function paiement()
    {
        return $this->belongsTo(Paiement::class);
    }

    public function getChequeDetailsAttribute()
    {
        if ($this->type_paiement !== 'cheque' || !$this->details) {
            return null;
        }

        return (object) $this->details;
    }
}
