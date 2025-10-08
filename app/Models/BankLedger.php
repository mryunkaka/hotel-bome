<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankLedger extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'hotel_id',
        'bank_id',
        'deposit',
        'withdraw',
        'date',
        'description',
        'method',
        'ledger_type',
        'reference_id',
        'reference_table',
        'is_posted',
        'posted_at',
        'posted_by',
    ];

    protected $casts = [
        'deposit'   => 'decimal:2',
        'withdraw'  => 'decimal:2',
        'date'      => 'date',
        'is_posted' => 'boolean',
        'posted_at' => 'datetime',
    ];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }
}
