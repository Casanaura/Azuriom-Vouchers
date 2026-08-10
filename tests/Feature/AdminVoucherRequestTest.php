<?php

namespace Azuriom\Plugin\Vouchers\Tests\Feature;

use Azuriom\Models\Server;
use Azuriom\Plugin\Vouchers\Models\Reward;
use Azuriom\Plugin\Vouchers\Requests\VoucherRequest;
use Azuriom\Plugin\Vouchers\Tests\TestCase;
use Illuminate\Routing\Route;
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

    /**
     * Validate one reward through the same rules and post-validation hooks as the form request.
     *
     * @return array<string, array<int, string>>
     */
    private function validateReward(array $reward): array
    {
        $request = VoucherRequest::create('/admin/vouchers/codes', 'POST', [
            'name' => 'Validation voucher',
            'code' => 'VALIDATION2026',
            'is_enabled' => '1',
            'requires_authentication' => '1',
            'rewards' => [$reward],
        ]);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make(\Illuminate\Routing\Redirector::class));
        $route = new Route('POST', '/admin/vouchers/codes', fn () => null);
        $route->setContainer($this->app);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);

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
}
