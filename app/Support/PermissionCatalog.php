<?php

namespace App\Support;

/**
 * Catalogue des permissions, versionné avec le code : chaque clé correspond à
 * un contrôle d'accès dans l'API, une école ne peut donc pas en inventer.
 *
 * Les permissions sont regroupées par bloc du menu de gauche, une par action
 * (consulter, créer, modifier, supprimer…), chacune activable séparément.
 * La première permission d'un bloc est sa consultation : toute autre
 * permission du bloc l'inclut. Le privilège de direction reste non attribuable.
 */
class PermissionCatalog
{
    /**
     * Blocs attribuables, dans l'ordre du menu. 'requires' : permission d'un
     * autre bloc ajoutée automatiquement, sans laquelle le bloc ne sert à rien.
     */
    public const MODULES = [
        'school' => ['label' => 'Établissement', 'description' => 'Modifier les informations de l’école.', 'permissions' => [
            ['key' => 'school.settings.update', 'label' => 'Modifier les informations de l’école', 'description' => 'Dans cet établissement uniquement'],
        ]],
        'school_years' => ['label' => 'Années scolaires', 'description' => 'Créer, clôturer, reconduire les années et ouvrir les décisions.', 'permissions' => [
            ['key' => 'school_years.manage', 'label' => 'Créer, clôturer, reconduire les années et ouvrir les décisions', 'description' => 'Dans cet établissement uniquement'],
        ]],
        'roles' => ['label' => 'Rôles et permissions', 'description' => 'Créer et modifier les rôles et leurs permissions.', 'permissions' => [
            ['key' => 'roles.manage', 'label' => 'Créer et modifier les rôles et leurs permissions', 'description' => 'Dans cet établissement uniquement'],
        ]],

        'families' => [
            'label' => 'Familles',
            'description' => 'Fiches des familles, de leurs élèves et responsables.',
            'permissions' => [
                ['key' => 'families.view', 'label' => 'Consulter les familles', 'description' => 'Liste et fiches des familles, élèves et responsables'],
                ['key' => 'families.create', 'label' => 'Créer une famille', 'description' => 'Nouvelle famille avec son responsable'],
                ['key' => 'families.update', 'label' => 'Modifier une famille', 'description' => 'Ajouter et modifier les responsables, leurs coordonnées'],
                ['key' => 'families.delete', 'label' => 'Supprimer une famille', 'description' => 'Corbeille, restauration et suppression définitive'],
                ['key' => 'students.create', 'label' => 'Ajouter des élèves', 'description' => "Ajouter des élèves à une famille"],
                ['key' => 'students.update', 'label' => 'Modifier un élève', 'description' => 'Nom, date de naissance, genre…'],
                ['key' => 'students.delete', 'label' => 'Retirer un élève', 'description' => "Retirer un élève de sa famille et de ses classes de l'année"],
                ['key' => 'enrollments.create', 'label' => 'Inscrire dans une classe', 'description' => 'Choix des classes des élèves'],
                ['key' => 'enrollments.delete', 'label' => "Désinscrire d'une classe", 'description' => "Retirer une inscription depuis le choix des classes"],
                ['key' => 'comments.create', 'label' => 'Voir et ajouter un commentaire', 'description' => 'Consulter les commentaires de la famille et en ajouter'],
                ['key' => 'families.import', 'label' => 'Importer des familles', 'description' => "Import d'un fichier de familles et d'élèves"],
                ['key' => 'families.export', 'label' => 'Exporter les élèves', 'description' => 'Téléchargement de la liste des élèves'],
            ],
        ],
        'payments' => [
            'label' => 'Paiements',
            'description' => 'Paiements des familles et factures.',
            'requires' => 'families.view',
            'permissions' => [
                ['key' => 'payments.view', 'label' => 'Consulter les paiements', 'description' => 'Total, payé, reste à payer, détail et factures'],
                ['key' => 'payments.create', 'label' => 'Enregistrer un paiement', 'description' => 'Espèces, carte, chèques ou exonération'],
                ['key' => 'payments.update', 'label' => 'Modifier un paiement', 'description' => "Corriger un paiement déjà enregistré"],
                ['key' => 'payments.delete', 'label' => 'Supprimer un paiement', 'description' => 'Effacer un paiement enregistré'],
            ],
        ],
        'cursus' => [
            'label' => 'Cursus',
            'description' => "Programmes proposés par l'école et leurs niveaux.",
            'permissions' => [
                ['key' => 'cursus.view', 'label' => 'Consulter les cursus', 'description' => 'Cursus, niveaux et classes de chaque cursus'],
                ['key' => 'cursus.create', 'label' => 'Créer un cursus', 'description' => 'Nouveau cursus et ses niveaux'],
                ['key' => 'cursus.update', 'label' => 'Modifier un cursus', 'description' => 'Nom et niveaux'],
                ['key' => 'cursus.delete', 'label' => 'Supprimer un cursus', 'description' => 'Supprime aussi ses classes'],
            ],
        ],
        'classrooms' => [
            'label' => 'Classes',
            'description' => 'Classes, suivi des élèves, décisions et planning.',
            'permissions' => [
                ['key' => 'classrooms.view', 'label' => 'Consulter les classes', 'description' => 'Classes, suivi des élèves, décisions et planning'],
                ['key' => 'classrooms.create', 'label' => 'Créer une classe', 'description' => 'Nouvelle classe, professeur et créneaux'],
                ['key' => 'classrooms.update', 'label' => 'Modifier une classe', 'description' => 'Informations, professeur et créneaux'],
                ['key' => 'classrooms.delete', 'label' => 'Supprimer une classe', 'description' => 'Désinscrit aussi ses élèves'],
                ['key' => 'classrooms.remove_student', 'label' => "Retirer un élève d'une classe", 'description' => 'Depuis la liste des classes'],
                ['key' => 'classrooms.export', 'label' => 'Exporter les classes', 'description' => 'Téléchargement des classes et de leurs élèves'],
            ],
        ],
        'staff' => [
            'label' => 'Professeurs',
            'description' => "Équipe de l'école et planning des professeurs.",
            'permissions' => [
                ['key' => 'staff.view', 'label' => "Consulter l'équipe", 'description' => "Membres de l'équipe et planning des professeurs"],
                ['key' => 'staff.invite', 'label' => 'Inviter du personnel', 'description' => "Envoyer une invitation avec un rôle"],
                ['key' => 'staff.update', 'label' => 'Modifier un membre', 'description' => "Nom et coordonnées d'un membre de l'équipe"],
                ['key' => 'staff.roles', 'label' => 'Attribuer et retirer des rôles', 'description' => 'Uniquement des rôles moins étendus que le sien'],
                ['key' => 'staff.remove', 'label' => "Retirer de l'établissement", 'description' => "Retirer un membre et tous ses rôles dans l'école"],
            ],
        ],
        'tarification' => [
            'label' => 'Tarification',
            'description' => 'Tarifs des cursus et réductions.',
            'permissions' => [
                ['key' => 'tarification.view', 'label' => 'Consulter la tarification', 'description' => 'Tarifs des cursus et réductions'],
                ['key' => 'tarification.update', 'label' => 'Modifier les tarifs', 'description' => 'Tarif de base de chaque cursus'],
                ['key' => 'reductions.create', 'label' => 'Créer une réduction', 'description' => 'Réduction familiale ou multi-cursus'],
                ['key' => 'reductions.update', 'label' => 'Modifier une réduction', 'description' => 'Conditions et montant'],
                ['key' => 'reductions.delete', 'label' => 'Supprimer une réduction', 'description' => 'Ne touche pas aux inscriptions déjà faites'],
            ],
        ],
        'statistics' => [
            'label' => 'Statistiques',
            'description' => 'Tableaux de bord financiers et exports.',
            'permissions' => [
                ['key' => 'statistics.view', 'label' => 'Consulter les statistiques', 'description' => 'Inscriptions, recettes, impayés, chèques et exonérations'],
                ['key' => 'statistics.export', 'label' => 'Exporter les statistiques', 'description' => 'Téléchargement des paiements et des impayés'],
            ],
        ],
        'teaching' => [
            'label' => 'Espace professeur',
            'description' => 'Mes classes et Mon planning, limités à ses propres classes.',
            'permissions' => [
                ['key' => 'teaching.access', 'label' => "Accéder à l'espace professeur", 'description' => 'Ses classes, ses élèves et son planning'],
                ['key' => 'teaching.attendance', 'label' => "Faire l'appel", 'description' => 'Présences et absences de ses classes'],
                ['key' => 'teaching.outcomes', 'label' => 'Saisir les décisions', 'description' => "Décisions de fin d'année de ses classes"],
            ],
        ],
    ];

