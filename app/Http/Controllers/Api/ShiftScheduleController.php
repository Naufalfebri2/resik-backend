<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Shift;
use App\Traits\ChecksManagerOutletAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ShiftScheduleController extends Controller
{
    use ChecksManagerOutletAccess;

    public function index(Request $request, string $employeeId)
    {
        $employee = $this->findOwnedEmployee($request, $employeeId);

        if (!$employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }

        if ($response = $this->authorizeManagerOutlet($request, $employee->section->outlet_id)) {
            return $response;
        }

        return response()->json(
            $employee->shiftSchedules()->with('shift')->orderBy('date', 'desc')->get()
        );
    }

    public function store(Request $request, string $employeeId)
    {
        $employee = $this->findOwnedEmployee($request, $employeeId);

        if (!$employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'shift_id' => 'required|uuid|exists:shifts,id',
            'date' => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $shift = Shift::whereHas('section.outlet', function ($query) use ($request) {
            $query->where('tenant_id', $request->user()->tenant_id);
        })->find($request->shift_id);

        if (!$shift) {
            return response()->json(['message' => 'Shift not found'], 404);
        }

        if ($employee->shiftSchedules()->where('date', $request->date)->exists()) {
            return response()->json([
                'message' => 'Employee already has a schedule for this date',
            ], 422);
        }

        $schedule = $employee->shiftSchedules()->create([
            'shift_id' => $request->shift_id,
            'date' => $request->date,
        ]);

        return response()->json([
            'message' => 'Shift schedule created successfully',
            'shift_schedule' => $schedule->load('shift'),
        ], 201);
    }

    public function destroy(Request $request, string $employeeId, string $scheduleId)
    {
        $employee = $this->findOwnedEmployee($request, $employeeId);

        if (!$employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }

        $schedule = $employee->shiftSchedules()->find($scheduleId);

        if (!$schedule) {
            return response()->json(['message' => 'Shift schedule not found'], 404);
        }

        if ($schedule->attendance()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a shift schedule that already has attendance recorded',
            ], 422);
        }

        $schedule->delete();

        return response()->json(['message' => 'Shift schedule deleted successfully']);
    }

    private function findOwnedEmployee(Request $request, string $employeeId): ?Employee
    {
        return Employee::whereHas('section.outlet', function ($query) use ($request) {
            $query->where('tenant_id', $request->user()->tenant_id);
        })->find($employeeId);
    }
}