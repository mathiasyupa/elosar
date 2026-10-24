<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A money movement typed by hand, outside the invoicing flow:
 *  - expense           → operating cost of the clinic (supplies, rent, wages…)
 *  - income            → money in that never went through a patient invoice
 *                        (renting a consulting room to a visiting specialist…)
 *  - doctor_adjustment → deduction from ONE doctor's settlement (supplies they
 *                        consumed, an advance already handed over). Requires
 *                        doctor_id; it lowers that doctor's payout and raises
 *                        what the clinic keeps.
 *
 * `amount` is always positive; `type` decides the sign in the P&L.
 */
class FinanceEntry extends Model
{
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    public const TYPE_EXPENSE = 'expense';

    public const TYPE_INCOME = 'income';

    public const TYPE_DOCTOR_ADJUSTMENT = 'doctor_adjustment';

    /** @var list<string> */
    public const TYPES = [self::TYPE_EXPENSE, self::TYPE_INCOME, self::TYPE_DOCTOR_ADJUSTMENT];

    /**
     * Suggested categories per type. Values are English (schema layer); the UI
     * renders the Spanish labels, same convention as MedicalService::CATEGORIES.
     * Not enforced as an enum — a clinic may type its own.
     *
     * @var array<string, list<string>>
     */
    public const CATEGORIES = [
        self::TYPE_EXPENSE => [
            'supplies',      // Insumos médicos
            'utilities',     // Servicios básicos
            'rent',          // Alquiler del local
            'maintenance',   // Mantenimiento
            'equipment',     // Equipamiento
            'payroll',       // Personal de apoyo
            'taxes',         // Impuestos y tasas
            'other',         // Otros gastos
        ],
        self::TYPE_INCOME => [
            'room_rental',   // Alquiler de consultorio
            'equipment_rental', // Alquiler de equipos
            'other',         // Otros ingresos
        ],
        self::TYPE_DOCTOR_ADJUSTMENT => [
            'supplies',      // Insumos consumidos
            'advance',       // Adelanto entregado
            'other',         // Otro descuento
        ],
    ];

    protected $fillable = [
        'workspace_id',
        'type',
        'category',
        'description',
        'amount',
        'entry_date',
        'doctor_id',
        'receipt_number',
        'notes',
        'registered_by',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function registeredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /** Sum of `amount` for one type over a date window, as a bcmath string. */
    public static function totalFor(string $type, ?string $from = null, ?string $to = null): string
    {
        $total = static::query()
            ->where('type', $type)
            ->when($from, fn ($q) => $q->where('entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('entry_date', '<=', $to))
            ->sum('amount');

        return bcadd((string) ($total ?? '0'), '0', 2);
    }
}
