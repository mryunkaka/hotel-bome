<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountLedger extends Model
{
    use SoftDeletes;

    protected $table = 'ledger_accounts';

    // Perlu 'hotel_id' diisi via Filament ->create(), jadi biarkan fillable.
    protected $fillable = [
        'hotel_id',
        'ledger_type',
        'reference_id',
        'reference_table',
        'account_code',
        'method',
        'debit',
        'credit',
        'date',
        'description',
        'is_posted',
        'posted_at',
        'posted_by',
    ];

    protected $casts = [
        'debit'     => 'decimal:2',
        'credit'    => 'decimal:2',
        'date'      => 'date',
        'is_posted' => 'boolean',
        'posted_at' => 'datetime',
    ];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }
}
