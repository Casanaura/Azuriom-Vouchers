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
        Schema::table('vouchers_reward_executions', function (Blueprint $table) {
            $table->unsignedSmallInteger('attempts')->default(0)->after('status');
            $table->string('external_reference', 100)->nullable()->unique()->after('attempts');
            $table->index(['type', 'status', 'started_at'], 'vouchers_executions_delivery_index');
        });

        Schema::table('vouchers_redemptions', function (Blueprint $table) {
            $table->index('status', 'vouchers_redemptions_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vouchers_redemptions', function (Blueprint $table) {
            $table->dropIndex('vouchers_redemptions_status_index');
        });

        Schema::table('vouchers_reward_executions', function (Blueprint $table) {
            $table->dropIndex('vouchers_executions_delivery_index');
            $table->dropUnique(['external_reference']);
            $table->dropColumn(['external_reference', 'attempts']);
        });
    }
};
