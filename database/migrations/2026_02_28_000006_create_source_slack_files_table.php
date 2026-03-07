<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_slack_files', function (Blueprint $table) {
            $table->id();
            $table->string('system_slug');
            $table->string('channel_id');
            $table->string('message_ts');              // ts of parent message
            $table->string('file_id');
            $table->string('name')->nullable();
            $table->string('title')->nullable();
            $table->string('mimetype')->nullable();
            $table->text('url_private')->nullable();   // Slack-authenticated URL (no binary stored)
            $table->unsignedBigInteger('size')->nullable();
            $table->jsonb('payload_json');
            $table->char('row_hash', 64);
            $table->timestamps();

            $table->unique(['system_slug', 'channel_id', 'message_ts', 'file_id']);
            $table->index(['system_slug', 'channel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_slack_files');
    }
};
