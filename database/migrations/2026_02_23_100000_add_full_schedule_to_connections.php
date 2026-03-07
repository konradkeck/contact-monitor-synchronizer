<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->boolean('schedule_full_enabled')->default(false)->after('schedule_cron');
            $table->string('schedule_full_cron')->nullable()->after('schedule_full_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn(['schedule_full_enabled', 'schedule_full_cron']);
        });
    }
};
