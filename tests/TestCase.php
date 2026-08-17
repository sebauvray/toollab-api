<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Bases sur lesquelles il est permis de lancer la suite de tests.
     */
    private const ALLOWED_TEST_DATABASES = ['testing', ':memory:'];

    /**
     * Garde-fou : refuse de tourner sur autre chose qu'une base de test.
     *
     * Le conteneur de dev exécute `php artisan config:cache` à chaque démarrage
     * (docker/php/entrypoint.sh). Or une config en cache court-circuite env() :
     * les <env> de phpunit.xml, dont DB_DATABASE=testing, sont alors ignorées et
     * la suite se rabat sur la base applicative. Comme RefreshDatabase commence
     * par un migrate:fresh, lancer les tests détruit la base de développement.
     *
     * Le contrôle est fait dans refreshApplication() et non dans setUp(), car
     * setUpTraits() — donc RefreshDatabase — s'exécute juste après : au moment
     * où setUp() rend la main, la base est déjà effacée.
     */
    protected function refreshApplication()
    {
        parent::refreshApplication();

        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if (in_array($database, self::ALLOWED_TEST_DATABASES, true)) {
            return;
        }

        throw new RuntimeException(sprintf(
            "Tests interrompus : la connexion vise la base « %s », qui n'est pas une base de test.\n".
            "RefreshDatabase l'aurait effacée.\n\n".
            "Cause probable : une config en cache masque les variables de phpunit.xml.\n".
            "Correctif : docker exec %s php artisan config:clear",
            $database,
            env('CONTAINER_NAME_API', 'api_dev_toollab')
        ));
    }
}
