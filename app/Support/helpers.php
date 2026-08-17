<?php

if (!function_exists('currentSchoolId')) {
    function currentSchoolId(): ?int
    {
        $request = request();
        if (!$request) {
            return null;
        }

        $value = $request->attributes->get('current_school_id');

        return is_int($value) ? $value : null;
    }
}

if (!function_exists('currentSchoolYearId')) {
    function currentSchoolYearId(): ?int
    {
        $request = request();
        if (!$request) {
            return null;
        }

        $value = $request->attributes->get('current_school_year_id');

        return is_int($value) ? $value : null;
    }
}

if (!function_exists('visibleRolesFilter')) {
    /**
     * Contrainte à appliquer aux relations qui lisent user_roles comme table
     * pivot : une relation pivot est du SQL brut, le global scope du modèle
     * UserRole ne s'y applique pas. On y rejoue donc la même règle que
     * VisibleUntilYearClosedScope.
     *
     * Usage : ->where(visibleRolesFilter('user_roles'))
     */
    function visibleRolesFilter(string $table): Closure
    {
        return function ($query) use ($table) {
            $query->whereNull($table.'.deleted_at');

            $closedAt = currentSchoolYearClosedAt();
            if ($closedAt !== null) {
                $query->orWhere($table.'.deleted_at', '>', $closedAt);
            }
        };
    }
}

if (!function_exists('currentSchoolYearClosedAt')) {
    /**
     * Date de clôture de l'année courante, ou null si l'année est encore ouverte
     * (ou si aucune année n'est résolue).
     *
     * Sert à la visibilité des familles supprimées : une famille effacée APRÈS
     * la clôture d'une année reste visible quand on consulte cette année-là.
     * Le résultat est mémorisé dans la requête — le scope est évalué à chaque
     * requête Eloquent, on ne veut pas un SELECT sur school_years à chaque fois.
     */
    function currentSchoolYearClosedAt(): ?\Illuminate\Support\Carbon
    {
        $request = request();
        $yearId = currentSchoolYearId();

        if (!$request || $yearId === null) {
            return null;
        }

        $cacheKey = 'current_school_year_closed_at_'.$yearId;

        if ($request->attributes->has($cacheKey)) {
            return $request->attributes->get($cacheKey);
        }

        $closedAt = \App\Models\SchoolYear::query()
            ->withoutGlobalScopes()
            ->whereKey($yearId)
            ->value('closed_at');

        $closedAt = $closedAt ? \Illuminate\Support\Carbon::parse($closedAt) : null;
        $request->attributes->set($cacheKey, $closedAt);

        return $closedAt;
    }
}
