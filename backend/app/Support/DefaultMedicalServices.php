<?php

namespace App\Support;

use App\Models\MedicalService;

/**
 * Starter fee schedule seeded into a brand-new doctor's personal workspace so
 * "Servicios" isn't completely empty right after self-registration. Kept
 * deliberately short (one example per category) — doctors build their real
 * catalog themselves; this is just a hint of what belongs here, not a
 * pre-filled arancel. Not applied to clinics (they inherit the owner's
 * workspace, which already has one) or to demo data (DemoDataSeeder curates
 * its own).
 */
class DefaultMedicalServices
{
    /** [name, category, base_price, iva_rate] */
    private const CATALOG = [
        ['Consulta médica general', 'general', 30.00, 0],
        ['Biometría hemática completa', 'laboratory', 12.00, 15],
        ['Radiografía de tórax', 'imaging', 35.00, 15],
        ['Curación de heridas', 'procedure', 15.00, 15],
        ['Consulta odontológica', 'dental', 25.00, 0],
    ];

    /** Bulk insert (one query) — this runs on every doctor self-registration. */
    public static function seed(int $workspaceId): void
    {
        $now = now();

        $rows = array_map(
            fn (int $index, array $row) => [
                'workspace_id' => $workspaceId,
                'name'         => $row[0],
                'category'     => $row[1],
                'base_price'   => $row[2],
                'iva_rate'     => $row[3],
                'is_active'    => true,
                'sort_order'   => $index,
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            array_keys(self::CATALOG),
            self::CATALOG,
        );

        // insert() still routes through Eloquent\Builder::toBase(), which applies
        // global scopes — WorkspaceScope would throw with no active workspace
        // bound yet (this runs mid-registration, before any session exists).
        app(ActiveWorkspace::class)->runUnguarded(fn () => MedicalService::insert($rows));
    }
}
