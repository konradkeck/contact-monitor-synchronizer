<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_slack_messages', function (Blueprint $table) {
            $table->id();
            $table->string('system_slug');
            $table->string('channel_id');
            $table->string('ts');                      // Slack timestamp: "1234567890.123456"
            $table->string('thread_ts')->nullable();   // Set for threaded replies
            $table->string('user_id')->nullable();
            $table->string('bot_id')->nullable();
            $table->text('text')->nullable();
            $table->string('subtype')->nullable();     // e.g. "bot_message", "channel_join"
            $table->boolean('is_deleted')->default(false);
            $table->jsonb('payload_json');
            $table->char('row_hash', 64);
            $table->timestamp('sent_at')->nullable();  // Parsed from ts
            $table->timestamp('fetched_at')->useCurrent();
            $table->timestamps();

            $table->unique(['system_slug', 'channel_id', 'ts']);
            $table->index(['system_slug', 'channel_id']);
            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_slack_messages');
    }
};
