<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filtre toutes les requêtes par school_id = currentSchoolId().
 * Fail-closed : 0 ligne renvoyée si aucune école courante (sécurité par défaut).
 * Bypass volontaire via ::withoutGlobalScope(BelongsToSchoolScope::class).
 */
class BelongsToSchoolScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $schoolId = currentSchoolId();

        if ($schoolId === null) {
            $builder->whereRaw('0 = 1');

            return;
        }

        $builder->where($model->getTable() . '.school_id', $schoolId);
    }
}
