<?php

namespace App\Services;

use App\Models\TableBooking;
use Illuminate\Support\Facades\DB;

class BookingGracePeriodService
{
    private const GRACE_PERIOD_MINUTES = 60;
    private const ACTIVE_STATUSES = ['pending', 'awaiting_deposit', 'confirmed'];

    public static function applyForOutlet(string $outletId): int
    {
        $cutoff = now()->subMinutes(self::GRACE_PERIOD_MINUTES);

        return TableBooking::where('outlet_id', $outletId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->where('booking_datetime', '<', $cutoff)
            ->update([
                'status' => 'no_show',
                'no_show_reason' => 'grace_period',
            ]);
    }
}