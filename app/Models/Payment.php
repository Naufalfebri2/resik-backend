<?php

namespace App\Models;

use App\Models\Order;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'order_id',
        'method',
        'amount',
        'cash_received',
        'change_amount',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
