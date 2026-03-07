<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_slack_channels', function (Blueprint $table) {
            $table->id();
            $table->string('system_slug');
            $table->string('channel_id');
            $table->string('channel_name')->default('');
            $table->boolean('is_private')->default(false);
            $table->boolean('is_member')->default(false);
            $table->text('topic')->nullable();
            $table->text('purpose')->nullable();
            $table->unsignedInteger('num_members')->nullable();
            $table->jsonb('payload_json');
            $table->char('row_hash', 64);
            $table->timestamps();

            $table->unique(['system_slug', 'channel_id']);
            $table->index('system_slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_slack_channels');
    }
};
