<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MedicalService extends Model
{
    use HasFactory, BelongsToWorkspace;

    protected $fillable = [
        'workspace_id',
        'name',
        'description',
        'category',
        'base_price',
        'iva_rate',
        'is_active',
        'sort_order',
    ];

    /**
     * Service categories stored in English (DB convention) with Spanish display labels.
     *
     * @var array<string,string>
     */
    public const CATEGORIES = [
        'general'    => 'General',
        'laboratory' => 'Laboratory',
        'imaging'    => 'Imaging',
        'procedure'  => 'Procedure',
        'dental'     => 'Dental',
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
        'iva_rate'   => 'decimal:2',
        'is_active'  => 'boolean',
    ];

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class, 'medical_service_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
