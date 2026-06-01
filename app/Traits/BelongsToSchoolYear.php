<?php

namespace App\Traits;

use App\Models\Scopes\BelongsToSchoolYearScope;
use App\Models\SchoolYear;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Isole un modèle par année scolaire : global scope sur school_year_id + auto-set à la création.
 * Combine avec BelongsToSchool (l'année est elle-même rattachée à une école).
 */
trait BelongsToSchoolYear
{
    protected static function bootBelongsToSchoolYear(): void
    {
        static::addGlobalScope(new BelongsToSchoolYearScope);

        static::creating(function ($model) {
            if (empty($model->school_year_id) && ($id = currentSchoolYearId()) !== null) {
                $model->school_year_id = $id;
            }
        });
    }

    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }
}
