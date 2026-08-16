<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashAccount;
use App\Models\DailyStock;
use App\Models\Outlet;
use App\Models\PurchaseOrder;
use App\Services\CashTransactionService;
use App\Services\DailyStockCalculationService;
use App\Services\MenuAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PurchaseOrderController extends Controller
{
    private const STATUS_ORDER = ['draft', 'ordered', 'received'];

    public function index(Request $request, string $outletId)
    {
        $outlet = $this->findOwnedOutlet($request, $outletId);

        if (!$outlet) {
            return response()->json(['message' => 'Outlet not found'], 404);
        }

        $purchaseOrders = $outlet->purchaseOrders()
            ->with(['supplier', 'items.ingredient'])
            ->orderBy('date', 'desc')
            ->get();

        return response()->json($purchaseOrders);
    }

    public function show(Request $request, string $outletId, string $purchaseOrderId)
    {
        $outlet = $this->findOwnedOutlet($request, $outletId);

        if (!$outlet) {
            return response()->json(['message' => 'Outlet not found'], 404);
        }

        $purchaseOrder = $outlet->purchaseOrders()
            ->with(['supplier', 'items.ingredient'])
            ->find($purchaseOrderId);

        if (!$purchaseOrder) {
            return response()->json(['message' => 'Purchase order not found'], 404);
        }

        return response()->json($purchaseOrder);
    }

    public function store(Request $request, string $outletId)
    {
        $outlet = $this->findOwnedOutlet($request, $outletId);

        if (!$outlet) {
            return response()->json(['message' => 'Outlet not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'supplier_id' => 'required|uuid|exists:suppliers,id',
            'date' => 'required|date',
            'items' => 'required|array|min:1',
            'items.*.ingredient_id' => 'required|uuid|exists:ingredients,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $purchaseOrder = DB::transaction(function () use ($request, $outlet) {
            $purchaseOrder = $outlet->purchaseOrders()->create([
                'supplier_id' => $request->supplier_id,
                'date' => $request->date,
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);

            foreach ($request->items as $item) {
                $purchaseOrder->items()->create([
                    'ingredient_id' => $item['ingredient_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                ]);
            }

            return $purchaseOrder;
        });

        return response()->json([
            'message' => 'Purchase order created successfully',
            'purchase_order' => $purchaseOrder->load(['supplier', 'items.ingredient']),
        ], 201);
    }

    public function update(Request $request, string $outletId, string $purchaseOrderId)
    {
        $outlet = $this->findOwnedOutlet($request, $outletId);

        if (!$outlet) {
            return response()->json(['message' => 'Outlet not found'], 404);
        }

        $purchaseOrder = $outlet->purchaseOrders()->with('items')->find($purchaseOrderId);

        if (!$purchaseOrder) {
            return response()->json(['message' => 'Purchase order not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'supplier_id' => 'required|uuid|exists:suppliers,id',
            'date' => 'required|date',
            'items' => 'required|array|min:1',
            'items.*.ingredient_id' => 'required|uuid|exists:ingredients,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($purchaseOrder->status === 'received') {
            $missingIngredients = [];

            foreach ($request->items as $item) {
                $exists = DailyStock::where('ingredient_id', $item['ingredient_id'])
                    ->whereDate('date', $purchaseOrder->received_at)
                    ->exists();

                if (!$exists) {
                    $missingIngredients[] = $item['ingredient_id'];
                }
            }

            if (!empty($missingIngredients)) {
                return response()->json([
                    'message' => 'Cannot update items: daily stock closing has not been recorded for one or more ingredients on the received date',
                    'errors' => [
                        'ingredient_id' => $missingIngredients,
                    ],
                ], 422);
            }
        }

        DB::transaction(function () use ($request, $purchaseOrder) {
            $oldIngredientIds = $purchaseOrder->items->pluck('ingredient_id')->all();

            $purchaseOrder->update([
                'supplier_id' => $request->supplier_id,
                'date' => $request->date,
            ]);

            $purchaseOrder->items()->delete();

            foreach ($request->items as $item) {
                $purchaseOrder->items()->create([
                    'ingredient_id' => $item['ingredient_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                ]);
            }

            if ($purchaseOrder->status === 'received') {
                $newIngredientIds = collect($request->items)->pluck('ingredient_id')->all();
                $affectedIngredientIds = array_unique(array_merge($oldIngredientIds, $newIngredientIds));

                foreach ($affectedIngredientIds as $ingredientId) {
                    $dailyStock = DailyStock::where('ingredient_id', $ingredientId)
                        ->whereDate('date', $purchaseOrder->received_at)
                        ->first();

                    if ($dailyStock) {
                        DailyStockCalculationService::recalculate($dailyStock);
                        MenuAvailabilityService::sync($dailyStock->ingredient);
                    }
                }

                $newTotal = $purchaseOrder->items->fresh()->sum(function ($item) {
                    return $item->quantity * $item->unit_price;
                });

                $cashTransaction = $purchaseOrder->cashTransaction;

                if ($cashTransaction) {
                    $cashTransaction->update(['amount' => $newTotal]);
                }
            }
        });

        return response()->json([
            'message' => 'Purchase order updated successfully',
            'purchase_order' => $purchaseOrder->fresh(['supplier', 'items.ingredient']),
        ]);
    }

    public function destroy(Request $request, string $outletId, string $purchaseOrderId)
    {
        $outlet = $this->findOwnedOutlet($request, $outletId);

        if (!$outlet) {
            return response()->json(['message' => 'Outlet not found'], 404);
        }

        $purchaseOrder = $outlet->purchaseOrders()->with('items')->find($purchaseOrderId);

        if (!$purchaseOrder) {
            return response()->json(['message' => 'Purchase order not found'], 404);
        }

        DB::transaction(function () use ($purchaseOrder) {
            $wasReceived = $purchaseOrder->status === 'received';
            $ingredientIds = $purchaseOrder->items->pluck('ingredient_id')->unique()->all();
            $receivedAt = $purchaseOrder->received_at;

            $purchaseOrder->cashTransaction?->delete();
            $purchaseOrder->items()->delete();
            $purchaseOrder->delete();

            if ($wasReceived) {
                foreach ($ingredientIds as $ingredientId) {
                    $dailyStock = DailyStock::where('ingredient_id', $ingredientId)
                        ->whereDate('date', $receivedAt)
                        ->first();

                    if ($dailyStock) {
                        DailyStockCalculationService::recalculate($dailyStock);
                        MenuAvailabilityService::sync($dailyStock->ingredient);
                    }
                }
            }
        });

        return response()->json(['message' => 'Purchase order deleted successfully']);
    }

    public function updateStatus(Request $request, string $outletId, string $purchaseOrderId)
    {
        $outlet = $this->findOwnedOutlet($request, $outletId);

        if (!$outlet) {
            return response()->json(['message' => 'Outlet not found'], 404);
        }

        $purchaseOrder = $outlet->purchaseOrders()->with('items')->find($purchaseOrderId);

        if (!$purchaseOrder) {
            return response()->json(['message' => 'Purchase order not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:draft,ordered,received',
            'cash_account_id' => 'required_if:status,received|uuid|exists:cash_accounts,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $currentIndex = array_search($purchaseOrder->status, self::STATUS_ORDER);
        $newIndex = array_search($request->status, self::STATUS_ORDER);

        if ($newIndex < $currentIndex) {
            return response()->json([
                'message' => 'Cannot move purchase order status backward',
                'errors' => [
                    'status' => [
                        "Status cannot move from '{$purchaseOrder->status}' back to '{$request->status}'.",
                    ],
                ],
            ], 422);
        }

        $receivedDate = $request->date ?? now()->toDateString();
        $cashAccount = null;

        if ($request->status === 'received') {
            $cashAccount = CashAccount::where('outlet_id', $outlet->id)->find($request->cash_account_id);

            if (!$cashAccount) {
                return response()->json([
                    'message' => 'Cash account not found for this outlet',
                ], 404);
            }

            $missingIngredients = [];

            foreach ($purchaseOrder->items as $item) {
                $exists = DailyStock::where('ingredient_id', $item->ingredient_id)
                    ->where('date', $receivedDate)
                    ->exists();

                if (!$exists) {
                    $missingIngredients[] = $item->ingredient_id;
                }
            }

            if (!empty($missingIngredients)) {
                return response()->json([
                    'message' => 'Cannot mark as received: daily stock closing has not been recorded for one or more ingredients on this date',
                    'errors' => [
                        'ingredient_id' => $missingIngredients,
                    ],
                ], 422);
            }
        }

        DB::transaction(function () use ($request, $purchaseOrder, $receivedDate, $cashAccount) {
            $data = ['status' => $request->status];

            if ($request->status === 'received') {
                $data['received_at'] = $receivedDate;
            }

            $purchaseOrder->update($data);

            if ($request->status === 'received') {
                foreach ($purchaseOrder->items as $item) {
                    $dailyStock = DailyStock::where('ingredient_id', $item->ingredient_id)
                        ->where('date', $receivedDate)
                        ->first();

                    DailyStockCalculationService::recalculate($dailyStock);
                    MenuAvailabilityService::sync($item->ingredient);
                }

                $totalAmount = $purchaseOrder->items->sum(function ($item) {
                    return $item->quantity * $item->unit_price;
                });

                CashTransactionService::record(
                    $cashAccount,
                    $receivedDate,
                    'out',
                    'purchase_order',
                    $totalAmount,
                    "Purchase order #{$purchaseOrder->id}",
                    $purchaseOrder->id
                );
            }
        });

        return response()->json([
            'message' => 'Purchase order status updated successfully',
            'purchase_order' => $purchaseOrder->fresh(['supplier', 'items.ingredient']),
        ]);
    }

    private function findOwnedOutlet(Request $request, string $outletId): ?Outlet
    {
        return Outlet::where('tenant_id', $request->user()->tenant_id)->find($outletId);
    }
}
