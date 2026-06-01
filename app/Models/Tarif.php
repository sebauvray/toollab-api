<?php

namespace App\Models;

use App\Traits\BelongsToSchoolYear;
use App\Traits\TrackChangesTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tarif extends Model
{
    use HasFactory, TrackChangesTrait, BelongsToSchoolYear;

    protected $fillable = [
        'cursus_id',
        'prix',
        'actif',
            ];

    protected $casts = [
        'prix' => 'integer',
        'actif' => 'boolean'
    ];

    public function cursus()
    {
        return $this->belongsTo(Cursus::class);
    }
}
