<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rule R5: refund_events is append-only. The RefundEvent model already
 * refuses updates and deletes; these triggers refuse them in the database
 * too. Creating a trigger needs the TRIGGER privilege (and, with binary
 * logging, log_bin_trust_function_creators or SUPER), which shared hosting
 * users often lack. When it is refused the migration still succeeds and
 * logs a warning, so the application-level protection stays the floor.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (['UPDATE' => 'update', 'DELETE' => 'delete'] as $operation => $name) {
            try {
                DB::unprepared("DROP TRIGGER IF EXISTS refund_events_no_{$name}");
                DB::unprepared(
                    "CREATE TRIGGER refund_events_no_{$name} BEFORE {$operation} ON refund_events FOR EACH ROW "
                    ."SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refund_events is append-only'"
                );
            } catch (Throwable $e) {
                Log::warning("Could not create the refund_events {$name} trigger; the application still refuses it.", [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS refund_events_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS refund_events_no_delete');
    }
};
