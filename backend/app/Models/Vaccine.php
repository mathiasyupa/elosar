<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Vaccine catalog (Ecuador PAI scheme). Global rows define the vaccination card:
 * each vaccine with the number of doses + boosters the scheme contemplates.
 */
class Vaccine extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'category',
        'max_doses',
        'max_boosters',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'max_doses'    => 'integer',
        'max_boosters' => 'integer',
        'is_active'    => 'boolean',
        'sort_order'   => 'integer',
    ];
}
