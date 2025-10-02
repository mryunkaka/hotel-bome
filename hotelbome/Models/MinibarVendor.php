<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class MinibarVendor extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'minibar_vendors';

    protected $fillable = [
        'hotel_id',
        'name',
        'contact_person',
        'phone',
        'email',
        'address',
        'notes',
    ];

    protected $casts = [];

    /* Relationships */
    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }

    public function stockMovements()
    {
        return $this->hasMany(MinibarStockMovement::class, 'vendor_id');
    }
}
