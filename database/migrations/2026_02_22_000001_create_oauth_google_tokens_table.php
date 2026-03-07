<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_google_tokens', function (Blueprint $table) {
            $table->id();
            $table->text('system');
            $table->text('subject_email');
            $table->text('scopes');
            $table->text('refresh_token'); // encrypted via Crypt::encryptString
            $table->json('payload_json');
            $table->timestamps();

            $table->unique(['system', 'subject_email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_google_tokens');
    }
};
