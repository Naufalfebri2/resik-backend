<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\CashAccount;
use App\Models\PurchaseOrder;


class CashTransaction extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'cash_account_id',
        'purchase_order_id',
        'date',
        'type',
        'source',
        'amount',
        'notes',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}
