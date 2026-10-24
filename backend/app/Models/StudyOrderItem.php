<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single requested study within a StudyOrder. name/category are snapshotted
 * from the catalog so the printed order stays stable.
 *
 * Does not need BelongsToWorkspace — access is controlled through StudyOrder.
 */
class StudyOrderItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'study_order_id',
        'study_id',
        'name',
        'category',
        'notes',
    ];

    public function studyOrder(): BelongsTo
    {
        return $this->belongsTo(StudyOrder::class, 'study_order_id');
    }

    public function study(): BelongsTo
    {
        return $this->belongsTo(Study::class, 'study_id');
    }
}
