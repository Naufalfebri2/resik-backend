<?php

namespace App\Models;

use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Table;
use App\Models\User;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'outlet_id',
        'table_id',
        'order_number',
        'customer_name',
        'customer_phone',
        'order_type',
        'status',
        'opened_by',
        'acknowledged_at',
        'acknowledged_by',
        'requested_pickup_time',
        'source_platform',
        'platform_order_id',
        'input_method',
        'courier_status',
        'courier_picked_up_at',
        'subtotal',
        'tax_amount',
        'service_charge_amount',
    ];

    protected $casts = [
        'acknowledged_at' => 'datetime',
        'requested_pickup_time' => 'datetime',
        'courier_picked_up_at' => 'datetime',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
