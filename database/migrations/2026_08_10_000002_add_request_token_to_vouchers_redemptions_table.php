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
        Schema::table('vouchers_redemptions', function (Blueprint $table) {
            $table->uuid('request_token')->nullable()->unique()->after('uuid');
            $table->char('request_fingerprint', 64)->nullable()->after('request_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vouchers_redemptions', function (Blueprint $table) {
            $table->dropUnique(['request_token']);
            $table->dropColumn('request_fingerprint');
            $table->dropColumn('request_token');
        });
    }
};
