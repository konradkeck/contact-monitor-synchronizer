<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_checkpoints', function (Blueprint $table) {
            $table->json('cursor_meta')->nullable()->after('cursor_type');
        });
    }

    public function down(): void
    {
        Schema::table('import_checkpoints', function (Blueprint $table) {
            $table->dropColumn('cursor_meta');
        });
    }
};
