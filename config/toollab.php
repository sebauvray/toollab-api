<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Super-admins de la plateforme Toollab
    |--------------------------------------------------------------------------
    |
    | Liste d'emails (séparés par virgules dans .env) ayant accès à
    | l'interface d'administration globale : création/gestion d'écoles,
    | métriques cross-tenant, etc.
    |
    | Ce mécanisme volontairement simple évite d'avoir à gérer un
    | super-rôle en base. Pour ajouter/retirer un super-admin, on
    | modifie SUPER_ADMIN_EMAILS dans .env puis on redéploie.
    |
    */
    'super_admin_emails' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SUPER_ADMIN_EMAILS', ''))
    ))),
];
