<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory, BelongsToWorkspace;

    protected $fillable = [
        'invoice_id',
        'workspace_id',
        'amount',
        'payment_method',
        'payment_date',
        'reference',
        'notes',
        'registered_by',
        'registered_by_user_id',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount'       => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function registrar(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'registered_by');
    }

    /** User (e.g. cashier staff) who registered the payment. */
    public function registeredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by_user_id');
    }
}
