<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type');           // whmcs | gmail | imap
            $table->string('system_slug');    // maps to ENV prefixes
            $table->json('settings')->nullable();
            $table->boolean('schedule_enabled')->default(false);
            $table->string('schedule_cron')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('type');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connections');
    }
};