    /** Privilège de direction non attribuable : autorité sur la hiérarchie des rôles. */
    public const SCHOOL_MANAGE = 'school.manage';

    /** Rôles staff copiés dans chaque école, dans l'ordre d'affichage. */
    public const STAFF_SLUGS = ['director', 'admin', 'registar', 'teacher'];

    /** Rôles qu'une école ne peut ni modifier ni supprimer. */
    public const LOCKED_SLUGS = ['director'];

    /** Toutes les permissions connues (clé => [group, label]), non attribuables comprises. */
    public static function definitions(): array
    {
        $definitions = [];
        foreach (self::MODULES as $module) {
            foreach ($module['permissions'] as $permission) {
                $definitions[$permission['key']] = ['group' => $module['label'], 'label' => $permission['label']];
            }
        }
        $definitions[self::SCHOOL_MANAGE] = ['group' => 'Établissement', 'label' => "Autorité de direction"];

        return $definitions;
    }

    /** Clé => libellé. */
    public static function all(): array
    {
        return array_map(fn ($definition) => $definition['label'], self::definitions());
    }

    /** Permissions qu'un rôle de l'école peut recevoir. */
    public static function assignable(): array
    {
        return array_values(array_diff(array_keys(self::all()), [self::SCHOOL_MANAGE]));
    }

