<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tarif extends Model
{
    use HasFactory;

    protected $fillable = [
        'cursus_id',
        'prix',
        'actif'
    ];

    protected $casts = [
        'prix' => 'decimal:2',
        'actif' => 'boolean'
    ];

    public function cursus()
    {
        return $this->belongsTo(Cursus::class);
    }
}
