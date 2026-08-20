<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vouchers_codes', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 100);
            $table->text('code');
            $table->char('code_hash', 64)->unique();
            $table->string('code_preview', 20);
            $table->boolean('is_enabled')->default(true);
            $table->boolean('requires_authentication')->default(true);
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('max_redemptions_per_user')->nullable();
            $table->unsignedInteger('redemptions_count')->default(0);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['is_enabled', 'starts_at', 'expires_at']);
        });

        Schema::create('vouchers_rewards', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('voucher_id');
            $table->string('type', 32);
            $table->json('configuration');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->foreign('voucher_id')
                ->references('id')
                ->on('vouchers_codes')
                ->cascadeOnDelete();
            $table->index(['voucher_id', 'position']);
        });

        Schema::create('vouchers_redemptions', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->uuid('request_token')->nullable()->unique();
            $table->char('request_fingerprint', 64)->nullable();
            $table->unsignedInteger('voucher_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('redeemer_id')->nullable();
            $table->string('username');
            $table->string('recipient_key', 100);
            $table->string('ip_address', 45)->nullable();
            $table->string('status', 20);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('voucher_id')
                ->references('id')
                ->on('vouchers_codes')
                ->restrictOnDelete();
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->foreign('redeemer_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->index(['voucher_id', 'status']);
            $table->index(['voucher_id', 'user_id', 'status']);
            $table->index(['voucher_id', 'recipient_key', 'status']);
            $table->index('user_id');
            $table->index('redeemer_id');
            $table->index('status', 'vouchers_redemptions_status_index');
        });

        Schema::create('vouchers_reward_executions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('redemption_id');
            $table->unsignedInteger('reward_id')->nullable();
            $table->uuid('reward_uuid');
            $table->string('type', 32);
            $table->json('configuration');
            $table->string('status', 20);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('external_reference', 100)->nullable()->unique();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('redemption_id')
                ->references('id')
                ->on('vouchers_redemptions')
                ->restrictOnDelete();
            $table->foreign('reward_id')
                ->references('id')
                ->on('vouchers_rewards')
                ->nullOnDelete();
            $table->unique(['redemption_id', 'reward_uuid']);
            $table->index(['redemption_id', 'status']);
            $table->index('reward_id');
            $table->index('status');
            $table->index(
                ['type', 'status', 'started_at'],
                'vouchers_executions_delivery_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vouchers_reward_executions');
        Schema::dropIfExists('vouchers_redemptions');
        Schema::dropIfExists('vouchers_rewards');
        Schema::dropIfExists('vouchers_codes');
    }
};
