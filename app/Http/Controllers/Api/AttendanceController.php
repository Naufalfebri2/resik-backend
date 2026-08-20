<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use App\Traits\ChecksManagerOutletAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class AttendanceController extends Controller
{
    use ChecksManagerOutletAccess;

    private const GEOFENCE_RADIUS_METERS = 100;
    private const MINIMUM_MONTHS_FOR_LEAVE = 12;

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
            $employee->attendance()->orderBy('date', 'desc')->get()
        );
    }

    public function checkIn(Request $request, string $employeeId)
    {
        $employee = $this->findOwnedEmployee($request, $employeeId);

        if (!$employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'date' => 'required|date',
            'location_lat' => 'required|numeric|between:-90,90',
            'location_long' => 'required|numeric|between:-180,180',
            'check_in_photo' => 'required|image|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $outlet = $employee->section->outlet;

        if ($outlet->latitude === null || $outlet->longitude === null) {
            return response()->json([
                'message' => 'Outlet location has not been set, cannot validate geofence',
            ], 422);
        }

        $distance = $this->calculateDistanceMeters(
            (float) $outlet->latitude,
            (float) $outlet->longitude,
            (float) $request->location_lat,
            (float) $request->location_long
        );

        if ($distance > self::GEOFENCE_RADIUS_METERS) {
            return response()->json([
                'message' => 'Check-in rejected: outside outlet geofence radius',
                'errors' => [
                    'location' => [
                        "You are approximately {$distance} meters from the outlet. Maximum allowed distance is " . self::GEOFENCE_RADIUS_METERS . " meters.",
                    ],
                ],
            ], 422);
        }

        if ($employee->attendance()->where('date', $request->date)->exists()) {
            return response()->json([
                'message' => 'Attendance for this date already recorded',
            ], 422);
        }

        $shiftSchedule = $employee->shiftSchedules()
            ->where('date', $request->date)
            ->with('shift')
            ->first();

        $checkInTime = now();
        $lateMinutes = 0;
        $status = 'on_time';

        if ($shiftSchedule) {
            $scheduledStart = \Carbon\Carbon::parse($request->date . ' ' . $shiftSchedule->shift->start_time);

            if ($checkInTime->greaterThan($scheduledStart)) {
                $lateMinutes = (int) round($scheduledStart->diffInMinutes($checkInTime, true));
                $status = 'late';
            }
        }

        $photoPath = $request->file('check_in_photo')->store('attendance/check-in', 'public');

        $attendance = $employee->attendance()->create([
            'shift_schedule_id' => $shiftSchedule?->id,
            'date' => $request->date,
            'check_in_time' => $checkInTime,
            'check_in_photo' => $photoPath,
            'location_lat' => $request->location_lat,
            'location_long' => $request->location_long,
            'late_minutes' => $lateMinutes,
            'status' => $status,
        ]);

        return response()->json([
            'message' => 'Check-in recorded successfully',
            'attendance' => $attendance,
        ], 201);
    }

    public function checkOut(Request $request, string $employeeId)
    {
        $employee = $this->findOwnedEmployee($request, $employeeId);

        if (!$employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'date' => 'required|date',
            'location_lat' => 'required|numeric|between:-90,90',
            'location_long' => 'required|numeric|between:-180,180',
            'check_out_photo' => 'required|image|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $attendance = $employee->attendance()->where('date', $request->date)->first();

        if (!$attendance) {
            return response()->json([
                'message' => 'No check-in record found for this date',
            ], 422);
        }

        if ($attendance->check_out_time !== null) {
            return response()->json([
                'message' => 'Check-out already recorded for this date',
            ], 422);
        }

        $outlet = $employee->section->outlet;

        if ($outlet->latitude === null || $outlet->longitude === null) {
            return response()->json([
                'message' => 'Outlet location has not been set, cannot validate geofence',
            ], 422);
        }

        $distance = $this->calculateDistanceMeters(
            (float) $outlet->latitude,
            (float) $outlet->longitude,
            (float) $request->location_lat,
            (float) $request->location_long
        );

        if ($distance > self::GEOFENCE_RADIUS_METERS) {
            return response()->json([
                'message' => 'Check-out rejected: outside outlet geofence radius',
                'errors' => [
                    'location' => [
                        "You are approximately {$distance} meters from the outlet. Maximum allowed distance is " . self::GEOFENCE_RADIUS_METERS . " meters.",
                    ],
                ],
            ], 422);
        }

        $photoPath = $request->file('check_out_photo')->store('attendance/check-out', 'public');

        $attendance->update([
            'check_out_time' => now(),
            'check_out_photo' => $photoPath,
        ]);

        return response()->json([
            'message' => 'Check-out recorded successfully',
            'attendance' => $attendance->fresh(),
        ]);
    }

    public function markStatus(Request $request, string $employeeId)
    {
        $employee = $this->findOwnedEmployee($request, $employeeId);

        if (!$employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'date' => 'required|date',
            'status' => 'required|in:sick_with_letter,sick_without_letter,leave,time_off,absent',
            'supporting_document' => 'nullable|image|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->status === 'leave') {
            $monthsWorked = $employee->start_date->diffInMonths($request->date);

            if ($monthsWorked < self::MINIMUM_MONTHS_FOR_LEAVE) {
                return response()->json([
                    'message' => 'Employee is not yet eligible for leave (requires 12 months of employment)',
                    'errors' => [
                        'status' => [
                            "This employee has worked for {$monthsWorked} month(s). Leave requires at least " . self::MINIMUM_MONTHS_FOR_LEAVE . " months. Use 'time_off' for urgent unpaid absence instead.",
                        ],
                    ],
                ], 422);
            }
        }

        if ($employee->attendance()->where('date', $request->date)->exists()) {
            return response()->json([
                'message' => 'Attendance for this date already recorded',
            ], 422);
        }

        $documentPath = null;

        if ($request->hasFile('supporting_document')) {
            $documentPath = $request->file('supporting_document')->store('attendance/documents', 'public');
        }

        $attendance = $employee->attendance()->create([
            'date' => $request->date,
            'status' => $request->status,
            'supporting_document' => $documentPath,
        ]);

        return response()->json([
            'message' => 'Attendance status recorded successfully',
            'attendance' => $attendance,
        ], 201);
    }

    private function calculateDistanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusMeters = 6371000;

        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) * sin($latDelta / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($lonDelta / 2) * sin($lonDelta / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthRadiusMeters * $c, 2);
    }

    private function findOwnedEmployee(Request $request, string $employeeId): ?Employee
    {
        return Employee::whereHas('section.outlet', function ($query) use ($request) {
            $query->where('tenant_id', $request->user()->tenant_id);
        })->find($employeeId);
    }
}