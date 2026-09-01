<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Models\Table;
use App\Models\TableBooking;
use App\Services\BookingAvailabilityService;
use App\Services\BookingGracePeriodService;
use App\Services\BookingStatusService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use const FILTER_VALIDATE_BOOLEAN;

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

    public function history(Request $request, string $outletId)
    {
        $outlet = $this->findOwnedOutlet($request, $outletId);

        if (!$outlet) {
            return response()->json(['message' => 'Outlet not found'], 404);
        }

        $query = TableBooking::where('outlet_id', $outlet->id)
            ->with(['table', 'tableAssignments.table'])
            ->whereIn('status', ['seated', 'cancelled', 'no_show']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('is_event')) {
            $query->where('is_event', filter_var($request->is_event, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('date_from')) {
            $query->where('booking_datetime', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('booking_datetime', '<=', $request->date_to);
        }

        $history = $query->orderBy('booking_datetime', 'desc')
            ->paginate($request->input('per_page', 15));

        return response()->json($history);
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

        $startTime = Carbon::parse($request->datetime)->setTimezone(config('app.timezone'));
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

        $bookingDatetime = Carbon::parse($request->booking_datetime)->setTimezone(config('app.timezone'));
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

        BookingStatusService::logHistory($booking, null, 'pending');

        return response()->json([
            'message' => 'Booking created successfully',
            'booking' => $booking->load('table'),
        ], 201);
    }

    public function update(Request $request, string $outletId, string $bookingId)
    {
        $booking = $this->findOwnedBooking($request, $outletId, $bookingId);

        if (!$booking) {
            return response()->json(['message' => 'Booking not found'], 404);
        }

        if ($booking->is_event) {
            return response()->json([
                'message' => 'This is an event booking. Use the event update endpoint instead.',
            ], 422);
        }

        if (!BookingStatusService::canEdit($booking)) {
            return response()->json([
                'message' => "Booking in status '{$booking->status}' cannot be edited.",
            ], 422);
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

        $table = Table::whereHas('section', fn($q) => $q->where('outlet_id', $booking->outlet_id))
            ->find($request->table_id);

        if (!$table) {
            return response()->json([
                'message' => 'Table does not belong to this outlet',
            ], 422);
        }

        $bookingDatetime = Carbon::parse($request->booking_datetime)->setTimezone(config('app.timezone'));
        $durationMinutes = $request->duration_minutes ?? 120;

        $isAvailable = BookingAvailabilityService::isTableAvailable(
            $table->id,
            $bookingDatetime,
            $durationMinutes,
            $booking->id
        );

        if (!$isAvailable) {
            return response()->json([
                'message' => 'This table is not available at the requested time',
            ], 422);
        }

        $booking->update([
            'table_id' => $table->id,
            'customer_name' => $request->customer_name,
            'phone' => $request->phone,
            'guest_count' => $request->guest_count,
            'booking_datetime' => $bookingDatetime,
            'duration_minutes' => $durationMinutes,
            'notes' => $request->notes,
        ]);

        return response()->json([
            'message' => 'Booking updated successfully',
            'booking' => $booking->fresh()->load('table'),
        ]);
    }

    public function destroy(Request $request, string $outletId, string $bookingId)
    {
        $booking = $this->findOwnedBooking($request, $outletId, $bookingId);

        if (!$booking) {
            return response()->json(['message' => 'Booking not found'], 404);
        }

        if (!BookingStatusService::canDelete($booking)) {
            return response()->json([
                'message' => "Booking in status '{$booking->status}' cannot be deleted.",
            ], 422);
        }

        $booking->delete();

        return response()->json(['message' => 'Booking deleted successfully']);
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