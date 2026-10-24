<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountTrialGrant extends Model
{
    public const MODE_DOCTOR_PERSONAL_FIRST_CLINIC = 'doctor_personal_first_clinic';

    public const MODE_CLINIC_ADMIN_CLINIC_ONLY = 'clinic_admin_clinic_only';

    protected $fillable = [
        'user_id',
        'mode',
        'started_at',
        'ends_at',
        'first_clinic_claimed_at',
    ];

    protected $casts = [
        'started_at' => 'immutable_datetime',
        'ends_at' => 'immutable_datetime',
        'first_clinic_claimed_at' => 'immutable_datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function allowsPersonalEntitlement(): bool
    {
        return $this->mode === self::MODE_DOCTOR_PERSONAL_FIRST_CLINIC;
    }

    public function allowsFirstClinicEntitlement(): bool
    {
        return in_array($this->mode, [
            self::MODE_DOCTOR_PERSONAL_FIRST_CLINIC,
            self::MODE_CLINIC_ADMIN_CLINIC_ONLY,
        ], true);
    }
}
