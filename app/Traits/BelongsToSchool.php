<?php

namespace App\Traits;

use App\Models\Scopes\BelongsToSchoolScope;
use App\Models\School;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Isole un modèle par école : global scope sur school_id + auto-set à la création.
 */
trait BelongsToSchool
{
    protected static function bootBelongsToSchool(): void
    {
        static::addGlobalScope(new BelongsToSchoolScope);

        static::creating(function ($model) {
            if (empty($model->school_id) && ($id = currentSchoolId()) !== null) {
                $model->school_id = $id;
            }
        });
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
