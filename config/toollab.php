<?php

return [
    'super_admin_emails' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SUPER_ADMIN_EMAILS', ''))
    ))),

    // Renseignés au déploiement (affichés dans /admin)
    'version' => env('APP_VERSION'),
    'commit' => env('GIT_COMMIT'),

    'display_timezone' => env('APP_DISPLAY_TIMEZONE', 'Europe/Paris'),
];
