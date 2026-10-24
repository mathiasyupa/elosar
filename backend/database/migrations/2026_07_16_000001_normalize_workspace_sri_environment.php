<?php

use App\Models\Workspace;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repairs `workspaces.sri_environment` rows written by the settings API.
 *
 * The Ajustes screen used to POST the SRI `ambiente` digits ("1" / "2") while
 * the engine (SriClaveAccesoBuilder, SriSoapClient) only ever recognized
 * "test" / "production". A workspace that picked "Producción" was stored as
 * "2", which matched no branch: the clave de acceso was built with ambiente 1
 * and the SOAP client fell back to celcer — emitting to PRUEBAS while the
 * owner believed they were invoicing for real.
 *
 * The API now validates against Workspace::SRI_ENVIRONMENTS, so this only has
 * to heal rows persisted before the fix.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('workspaces')
            ->where('sri_environment', '1')
            ->update(['sri_environment' => Workspace::SRI_ENVIRONMENT_TEST]);

        DB::table('workspaces')
            ->where('sri_environment', '2')
            ->update(['sri_environment' => Workspace::SRI_ENVIRONMENT_PRODUCTION]);

        // Anything else unrecognized (null, empty, stray value) is parked on the
        // safe side: pruebas never produces a voucher with fiscal validity.
        DB::table('workspaces')
            ->whereNotIn('sri_environment', array_keys(Workspace::SRI_ENVIRONMENTS))
            ->orWhereNull('sri_environment')
            ->update(['sri_environment' => Workspace::SRI_ENVIRONMENT_TEST]);
    }

    public function down(): void
    {
        // Intentionally irreversible: restoring the digits would reintroduce the
        // silent misrouting this migration exists to repair.
    }
};
