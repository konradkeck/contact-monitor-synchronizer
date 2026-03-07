<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_gmail_messages', function (Blueprint $table) {
            $table->id();
            $table->text('system');
            $table->text('subject_email');
            $table->text('external_id');          // Gmail message id
            $table->text('thread_id')->nullable();
            $table->bigInteger('internal_date')->nullable(); // millis from internalDate
            $table->char('row_hash', 64);
            $table->json('payload_json');
            $table->timestamp('fetched_at')->useCurrent();
            $table->timestamps();

            $table->unique(['system', 'subject_email', 'external_id']);
            $table->index(['system', 'subject_email']);
            $table->index('thread_id');
            $table->index('internal_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_gmail_messages');
    }
};
