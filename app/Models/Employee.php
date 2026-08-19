<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\Section;
use App\Models\User;
use App\Models\ShiftSchedule;
use App\Models\Attendance;
use App\Models\PayrollPeriod;


class Employee extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'section_id',
        'name',
        'phone',
        'role',
        'start_date',
        'finish_date',
        'base_salary',
        'remaining_leave_quota',
        'is_active',
        'custom_fields',
    ];

    protected $casts = [
        'start_date' => 'date',
        'finish_date' => 'date',
        'is_active' => 'boolean',
        'custom_fields' => 'array',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function shiftSchedules(): HasMany
    {
        return $this->hasMany(ShiftSchedule::class);
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function payrollPeriods(): HasMany
    {
        return $this->hasMany(PayrollPeriod::class);
    }
}