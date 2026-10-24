<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * ICD-10 (CIE-10) diagnosis catalog.
 *
 * Not workspace-scoped — a global catalog shared across all workspaces.
 * Descriptions are stored in Spanish (official PAHO/WHO Spanish edition,
 * clinician-facing content for the Ecuadorian market).
 */
class Cie10Code extends Model
{
    use HasFactory;

    protected $table = 'cie10_codes';

    protected $fillable = [
        'code',
        'description',
        'category',
    ];

    /**
     * Returns an array ready for Filament Select options.
     * Format: ['J06.9' => 'J06.9 — Infección aguda de las vías respiratorias superiores']
     *
     * @return array<string,string>
     */
    public static function selectOptions(): array
    {
        return static::orderBy('code')
            ->get()
            ->mapWithKeys(fn (self $code) => [
                $code->code => $code->code . ' — ' . $code->description,
            ])
            ->all();
    }
}
