<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Paiement extends Model
{
    use HasFactory;

    protected $fillable = [
        'family_id',
        'created_by'
    ];

    protected $appends = ['montant_total'];

    public function family()
    {
        return $this->belongsTo(Family::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lignes()
    {
        return $this->hasMany(LignePaiement::class);
    }

    public function getMontantTotalAttribute()
    {
        return $this->lignes->sum('montant');
    }

    public function getMontantEspeceAttribute()
    {
        return $this->lignes()->where('type_paiement', 'espece')->sum('montant');
    }

    public function getMontantCarteAttribute()
    {
        return $this->lignes()->where('type_paiement', 'carte')->sum('montant');
    }

    public function getMontantChequeAttribute()
    {
        return $this->lignes()->where('type_paiement', 'cheque')->sum('montant');
    }

    public function getMontantExonerationAttribute()
    {
        return $this->lignes()->where('type_paiement', 'exoneration')->sum('montant');
    }
}
