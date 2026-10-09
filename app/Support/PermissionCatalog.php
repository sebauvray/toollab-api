<?php

namespace App\Support;

/**
 * Catalogue des permissions, versionné avec le code : chaque clé correspond à
 * un contrôle d'accès dans l'API, une école ne peut donc pas en inventer.
 * Voir audit-tickets/inventaire-permissions.md pour la correspondance routes.
 */
class PermissionCatalog
{
    public const PERMISSIONS = [
        'school.settings.update' => ['group' => 'École', 'label' => "Modifier les informations de l'école"],
        'school_years.manage' => ['group' => 'École', 'label' => 'Créer, clôturer et reconduire les années scolaires'],
        'staff.view' => ['group' => 'Équipe', 'label' => "Voir l'équipe et les utilisateurs de l'école"],
        'staff.manage' => ['group' => 'Équipe', 'label' => 'Inviter du personnel, attribuer et retirer des rôles'],
        'roles.manage' => ['group' => 'Équipe', 'label' => 'Créer et modifier les rôles et leurs permissions'],
        'families.view' => ['group' => 'Familles', 'label' => "Voir toutes les familles de l'école"],
        'families.edit' => ['group' => 'Familles', 'label' => 'Créer et modifier familles, élèves, responsables, commentaires'],
        'families.import_export' => ['group' => 'Familles', 'label' => 'Importer et exporter les familles'],
        'families.delete' => ['group' => 'Familles', 'label' => 'Supprimer, restaurer et purger une famille'],
        'enrollments.manage' => ['group' => 'Pédagogie', 'label' => "Inscrire et désinscrire un élève d'une classe"],
        'cursus.manage' => ['group' => 'Pédagogie', 'label' => 'Gérer les cursus'],
        'classrooms.manage' => ['group' => 'Pédagogie', 'label' => 'Créer, modifier et supprimer des classes'],
        'classrooms.supervise' => ['group' => 'Pédagogie', 'label' => 'Suivi des classes, décisions, plannings, export'],
        'teaching.access' => ['group' => 'Pédagogie', 'label' => 'Espace professeur (ses classes : appel, résultats, planning)'],
        'tarification.manage' => ['group' => 'Finances', 'label' => 'Gérer tarifs et réductions'],
        'payments.edit' => ['group' => 'Finances', 'label' => 'Ajouter, modifier et supprimer des lignes de paiement'],
        'statistics.view' => ['group' => 'Finances', 'label' => 'Statistiques, impayés, chèques, exports'],
    ];

    /** Rôles staff copiés dans chaque école, dans l'ordre d'affichage. */
    public const STAFF_SLUGS = ['director', 'admin', 'registar', 'teacher'];

    /** Rôles qu'une école ne peut ni modifier ni supprimer. */
    public const LOCKED_SLUGS = ['director'];

    /**
     * Permissions des rôles par défaut : reproduit à l'identique les droits
     * codés en dur avant l'introduction des permissions.
     */
    public static function defaultsFor(string $slug): array
    {
        $all = array_keys(self::PERMISSIONS);

        return match ($slug) {
            'director' => array_values(array_diff($all, ['teaching.access'])),
            'admin' => array_values(array_diff($all, ['teaching.access', 'roles.manage'])),
            'registar' => ['families.view', 'families.edit', 'enrollments.manage', 'payments.edit'],
            'teacher' => ['teaching.access'],
            default => [],
        };
    }
}
