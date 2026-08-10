<?php

namespace Azuriom\Plugin\Vouchers\Providers;

use Azuriom\Extensions\Plugin\BasePluginServiceProvider;
use Azuriom\Models\ActionLog;
use Azuriom\Models\Permission;
use Azuriom\Plugin\Vouchers\Commands\ProcessDeliveriesCommand;
use Azuriom\Plugin\Vouchers\Models\Reward;
use Azuriom\Plugin\Vouchers\Models\Voucher;
use Illuminate\Console\Scheduling\Schedule;

class VouchersServiceProvider extends BasePluginServiceProvider
{
    /**
     * Bootstrap the plugin services.
     */
    public function boot(): void
    {
        $this->loadViews();
        $this->loadTranslations();
        $this->loadMigrations();
        $this->registerRouteDescriptions();
        $this->registerAdminNavigation();
        $this->registerSchedule();

        $this->commands(ProcessDeliveriesCommand::class);

        Permission::registerPermissions([
            'vouchers.admin' => 'vouchers::admin.permission',
        ]);

        ActionLog::registerLogModels([
            Voucher::class,
            Reward::class,
        ], 'vouchers::admin.logs');
    }

    /**
     * Process deferred rewards and close abandoned delivery claims.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('vouchers:deliveries')
            ->everyFiveMinutes()
            ->withoutOverlapping(15);
    }

    /**
     * Return the routes that can be added to the site navigation.
     *
     * @return array<string, string>
     */
    protected function routeDescriptions(): array
    {
        return [
            'vouchers.index' => trans('vouchers::messages.title'),
        ];
    }

    /**
     * Return the plugin entries for the administration navigation.
     *
     * @return array<string, array<string, string>>
     */
    protected function adminNavigation(): array
    {
        return [
            'vouchers' => [
                'name' => trans('vouchers::admin.title'),
                'icon' => 'bi bi-ticket-perforated',
                'permission' => 'vouchers.admin',
                'route' => 'vouchers.admin.codes.index',
            ],
        ];
    }
}
