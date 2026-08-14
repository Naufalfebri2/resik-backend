<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Services\DailyStockCalculationService;
use App\Services\MenuAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DailyStockController extends Controller
{
    public function index(Request $request, string $ingredientId)
    {
        $ingredient = $this->findOwnedIngredient($request, $ingredientId);

        if (!$ingredient) {
            return response()->json(['message' => 'Ingredient not found'], 404);
        }

        return response()->json($ingredient->dailyStocks()->orderBy('date', 'desc')->get());
    }

    public function store(Request $request, string $ingredientId)
    {
        $ingredient = $this->findOwnedIngredient($request, $ingredientId);

        if (!$ingredient) {
            return response()->json(['message' => 'Ingredient not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'date' => 'required|date',
            'opening_stock' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($ingredient->dailyStocks()->where('date', $request->date)->exists()) {
            return response()->json([
                'message' => 'Daily stock for this date already exists',
            ], 422);
        }

        $recordingMode = $ingredient->section->outlet->recording_mode;

        if ($recordingMode === 'detail') {
            $previousDailyStock = $ingredient->dailyStocks()
                ->where('date', '<', $request->date)
                ->orderBy('date', 'desc')
                ->first();

            if ($previousDailyStock && $previousDailyStock->actual_closing_stock !== null) {
                if ((float) $request->opening_stock !== (float) $previousDailyStock->actual_closing_stock) {
                    return response()->json([
                        'message' => 'Opening stock must match the previous closing stock in Detail mode',
                        'errors' => [
                            'opening_stock' => [
                                "This outlet uses Detail mode. Opening stock must equal {$previousDailyStock->actual_closing_stock} (the previous day's actual closing stock), got {$request->opening_stock}.",
                            ],
                        ],
                    ], 422);
                }
            }
        }

        $dailyStock = $ingredient->dailyStocks()->create([
            'date' => $request->date,
            'opening_stock' => $request->opening_stock,
        ]);

        MenuAvailabilityService::sync($ingredient);

        return response()->json([
            'message' => 'Daily stock created successfully',
            'daily_stock' => $dailyStock,
        ], 201);
    }

    public function update(Request $request, string $ingredientId, string $dailyStockId)
    {
        $ingredient = $this->findOwnedIngredient($request, $ingredientId);

        if (!$ingredient) {
            return response()->json(['message' => 'Ingredient not found'], 404);
        }

        $dailyStock = $ingredient->dailyStocks()->find($dailyStockId);

        if (!$dailyStock) {
            return response()->json(['message' => 'Daily stock not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'opening_stock' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $dailyStock->update([
            'opening_stock' => $request->opening_stock,
        ]);

        DailyStockCalculationService::recalculate($dailyStock);

        MenuAvailabilityService::sync($ingredient);

        return response()->json([
            'message' => 'Daily stock updated successfully',
            'daily_stock' => $dailyStock->fresh(),
        ]);
    }

    public function destroy(Request $request, string $ingredientId, string $dailyStockId)
    {
        $ingredient = $this->findOwnedIngredient($request, $ingredientId);

        if (!$ingredient) {
            return response()->json(['message' => 'Ingredient not found'], 404);
        }

        $dailyStock = $ingredient->dailyStocks()->find($dailyStockId);

        if (!$dailyStock) {
            return response()->json(['message' => 'Daily stock not found'], 404);
        }

        $dailyStock->stockOutflows()->delete();
        $dailyStock->delete();

        MenuAvailabilityService::sync($ingredient);

        return response()->json(['message' => 'Daily stock deleted successfully']);
    }

    private function findOwnedIngredient(Request $request, string $ingredientId): ?Ingredient
    {
        return Ingredient::whereHas('section.outlet', function ($query) use ($request) {
            $query->where('tenant_id', $request->user()->tenant_id);
        })->find($ingredientId);
    }
}
