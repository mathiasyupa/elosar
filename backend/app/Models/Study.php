<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Orderable study catalog (laboratory tests + imaging studies).
 *
 * Global entries (workspace_id = null) are shared; workspace-specific entries are
 * clinic favorites/custom studies. Like Medication this model intentionally does
 * NOT use BelongsToWorkspace — queries must include global + active workspace rows.
 */
class Study extends Model
{
    use HasFactory;

    public const TYPE_LABORATORY = 'laboratory';

    public const TYPE_IMAGING = 'imaging';

    /** @var array<string, string> */
    public const TYPES = [
        self::TYPE_LABORATORY => 'Laboratorio',
        self::TYPE_IMAGING    => 'Imagenología',
    ];

    protected $fillable = [
        'workspace_id',
        'type',
        'category',
        'name',
        'code',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function isGlobal(): bool
    {
        return $this->workspace_id === null;
    }
}
