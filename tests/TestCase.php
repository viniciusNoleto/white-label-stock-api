<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase {

    /**
     * Os testes sempre usam `${DB_DATABASE}_testing` (criado pelo init do Postgres e por
     * `make recreate-testing-database`): o nome sai do mesmo `.env`/ambiente da aplicação, sem ficar
     * fixo no `phpunit.xml`, e nunca aponta para o banco de desenvolvimento.
     *
     * @return Application
     */
    public function createApplication() {
        $app = parent::createApplication();

        $connection = $app['config']->get('database.default');
        $database = (string)$app['config']->get("database.connections.{$connection}.database");

        if (!str_ends_with($database, '_testing')) {
            $app['config']->set("database.connections.{$connection}.database", $database . '_testing');
        }

        return $app;
    }
}
