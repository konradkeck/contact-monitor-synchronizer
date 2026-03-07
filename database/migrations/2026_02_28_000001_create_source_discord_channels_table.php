<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_discord_channels', function (Blueprint $table) {
            $table->id();
            $table->string('system_slug');
            $table->string('guild_id');
            $table->string('channel_id');
            $table->string('channel_name')->default('');
            $table->unsignedTinyInteger('channel_type')->default(0);
            $table->json('payload_json');
            $table->char('row_hash', 64);
            $table->timestamps();

            $table->unique(['system_slug', 'channel_id']);
            $table->index(['system_slug', 'guild_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_discord_channels');
    }
};
