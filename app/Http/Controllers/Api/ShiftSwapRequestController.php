<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShiftSchedule;
use App\Models\ShiftSwapRequest;
use App\Traits\ChecksManagerOutletAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ShiftSwapRequestController extends Controller
{
    use ChecksManagerOutletAccess;

    public function index(Request $request)
    {
        $requests = ShiftSwapRequest::whereHas('requesterSchedule.employee.section.outlet', function ($query) use ($request) {
            $query->where('tenant_id', $request->user()->tenant_id);
        })
            ->with([
                'requesterSchedule.employee',
                'requesterSchedule.shift',
                'targetSchedule.employee',
                'targetSchedule.shift',
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($requests);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'requester_schedule_id' => 'required|uuid|exists:shift_schedules,id',
            'target_schedule_id' => 'required|uuid|different:requester_schedule_id|exists:shift_schedules,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $requesterSchedule = $this->findOwnedSchedule($request, $request->requester_schedule_id);
        $targetSchedule = $this->findOwnedSchedule($request, $request->target_schedule_id);

        if (!$requesterSchedule || !$targetSchedule) {
            return response()->json(['message' => 'Shift schedule not found'], 404);
        }

        if ($requesterSchedule->employee_id === $targetSchedule->employee_id) {
            return response()->json([
                'message' => 'Cannot swap shifts with yourself',
            ], 422);
        }

        if ($requesterSchedule->attendance()->exists() || $targetSchedule->attendance()->exists()) {
            return response()->json([
                'message' => 'Cannot request a swap for a schedule that already has attendance recorded',
            ], 422);
        }

        $swapRequest = ShiftSwapRequest::create([
            'requester_schedule_id' => $requesterSchedule->id,
            'target_schedule_id' => $targetSchedule->id,
            'status' => 'pending',
        ]);

        $requesterSchedule->update(['swap_status' => 'swap_pending']);
        $targetSchedule->update(['swap_status' => 'swap_pending']);

        return response()->json([
            'message' => 'Shift swap request created successfully',
            'shift_swap_request' => $swapRequest,
        ], 201);
    }

    public function approve(Request $request, string $swapRequestId)
    {
        $swapRequest = ShiftSwapRequest::whereHas('requesterSchedule.employee.section.outlet', function ($query) use ($request) {
            $query->where('tenant_id', $request->user()->tenant_id);
        })->with(['requesterSchedule.employee.section', 'targetSchedule'])->find($swapRequestId);

        if (!$swapRequest) {
            return response()->json(['message' => 'Shift swap request not found'], 404);
        }

        if ($response = $this->authorizeManagerOutlet($request, $swapRequest->requesterSchedule->employee->section->outlet_id)) {
            return $response;
        }

        if ($swapRequest->status !== 'pending') {
            return response()->json([
                'message' => 'This swap request has already been resolved',
            ], 422);
        }

        DB::transaction(function () use ($swapRequest, $request) {
            $requesterShiftId = $swapRequest->requesterSchedule->shift_id;
            $targetShiftId = $swapRequest->targetSchedule->shift_id;

            $swapRequest->requesterSchedule->update([
                'shift_id' => $targetShiftId,
                'swap_status' => 'swap_approved',
            ]);

            $swapRequest->targetSchedule->update([
                'shift_id' => $requesterShiftId,
                'swap_status' => 'swap_approved',
            ]);

            $swapRequest->update([
                'status' => 'approved',
                'approved_by' => $request->user()->id,
            ]);
        });

        return response()->json([
            'message' => 'Shift swap approved successfully',
            'shift_swap_request' => $swapRequest->fresh(['requesterSchedule.shift', 'targetSchedule.shift']),
        ]);
    }

    public function reject(Request $request, string $swapRequestId)
    {
        $swapRequest = ShiftSwapRequest::whereHas('requesterSchedule.employee.section.outlet', function ($query) use ($request) {
            $query->where('tenant_id', $request->user()->tenant_id);
        })->with(['requesterSchedule.employee.section', 'targetSchedule'])->find($swapRequestId);

        if (!$swapRequest) {
            return response()->json(['message' => 'Shift swap request not found'], 404);
        }

        if ($response = $this->authorizeManagerOutlet($request, $swapRequest->requesterSchedule->employee->section->outlet_id)) {
            return $response;
        }

        if ($swapRequest->status !== 'pending') {
            return response()->json([
                'message' => 'This swap request has already been resolved',
            ], 422);
        }

        DB::transaction(function () use ($swapRequest, $request) {
            $swapRequest->requesterSchedule->update(['swap_status' => 'normal']);
            $swapRequest->targetSchedule->update(['swap_status' => 'normal']);

            $swapRequest->update([
                'status' => 'rejected',
                'approved_by' => $request->user()->id,
            ]);
        });

        return response()->json([
            'message' => 'Shift swap rejected',
            'shift_swap_request' => $swapRequest,
        ]);
    }

    private function findOwnedSchedule(Request $request, string $scheduleId): ?ShiftSchedule
    {
        return ShiftSchedule::whereHas('employee.section.outlet', function ($query) use ($request) {
            $query->where('tenant_id', $request->user()->tenant_id);
        })->find($scheduleId);
    }
}