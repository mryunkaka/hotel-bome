<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CardScan extends Model
{
    protected $fillable = ['uid_raw', 'uid_norm', 'source', 'meta'];
    protected $casts = ['meta' => 'array'];
}
