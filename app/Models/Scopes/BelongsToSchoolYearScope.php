<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filtre toutes les requêtes par school_year_id = currentSchoolYearId().
 * Sans année courante mais avec une école courante (routes école hors
 * middleware schoolyear), on se rabat sur les années de cette école : ces
 * modèles n'ont pas de school_id, sans ce repli ils seraient lisibles
 * toutes écoles confondues.
 * Sans aucun contexte (console, jobs en file), aucun filtre n'est appliqué.
 * Bypass volontaire via ::withoutGlobalScope(BelongsToSchoolYearScope::class).
 */
class BelongsToSchoolYearScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $yearId = currentSchoolYearId();

        if ($yearId === null) {
            (new BelongsToCurrentSchoolYearsScope)->apply($builder, $model);

            return;
        }

        $builder->where($model->getTable().'.school_year_id', $yearId);
    }
}
