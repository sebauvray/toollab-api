<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Variante du soft delete qui tient compte de l'année scolaire consultée.
 *
 * Une famille supprimée aujourd'hui ne doit pas s'effacer rétroactivement de
 * l'historique : elle existait bel et bien dans les années déjà clôturées à ce
 * moment-là. La règle est donc :
 *
 *   visible  si  deleted_at IS NULL
 *           ou   l'année consultée était close AVANT la suppression
 *                (deleted_at > année.closed_at)
 *
 * Sur l'année active, closed_at est nul : le comportement est strictement celui
 * du soft delete standard.
 *
 * On hérite de SoftDeletingScope (au lieu d'implémenter Scope) pour conserver
 * les macros withTrashed() / onlyTrashed() / restore() qu'il installe sur le
 * builder. Ces macros appellent withoutGlobalScope($this) : elles retirent donc
 * bien CETTE instance, enregistrée sous le nom de cette classe.
 */
class VisibleUntilYearClosedScope extends SoftDeletingScope
{
    public function apply(Builder $builder, Model $model): void
    {
        $column = $model->getQualifiedDeletedAtColumn();
        $closedAt = currentSchoolYearClosedAt();

        if ($closedAt === null) {
            $builder->whereNull($column);

            return;
        }

        $builder->where(function (Builder $query) use ($column, $closedAt) {
            $query->whereNull($column)
                ->orWhere($column, '>', $closedAt);
        });
    }
}