    /** Toute permission d'un bloc inclut sa consultation, un bloc inclut ce qu'il requiert (jusqu'à stabilité). */
    public static function normalize(array $keys): array
    {
        do {
            $before = count($keys = array_values(array_unique($keys)));
            foreach (self::MODULES as $module) {
                $moduleKeys = array_column($module['permissions'], 'key');
                $base = $moduleKeys[0];
                if (array_intersect($moduleKeys, $keys)) {
                    $keys[] = $base;
                }
                if (isset($module['requires']) && in_array($base, $keys, true)) {
                    $keys[] = $module['requires'];
                }
            }
            $keys = array_values(array_unique($keys));
        } while (count($keys) !== $before);

        return $keys;
    }

    /** Clés d'un bloc. */
    public static function moduleKeys(string $module): array
    {
        return array_column(self::MODULES[$module]['permissions'], 'key');
    }

    /** Permissions des rôles par défaut. */
    public static function defaultsFor(string $slug): array
    {
        $teaching = self::moduleKeys('teaching');
        $managers = array_values(array_diff(self::assignable(), $teaching));

        return match ($slug) {
            'director' => [...$managers, self::SCHOOL_MANAGE],
            'admin' => $managers,
            'registar' => [
                'families.view', 'families.create', 'families.update',
                'students.create', 'students.update', 'students.delete',
                'enrollments.create', 'enrollments.delete', 'comments.create',
                ...self::moduleKeys('payments'),
            ],
            'teacher' => $teaching,
            default => [],
        };
    }

    /** Blocs au format de l'API (écran de gestion des rôles). */
    public static function modulesForApi(): array
    {
        return collect(self::MODULES)
            ->map(fn ($module, $key) => [
                'key' => $key,
                'label' => $module['label'],
                'description' => $module['description'],
                'requires' => $module['requires'] ?? null,
                'permissions' => $module['permissions'],
            ])
            ->values()
            ->all();
    }
}
