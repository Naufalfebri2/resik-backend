<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Section;
use App\Traits\ChecksManagerOutletAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ShiftController extends Controller
{
    use ChecksManagerOutletAccess;

    public function index(Request $request, string $sectionId)
    {
        $section = $this->findOwnedSection($request, $sectionId);

        if (!$section) {
            return response()->json(['message' => 'Section not found'], 404);
        }

        if ($response = $this->authorizeManagerOutlet($request, $section->outlet_id)) {
            return $response;
        }

        return response()->json($section->shifts);
    }

    public function store(Request $request, string $sectionId)
    {
        $section = $this->findOwnedSection($request, $sectionId);

        if (!$section) {
            return response()->json(['message' => 'Section not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'shift_name' => 'required|string|max:50',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $shift = $section->shifts()->create([
            'shift_name' => $request->shift_name,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
        ]);

        return response()->json([
            'message' => 'Shift created successfully',
            'shift' => $shift,
        ], 201);
    }

    public function update(Request $request, string $sectionId, string $shiftId)
    {
        $section = $this->findOwnedSection($request, $sectionId);

        if (!$section) {
            return response()->json(['message' => 'Section not found'], 404);
        }

        $shift = $section->shifts()->find($shiftId);

        if (!$shift) {
            return response()->json(['message' => 'Shift not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'shift_name' => 'sometimes|string|max:50',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $shift->update($request->only(['shift_name', 'start_time', 'end_time']));

        return response()->json([
            'message' => 'Shift updated successfully',
            'shift' => $shift,
        ]);
    }

    public function destroy(Request $request, string $sectionId, string $shiftId)
    {
        $section = $this->findOwnedSection($request, $sectionId);

        if (!$section) {
            return response()->json(['message' => 'Section not found'], 404);
        }

        $shift = $section->shifts()->find($shiftId);

        if (!$shift) {
            return response()->json(['message' => 'Shift not found'], 404);
        }

        $shift->delete();

        return response()->json(['message' => 'Shift deleted successfully']);
    }

    private function findOwnedSection(Request $request, string $sectionId): ?Section
    {
        return Section::whereHas('outlet', function ($query) use ($request) {
            $query->where('tenant_id', $request->user()->tenant_id);
        })->find($sectionId);
    }
}