<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\CashTransaction;

class CashTransactionService
{
    /**
     * Record a system-generated cash transaction (e.g. from a received
     * purchase order). Used internally by other controllers/services —
     * manual entries go through CashTransactionController instead.
     */
    public static function record(
        CashAccount $cashAccount,
        string $date,
        string $type,
        string $source,
        float $amount,
        ?string $notes = null,
        ?string $purchaseOrderId = null
    ): CashTransaction {
        return $cashAccount->cashTransactions()->create([
            'purchase_order_id' => $purchaseOrderId,
            'date' => $date,
            'type' => $type,
            'source' => $source,
            'amount' => $amount,
            'notes' => $notes,
        ]);
    }
}
