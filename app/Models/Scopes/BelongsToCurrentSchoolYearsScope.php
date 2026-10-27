<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restreint un modèle portant school_year_id aux années de l'école courante.
 * Pour les modèles sans school_id : l'école est atteinte via l'année.
 * Sans école courante (console, jobs en file), aucun filtre n'est appliqué.
 * Bypass volontaire via ::withoutGlobalScope(BelongsToCurrentSchoolYearsScope::class).
 */
class BelongsToCurrentSchoolYearsScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $schoolId = currentSchoolId();

        if ($schoolId === null) {
            return;
        }

        $builder->whereIn(
            $model->getTable().'.school_year_id',
            fn ($q) => $q->select('id')->from('school_years')->where('school_id', $schoolId)
        );
    }
}
