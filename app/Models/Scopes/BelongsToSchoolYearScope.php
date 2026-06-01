<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filtre toutes les requêtes par school_year_id = currentSchoolYearId().
 * Si aucune année courante n'est résolue, on laisse passer (pas de fail-closed)
 * car le scope BelongsToSchoolScope filtre déjà par école.
 * Bypass volontaire via ::withoutGlobalScope(BelongsToSchoolYearScope::class).
 */
class BelongsToSchoolYearScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $yearId = currentSchoolYearId();

        if ($yearId === null) {
            return;
        }

        $builder->where($model->getTable() . '.school_year_id', $yearId);
    }
}
