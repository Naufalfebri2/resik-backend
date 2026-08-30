<?php

namespace App\Services;

use App\Models\BookingStatusHistory;
use App\Models\TableBooking;
use InvalidArgumentException;

class BookingStatusService
{
    private const ADVANCE_REGULAR = [
        'pending' => 'confirmed',
        'confirmed' => 'seated',
    ];

    private const ADVANCE_EVENT = [
        'pending' => 'awaiting_deposit',
        'awaiting_deposit' => 'confirmed',
        'confirmed' => 'seated',
    ];

    private const CANCELLABLE_STATUSES = ['pending', 'awaiting_deposit', 'confirmed'];
    private const EDITABLE_STATUSES = ['pending', 'awaiting_deposit', 'confirmed'];
    private const LOCKED_FOR_DELETE_STATUSES = ['seated'];

    public static function advance(TableBooking $booking): TableBooking
    {
        $map = $booking->is_event ? self::ADVANCE_EVENT : self::ADVANCE_REGULAR;
        $currentStatus = $booking->status;

        if (!array_key_exists($currentStatus, $map)) {
            throw new InvalidArgumentException("Booking is in status '{$currentStatus}' and cannot be advanced further.");
        }

        $nextStatus = $map[$currentStatus];
        $booking->update(['status' => $nextStatus]);
        self::logHistory($booking, $currentStatus, $nextStatus);

        return $booking->fresh();
    }

    public static function cancel(TableBooking $booking): TableBooking
    {
        if (!in_array($booking->status, self::CANCELLABLE_STATUSES)) {
            throw new InvalidArgumentException("Booking in status '{$booking->status}' cannot be cancelled.");
        }

        $previousStatus = $booking->status;
        $booking->update(['status' => 'cancelled']);
        self::logHistory($booking, $previousStatus, 'cancelled');

        return $booking->fresh();
    }

    public static function markNoShow(TableBooking $booking): TableBooking
    {
        if (!in_array($booking->status, self::CANCELLABLE_STATUSES)) {
            throw new InvalidArgumentException("Booking in status '{$booking->status}' cannot be marked as no-show.");
        }

        $previousStatus = $booking->status;
        $booking->update([
            'status' => 'no_show',
            'no_show_reason' => 'manual',
        ]);
        self::logHistory($booking, $previousStatus, 'no_show');

        return $booking->fresh();
    }

    public static function canEdit(TableBooking $booking): bool
    {
        return in_array($booking->status, self::EDITABLE_STATUSES);
    }

    public static function canDelete(TableBooking $booking): bool
    {
        return !in_array($booking->status, self::LOCKED_FOR_DELETE_STATUSES);
    }

    public static function logHistory(
        TableBooking $booking,
        ?string $fromStatus,
        string $toStatus,
        ?string $changedBy = null
    ): void {
        BookingStatusHistory::create([
            'booking_id' => $booking->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'changed_by' => $changedBy ?? auth()->id(),
        ]);
    }
}
