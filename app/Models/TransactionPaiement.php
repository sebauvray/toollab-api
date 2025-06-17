<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionPaiement extends Model
{
    use HasFactory;

    protected $table = 'transactions_paiement';

    protected $fillable = [
        'family_id',
        'user_id',
        'type',
        'montant',
        'metadata'
    ];

    protected $casts = [
        'metadata' => 'array',
        'montant' => 'decimal:2'
    ];

    public function family()
    {
        return $this->belongsTo(Family::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeDebits($query)
    {
        return $query->whereIn('type', ['inscription', 'remboursement']);
    }

    public function scopeCredits($query)
    {
        return $query->whereIn('type', ['paiement', 'exoneration', 'annulation_inscription']);
    }
}
