<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyStock;
use App\Models\StockOutflow;
use App\Services\DailyStockCalculationService;
use App\Services\MenuAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class StockOutflowController extends Controller
{
    public function index(Request $request, string $dailyStockId)
    {
        $dailyStock = $this->findOwnedDailyStock($request, $dailyStockId);

        if (!$dailyStock) {
            return response()->json(['message' => 'Daily stock not found'], 404);
        }

        return response()->json($dailyStock->stockOutflows);
    }

    public function store(Request $request, string $dailyStockId)
    {
        $dailyStock = $this->findOwnedDailyStock($request, $dailyStockId);

        if (!$dailyStock) {
            return response()->json(['message' => 'Daily stock not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'category' => 'required|in:production,waste,supplier_return',
            'quantity' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $totalOutflowSoFar = $dailyStock->stockOutflows()->sum('quantity');
        $availableStock = $dailyStock->opening_stock - $totalOutflowSoFar;

        if ($request->quantity > $availableStock) {
            return response()->json([
                'message' => 'Stock outflow exceeds available stock',
                'errors' => [
                    'quantity' => [
                        "Available stock is {$availableStock} {$dailyStock->ingredient->unit}, cannot record outflow of {$request->quantity}.",
                    ],
                ],
            ], 422);
        }

        $outflow = $dailyStock->stockOutflows()->create([
            'category' => $request->category,
            'quantity' => $request->quantity,
        ]);

        DailyStockCalculationService::recalculate($dailyStock);

        MenuAvailabilityService::sync($dailyStock->ingredient);

        return response()->json([
            'message' => 'Stock outflow recorded successfully',
            'stock_outflow' => $outflow,
            'daily_stock' => $dailyStock->fresh(),
        ], 201);
    }

    public function update(Request $request, string $dailyStockId, string $outflowId)
    {
        $dailyStock = $this->findOwnedDailyStock($request, $dailyStockId);

        if (!$dailyStock) {
            return response()->json(['message' => 'Daily stock not found'], 404);
        }

        $outflow = $dailyStock->stockOutflows()->find($outflowId);

        if (!$outflow) {
            return response()->json(['message' => 'Stock outflow not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'category' => 'required|in:production,waste,supplier_return',
            'quantity' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $totalOtherOutflows = $dailyStock->stockOutflows()
            ->where('id', '!=', $outflow->id)
            ->sum('quantity');
        $availableStock = $dailyStock->opening_stock - $totalOtherOutflows;

        if ($request->quantity > $availableStock) {
            return response()->json([
                'message' => 'Stock outflow exceeds available stock',
                'errors' => [
                    'quantity' => [
                        "Available stock is {$availableStock} {$dailyStock->ingredient->unit}, cannot set outflow to {$request->quantity}.",
                    ],
                ],
            ], 422);
        }

        $outflow->update([
            'category' => $request->category,
            'quantity' => $request->quantity,
        ]);

        DailyStockCalculationService::recalculate($dailyStock);

        MenuAvailabilityService::sync($dailyStock->ingredient);

        return response()->json([
            'message' => 'Stock outflow updated successfully',
            'stock_outflow' => $outflow->fresh(),
            'daily_stock' => $dailyStock->fresh(),
        ]);
    }

    public function destroy(Request $request, string $dailyStockId, string $outflowId)
    {
        $dailyStock = $this->findOwnedDailyStock($request, $dailyStockId);

        if (!$dailyStock) {
            return response()->json(['message' => 'Daily stock not found'], 404);
        }

        $outflow = $dailyStock->stockOutflows()->find($outflowId);

        if (!$outflow) {
            return response()->json(['message' => 'Stock outflow not found'], 404);
        }

        $outflow->delete();

        DailyStockCalculationService::recalculate($dailyStock);

        MenuAvailabilityService::sync($dailyStock->ingredient);

        return response()->json(['message' => 'Stock outflow deleted successfully']);
    }

    public function closeDailyStock(Request $request, string $dailyStockId)
    {
        $dailyStock = $this->findOwnedDailyStock($request, $dailyStockId);

        if (!$dailyStock) {
            return response()->json(['message' => 'Daily stock not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'actual_closing_stock' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $dailyStock->update([
            'actual_closing_stock' => $request->actual_closing_stock,
            'variance' => $request->actual_closing_stock - $dailyStock->expected_closing_stock,
        ]);

        MenuAvailabilityService::sync($dailyStock->ingredient);

        return response()->json([
            'message' => 'Daily stock closed successfully',
            'daily_stock' => $dailyStock,
        ]);
    }

    private function findOwnedDailyStock(Request $request, string $dailyStockId): ?DailyStock
    {
        return DailyStock::whereHas('ingredient.section.outlet', function ($query) use ($request) {
            $query->where('tenant_id', $request->user()->tenant_id);
        })->find($dailyStockId);
    }
}