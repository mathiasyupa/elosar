<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A service or procedure the doctor declares as performed during a visit, so
 * the cash desk invoices what actually happened instead of reconstructing it.
 *
 * Workspace-scoped in its own right, unlike the other MedicalRecord children:
 * this row feeds billing, and relying on the parent alone for isolation is a
 * documented leak risk that must not be extended to money.
 *
 * name/category are snapshotted from the catalogue so a later rename or delete
 * cannot rewrite what a closed visit says was done.
 */
class RecordService extends Model
{
    use HasFactory, BelongsToWorkspace, SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'medical_record_id',
        'medical_service_id',
        'name',
        'category',
        'quantity',
        'unit_price',
        'notes',
    ];

    protected $casts = [
        'quantity'   => 'decimal:2',
        'unit_price' => 'decimal:2',
    ];

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class, 'medical_record_id');
    }

    public function medicalService(): BelongsTo
    {
        return $this->belongsTo(MedicalService::class, 'medical_service_id');
    }

    /**
     * Line total, or null when the service was declared without a price.
     *
     * bcmath, never floats: this figure becomes an invoice line.
     */
    public function lineTotal(): ?string
    {
        if ($this->unit_price === null) {
            return null;
        }

        return bcmul((string) $this->unit_price, (string) $this->quantity, 2);
    }
}
