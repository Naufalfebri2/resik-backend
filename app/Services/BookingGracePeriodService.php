<?php

namespace App\Services;

use App\Models\TableBooking;

class BookingGracePeriodService
{
    private const GRACE_PERIOD_MINUTES = 60;
    private const ACTIVE_STATUSES = ['pending', 'awaiting_deposit', 'confirmed'];

    public static function applyForOutlet(string $outletId): int
    {
        $cutoff = now()->subMinutes(self::GRACE_PERIOD_MINUTES);

        $expiredBookings = TableBooking::where('outlet_id', $outletId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->where('booking_datetime', '<', $cutoff)
            ->get();

        foreach ($expiredBookings as $booking) {
            $previousStatus = $booking->status;

            $booking->update([
                'status' => 'no_show',
                'no_show_reason' => 'grace_period',
            ]);

            BookingStatusService::logHistory($booking, $previousStatus, 'no_show', changedBy: null);
        }

        return $expiredBookings->count();
    }
}
