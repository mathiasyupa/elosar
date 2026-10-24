<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Translates values stored in patients from Spanish to English:
     *
     * 1. civil_status: replaces the CHECK constraint with English values and
     *    updates all existing rows.
     *
     * 2. personal_background (JSONB): renames top-level keys and the inner
     *    values of the "habits" sub-object.
     */
    public function up(): void
    {
        // -------------------------------------------------------------------------
        // 1. civil_status — drop old constraint and recreate with English values
        // -------------------------------------------------------------------------

        // The constraint name Laravel generates for enum() on PostgreSQL is:
        // {table}_{column}_check
        DB::unprepared('ALTER TABLE patients DROP CONSTRAINT IF EXISTS patients_civil_status_check');

        // Update existing values before applying the new constraint
        DB::unprepared("UPDATE patients SET civil_status = 'single'      WHERE civil_status = 'soltero'");
        DB::unprepared("UPDATE patients SET civil_status = 'married'     WHERE civil_status = 'casado'");
        DB::unprepared("UPDATE patients SET civil_status = 'common_law'  WHERE civil_status = 'union_libre'");
        DB::unprepared("UPDATE patients SET civil_status = 'divorced'    WHERE civil_status = 'divorciado'");
        DB::unprepared("UPDATE patients SET civil_status = 'widowed'     WHERE civil_status = 'viudo'");
        DB::unprepared("UPDATE patients SET civil_status = 'separated'   WHERE civil_status = 'separado'");

        // Recreate the CHECK constraint with English values
        DB::unprepared(
            "ALTER TABLE patients ADD CONSTRAINT patients_civil_status_check
             CHECK (civil_status IN ('single','married','common_law','divorced','widowed','separated'))"
        );

        // -------------------------------------------------------------------------
        // 2. personal_background (JSONB) — rename top-level keys
        // -------------------------------------------------------------------------

        // alergias → allergies
        DB::unprepared(
            "UPDATE patients
             SET personal_background = personal_background - 'alergias'
                 || jsonb_build_object('allergies', personal_background->'alergias')
             WHERE personal_background ? 'alergias'"
        );

        // cirugias → surgeries
        DB::unprepared(
            "UPDATE patients
             SET personal_background = personal_background - 'cirugias'
                 || jsonb_build_object('surgeries', personal_background->'cirugias')
             WHERE personal_background ? 'cirugias'"
        );

        // medicacion_actual → current_medication
        DB::unprepared(
            "UPDATE patients
             SET personal_background = personal_background - 'medicacion_actual'
                 || jsonb_build_object('current_medication', personal_background->'medicacion_actual')
             WHERE personal_background ? 'medicacion_actual'"
        );

        // habitos → habits (rename the top-level key keeping its content)
        DB::unprepared(
            "UPDATE patients
             SET personal_background = personal_background - 'habitos'
                 || jsonb_build_object('habits', personal_background->'habitos')
             WHERE personal_background ? 'habitos'"
        );

        // -------------------------------------------------------------------------
        // 3. Inner values of habits.tobacco (previously habitos.tabaco)
        //    nunca → never | ocasional → occasional | frecuente → frequent
        // -------------------------------------------------------------------------

        DB::unprepared(
            "UPDATE patients
             SET personal_background = jsonb_set(
                 personal_background,
                 '{habits,tobacco}',
                 '\"never\"'
             )
             WHERE personal_background->'habits'->>'tobacco' = 'nunca'"
        );

        DB::unprepared(
            "UPDATE patients
             SET personal_background = jsonb_set(
                 personal_background,
                 '{habits,tobacco}',
                 '\"occasional\"'
             )
             WHERE personal_background->'habits'->>'tobacco' = 'ocasional'"
        );

        DB::unprepared(
            "UPDATE patients
             SET personal_background = jsonb_set(
                 personal_background,
                 '{habits,tobacco}',
                 '\"frequent\"'
             )
             WHERE personal_background->'habits'->>'tobacco' = 'frecuente'"
        );

        // Rename the inner key tabaco → tobacco inside habits
        // (in case the object still had the Spanish key before the top-level migration)
        DB::unprepared(
            "UPDATE patients
             SET personal_background = jsonb_set(
                 personal_background,
                 '{habits}',
                 (personal_background->'habits') - 'tabaco'
                 || jsonb_build_object('tobacco', personal_background->'habits'->'tabaco')
             )
             WHERE personal_background->'habits' ? 'tabaco'"
        );

        // -------------------------------------------------------------------------
        // 4. Inner values of habits.alcohol
        //    nunca → never | ocasional → occasional | frecuente → frequent
        // -------------------------------------------------------------------------

        DB::unprepared(
            "UPDATE patients
             SET personal_background = jsonb_set(
                 personal_background,
                 '{habits,alcohol}',
                 '\"never\"'
             )
             WHERE personal_background->'habits'->>'alcohol' = 'nunca'"
        );

        DB::unprepared(
            "UPDATE patients
             SET personal_background = jsonb_set(
                 personal_background,
                 '{habits,alcohol}',
                 '\"occasional\"'
             )
             WHERE personal_background->'habits'->>'alcohol' = 'ocasional'"
        );

        DB::unprepared(
            "UPDATE patients
             SET personal_background = jsonb_set(
                 personal_background,
                 '{habits,alcohol}',
                 '\"frequent\"'
             )
             WHERE personal_background->'habits'->>'alcohol' = 'frecuente'"
        );
    }

    /**
     * Reverts the migration: English → Spanish in civil_status and JSONB.
     */
    public function down(): void
    {
        // -------------------------------------------------------------------------
        // 1. civil_status — revert constraint and values
        // -------------------------------------------------------------------------

        DB::unprepared('ALTER TABLE patients DROP CONSTRAINT IF EXISTS patients_civil_status_check');

        DB::unprepared("UPDATE patients SET civil_status = 'soltero'     WHERE civil_status = 'single'");
        DB::unprepared("UPDATE patients SET civil_status = 'casado'      WHERE civil_status = 'married'");
        DB::unprepared("UPDATE patients SET civil_status = 'union_libre' WHERE civil_status = 'common_law'");
        DB::unprepared("UPDATE patients SET civil_status = 'divorciado'  WHERE civil_status = 'divorced'");
        DB::unprepared("UPDATE patients SET civil_status = 'viudo'       WHERE civil_status = 'widowed'");
        DB::unprepared("UPDATE patients SET civil_status = 'separado'    WHERE civil_status = 'separated'");

        DB::unprepared(
            "ALTER TABLE patients ADD CONSTRAINT patients_civil_status_check
             CHECK (civil_status IN ('soltero','casado','union_libre','divorciado','viudo','separado'))"
        );

        // -------------------------------------------------------------------------
        // 2. personal_background — revert top-level keys
        // -------------------------------------------------------------------------

        DB::unprepared(
            "UPDATE patients
             SET personal_background = personal_background - 'allergies'
                 || jsonb_build_object('alergias', personal_background->'allergies')
             WHERE personal_background ? 'allergies'"
        );

        DB::unprepared(
            "UPDATE patients
             SET personal_background = personal_background - 'surgeries'
                 || jsonb_build_object('cirugias', personal_background->'surgeries')
             WHERE personal_background ? 'surgeries'"
        );

        DB::unprepared(
            "UPDATE patients
             SET personal_background = personal_background - 'current_medication'
                 || jsonb_build_object('medicacion_actual', personal_background->'current_medication')
             WHERE personal_background ? 'current_medication'"
        );

        DB::unprepared(
            "UPDATE patients
             SET personal_background = personal_background - 'habits'
                 || jsonb_build_object('habitos', personal_background->'habits')
             WHERE personal_background ? 'habits'"
        );

        // -------------------------------------------------------------------------
        // 3. Revert inner values of habitos.tabaco
        // -------------------------------------------------------------------------

        DB::unprepared(
            "UPDATE patients
             SET personal_background = jsonb_set(
                 personal_background,
                 '{habitos,tabaco}',
                 '\"nunca\"'
             )
             WHERE personal_background->'habitos'->>'tabaco' = 'never'"
        );

        DB::unprepared(
            "UPDATE patients
             SET personal_background = jsonb_set(
                 personal_background,
                 '{habitos,tabaco}',
                 '\"ocasional\"'
             )
             WHERE personal_background->'habitos'->>'tabaco' = 'occasional'"
        );

        DB::unprepared(
            "UPDATE patients
             SET personal_background = jsonb_set(
                 personal_background,
                 '{habitos,tabaco}',
                 '\"frecuente\"'
             )
             WHERE personal_background->'habitos'->>'tabaco' = 'frequent'"
        );

        // Revert the inner key tobacco → tabaco
        DB::unprepared(
            "UPDATE patients
             SET personal_background = jsonb_set(
                 personal_background,
                 '{habitos}',
                 (personal_background->'habitos') - 'tobacco'
                 || jsonb_build_object('tabaco', personal_background->'habitos'->'tobacco')
             )
             WHERE personal_background->'habitos' ? 'tobacco'"
        );

        // -------------------------------------------------------------------------
        // 4. Revert inner values of habitos.alcohol
        // -------------------------------------------------------------------------

        DB::unprepared(
            "UPDATE patients
             SET personal_background = jsonb_set(
                 personal_background,
                 '{habitos,alcohol}',
                 '\"nunca\"'
             )
             WHERE personal_background->'habitos'->>'alcohol' = 'never'"
        );

        DB::unprepared(
            "UPDATE patients
             SET personal_background = jsonb_set(
                 personal_background,
                 '{habitos,alcohol}',
                 '\"ocasional\"'
             )
             WHERE personal_background->'habitos'->>'alcohol' = 'occasional'"
        );

        DB::unprepared(
            "UPDATE patients
             SET personal_background = jsonb_set(
                 personal_background,
                 '{habitos,alcohol}',
                 '\"frecuente\"'
             )
             WHERE personal_background->'habitos'->>'alcohol' = 'frequent'"
        );
    }
};
