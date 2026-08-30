<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Models\Table;
use App\Models\TableBooking;
use App\Services\BookingAvailabilityService;
use App\Services\BookingStatusService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class BookingEventController extends Controller
{
    public function store(Request $request, string $outletId)
    {
        $outlet = $this->findOwnedOutlet($request, $outletId);

        if (!$outlet) {
            return response()->json(['message' => 'Outlet not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'table_ids' => 'required|array|min:1',
            'table_ids.*' => 'required|uuid|distinct|exists:tables,id',
            'customer_name' => 'required|string|max:100',
            'phone' => 'required|string|max:20',
            'guest_count' => 'required|integer|min:1',
            'booking_datetime' => 'required|date',
            'duration_minutes' => 'nullable|integer|min:15',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tables = Table::whereHas('section', fn($q) => $q->where('outlet_id', $outlet->id))
            ->whereIn('id', $request->table_ids)
            ->get();

        if ($tables->count() !== count($request->table_ids)) {
            return response()->json([
                'message' => 'One or more tables do not belong to this outlet',
            ], 422);
        }

        $bookingDatetime = Carbon::parse($request->booking_datetime);
        $durationMinutes = $request->duration_minutes ?? 120;

        $unavailableTableIds = BookingAvailabilityService::areTablesAvailable(
            $request->table_ids,
            $bookingDatetime,
            $durationMinutes
        );

        if (!empty($unavailableTableIds)) {
            return response()->json([
                'message' => 'One or more tables are not available at the requested time',
                'unavailable_table_ids' => $unavailableTableIds,
            ], 422);
        }

        $booking = DB::transaction(function () use ($request, $outlet, $bookingDatetime, $durationMinutes) {
            $booking = TableBooking::create([
                'outlet_id' => $outlet->id,
                'table_id' => null,
                'customer_name' => $request->customer_name,
                'phone' => $request->phone,
                'guest_count' => $request->guest_count,
                'booking_datetime' => $bookingDatetime,
                'duration_minutes' => $durationMinutes,
                'status' => 'pending',
                'is_event' => true,
                'notes' => $request->notes,
            ]);

            foreach ($request->table_ids as $tableId) {
                $booking->tableAssignments()->create([
                    'table_id' => $tableId,
                ]);
            }

            BookingStatusService::logHistory($booking, null, 'pending');

            return $booking;
        });

        return response()->json([
            'message' => 'Event booking created successfully',
            'booking' => $booking->load('tableAssignments.table'),
        ], 201);
    }

    public function update(Request $request, string $outletId, string $bookingId)
    {
        $outlet = $this->findOwnedOutlet($request, $outletId);

        if (!$outlet) {
            return response()->json(['message' => 'Outlet not found'], 404);
        }

        $booking = TableBooking::where('outlet_id', $outlet->id)->find($bookingId);

        if (!$booking) {
            return response()->json(['message' => 'Booking not found'], 404);
        }

        if (!$booking->is_event) {
            return response()->json([
                'message' => 'This is not an event booking. Use the regular update endpoint instead.',
            ], 422);
        }

        if (!BookingStatusService::canEdit($booking)) {
            return response()->json([
                'message' => "Booking in status '{$booking->status}' cannot be edited.",
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'table_ids' => 'required|array|min:1',
            'table_ids.*' => 'required|uuid|distinct|exists:tables,id',
            'customer_name' => 'required|string|max:100',
            'phone' => 'required|string|max:20',
            'guest_count' => 'required|integer|min:1',
            'booking_datetime' => 'required|date',
            'duration_minutes' => 'nullable|integer|min:15',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tables = Table::whereHas('section', fn($q) => $q->where('outlet_id', $outlet->id))
            ->whereIn('id', $request->table_ids)
            ->get();

        if ($tables->count() !== count($request->table_ids)) {
            return response()->json([
                'message' => 'One or more tables do not belong to this outlet',
            ], 422);
        }

        $bookingDatetime = Carbon::parse($request->booking_datetime);
        $durationMinutes = $request->duration_minutes ?? 120;

        $unavailableTableIds = BookingAvailabilityService::areTablesAvailable(
            $request->table_ids,
            $bookingDatetime,
            $durationMinutes,
            $booking->id
        );

        if (!empty($unavailableTableIds)) {
            return response()->json([
                'message' => 'One or more tables are not available at the requested time',
                'unavailable_table_ids' => $unavailableTableIds,
            ], 422);
        }

        DB::transaction(function () use ($booking, $request, $bookingDatetime, $durationMinutes) {
            $booking->update([
                'customer_name' => $request->customer_name,
                'phone' => $request->phone,
                'guest_count' => $request->guest_count,
                'booking_datetime' => $bookingDatetime,
                'duration_minutes' => $durationMinutes,
                'notes' => $request->notes,
            ]);

            $booking->tableAssignments()->delete();

            foreach ($request->table_ids as $tableId) {
                $booking->tableAssignments()->create([
                    'table_id' => $tableId,
                ]);
            }
        });

        return response()->json([
            'message' => 'Event booking updated successfully',
            'booking' => $booking->fresh()->load('tableAssignments.table'),
        ]);
    }

    private function findOwnedOutlet(Request $request, string $outletId): ?Outlet
    {
        return Outlet::where('tenant_id', $request->user()->tenant_id)->find($outletId);
    }
}
