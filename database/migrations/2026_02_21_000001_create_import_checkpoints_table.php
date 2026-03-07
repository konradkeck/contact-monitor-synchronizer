<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->string('source_system');
            $table->string('importer');
            $table->string('entity');
            $table->string('cursor_type');
            $table->unsignedBigInteger('last_processed_id')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->unique(['source_system', 'importer', 'entity', 'cursor_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_checkpoints');
    }
};
