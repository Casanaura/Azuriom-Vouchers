<?php

namespace Azuriom\Plugin\Vouchers\Tests\Feature;

use Azuriom\Plugin\Vouchers\Models\Voucher;
use Azuriom\Plugin\Vouchers\Services\VoucherCodeGenerator;
use Azuriom\Plugin\Vouchers\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class VoucherModelTest extends TestCase
{
    public function test_plugin_migration_creates_the_domain_tables(): void
    {
        $this->assertTrue(Schema::hasTable('vouchers_codes'));
        $this->assertTrue(Schema::hasTable('vouchers_rewards'));
        $this->assertTrue(Schema::hasTable('vouchers_redemptions'));
        $this->assertTrue(Schema::hasTable('vouchers_reward_executions'));
        $this->assertTrue(Schema::hasColumns('vouchers_codes', [
            'code_hash', 'requires_authentication', 'max_redemptions',
            'max_redemptions_per_user', 'starts_at', 'expires_at',
        ]));
        $this->assertTrue(Schema::hasColumns('vouchers_redemptions', [
            'user_id', 'recipient_key', 'status',
        ]));
    }

    public function test_codes_are_encrypted_and_lookups_ignore_case_and_separators(): void
    {
        $voucher = Voucher::create([
            'name' => 'Founders',
            'code' => 'abcd-1234',
        ]);

        $storedCode = DB::table('vouchers_codes')->where('id', $voucher->id)->value('code');

        $this->assertNotSame('ABCD-1234', $storedCode);
        $this->assertSame('ABCD-1234', $voucher->fresh()->code);
        $this->assertSame('****-1234', $voucher->code_preview);
        $this->assertTrue(Voucher::query()->whereCode('Ab Cd 12-34')->whereKey($voucher)->exists());
        $this->assertNotSame(hash('sha256', Voucher::normalizeCode('ABCD-1234')), $voucher->code_hash);
    }

    public function test_availability_honors_enabled_state_dates_and_global_limit(): void
    {
        $date = CarbonImmutable::parse('2026-08-10 12:00:00');
        $voucher = new Voucher([
            'name' => 'Timed',
            'code' => 'TIME-2026',
            'is_enabled' => true,
            'max_redemptions' => 2,
            'starts_at' => $date->subHour(),
            'expires_at' => $date->addHour(),
        ]);
        $voucher->redemptions_count = 1;

        $this->assertTrue($voucher->isAvailableAt($date));
        $this->assertTrue($voucher->hasRemainingRedemptions());
        $this->assertFalse($voucher->isAvailableAt($date->subHours(2)));
        $this->assertFalse($voucher->isAvailableAt($date->addHours(2)));

        $voucher->redemptions_count = 2;

        $this->assertFalse($voucher->hasRemainingRedemptions());
    }

    public function test_generator_returns_readable_unique_codes(): void
    {
        $generator = new VoucherCodeGenerator();
        $first = $generator->generate();

        Voucher::create([
            'name' => 'Generated',
            'code' => $first,
        ]);

        $second = $generator->generate();

        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{4}(?:-[A-HJ-NP-Z2-9]{4}){3}$/', $first);
        $this->assertNotSame($first, $second);
    }
}
