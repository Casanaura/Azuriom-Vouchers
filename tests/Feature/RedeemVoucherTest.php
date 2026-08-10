<?php

namespace Azuriom\Plugin\Vouchers\Tests\Feature;

use Azuriom\Models\User;
use Azuriom\Plugin\Vouchers\Exceptions\VoucherRedemptionException;
use Azuriom\Plugin\Vouchers\Models\Redemption;
use Azuriom\Plugin\Vouchers\Models\Reward;
use Azuriom\Plugin\Vouchers\Models\RewardExecution;
use Azuriom\Plugin\Vouchers\Models\Voucher;
use Azuriom\Plugin\Vouchers\Services\RedeemVoucher;
use Azuriom\Plugin\Vouchers\Tests\TestCase;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
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
        $repeated = $service->redeem('welcome-2026', $user, null, $token, '127.0.0.1');

        $this->assertSame($first->id, $repeated->id);
        $this->assertSame(Redemption::STATUS_COMPLETED, $first->status);
        $this->assertSame(125.5, $user->fresh()->money);
        $this->assertSame(1, $voucher->fresh()->redemptions_count);
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
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail($id);
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
