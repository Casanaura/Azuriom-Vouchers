<?php

namespace Azuriom\Plugin\Vouchers\Tests;

use Azuriom\Http\Controllers\InstallController;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Create an Azuriom application isolated from its cached configuration.
     */
    public function createApplication(): Application
    {
        $configCache = __DIR__.'/cache/vouchers-config.php';

        if (is_file($configCache)) {
            throw new RuntimeException('Vouchers tests refuse to load a cached application configuration.');
        }

        $this->setEnvironmentVariables([
            'APP_ENV' => 'testing',
            'APP_KEY' => InstallController::TEMP_KEY,
            'APP_CONFIG_CACHE' => $configCache,
            'DB_CONNECTION' => 'sqlite',
            'DB_PATH' => ':memory:',
            'DB_URL' => '(null)',
        ]);

        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        if (config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:'
            || config('app.key') !== InstallController::TEMP_KEY) {
            throw new RuntimeException('Vouchers tests refuse to bootstrap an unsafe application environment.');
        }

        config([
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'app.previous_keys' => [],
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

        if (DB::connection('sqlite')->getDatabaseName() !== ':memory:') {
            throw new RuntimeException('Vouchers tests refuse to run outside SQLite memory.');
        }

        (require dirname(__DIR__, 3).'/database/migrations/2014_10_12_000000_create_users_table.php')->up();
        (require dirname(__DIR__, 3).'/database/migrations/2019_08_15_000000_create_roles_table.php')->up();

        foreach (glob(dirname(__DIR__).'/database/migrations/*.php') as $migrationPath) {
            $migration = require $migrationPath;
            $migration->up();
        }
    }

    /**
     * Set immutable test environment values before Laravel bootstraps providers.
     *
     * @param array<string, string> $variables
     */
    private function setEnvironmentVariables(array $variables): void
    {
        foreach ($variables as $name => $value) {
            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}
