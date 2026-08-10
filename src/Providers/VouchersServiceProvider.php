<?php

namespace Azuriom\Plugin\Vouchers\Providers;

use Azuriom\Extensions\Plugin\BasePluginServiceProvider;
use Azuriom\Models\Permission;

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

        Permission::registerPermissions([
            'vouchers.admin' => 'vouchers::admin.permission',
        ]);
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
                'route' => 'vouchers.admin.index',
            ],
        ];
    }
}
