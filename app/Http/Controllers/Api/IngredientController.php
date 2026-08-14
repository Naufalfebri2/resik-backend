<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\Section;
use App\Services\CustomFieldValidator;
use App\Services\MenuAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class IngredientController extends Controller
{
    public function index(Request $request, string $sectionId)
    {
        $section = $this->findOwnedSection($request, $sectionId);

        if (!$section) {
            return response()->json(['message' => 'Section not found'], 404);
        }

        $ingredients = $section->ingredients;

        $ingredients->each(function ($ingredient) {
            $ingredient->current_stock = MenuAvailabilityService::getCurrentStock($ingredient);
        });

        return response()->json($ingredients);
    }

    public function lowStock(Request $request)
    {
        $ingredients = Ingredient::whereHas('section.outlet', function ($query) use ($request) {
            $query->where('tenant_id', $request->user()->tenant_id);
        })
            ->with(['section', 'dailyStocks' => function ($query) {
                $query->orderBy('date', 'desc')->limit(1);
            }])
            ->get()
            ->filter(function ($ingredient) {
                $currentStock = MenuAvailabilityService::getCurrentStock($ingredient);
                return $currentStock !== null && $currentStock <= $ingredient->alert_threshold;
            })
            ->values();

        return response()->json($ingredients);
    }

    public function show(Request $request, string $ingredientId)
    {
        $ingredient = Ingredient::whereHas('section.outlet', function ($query) use ($request) {
            $query->where('tenant_id', $request->user()->tenant_id);
        })
            ->with(['section.outlet'])
            ->with(['dailyStocks' => function ($query) {
                $query->orderBy('date', 'desc')->limit(30)->with('stockOutflows');
            }])
            ->find($ingredientId);

        if (!$ingredient) {
            return response()->json(['message' => 'Ingredient not found'], 404);
        }

        return response()->json($ingredient);
    }

    public function store(Request $request, string $sectionId)
    {
        $section = $this->findOwnedSection($request, $sectionId);

        if (!$section) {
            return response()->json(['message' => 'Section not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'unit' => 'required|string|max:20',
            'risk_category' => 'required|in:perishable,dry_goods',
            'alert_threshold' => 'nullable|numeric|min:0',
            'custom_fields' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $customFields = CustomFieldValidator::validate(
                $request->user()->tenant_id,
                'ingredients',
                $request->custom_fields ?? []
            );
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Custom field validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        $ingredient = Ingredient::create([
            'section_id' => $section->id,
            'name' => $request->name,
            'unit' => $request->unit,
            'risk_category' => $request->risk_category,
            'alert_threshold' => $request->alert_threshold ?? 0,
            'custom_fields' => $customFields,
        ]);

        return response()->json([
            'message' => 'Ingredient created successfully',
            'ingredient' => $ingredient,
        ], 201);
    }

    public function update(Request $request, string $sectionId, string $ingredientId)
    {
        $section = $this->findOwnedSection($request, $sectionId);

        if (!$section) {
            return response()->json(['message' => 'Section not found'], 404);
        }

        $ingredient = $section->ingredients()->find($ingredientId);

        if (!$ingredient) {
            return response()->json(['message' => 'Ingredient not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:100',
            'unit' => 'sometimes|required|string|max:20',
            'risk_category' => 'sometimes|required|in:perishable,dry_goods',
            'alert_threshold' => 'sometimes|nullable|numeric|min:0',
            'custom_fields' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $updateData = $request->only(['name', 'unit', 'risk_category', 'alert_threshold']);

        if ($request->has('custom_fields')) {
            try {
                $updateData['custom_fields'] = CustomFieldValidator::validate(
                    $request->user()->tenant_id,
                    'ingredients',
                    $request->custom_fields
                );
            } catch (ValidationException $e) {
                return response()->json([
                    'message' => 'Custom field validation failed',
                    'errors' => $e->errors(),
                ], 422);
            }
        }

        $ingredient->update($updateData);

        MenuAvailabilityService::sync($ingredient);

        return response()->json([
            'message' => 'Ingredient updated successfully',
            'ingredient' => $ingredient,
        ]);
    }

    public function destroy(Request $request, string $sectionId, string $ingredientId)
    {
        $section = $this->findOwnedSection($request, $sectionId);

        if (!$section) {
            return response()->json(['message' => 'Section not found'], 404);
        }

        $ingredient = $section->ingredients()->find($ingredientId);

        if (!$ingredient) {
            return response()->json(['message' => 'Ingredient not found'], 404);
        }

        $ingredient->delete();

        return response()->json(['message' => 'Ingredient deleted successfully']);
    }

    private function findOwnedSection(Request $request, string $sectionId): ?Section
    {
        return Section::whereHas('outlet', function ($query) use ($request) {
            $query->where('tenant_id', $request->user()->tenant_id);
        })->find($sectionId);
    }
}
