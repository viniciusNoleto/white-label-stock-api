<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase {

    /**
     * A sessão vive em cookies: as requisições JSON dos testes sempre os enviam
     * (no Laravel, `getJson`/`postJson` só mandam cookies com `withCredentials`).
     */
    protected function setUp(): void {
        parent::setUp();

        $this->withCredentials();
    }
}
