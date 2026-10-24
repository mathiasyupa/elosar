<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Medication catalog for prescription suggestions.
 *
 * Global entries (workspace_id = null) are shared; workspace-specific entries
 * are clinic favorites/custom drugs. This model intentionally does NOT use
 * BelongsToWorkspace — queries must include global + active workspace rows.
 */
class Medication extends Model
{
    use HasFactory;

    public const DOSE_UNITS = [
        'tablet'  => 'tableta',
        'tablets' => 'tabletas',
        'capsule' => 'cápsula',
        'ml'      => 'ml',
        'drop'    => 'gota',
        'puff'    => 'inhalación',
        'other'   => 'dosis',
    ];

    public const FREQUENCIES = [
        'every_6_hours'  => 'cada 6 horas',
        'every_8_hours'  => 'cada 8 horas',
        'every_12_hours' => 'cada 12 horas',
        'once_daily'     => 'una vez al día',
        'every_24_hours' => 'cada 24 horas',
        'at_bedtime'     => 'antes de dormir',
        'custom'         => 'personalizada',
    ];

    public const ROUTES = [
        'oral'       => 'oral',
        'sublingual' => 'sublingual',
        'iv'         => 'intravenosa',
        'im'         => 'intramuscular',
        'sc'         => 'subcutánea',
        'topical'    => 'tópica',
        'inhaled'    => 'inhalada',
        'rectal'     => 'rectal',
        'other'      => 'otra',
    ];

    protected $fillable = [
        'workspace_id',
        'name',
        'concentration',
        'default_dose_quantity',
        'default_dose_unit',
        'default_frequency',
        'default_duration_days',
        'route',
        'is_controlled',
        'default_instructions',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'default_dose_quantity'  => 'integer',
        'default_duration_days'  => 'integer',
        'is_controlled'          => 'boolean',
        'is_active'              => 'boolean',
        'sort_order'             => 'integer',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function isGlobal(): bool
    {
        return $this->workspace_id === null;
    }

    public function doseUnitLabel(): string
    {
        return self::DOSE_UNITS[$this->default_dose_unit] ?? $this->default_dose_unit;
    }

    public function frequencyLabel(): string
    {
        return self::FREQUENCIES[$this->default_frequency] ?? $this->default_frequency;
    }

    public function routeLabel(): string
    {
        return self::ROUTES[$this->route] ?? $this->route;
    }

    /** Builds the dosage string stored on record_prescriptions. */
    public function suggestedDosage(): string
    {
        $qty = $this->default_dose_quantity;
        $unit = $this->doseUnitLabel();

        if ($qty === 1) {
            return "1 {$unit}";
        }

        return "{$qty} {$unit}";
    }
}
