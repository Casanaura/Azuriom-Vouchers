<?php

namespace Azuriom\Plugin\Vouchers\Tests\Feature;

use Azuriom\Models\Role;
use Azuriom\Models\Server;
use Azuriom\Models\User;
use Azuriom\Plugin\Vouchers\Models\Reward;
use Azuriom\Plugin\Vouchers\Requests\VoucherRequest;
use Azuriom\Plugin\Vouchers\Services\InternalRoleCatalog;
use Azuriom\Plugin\Vouchers\Tests\TestCase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminVoucherRequestTest extends TestCase
{
    public function test_server_command_admin_validation_enforces_the_bridge_contract(): void
    {
        $this->enableServerIntegration();
        $rconServer = $this->createServer('recording-server');
        $azLinkServer = $this->createServer('mc-azlink', 'AzLink server');

        $this->assertSame([], $this->validateReward([
            'type' => Reward::TYPE_SERVER_COMMAND,
            'server_id' => $azLinkServer->id,
            'command' => 'grant {player} vip',
            'require_online' => '1',
        ]));

        foreach ([
            '/grant {player} vip',
            "grant {player}\nop Attacker",
            "grant {player}\0vip",
            'grant {uuid} vip',
            ['nested' => 'grant {player} vip'],
        ] as $command) {
            $errors = $this->validateReward([
                'type' => Reward::TYPE_SERVER_COMMAND,
                'server_id' => $rconServer->id,
                'command' => $command,
                'require_online' => '0',
            ]);

            $this->assertArrayHasKey('rewards.0.command', $errors);
        }

        $missingServerErrors = $this->validateReward([
            'type' => Reward::TYPE_SERVER_COMMAND,
            'server_id' => 4294967295,
            'command' => 'grant {player} vip',
            'require_online' => '0',
        ]);
        $this->assertArrayHasKey('rewards.0.server_id', $missingServerErrors);

        $rconOnlineErrors = $this->validateReward([
            'type' => Reward::TYPE_SERVER_COMMAND,
            'server_id' => $rconServer->id,
            'command' => 'grant {name} vip',
            'require_online' => '1',
        ]);
        $this->assertArrayHasKey('rewards.0.require_online', $rconOnlineErrors);
    }

    public function test_internal_role_admin_validation_excludes_privilege_escalation(): void
    {
        $managerRole = $this->createRole('Voucher manager', 20);
        $manager = $this->createUser($managerRole);
        $vipRole = $this->createRole('VIP', 10);
        $higherRole = $this->createRole('Higher staff', 30);
        $adminRole = $this->createRole('Administrator', 5, true);
        $adminAccessRole = $this->createRole('Hidden administrator', 5);
        $adminAccessRole->permissions()->create(['permission' => 'admin.access']);

        $this->assertSame([], $this->validateReward([
            'type' => Reward::TYPE_INTERNAL_ROLE,
            'role_id' => $vipRole->id,
        ], $manager));

        foreach ([$higherRole->id, $adminRole->id, $adminAccessRole->id, 4294967295] as $roleId) {
            $errors = $this->validateReward([
                'type' => Reward::TYPE_INTERNAL_ROLE,
                'role_id' => $roleId,
            ], $manager);

            $this->assertArrayHasKey('rewards.0.role_id', $errors);
        }

        $malformedErrors = $this->validateReward([
            'type' => Reward::TYPE_INTERNAL_ROLE,
            'role_id' => ['nested' => $vipRole->id],
        ], $manager);
        $this->assertArrayHasKey('rewards.0.role_id', $malformedErrors);

        $duplicateErrors = $this->validateRewards([
            ['type' => Reward::TYPE_INTERNAL_ROLE, 'role_id' => $vipRole->id],
            ['type' => Reward::TYPE_INTERNAL_ROLE, 'role_id' => $vipRole->id],
        ], $manager);
        $this->assertArrayHasKey('rewards', $duplicateErrors);
    }

    public function test_internal_role_snapshot_is_rechecked_when_the_role_changes(): void
    {
        $managerRole = $this->createRole('Administrator', 100, true);
        $manager = $this->createUser($managerRole);
        $role = $this->createRole('VIP', 10);
        $catalog = app(InternalRoleCatalog::class);

        $this->assertSame($role->id, $catalog->configuration($role->id, $manager)['role_id']);

        $role->forceFill(['is_admin' => true])->save();

        $this->expectException(\UnexpectedValueException::class);
        $catalog->configuration($role->id, $manager);
    }

    /**
     * Validate one reward through the same rules and post-validation hooks as the form request.
     *
     * @return array<string, array<int, string>>
     */
    private function validateReward(array $reward, ?User $actor = null): array
    {
        return $this->validateRewards([$reward], $actor);
    }

    /**
     * Validate rewards through the same rules and post-validation hooks as the form request.
     *
     * @param  array<int, array<string, mixed>>  $rewards
     * @return array<string, array<int, string>>
     */
    private function validateRewards(array $rewards, ?User $actor = null): array
    {
        $request = VoucherRequest::create('/admin/vouchers/codes', 'POST', [
            'name' => 'Validation voucher',
            'code' => 'VALIDATION2026',
            'is_enabled' => '1',
            'requires_authentication' => '1',
            'rewards' => $rewards,
        ]);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make(\Illuminate\Routing\Redirector::class));
        $route = new Route('POST', '/admin/vouchers/codes', fn () => null);
        $route->setContainer($this->app);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);

        if ($actor !== null) {
            $request->setUserResolver(fn () => $actor);
        }

        try {
            $request->validateResolved();

            return [];
        } catch (ValidationException $exception) {
            return $exception->errors();
        }
    }

    private function createServer(string $type, string $name = 'RCON server'): Server
    {
        return Server::create([
            'name' => $name,
            'address' => '127.0.0.1',
            'port' => 25565,
            'type' => $type,
            'token' => 'test-token',
            'data' => [],
        ]);
    }

    private function createRole(string $name, int $power, bool $isAdmin = false): Role
    {
        return Role::create([
            'name' => $name,
            'color' => 'ffffff',
            'power' => $power,
            'is_admin' => $isAdmin,
        ]);
    }

    private function createUser(Role $role): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'VoucherManager',
            'email' => 'manager@example.com',
            'password' => 'not-used-in-tests',
            'role_id' => $role->id,
            'money' => 0,
            'game_id' => 'voucher-manager',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail($id);
    }
}
