<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FamilyBackground extends Model
{
    use HasFactory;

    /**
     * Relative types stored in English (DB convention) with English display labels.
     *
     * @var array<string,string>
     */
    public const RELATIVE_TYPES = [
        'father'               => 'Father',
        'mother'               => 'Mother',
        'brother'              => 'Brother',
        'sister'               => 'Sister',
        'son'                  => 'Son',
        'daughter'             => 'Daughter',
        'paternal_grandfather' => 'Paternal grandfather',
        'paternal_grandmother' => 'Paternal grandmother',
        'maternal_grandfather' => 'Maternal grandfather',
        'maternal_grandmother' => 'Maternal grandmother',
        'uncle'                => 'Uncle',
        'aunt'                 => 'Aunt',
        'other'                => 'Other',
    ];

    protected $table = 'family_background';

    protected $fillable = [
        'patient_id',
        'disease',
        'relative_type',
        'notes',
    ];

    /**
     * Patient this family history belongs to.
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }
}
