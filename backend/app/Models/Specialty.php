<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Specialty extends Model
{
    use HasFactory;

    public const CAPABILITY_GYNECO_OBSTETRICS = 'gyneco_obstetrics';

    /**
     * Mass assignable attributes (Mass Assignment Protection).
     */
    protected $fillable = [
        'name',
        'description',
    ];

    /**
     * Capabilities are deliberately excluded from $fillable. The public
     * specialty-creation endpoint can add a catalog row, but can never grant a
     * clinical module to that row.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
        ];
    }

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities ?? [], true);
    }

    /**
     * One-to-many relation with doctors.
     * A specialty can have multiple doctors (legacy specialty_id FK).
     */
    public function doctors(): HasMany
    {
        return $this->hasMany(Doctor::class, 'specialty_id');
    }
}
