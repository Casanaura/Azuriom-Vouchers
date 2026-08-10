<?php

namespace Azuriom\Plugin\Vouchers\Tests\Feature;

use Azuriom\Models\Server;
use Azuriom\Models\User;
use Azuriom\Plugin\Shop\Events\PackageDelivered;
use Azuriom\Plugin\Shop\Events\PaymentPaid;
use Azuriom\Plugin\Shop\Models\Payment;
use Azuriom\Plugin\Shop\Models\PaymentItem;
use Azuriom\Plugin\Vouchers\Exceptions\VoucherRedemptionException;
use Azuriom\Plugin\Vouchers\Models\Redemption;
use Azuriom\Plugin\Vouchers\Models\Reward;
use Azuriom\Plugin\Vouchers\Models\RewardExecution;
use Azuriom\Plugin\Vouchers\Models\Voucher;
use Azuriom\Plugin\Vouchers\Services\RedeemVoucher;
use Azuriom\Plugin\Vouchers\Services\ServerCommandRewardService;
use Azuriom\Plugin\Vouchers\Services\ShopPackageRewardService;
use Azuriom\Plugin\Vouchers\Tests\TestCase;
use Azuriom\Plugin\Vouchers\Tests\Fakes\RecordingServerBridge;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

class RedeemVoucherTest extends TestCase
{
    public function test_multiple_point_rewards_are_delivered_and_request_idempotent(): void
    {
        $user = $this->createUser();
        $voucher = $this->createVoucher(maxPerUser: 2);
        $voucher->rewards()->createMany([
            ['type' => Reward::TYPE_MONEY, 'configuration' => ['amount' => 100], 'position' => 0],
            ['type' => Reward::TYPE_MONEY, 'configuration' => ['amount' => 25.5], 'position' => 1],
        ]);
        $token = (string) Str::uuid();
        $service = app(RedeemVoucher::class);

        $first = $service->redeem('welcome-2026', $user, null, $token, '127.0.0.1');
        $completedAt = $first->completed_at?->toISOString();
        $repeated = $service->redeem('welcome-2026', $user, null, $token, '127.0.0.1');

        $this->assertSame($first->id, $repeated->id);
        $this->assertSame(Redemption::STATUS_COMPLETED, $first->status);
        $this->assertSame(125.5, $user->fresh()->money);
        $this->assertSame(1, $voucher->fresh()->redemptions_count);
        $this->assertSame($completedAt, $repeated->completed_at?->toISOString());
        $this->assertCount(2, $first->executions);
        $this->assertTrue($first->executions->every(
            fn (RewardExecution $execution) => $execution->status === RewardExecution::STATUS_SUCCEEDED
        ));
    }

    public function test_an_invalid_second_reward_rolls_back_the_entire_redemption(): void
    {
        $user = $this->createUser();
        $voucher = $this->createVoucher(maxPerUser: 1);
        $voucher->rewards()->createMany([
            ['type' => Reward::TYPE_MONEY, 'configuration' => ['amount' => 100], 'position' => 0],
            ['type' => Reward::TYPE_MONEY, 'configuration' => ['amount' => 0.001], 'position' => 1],
        ]);

        try {
            app(RedeemVoucher::class)->redeem(
                'welcome-2026',
                $user,
                null,
                (string) Str::uuid(),
                '127.0.0.1',
            );
            $this->fail('The invalid point reward did not abort the redemption.');
        } catch (VoucherRedemptionException $exception) {
            $this->assertSame(VoucherRedemptionException::INVALID_CONFIGURATION, $exception->reason);
        }

        $this->assertSame(0.0, $user->fresh()->money);
        $this->assertSame(0, $voucher->fresh()->redemptions_count);
        $this->assertSame(0, Redemption::query()->count());
        $this->assertSame(0, RewardExecution::query()->count());
    }

    public function test_a_large_two_decimal_point_reward_is_accepted(): void
    {
        $user = $this->createUser();
        $voucher = $this->createVoucher(maxPerUser: 1);
        $voucher->rewards()->create([
            'type' => Reward::TYPE_MONEY,
            'configuration' => ['amount' => 123456789.12],
            'position' => 0,
        ]);

        $redemption = app(RedeemVoucher::class)->redeem(
            'welcome-2026',
            $user,
            null,
            (string) Str::uuid(),
            '127.0.0.1',
        );

        $this->assertSame(Redemption::STATUS_COMPLETED, $redemption->status);
        $this->assertSame(123456789.12, $user->fresh()->money);
    }

