<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'medical_service_id',
        'description',
        'quantity',
        'unit_price',
        'discount_percentage',
        'discount_amount',
        'iva_rate',
        'iva_amount',
        'line_total',
        'sort_order',
    ];

    protected $casts = [
        'quantity'            => 'decimal:2',
        'unit_price'          => 'decimal:2',
        'discount_percentage' => 'decimal:2',
        'discount_amount'     => 'decimal:2',
        'iva_rate'            => 'decimal:2',
        'iva_amount'          => 'decimal:2',
        'line_total'          => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function medicalService(): BelongsTo
    {
        return $this->belongsTo(MedicalService::class, 'medical_service_id');
    }
}
