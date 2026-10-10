<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Feature flags par école.
 *
 * Le catalogue vit dans le code (clé, libellé, valeur par défaut) ; la table
 * school_features ne stocke que les surcharges décidées par le super-admin.
 * Pour ajouter un flag : une entrée ici, puis `feature:<clé>` sur les routes
 * concernées et `useSchoolFeatures()` côté front.
 */
class Features
{
    public const CATALOG = [
        'custom_roles' => [
            'label' => 'Rôles personnalisés',
            'description' => "Créer, modifier et supprimer des rôles et leurs permissions dans Paramètres. Désactivé : la matrice des rôles reste visible en lecture seule.",
            'default' => true,
        ],
    ];

    public static function enabled(string $feature, ?int $schoolId = null): bool
    {
        $schoolId ??= currentSchoolId();
        $default = self::CATALOG[$feature]['default'] ?? false;

        if (! $schoolId) {
            return $default;
        }

        return self::overridesFor($schoolId)[$feature] ?? $default;
    }

    /** @return array<string, bool> toutes les valeurs effectives pour une école */
    public static function forSchool(int $schoolId): array
    {
        $overrides = self::overridesFor($schoolId);

        return collect(self::CATALOG)
            ->map(fn ($def, $key) => $overrides[$key] ?? $def['default'])
            ->all();
    }

    public static function set(int $schoolId, string $feature, bool $enabled, ?int $userId): void
    {
        DB::table('school_features')->updateOrInsert(
            ['school_id' => $schoolId, 'feature' => $feature],
            ['enabled' => $enabled, 'updated_by' => $userId, 'updated_at' => now(), 'created_at' => now()]
        );
    }

    private static function overridesFor(int $schoolId): array
    {
        return DB::table('school_features')
            ->where('school_id', $schoolId)
            ->pluck('enabled', 'feature')
            ->map(fn ($v) => (bool) $v)
            ->all();
    }
}
