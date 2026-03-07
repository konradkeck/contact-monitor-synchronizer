<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_slack_users', function (Blueprint $table) {
            $table->id();
            $table->string('system_slug');
            $table->string('user_id');
            $table->string('display_name')->nullable();
            $table->string('real_name')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_bot')->default(false);
            $table->boolean('deleted')->default(false);
            $table->char('row_hash', 64);
            $table->timestamps();

            $table->unique(['system_slug', 'user_id']);
            $table->index('system_slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_slack_users');
    }
};
