<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Models\Table;
use App\Models\TableBooking;
use App\Services\BookingAvailabilityService;
use App\Services\BookingGracePeriodService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TableBookingController extends Controller
{
    public function index(Request $request, string $outletId)
    {
        $outlet = $this->findOwnedOutlet($request, $outletId);

        if (!$outlet) {
            return response()->json(['message' => 'Outlet not found'], 404);
        }

        BookingGracePeriodService::applyForOutlet($outlet->id);

        $bookings = TableBooking::where('outlet_id', $outlet->id)
            ->with(['table', 'tableAssignments.table'])
            ->orderBy('booking_datetime', 'desc')
            ->get();

        return response()->json($bookings);
    }

    public function show(Request $request, string $outletId, string $bookingId)
    {
        $booking = $this->findOwnedBooking($request, $outletId, $bookingId);

        if (!$booking) {
            return response()->json(['message' => 'Booking not found'], 404);
        }

        return response()->json($booking->load(['table', 'tableAssignments.table']));
    }

    public function availableTables(Request $request, string $outletId)
    {
        $outlet = $this->findOwnedOutlet($request, $outletId);

        if (!$outlet) {
            return response()->json(['message' => 'Outlet not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'datetime' => 'required|date',
            'duration_minutes' => 'nullable|integer|min:15',
            'exclude_booking_id' => 'nullable|uuid|exists:table_bookings,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $startTime = Carbon::parse($request->datetime);
        $durationMinutes = $request->duration_minutes ?? 120;

        $tables = BookingAvailabilityService::getAvailabilityForOutlet(
            $outlet->id,
            $startTime,
            $durationMinutes,
            $request->exclude_booking_id
        );

        return response()->json($tables);
    }

    public function store(Request $request, string $outletId)
    {
        $outlet = $this->findOwnedOutlet($request, $outletId);

        if (!$outlet) {
            return response()->json(['message' => 'Outlet not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'table_id' => 'required|uuid|exists:tables,id',
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

        $table = Table::whereHas('section', fn($q) => $q->where('outlet_id', $outlet->id))
            ->find($request->table_id);

        if (!$table) {
            return response()->json([
                'message' => 'Table does not belong to this outlet',
            ], 422);
        }

        $bookingDatetime = Carbon::parse($request->booking_datetime);
        $durationMinutes = $request->duration_minutes ?? 120;

        $isAvailable = BookingAvailabilityService::isTableAvailable(
            $table->id,
            $bookingDatetime,
            $durationMinutes
        );


        if (!$isAvailable) {
            return response()->json([
                'message' => 'This table is not available at the requested time',
            ], 422);
        }

        $booking = TableBooking::create([
            'outlet_id' => $outlet->id,
            'table_id' => $table->id,
            'customer_name' => $request->customer_name,
            'phone' => $request->phone,
            'guest_count' => $request->guest_count,
            'booking_datetime' => $bookingDatetime,
            'duration_minutes' => $durationMinutes,
            'status' => 'pending',
            'is_event' => false,
            'notes' => $request->notes,
        ]);

        return response()->json([
            'message' => 'Booking created successfully',
            'booking' => $booking->load('table'),
        ], 201);
    }

    private function findOwnedBooking(Request $request, string $outletId, string $bookingId): ?TableBooking
    {
        $outlet = $this->findOwnedOutlet($request, $outletId);

        if (!$outlet) {
            return null;
        }

        return TableBooking::where('outlet_id', $outlet->id)->find($bookingId);
    }

    private function findOwnedOutlet(Request $request, string $outletId): ?Outlet
    {
        return Outlet::where('tenant_id', $request->user()->tenant_id)->find($outletId);
    }
}