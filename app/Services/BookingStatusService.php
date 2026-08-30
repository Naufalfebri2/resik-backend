<?php

namespace App\Services;

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

    public static function advance(TableBooking $booking): TableBooking
    {
        $map = $booking->is_event ? self::ADVANCE_EVENT : self::ADVANCE_REGULAR;
        $currentStatus = $booking->status;

        if (!array_key_exists($currentStatus, $map)) {
            throw new InvalidArgumentException("Booking is in status '{$currentStatus}' and cannot be advanced further.");
        }

        $booking->update(['status' => $map[$currentStatus]]);

        return $booking->fresh();
    }

    public static function cancel(TableBooking $booking): TableBooking
    {
        if (!in_array($booking->status, self::CANCELLABLE_STATUSES)) {
            throw new InvalidArgumentException("Booking in status '{$booking->status}' cannot be cancelled.");
        }

        $booking->update(['status' => 'cancelled']);

        return $booking->fresh();
    }

    public static function markNoShow(TableBooking $booking): TableBooking
    {
        if (!in_array($booking->status, self::CANCELLABLE_STATUSES)) {
            throw new InvalidArgumentException("Booking in status '{$booking->status}' cannot be marked as no-show.");
        }

        $booking->update([
            'status' => 'no_show',
            'no_show_reason' => 'manual',
        ]);

        return $booking->fresh();
    }
}