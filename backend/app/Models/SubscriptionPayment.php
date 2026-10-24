<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment a clinic made to Elosar for its subscription.
 *
 * Not to be confused with `Payment`, which is a patient paying a clinic. These
 * never mix: no invoice, no SRI voucher and no patient is involved here.
 */
class SubscriptionPayment extends Model
{
    public const METHOD_TRANSFER = 'transfer';

    public const METHOD_CASH = 'cash';

    public const METHOD_CARD = 'card';

    public const METHOD_OTHER = 'other';

    /** @var list<string> */
    public const METHODS = [
        self::METHOD_TRANSFER,
        self::METHOD_CASH,
        self::METHOD_CARD,
        self::METHOD_OTHER,
    ];

    protected $fillable = [
        'workspace_id',
        'amount',
        'method',
        'paid_at',
        'period_start',
        'period_end',
        'reference',
        'note',
        'recorded_by_user_id',
    ];

    protected $casts = [
        'paid_at' => 'immutable_date',
        'period_start' => 'immutable_date',
        'period_end' => 'immutable_date',
        // Money stays a string: the project uses bcmath and never floats.
        'amount' => 'string',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
