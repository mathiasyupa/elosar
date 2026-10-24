<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Operational identity for non-clinical staff (receptionist, nurse, billing).
 * The staff counterpart to Doctor: no medical license, no signature, and no
 * auto-created personal workspace. Staff only reach clinics they are a member of.
 */
class StaffProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'phone',
        'job_title',
    ];

    /** Authentication account linked to this staff profile. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
