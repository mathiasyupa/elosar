<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Brings the entitlement tables onto the same datetime convention as the rest
 * of the schema.
 *
 * These four tables were the only ones in 107 migrations using `timestamptz`,
 * and that difference silently shifted every deadline by five hours. Eloquent
 * writes a datetime as a bare `Y-m-d H:i:s` — the app-timezone wall clock, no
 * offset — and reads it back through the same format. A plain `timestamp`
 * round-trips correctly. A `timestamptz` comes back as `... +00`, which no
 * longer matches that format, so Laravel falls through to a parser that
 * honours the offset and reads a Guayaquil wall clock as if it were UTC.
 *
 * Measured, not assumed: writing 2026-09-02 23:59:59-05 and reading it back
 * gave 23:59:59-05 from `timestamp` and 23:59:59+00 from `timestamptz`.
 *
 * The conversion uses `AT TIME ZONE 'America/Guayaquil'` so each stored instant
 * is re-expressed as the local wall clock the new convention expects. Values
 * written through the old UTC normalization hold the correct instant, so this
 * preserves them exactly — a plain cast would have shifted them instead.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'account_trial_grants' => ['started_at', 'ends_at', 'first_clinic_claimed_at', 'created_at', 'updated_at'],
        'workspace_entitlements' => ['trial_ends_at', 'access_ends_at', 'created_at', 'updated_at'],
        'workspace_entitlement_events' => ['created_at', 'updated_at'],
        'platform_settings' => ['created_at', 'updated_at'],
    ];

    private const TIMEZONE = 'America/Guayaquil';

    public function up(): void
    {
        // The events table rejects UPDATE and DELETE through triggers. ALTER
        // TYPE rewrites the table rather than issuing row updates, so the
        // triggers do not fire and the audit trail stays intact.
        $this->convert('timestamp(0) without time zone', self::TIMEZONE);
    }

    public function down(): void
    {
        $this->convert('timestamptz(0)', 'UTC');
    }

    private function convert(string $type, string $zone): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement(sprintf(
                    'ALTER TABLE %s ALTER COLUMN %s TYPE %s USING %s AT TIME ZONE %s',
                    $table,
                    $column,
                    $type,
                    $column,
                    DB::getPdo()->quote($zone),
                ));
            }
        }
    }
};
