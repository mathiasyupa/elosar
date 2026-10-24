<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicalAttachment extends Model
{
    use BelongsToWorkspace;

    /**
     * What an uploaded file is, chosen when it is uploaded. Null on older
     * uploads, which the library still infers.
     */
    public const CATEGORIES = ['lab', 'image', 'prescription', 'certificate', 'other'];

    protected $fillable = [
        'workspace_id',
        'patient_id',
        'medical_record_id',
        /** The study order this document answers, when it is a result. */
        'study_order_id',
        'title',
        'category',
        'file_path',
        'notes',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class, 'medical_record_id');
    }
}
