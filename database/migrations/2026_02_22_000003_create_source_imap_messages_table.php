<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_imap_messages', function (Blueprint $table) {
            $table->id();
            $table->string('account');               // ENV slug (e.g. "office")
            $table->string('mailbox');               // e.g. "INBOX"
            $table->unsignedBigInteger('uid');       // IMAP UID (unique per account+mailbox)
            $table->text('message_id')->nullable();  // Message-ID header
            $table->char('row_hash', 64);
            $table->json('payload_json');
            $table->timestamp('fetched_at')->useCurrent();
            $table->timestamps();

            $table->unique(['account', 'mailbox', 'uid']);
            $table->index(['account', 'mailbox']);
            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_imap_messages');
    }
};