    public function test_an_unavailable_shop_reward_rolls_back_the_reservation(): void
    {
        $user = $this->createUser();
        $voucher = $this->createVoucher(maxPerUser: 1);
        $voucher->rewards()->create([
            'type' => Reward::TYPE_SHOP_PACKAGE,
            'configuration' => ['package_id' => 1, 'package_name' => 'Unavailable'],
            'position' => 0,
        ]);

        try {
            app(RedeemVoucher::class)->redeem(
                'welcome-2026',
                $user,
                null,
                (string) Str::uuid(),
                '127.0.0.1',
            );
            $this->fail('The unavailable Shop reward did not abort the reservation.');
        } catch (VoucherRedemptionException $exception) {
            $this->assertSame(VoucherRedemptionException::INVALID_CONFIGURATION, $exception->reason);
        }

        $this->assertSame(0, $voucher->fresh()->redemptions_count);
        $this->assertSame(0, Redemption::query()->count());
        $this->assertSame(0, RewardExecution::query()->count());
    }

    public function test_shop_package_uses_the_payment_contract_and_is_idempotent(): void
    {
        $this->enableShopIntegration();
        $user = $this->createUser();
        $package = $this->createShopPackage(20);
        $voucher = $this->createVoucher(maxPerUser: 2);
        $voucher->rewards()->createMany([
            ['type' => Reward::TYPE_MONEY, 'configuration' => ['amount' => 10], 'position' => 0],
            [
                'type' => Reward::TYPE_SHOP_PACKAGE,
                'configuration' => ['package_id' => $package->id, 'package_name' => $package->name],
                'position' => 1,
            ],
        ]);
        $token = (string) Str::uuid();
        $paymentsPaid = 0;
        $packagesDelivered = 0;
        Event::listen(PaymentPaid::class, function () use (&$paymentsPaid) {
            $paymentsPaid++;
        });
        Event::listen(PackageDelivered::class, function () use (&$packagesDelivered) {
            $packagesDelivered++;
        });

        $first = app(RedeemVoucher::class)->redeem(
            'welcome-2026',
            $user,
            null,
            $token,
            '127.0.0.1',
        );
        $repeated = app(RedeemVoucher::class)->redeem(
            'welcome-2026',
            $user,
            null,
            $token,
            '127.0.0.1',
        );

        $shopExecution = $first->executions->firstWhere('type', Reward::TYPE_SHOP_PACKAGE);
        $payment = Payment::query()->sole();
        $item = PaymentItem::query()->sole();

        $this->assertSame($first->id, $repeated->id);
        $this->assertSame(Redemption::STATUS_COMPLETED, $first->status);
        $this->assertSame(30.0, $user->fresh()->money);
        $this->assertSame('completed', $payment->status);
        $this->assertSame('manual', $payment->gateway_type);
        $this->assertSame('shop.packages', $item->buyable_type);
        $this->assertSame($package->id, $item->buyable_id);
        $this->assertSame(RewardExecution::STATUS_SUCCEEDED, $shopExecution->status);
        $this->assertSame(1, $shopExecution->attempts);
        $this->assertSame(1, $paymentsPaid);
        $this->assertSame(1, $packagesDelivered);
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, PaymentItem::query()->count());
    }

    public function test_shop_failure_after_the_delivery_boundary_is_never_retried(): void
    {
        $this->enableShopIntegration();
        $user = $this->createUser();
        $package = $this->createShopPackage(7, 'Uncertain package');
        $voucher = $this->createVoucher(maxPerUser: 2);
        $voucher->rewards()->create([
            'type' => Reward::TYPE_SHOP_PACKAGE,
            'configuration' => ['package_id' => $package->id, 'package_name' => $package->name],
            'position' => 0,
        ]);
        $token = (string) Str::uuid();
        $attempts = 0;
        Event::listen(PackageDelivered::class, function (PackageDelivered $event) use ($package, &$attempts) {
            if ($event->package->is($package)) {
                $attempts++;

                throw new \RuntimeException('Failure after the package side effect.');
            }
        });

        $first = app(RedeemVoucher::class)->redeem(
            'welcome-2026',
            $user,
            null,
            $token,
            '127.0.0.1',
        );
        $repeated = app(RedeemVoucher::class)->redeem(
            'welcome-2026',
            $user,
            null,
            $token,
            '127.0.0.1',
        );
        $execution = $first->executions->sole();

        $this->assertSame($first->id, $repeated->id);
        $this->assertSame(Redemption::STATUS_REVIEW_REQUIRED, $first->status);
        $this->assertSame(RewardExecution::STATUS_UNCERTAIN, $execution->status);
        $this->assertSame(1, $execution->attempts);
        $this->assertSame(1, $attempts);
        $this->assertSame(7.0, $user->fresh()->money);
        $this->assertSame('completed', Payment::query()->sole()->status);
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, PaymentItem::query()->count());
    }

    public function test_an_interrupted_shop_claim_without_a_start_time_requires_review(): void
    {
        $this->enableShopIntegration();
        $user = $this->createUser();
        $package = $this->createShopPackage(3, 'Interrupted package');
        $voucher = $this->createVoucher(maxPerUser: 1);
        $reward = $voucher->rewards()->create([
            'type' => Reward::TYPE_SHOP_PACKAGE,
            'configuration' => ['package_id' => $package->id, 'package_name' => $package->name],
            'position' => 0,
        ]);
        $redemption = $voucher->redemptions()->create([
            'user_id' => $user->id,
            'redeemer_id' => $user->id,
            'username' => $user->name,
            'recipient_key' => Redemption::recipientKey($user),
            'status' => Redemption::STATUS_PROCESSING,
        ]);
        $execution = RewardExecution::fromReward($reward);
        $redemption->executions()->save($execution);
        $service = app(ShopPackageRewardService::class);

        DB::transaction(function () use ($service, $execution, $redemption, $user) {
            $service->prepare($execution, $redemption, $user);
        });
        $execution->forceFill([
            'status' => RewardExecution::STATUS_PROCESSING,
            'attempts' => 1,
            'started_at' => null,
        ])->save();

        $this->assertTrue($service->reconcileStale($execution->fresh(), now()->subMinutes(10)));
        $this->assertSame(RewardExecution::STATUS_UNCERTAIN, $execution->fresh()->status);
        $this->assertSame(Redemption::STATUS_REVIEW_REQUIRED, $redemption->fresh()->status);
        $this->assertSame('error', Payment::query()->sole()->status);
        $this->assertSame(0.0, $user->fresh()->money);
    }

    public function test_server_command_is_dispatched_once_with_a_rendered_recipient(): void
    {
        $this->enableServerIntegration();
        $user = $this->createUser();
        $server = $this->createServer('mc-azlink');
        $voucher = $this->createVoucher(maxPerUser: 2);
        $voucher->rewards()->create([
            'type' => Reward::TYPE_SERVER_COMMAND,
            'configuration' => [
                'server_id' => $server->id,
                'server_name' => $server->name,
                'server_type' => $server->type,
                'command' => 'give {player} diamond 1',
                'require_online' => true,
            ],
            'position' => 0,
        ]);
        $token = (string) Str::uuid();

        $first = app(RedeemVoucher::class)->redeem(
            'welcome-2026',
            $user,
            null,
            $token,
            '127.0.0.1',
        );
        $repeated = app(RedeemVoucher::class)->redeem(
            'welcome-2026',
            $user,
            null,
            $token,
            '127.0.0.1',
        );
        $execution = $first->executions->sole();

        $this->assertSame($first->id, $repeated->id);
        $this->assertSame(Redemption::STATUS_COMPLETED, $first->status);
        $this->assertSame(RewardExecution::STATUS_DISPATCHED, $execution->status);
        $this->assertSame(1, $execution->attempts);
        $this->assertSame('give PlayerOne diamond 1', $execution->configuration['command']);
        $this->assertCount(1, RecordingServerBridge::$calls);
        $this->assertSame(['give PlayerOne diamond 1'], RecordingServerBridge::$calls[0]['commands']);
        $this->assertSame($user->id, RecordingServerBridge::$calls[0]['user_id']);
        $this->assertTrue(RecordingServerBridge::$calls[0]['require_online']);
    }

    public function test_server_command_failure_after_dispatch_boundary_is_never_retried(): void
    {
        $this->enableServerIntegration();
        RecordingServerBridge::$throwAfterRecording = true;
        $user = $this->createUser();
        $server = $this->createServer();
        $voucher = $this->createVoucher(maxPerUser: 2);
        $voucher->rewards()->create([
            'type' => Reward::TYPE_SERVER_COMMAND,
            'configuration' => [
                'server_id' => $server->id,
                'server_name' => $server->name,
                'server_type' => $server->type,
                'command' => 'grant {name} vip',
                'require_online' => false,
            ],
            'position' => 0,
        ]);
        $token = (string) Str::uuid();

        $first = app(RedeemVoucher::class)->redeem(
            'welcome-2026',
            $user,
            null,
            $token,
            '127.0.0.1',
        );
        $repeated = app(RedeemVoucher::class)->redeem(
            'welcome-2026',
            $user,
            null,
            $token,
            '127.0.0.1',
        );
        $execution = $first->executions->sole();

        $this->assertSame($first->id, $repeated->id);
        $this->assertSame(Redemption::STATUS_REVIEW_REQUIRED, $first->status);
        $this->assertSame(RewardExecution::STATUS_UNCERTAIN, $execution->status);
        $this->assertSame(1, $execution->attempts);
        $this->assertCount(1, RecordingServerBridge::$calls);
    }

    public function test_guest_server_command_uses_the_resolved_existing_account(): void
    {
        $this->enableServerIntegration();
        $user = $this->createUser();
        $server = $this->createServer();
        $voucher = $this->createVoucher(maxPerUser: 1);
        $voucher->forceFill(['requires_authentication' => false])->save();
        $voucher->rewards()->create([
            'type' => Reward::TYPE_SERVER_COMMAND,
            'configuration' => [
                'server_id' => $server->id,
                'command' => 'grant {player} guest-reward',
                'require_online' => false,
            ],
            'position' => 0,
        ]);

        $redemption = app(RedeemVoucher::class)->redeem(
            'welcome-2026',
            null,
            'PlayerOne',
            (string) Str::uuid(),
            '127.0.0.1',
        );

        $this->assertSame(Redemption::STATUS_COMPLETED, $redemption->status);
        $this->assertNull($redemption->redeemer_id);
        $this->assertSame($user->id, $redemption->user_id);
        $this->assertSame('PlayerOne', RecordingServerBridge::$calls[0]['username']);
        $this->assertSame(['grant PlayerOne guest-reward'], RecordingServerBridge::$calls[0]['commands']);
    }

    public function test_server_removed_before_dispatch_fails_without_crossing_the_boundary(): void
    {
        $this->enableServerIntegration();
        $user = $this->createUser();
        $server = $this->createServer();
        $voucher = $this->createVoucher(maxPerUser: 1);
        $reward = $voucher->rewards()->create([
            'type' => Reward::TYPE_SERVER_COMMAND,
            'configuration' => [
                'server_id' => $server->id,
                'command' => 'grant {player} vip',
                'require_online' => false,
            ],
            'position' => 0,
        ]);
        $redemption = $voucher->redemptions()->create([
            'user_id' => $user->id,
            'redeemer_id' => $user->id,
            'username' => $user->name,
            'recipient_key' => Redemption::recipientKey($user),
            'status' => Redemption::STATUS_PROCESSING,
        ]);
        $execution = RewardExecution::fromReward($reward);
        $redemption->executions()->save($execution);
        $service = app(ServerCommandRewardService::class);

        DB::transaction(function () use ($service, $execution, $redemption, $user) {
            $service->prepare($execution, $redemption, $user);
        });
        $server->delete();
        $service->deliver($execution->fresh());

        $this->assertSame(RewardExecution::STATUS_FAILED, $execution->fresh()->status);
        $this->assertSame(0, $execution->fresh()->attempts);
        $this->assertSame(Redemption::STATUS_FAILED, $redemption->fresh()->status);
        $this->assertCount(0, RecordingServerBridge::$calls);
    }

    public function test_unsafe_recipient_name_rolls_back_server_command_reservation(): void
    {
        $this->enableServerIntegration();
        $user = $this->createUser(name: 'Player;op Attacker');
        $server = $this->createServer();
        $voucher = $this->createVoucher(maxPerUser: 1);
        $voucher->rewards()->create([
            'type' => Reward::TYPE_SERVER_COMMAND,
            'configuration' => [
                'server_id' => $server->id,
                'server_name' => $server->name,
                'server_type' => $server->type,
                'command' => 'grant {player} vip',
                'require_online' => false,
            ],
            'position' => 0,
        ]);

        try {
            app(RedeemVoucher::class)->redeem(
                'welcome-2026',
                $user,
                null,
                (string) Str::uuid(),
                '127.0.0.1',
            );
            $this->fail('An unsafe recipient name reached the server bridge.');
        } catch (VoucherRedemptionException $exception) {
            $this->assertSame(VoucherRedemptionException::INVALID_CONFIGURATION, $exception->reason);
        }

        $this->assertSame(0, $voucher->fresh()->redemptions_count);
        $this->assertSame(0, Redemption::query()->count());
        $this->assertSame(0, RewardExecution::query()->count());
        $this->assertCount(0, RecordingServerBridge::$calls);
    }

    public function test_multiple_server_command_rewards_keep_independent_ordered_attempts(): void
    {
        $this->enableServerIntegration();
        $user = $this->createUser();
        $server = $this->createServer();
        $voucher = $this->createVoucher(maxPerUser: 1);
        $voucher->rewards()->createMany([
            [
                'type' => Reward::TYPE_SERVER_COMMAND,
                'configuration' => [
                    'server_id' => $server->id,
                    'command' => 'first {player}',
                    'require_online' => false,
                ],
                'position' => 0,
            ],
            [
                'type' => Reward::TYPE_SERVER_COMMAND,
                'configuration' => [
                    'server_id' => $server->id,
                    'command' => 'second {player}',
                    'require_online' => false,
                ],
                'position' => 1,
            ],
        ]);

        $redemption = app(RedeemVoucher::class)->redeem(
            'welcome-2026',
            $user,
            null,
            (string) Str::uuid(),
            '127.0.0.1',
        );

        $this->assertSame(Redemption::STATUS_COMPLETED, $redemption->status);
        $this->assertSame(
            [['first PlayerOne'], ['second PlayerOne']],
            array_column(RecordingServerBridge::$calls, 'commands'),
        );
        $this->assertTrue($redemption->executions->every(
            fn (RewardExecution $execution) => $execution->status === RewardExecution::STATUS_DISPATCHED
        ));
    }

    public function test_interrupted_server_command_claim_becomes_uncertain_without_dispatch(): void
    {
        $this->enableServerIntegration();
        $user = $this->createUser();
        $server = $this->createServer();
        $voucher = $this->createVoucher(maxPerUser: 1);
        $reward = $voucher->rewards()->create([
            'type' => Reward::TYPE_SERVER_COMMAND,
            'configuration' => [
                'server_id' => $server->id,
                'command' => 'grant {player} vip',
                'require_online' => false,
            ],
            'position' => 0,
        ]);
        $redemption = $voucher->redemptions()->create([
            'user_id' => $user->id,
            'redeemer_id' => $user->id,
            'username' => $user->name,
            'recipient_key' => Redemption::recipientKey($user),
            'status' => Redemption::STATUS_PROCESSING,
        ]);
        $execution = RewardExecution::fromReward($reward);
        $redemption->executions()->save($execution);
        $service = app(ServerCommandRewardService::class);

        DB::transaction(function () use ($service, $execution, $redemption, $user) {
            $service->prepare($execution, $redemption, $user);
        });
        $execution->forceFill([
            'status' => RewardExecution::STATUS_PROCESSING,
            'attempts' => 1,
            'started_at' => null,
        ])->save();

        $this->assertTrue($service->reconcileStale($execution->fresh(), now()->subMinutes(10)));
        $this->assertSame(RewardExecution::STATUS_UNCERTAIN, $execution->fresh()->status);
        $this->assertSame(Redemption::STATUS_REVIEW_REQUIRED, $redemption->fresh()->status);
        $this->assertCount(0, RecordingServerBridge::$calls);
    }

    public function test_per_user_limit_rejects_a_new_redemption_request(): void
    {
        $user = $this->createUser();
        $otherUser = $this->createUser(2, 'PlayerTwo');
        $voucher = $this->createVoucher(maxPerUser: 1);
        $voucher->rewards()->create([
            'type' => Reward::TYPE_MONEY,
            'configuration' => ['amount' => 10],
            'position' => 0,
        ]);
        $service = app(RedeemVoucher::class);

        $service->redeem('welcome-2026', $user, null, (string) Str::uuid(), '127.0.0.1');

        try {
            $service->redeem('welcome-2026', $user, null, (string) Str::uuid(), '127.0.0.1');
            $this->fail('The per-user redemption limit was not enforced.');
        } catch (VoucherRedemptionException $exception) {
            $this->assertSame(VoucherRedemptionException::USER_LIMIT_REACHED, $exception->reason);
        }

        $service->redeem('welcome-2026', $otherUser, null, (string) Str::uuid(), '127.0.0.2');

        $this->assertSame(10.0, $user->fresh()->money);
        $this->assertSame(10.0, $otherUser->fresh()->money);
        $this->assertSame(2, $voucher->fresh()->redemptions_count);
        $this->assertSame(2, $voucher->redemptions()->count());
    }

    public function test_a_replayed_request_token_cannot_change_actor_or_code(): void
    {
        $user = $this->createUser();
        $otherUser = $this->createUser(2, 'PlayerTwo');
        $voucher = $this->createVoucher(maxPerUser: 2);
        $voucher->rewards()->create([
            'type' => Reward::TYPE_MONEY,
            'configuration' => ['amount' => 5],
            'position' => 0,
        ]);
        $otherVoucher = $this->createVoucher(maxPerUser: 2, code: 'SECOND-2026');
        $otherVoucher->rewards()->create([
            'type' => Reward::TYPE_MONEY,
            'configuration' => ['amount' => 50],
            'position' => 0,
        ]);
        $service = app(RedeemVoucher::class);
        $token = (string) Str::uuid();

        $service->redeem('welcome-2026', $user, null, $token, '127.0.0.1');

        foreach ([
            ['WELCOME-2026', $otherUser],
            ['SECOND-2026', $user],
        ] as [$code, $actor]) {
            try {
                $service->redeem($code, $actor, null, $token, '127.0.0.2');
                $this->fail('The request token accepted a different redemption intent.');
            } catch (VoucherRedemptionException $exception) {
                $this->assertSame(VoucherRedemptionException::UNAVAILABLE, $exception->reason);
            }
        }

        $this->assertSame(5.0, $user->fresh()->money);
        $this->assertSame(0.0, $otherUser->fresh()->money);
        $this->assertSame(1, Redemption::query()->count());
    }

    public function test_request_tokens_are_protected_by_a_database_unique_constraint(): void
    {
        $user = $this->createUser();
        $voucher = $this->createVoucher(maxPerUser: 2);
        $token = (string) Str::uuid();
        $attributes = [
            'request_token' => $token,
            'request_fingerprint' => hash('sha256', 'test-intent'),
            'user_id' => $user->getKey(),
            'redeemer_id' => $user->getKey(),
            'username' => $user->name,
            'recipient_key' => Redemption::recipientKey($user),
            'ip_address' => '127.0.0.1',
        ];

        $voucher->redemptions()->create($attributes);

        $this->expectException(UniqueConstraintViolationException::class);
        $voucher->redemptions()->create($attributes);
    }

    private function createUser(int $id = 1, string $name = 'PlayerOne'): User
    {
        if (! DB::table('roles')->where('id', 1)->exists()) {
            DB::table('roles')->insert([
                'id' => 1,
                'name' => 'Member',
                'color' => 'ffffff',
                'power' => 0,
                'is_admin' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('users')->insert([
            'id' => $id,
            'name' => $name,
            'email' => "player{$id}@example.com",
            'password' => 'not-used-in-tests',
            'role_id' => 1,
            'money' => 0,
            'game_id' => 'player-'.$id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail($id);
    }

    private function createServer(string $type = 'recording-server'): Server
    {
        return Server::create([
            'name' => 'Voucher server',
            'address' => '127.0.0.1',
            'port' => 25565,
            'type' => $type,
            'token' => 'test-token',
            'data' => [],
        ]);
    }

    private function createVoucher(int $maxPerUser, string $code = 'WELCOME-2026'): Voucher
    {
        return Voucher::create([
            'name' => $code,
            'code' => $code,
            'is_enabled' => true,
            'requires_authentication' => true,
            'max_redemptions_per_user' => $maxPerUser,
        ]);
    }
}
