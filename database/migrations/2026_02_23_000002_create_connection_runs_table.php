<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connection_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('connections')->cascadeOnDelete();
            $table->string('status')->default('pending'); // pending | running | completed | failed
            $table->string('triggered_by')->default('scheduler'); // scheduler | manual
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('duration_seconds')->nullable();
            // Structured log: [{t: epoch_ms, level: "info"|"error", msg: "..."}]
            $table->jsonb('log_lines')->default('[]');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['connection_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connection_runs');
    }
};
