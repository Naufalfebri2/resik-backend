<?php

namespace App\Models;

use App\Models\BookingTableAssignment;
use App\Models\Outlet;
use App\Models\Table;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TableBooking extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'outlet_id',
        'table_id',
        'customer_name',
        'phone',
        'guest_count',
        'booking_datetime',
        'duration_minutes',
        'status',
        'no_show_reason',
        'is_event',
        'notes',
    ];

    protected $casts = [
        'booking_datetime' => 'datetime',
        'is_event' => 'boolean',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    public function tableAssignments(): HasMany
    {
        return $this->hasMany(BookingTableAssignment::class, 'booking_id');
    }
}
