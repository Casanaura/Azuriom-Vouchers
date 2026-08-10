<?php

namespace Azuriom\Plugin\Vouchers\Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /**
     * Create an Azuriom application isolated from its cached configuration.
     */
    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');

        return $app;
    }

    /**
     * Create only the plugin tables in a new in-memory database.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $migration = require dirname(__DIR__).'/database/migrations/2026_08_10_000000_create_vouchers_tables.php';
        $migration->up();
    }
}
