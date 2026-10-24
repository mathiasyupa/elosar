<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Row-level write audit via a PostgreSQL trigger.
 *
 * A database trigger (rather than Eloquent events) is used deliberately: it
 * captures EVERY mutation to the protected tables, including writes that bypass
 * Eloquent entirely — raw query-builder updates, the SRI job's forceFill/save,
 * and bulk updates — none of which a model observer would see.
 *
 * The acting user is attributed best-effort from the `app.audit_user_id`
 * runtime setting, set per request by App\Http\Middleware\SetAuditUser. The
 * bulky `sri_xml` column is stripped from the captured snapshots to keep the
 * log compact (the signed XML is already persisted on the voucher itself).
 */
return new class extends Migration
{
    /**
     * Tables whose row mutations are audited.
     *
     * @var list<string>
     */
    private array $tables = [
        'patients',
        'medical_records',
        'record_diagnoses',
        'record_prescriptions',
        'invoices',
        'credit_notes',
        'payments',
    ];

    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('table_name', 64);
            $table->unsignedBigInteger('record_id')->nullable();
            $table->string('operation', 10); // INSERT | UPDATE | DELETE
            $table->jsonb('old_data')->nullable();
            $table->jsonb('new_data')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['table_name', 'record_id']);
            $table->index('user_id');
            $table->index('occurred_at');
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_row_change() RETURNS trigger AS $$
            DECLARE
                v_user_id bigint;
            BEGIN
                BEGIN
                    v_user_id := nullif(current_setting('app.audit_user_id', true), '')::bigint;
                EXCEPTION WHEN others THEN
                    v_user_id := NULL;
                END;

                IF (TG_OP = 'DELETE') THEN
                    INSERT INTO audit_log (table_name, record_id, operation, old_data, new_data, user_id, occurred_at)
                    VALUES (TG_TABLE_NAME, OLD.id, TG_OP, to_jsonb(OLD) - 'sri_xml', NULL, v_user_id, now());
                    RETURN OLD;
                ELSIF (TG_OP = 'UPDATE') THEN
                    INSERT INTO audit_log (table_name, record_id, operation, old_data, new_data, user_id, occurred_at)
                    VALUES (TG_TABLE_NAME, NEW.id, TG_OP, to_jsonb(OLD) - 'sri_xml', to_jsonb(NEW) - 'sri_xml', v_user_id, now());
                    RETURN NEW;
                ELSE
                    INSERT INTO audit_log (table_name, record_id, operation, old_data, new_data, user_id, occurred_at)
                    VALUES (TG_TABLE_NAME, NEW.id, TG_OP, NULL, to_jsonb(NEW) - 'sri_xml', v_user_id, now());
                    RETURN NEW;
                END IF;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        foreach ($this->tables as $table) {
            DB::unprepared(
                "CREATE TRIGGER {$table}_audit "
                . "AFTER INSERT OR UPDATE OR DELETE ON {$table} "
                . 'FOR EACH ROW EXECUTE FUNCTION audit_row_change();'
            );
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_audit ON {$table};");
        }

        DB::unprepared('DROP FUNCTION IF EXISTS audit_row_change();');

        Schema::dropIfExists('audit_log');
    }
};
