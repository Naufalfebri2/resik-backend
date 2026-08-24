<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantOrder;
use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class OrderReceiptController extends Controller
{
    use ResolvesTenantOrder;

    public function pdf(Request $request, string $outletId, string $orderId)
    {
        $order = $this->findOwnedOrder($request, $outletId, $orderId);

        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        $order->load(['table', 'items.menu', 'payments', 'outlet']);

        $pdf = Pdf::loadView('receipts.order', ['order' => $order])
            ->setPaper([0, 0, 226.77, 600], 'portrait');

        return $pdf->download("receipt-{$order->order_number}.pdf");
    }
}
