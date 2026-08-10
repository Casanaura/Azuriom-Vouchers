<?php

namespace Azuriom\Plugin\Vouchers\Tests;

use Azuriom\Http\Controllers\InstallController;
use Azuriom\Plugin\Shop\Models\Package;
use Azuriom\Plugin\Vouchers\Services\ShopPackageCatalog;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
            'LOG_CHANNEL' => 'null',
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
     * Create the optional Shop contract in SQLite and expose eligible packages.
     */
    protected function enableShopIntegration(): void
    {
        if (! class_exists(Package::class) || ! function_exists('currency')) {
            $this->markTestSkipped('The optional Shop plugin is not installed.');
        }

        Schema::create('shop_categories', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('parent_id')->nullable();
            $table->boolean('cumulate_purchases')->default(false);
            $table->boolean('cumulate_strict')->default(false);
            $table->boolean('single_purchase')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->string('icon')->nullable();
            $table->timestamps();
        });

        Schema::create('shop_packages', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('category_id');
            $table->string('name');
            $table->string('short_description');
            $table->text('description');
            $table->unsignedInteger('position')->default(0);
            $table->string('image')->nullable();
            $table->decimal('price', 14, 2)->default(0);
            $table->text('commands');
            $table->unsignedInteger('role_id')->nullable();
            $table->unsignedInteger('expired_role_id')->nullable();
            $table->text('required_roles')->nullable();
            $table->unsignedInteger('user_limit')->nullable();
            $table->string('user_limit_period')->nullable();
            $table->text('required_packages')->nullable();
            $table->boolean('has_quantity')->default(true);
            $table->boolean('is_enabled')->default(true);
            $table->decimal('money', 14, 2)->nullable();
            $table->decimal('giftcard_balance', 14, 2)->nullable();
            $table->boolean('custom_price')->default(false);
            $table->unsignedInteger('global_limit')->nullable();
            $table->string('global_limit_period')->nullable();
            $table->boolean('limits_no_expired')->default(false);
            $table->string('billing_type')->default('one-off');
            $table->string('billing_period')->nullable();
            $table->text('files')->nullable();
            $table->timestamps();
        });

        Schema::create('shop_payments', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('subscription_id')->nullable();
            $table->decimal('price', 14, 2);
            $table->char('currency', 3);
            $table->string('gateway_type');
            $table->string('status');
            $table->string('transaction_id')->nullable();
            $table->timestamps();
        });

        Schema::create('shop_payment_items', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('payment_id');
            $table->string('name');
            $table->decimal('price', 14, 2);
            $table->unsignedInteger('quantity');
            $table->nullableMorphs('buyable');
            $table->text('variables')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shop_variables', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('description');
            $table->string('type');
            $table->text('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->timestamps();
        });

        Schema::create('shop_package_variable', function (Blueprint $table) {
            $table->unsignedInteger('package_id');
            $table->unsignedInteger('variable_id');
            $table->unique(['package_id', 'variable_id']);
        });

        Schema::create('shop_giftcards', function (Blueprint $table) {
            $table->increments('id');
            $table->string('code')->unique();
            $table->decimal('original_balance', 14, 2);
            $table->decimal('balance', 14, 2);
            $table->timestamp('start_at')->nullable();
            $table->timestamp('expire_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shop_giftcard_payment', function (Blueprint $table) {
            $table->unsignedInteger('payment_id');
            $table->unsignedInteger('giftcard_id');
            $table->decimal('amount', 14, 2);
            $table->unique(['payment_id', 'giftcard_id']);
        });

        Relation::morphMap(['shop.packages' => Package::class]);

        $this->app->instance(ShopPackageCatalog::class, new class extends ShopPackageCatalog
        {
            public function isAvailable(): bool
            {
                return true;
            }

            public function find(int $packageId): ?Package
            {
                return Package::query()->find($packageId);
            }
        });
    }

    /**
     * Create a side-effect-free package which only grants site points.
     */
    protected function createShopPackage(float $money, string $name = 'Voucher package'): Package
    {
        $categoryId = DB::table('shop_categories')->insertGetId([
            'name' => 'Voucher rewards',
            'slug' => 'voucher-rewards-'.str()->random(8),
            'description' => null,
            'position' => 0,
            'parent_id' => null,
            'cumulate_purchases' => false,
            'cumulate_strict' => false,
            'single_purchase' => false,
            'is_enabled' => false,
            'icon' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Package::create([
            'category_id' => $categoryId,
            'name' => $name,
            'short_description' => 'Integration test package',
            'description' => 'Integration test package',
            'position' => 0,
            'price' => 0,
            'commands' => [],
            'required_roles' => null,
            'required_packages' => null,
            'has_quantity' => false,
            'is_enabled' => false,
            'money' => $money,
            'giftcard_balance' => null,
            'custom_price' => false,
            'limits_no_expired' => false,
            'billing_type' => 'one-off',
            'files' => [],
        ]);
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
