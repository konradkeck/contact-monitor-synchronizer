<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('source_whmcs_tickets');

        Schema::create('source_whmcs_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('source_system');
            $table->string('source_record_id', 32);
            $table->string('row_hash', 64);
            $table->json('payload_json');
            $table->timestamps();

            $table->unique(['source_system', 'source_record_id']);
            $table->index('row_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_whmcs_tickets');
    }
};
