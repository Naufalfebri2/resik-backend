<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantOrder;
use App\Http\Controllers\Controller;
use App\Models\CashAccount;
use App\Services\CashTransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class OrderPaymentController extends Controller
{
    use ResolvesTenantOrder;

    public function pay(Request $request, string $outletId, string $orderId)
    {
        $order = $this->findOwnedOrder($request, $outletId, $orderId);

        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        if ($order->status !== 'open') {
            return response()->json([
                'message' => 'Only open orders can be paid',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'cash_account_id' => 'required|uuid|exists:cash_accounts,id',
            'payments' => 'required|array|min:1',
            'payments.*.method' => 'required|in:cash,edc_bca,edc_bri,qr_bri,qr_gopay,qr_shopeepay,other',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.cash_received' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $cashAccount = CashAccount::where('outlet_id', $order->outlet_id)->find($request->cash_account_id);

        if (!$cashAccount) {
            return response()->json(['message' => 'Cash account not found for this outlet'], 404);
        }

        $activeItems = $order->items()->where('refund_status', 'none')->get();
        $subtotal = $activeItems->sum(function ($item) {
            return $item->unit_price * $item->quantity;
        });

        $servicePercentage = (float) ($request->user()->tenant->settings['service_charge_percentage'] ?? 0);
        $taxAmount = round($subtotal * 0.11, 2);
        $serviceChargeAmount = round($subtotal * ($servicePercentage / 100), 2);
        $totalDue = $subtotal + $taxAmount + $serviceChargeAmount;

        $totalPaid = collect($request->payments)->sum('amount');

        if (round($totalPaid, 2) !== round($totalDue, 2)) {
            return response()->json([
                'message' => 'Total payment does not match the order total',
                'errors' => [
                    'payments' => [
                        "Order total is {$totalDue}, but payments sum to {$totalPaid}.",
                    ],
                ],
            ], 422);
        }

        DB::transaction(function () use ($request, $order, $cashAccount, $subtotal, $taxAmount, $serviceChargeAmount, $totalDue) {
            foreach ($request->payments as $payment) {
                $cashReceived = $payment['cash_received'] ?? null;
                $changeAmount = $cashReceived !== null ? $cashReceived - $payment['amount'] : null;

                $order->payments()->create([
                    'method' => $payment['method'],
                    'amount' => $payment['amount'],
                    'cash_received' => $cashReceived,
                    'change_amount' => $changeAmount,
                ]);
            }

            $order->update([
                'status' => 'paid',
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'service_charge_amount' => $serviceChargeAmount,
            ]);

            CashTransactionService::record(
                $cashAccount,
                now()->toDateString(),
                'in',
                'pos',
                $totalDue,
                "Payment for order #{$order->order_number}"
            );
        });

        return response()->json([
            'message' => 'Order paid successfully',
            'order' => $order->fresh()->load(['items.menu', 'payments']),
        ]);
    }

    public function refundItem(Request $request, string $outletId, string $orderId, string $orderItemId)
    {
        $order = $this->findOwnedOrder($request, $outletId, $orderId);

        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        if (in_array($order->status, ['refunded', 'cancelled'])) {
            return response()->json([
                'message' => 'Cannot refund an item on an order that is already refunded or cancelled',
            ], 422);
        }

        $orderItem = $order->items()->find($orderItemId);

        if (!$orderItem) {
            return response()->json(['message' => 'Order item not found'], 404);
        }

        if ($orderItem->refund_status === 'refunded') {
            return response()->json([
                'message' => 'This item has already been refunded',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'refund_to_cash' => 'nullable|boolean',
            'cash_account_id' => 'required_if:refund_to_cash,true|uuid|exists:cash_accounts,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $refundToCash = $request->boolean('refund_to_cash');
        $cashAccount = null;

        if ($refundToCash) {
            $cashAccount = CashAccount::where('outlet_id', $order->outlet_id)->find($request->cash_account_id);

            if (!$cashAccount) {
                return response()->json(['message' => 'Cash account not found for this outlet'], 404);
            }
        }

        DB::transaction(function () use ($order, $orderItem, $refundToCash, $cashAccount) {
            $orderItem->update(['refund_status' => 'refunded']);

            if ($refundToCash) {
                CashTransactionService::record(
                    $cashAccount,
                    now()->toDateString(),
                    'out',
                    'refund',
                    $orderItem->unit_price * $orderItem->quantity,
                    "Refund item on order #{$order->order_number}"
                );
            }

            $activeItemsCount = $order->items()->where('refund_status', 'none')->count();

            if ($order->status === 'paid' || $order->status === 'partially_refunded') {
                $order->update([
                    'status' => $activeItemsCount === 0 ? 'refunded' : 'partially_refunded',
                ]);
            }
        });

        return response()->json([
            'message' => 'Item refunded successfully',
            'order' => $order->fresh()->load(['items.menu']),
        ]);
    }

    public function cancelAll(Request $request, string $outletId, string $orderId)
    {
        $order = $this->findOwnedOrder($request, $outletId, $orderId);

        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        if ($order->status !== 'open') {
            return response()->json([
                'message' => 'Cannot cancel this order: it has already been paid. Refund items individually instead.',
            ], 422);
        }

        $hasProcessedItems = $order->items()
            ->where('refund_status', 'none')
            ->where('prep_status', '!=', 'pending')
            ->exists();

        if ($hasProcessedItems) {
            return response()->json([
                'message' => 'Cannot cancel this order: one or more items have already started preparation.',
            ], 422);
        }

        DB::transaction(function () use ($order) {
            $order->items()->where('refund_status', 'none')->update(['refund_status' => 'refunded']);
            $order->update(['status' => 'cancelled']);
        });

        return response()->json([
            'message' => 'Order cancelled successfully',
            'order' => $order->fresh()->load(['items.menu']),
        ]);
    }
}
