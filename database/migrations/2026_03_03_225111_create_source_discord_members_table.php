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
        Schema::create('source_discord_members', function (Blueprint $table) {
            $table->id();
            $table->string('system_slug');
            $table->string('guild_id');
            $table->string('user_id');
            $table->string('username')->nullable();
            $table->string('display_name')->nullable(); // guild nickname or global_name or username
            $table->boolean('is_bot')->default(false);
            $table->string('row_hash')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
            $table->unique(['system_slug', 'guild_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('source_discord_members');
    }
};
