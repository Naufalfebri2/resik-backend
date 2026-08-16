<?php

namespace App\Models;

use App\Models\CashTransaction;
use App\Models\Outlet;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PurchaseOrder extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'supplier_id',
        'outlet_id',
        'date',
        'status',
        'received_at',
        'created_by',
    ];

    protected $casts = [
        'date' => 'date',
        'received_at' => 'date',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cashTransaction(): HasOne
    {
        return $this->hasOne(CashTransaction::class);
    }
}
