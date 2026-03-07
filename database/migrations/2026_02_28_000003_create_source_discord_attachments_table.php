<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_discord_attachments', function (Blueprint $table) {
            $table->id();
            $table->string('system_slug');
            $table->string('channel_id');
            $table->string('message_id');
            $table->string('attachment_id')->nullable();
            $table->text('url');
            $table->string('filename')->default('');
            $table->string('content_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->jsonb('payload_json');
            $table->char('row_hash', 64);
            $table->timestamps();

            // Named explicitly — auto-generated name would exceed PostgreSQL 63-char limit
            $table->unique(['system_slug', 'channel_id', 'message_id', 'url'], 'disc_attach_unique');
            $table->index(['system_slug', 'channel_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_discord_attachments');
    }
};
