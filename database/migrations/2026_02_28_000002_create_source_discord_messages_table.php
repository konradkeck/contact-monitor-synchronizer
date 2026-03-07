<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_discord_messages', function (Blueprint $table) {
            $table->id();
            $table->string('system_slug');
            $table->string('guild_id')->nullable();
            $table->string('channel_id');
            $table->string('thread_id')->nullable();
            $table->string('message_id');
            $table->string('author_id')->nullable();
            $table->text('content')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->jsonb('payload_json');
            $table->char('row_hash', 64);
            $table->timestamp('fetched_at')->useCurrent();
            $table->timestamps();

            $table->unique(['system_slug', 'channel_id', 'message_id']);
            $table->index(['system_slug', 'channel_id']);
            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_discord_messages');
    }
};
