<?php

namespace App\Models;

use App\Models\TableBooking;
use App\Models\User;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingStatusHistory extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'booking_status_history';

    protected $fillable = [
        'booking_id',
        'from_status',
        'to_status',
        'changed_by',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(TableBooking::class, 'booking_id');
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}