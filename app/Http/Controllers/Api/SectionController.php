<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SectionController extends Controller
{
    public function index(Request $request, string $outletId)
    {
        $outlet = $this->findOwnedOutlet($request, $outletId);

        if (!$outlet) {
            return response()->json(['message' => 'Outlet not found'], 404);
        }

        return response()->json($outlet->sections()->withCount('employees')->get());
    }

    public function store(Request $request, string $outletId)
    {
        $outlet = $this->findOwnedOutlet($request, $outletId);

        if (!$outlet) {
            return response()->json(['message' => 'Outlet not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $section = Section::create([
            'outlet_id' => $outlet->id,
            'name' => $request->name,
        ]);

        return response()->json([
            'message' => 'Section created successfully',
            'section' => $section,
        ], 201);
    }

    public function update(Request $request, string $outletId, string $sectionId)
    {
        $outlet = $this->findOwnedOutlet($request, $outletId);

        if (!$outlet) {
            return response()->json(['message' => 'Outlet not found'], 404);
        }

        $section = $outlet->sections()->find($sectionId);

        if (!$section) {
            return response()->json(['message' => 'Section not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $section->update(['name' => $request->name]);

        return response()->json([
            'message' => 'Section updated successfully',
            'section' => $section,
        ]);
    }

    public function destroy(Request $request, string $outletId, string $sectionId)
    {
        $outlet = $this->findOwnedOutlet($request, $outletId);

        if (!$outlet) {
            return response()->json(['message' => 'Outlet not found'], 404);
        }

        $section = $outlet->sections()->find($sectionId);

        if (!$section) {
            return response()->json(['message' => 'Section not found'], 404);
        }

        $employeeCount = $section->employees()->count();
        $shiftCount = $section->shifts()->count();
        $ingredientCount = $section->ingredients()->count();

        if ($employeeCount > 0 || $shiftCount > 0 || $ingredientCount > 0) {
            $parts = [];

            if ($employeeCount > 0) {
                $parts[] = "{$employeeCount} employee(s)";
            }
            if ($shiftCount > 0) {
                $parts[] = "{$shiftCount} shift(s)";
            }
            if ($ingredientCount > 0) {
                $parts[] = "{$ingredientCount} ingredient(s)";
            }

            $detail = implode(', ', $parts);

            return response()->json([
                'message' => "Cannot delete section: it still has {$detail}. Move or remove them first.",
            ], 422);
        }

        $section->delete();

        return response()->json(['message' => 'Section deleted successfully']);
    }

    private function findOwnedOutlet(Request $request, string $outletId): ?Outlet
    {
        return Outlet::where('tenant_id', $request->user()->tenant_id)->find($outletId);
    }
}