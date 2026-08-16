<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\CustomerType;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    protected $fillable = [
        'booking_code',
        'booking_color',
        'customer_name',
        'customer_phone',
        'customer_email',
        'customer_type',
        'booking_type',
        'checkin_at',
        'checkout_at',
        'adults',
        'children_under_6',
        'children_over_6',
        'status',
        'sales_user_id',
        'created_by',
        'updated_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'note',
        'internal_note',
        'quick_note',
    ];

    protected function casts(): array
    {
        return [
            'customer_type' => CustomerType::class,
            'booking_type' => BookingType::class,
            'checkin_at' => 'datetime',
            'checkout_at' => 'datetime',
            'adults' => 'integer',
            'children_under_6' => 'integer',
            'children_over_6' => 'integer',
            'status' => BookingStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }

    public function folio(): HasOne
    {
        return $this->hasOne(Folio::class);
    }

    public function bookingRequirements(): HasMany
    {
        return $this->hasMany(BookingRequirement::class);
    }

    public function bookingPayments(): HasMany
    {
        return $this->hasMany(BookingPayment::class);
    }

    public function roomAssignments(): HasMany
    {
        return $this->hasMany(RoomAssignment::class);
    }

    public function stays(): HasMany
    {
        return $this->hasMany(Stay::class);
    }

    public function packageFlags(): HasMany
    {
        return $this->hasMany(BookingPackageFlag::class);
    }

    /** Unified Services & Requests (docs/yeucaumoi.txt) — additive, alongside the legacy packageFlags()/specialRequests() relations. */
    public function bookingServices(): HasMany
    {
        return $this->hasMany(BookingService::class);
    }

    public function specialRequests(): HasMany
    {
        return $this->hasMany(BookingSpecialRequest::class);
    }

    public function checkoutInspections(): HasMany
    {
        return $this->hasMany(CheckoutInspection::class);
    }

    public function salesUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
