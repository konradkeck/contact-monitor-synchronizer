<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_metricscube_client_activities', function (Blueprint $table) {
            $table->id();
            $table->string('system');
            $table->string('external_id');
            $table->char('row_hash', 64);
            $table->json('payload_json');
            $table->timestamp('fetched_at')->useCurrent();
            $table->timestamps();

            $table->unique(['system', 'external_id']);
            $table->index('system');
            $table->index('external_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_metricscube_client_activities');
    }
};
