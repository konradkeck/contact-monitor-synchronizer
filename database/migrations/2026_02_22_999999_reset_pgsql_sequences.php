<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * After migrating data from SQLite → PostgreSQL with explicit IDs,
 * the auto-increment sequences are still at 1 even though rows exist.
 * This migration advances every sequence to MAX(id) so new inserts don't conflict.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared("
            DO \$\$
            DECLARE
                r   RECORD;
                seq TEXT;
            BEGIN
                FOR r IN (
                    SELECT c.table_name
                    FROM information_schema.columns c
                    JOIN information_schema.tables  t
                      ON t.table_schema = c.table_schema
                     AND t.table_name   = c.table_name
                     AND t.table_type   = 'BASE TABLE'
                    WHERE c.table_schema = 'public'
                      AND c.column_name  = 'id'
                )
                LOOP
                    seq := pg_get_serial_sequence(r.table_name, 'id');
                    IF seq IS NOT NULL THEN
                        EXECUTE format(
                            'SELECT setval(%L, COALESCE((SELECT MAX(id) FROM %I), 1))',
                            seq, r.table_name
                        );
                    END IF;
                END LOOP;
            END \$\$;
        ");
    }

    public function down(): void
    {
        // Cannot meaningfully reverse a sequence reset
    }
};
