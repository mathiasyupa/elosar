<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\GuardsSriRetention;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use HasFactory, BelongsToWorkspace, SoftDeletes, GuardsSriRetention;

    protected $fillable = [
        'workspace_id',
        'patient_id',
        'appointment_id',
        'invoice_number',
        'issued_at',
        'status',
        'subtotal',
        'iva_amount',
        'discount_amount',
        'total',
        'amount_paid',
        'notes',
        'cancelled_at',
        'cancellation_reason',
        'voided_at',
        'created_by',
        'attending_doctor_id',
        'attending_doctor_commission_pct',
        'attending_doctor_commission_amount',
        'issued_by_user_id',
        // Buyer (comprador) fiscal data
        'buyer_id_type',
        'buyer_id',
        'buyer_name',
        'buyer_address',
        'buyer_email',
        // SRI authorization
        'sri_sequence_number',
        'sri_clave_acceso',
        'sri_authorization_number',
        'sri_authorization_date',
        'sri_status',
        'sri_xml',
        'sri_environment',
        'sri_submission_attempts',
        'sri_last_error',
        'sri_error',
        'sri_last_attempt_at',
    ];

    protected $casts = [
        'issued_at'    => 'date',
        'cancelled_at' => 'datetime',
        'voided_at'    => 'datetime',
        'subtotal'        => 'decimal:2',
        'iva_amount'      => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total'           => 'decimal:2',
        'amount_paid'  => 'decimal:2',
        'attending_doctor_commission_pct' => 'decimal:2',
        'attending_doctor_commission_amount' => 'decimal:2',
        'sri_authorization_date'  => 'datetime',
        'sri_submission_attempts' => 'integer',
        'sri_error'           => 'array',
        'sri_last_attempt_at' => 'datetime',
    ];

    /**
     * Internal SRI artifacts that must not leak through serialization: the bulky
     * signed XML and the raw last-error diagnostic.
     */
    protected $hidden = [
        'sri_xml',
        'sri_last_error',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'created_by');
    }

    /** Physician who attended the patient (source of truth for revenue-by-doctor). */
    public function attendingDoctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'attending_doctor_id');
    }

    /** User (e.g. cashier staff) who issued the invoice. */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class, 'invoice_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'invoice_id');
    }

    public function getAmountDueAttribute(): string
    {
        return bcsub((string) $this->total, (string) $this->amount_paid, 2);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isCancelled(): bool
    {
        return in_array($this->status, ['cancelled', 'void']);
    }

    public function canBeEdited(): bool
    {
        return in_array($this->status, ['draft', 'issued']);
    }

    public function isSriAuthorized(): bool
    {
        return $this->sri_status === 'authorized';
    }

    public function isSriPending(): bool
    {
        return $this->sri_status === 'pending';
    }
}
