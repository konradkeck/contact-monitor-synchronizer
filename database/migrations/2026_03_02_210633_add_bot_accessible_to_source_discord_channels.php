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
        Schema::table('source_discord_channels', function (Blueprint $table) {
            $table->boolean('bot_accessible')->default(false)->after('channel_type');
        });
    }

    public function down(): void
    {
        Schema::table('source_discord_channels', function (Blueprint $table) {
            $table->dropColumn('bot_accessible');
        });
    }
};
