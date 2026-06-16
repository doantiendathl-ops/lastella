<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\BookingRequirement;
use App\Models\Folio;
use App\Models\FolioEntry;
use App\Models\Floor;
use App\Models\Resource;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\RoomRate;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\Stay;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Policies\AuditLogPolicy;
use App\Policies\BookingPaymentPolicy;
use App\Policies\BookingPolicy;
use App\Policies\FloorPolicy;
use App\Policies\FolioEntryPolicy;
use App\Policies\FolioPolicy;
use App\Policies\PermissionPolicy;
use App\Policies\ResourcePolicy;
use App\Policies\RoomAssignmentPolicy;
use App\Policies\RolePolicy;
use App\Policies\RoomPolicy;
use App\Policies\RoomRatePolicy;
use App\Policies\RoomTypePolicy;
use App\Policies\SettingPolicy;
use App\Policies\StayPolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Permission::class, PermissionPolicy::class);
        Gate::policy(Floor::class, FloorPolicy::class);
        Gate::policy(RoomType::class, RoomTypePolicy::class);
        Gate::policy(Resource::class, ResourcePolicy::class);
        Gate::policy(Room::class, RoomPolicy::class);
        Gate::policy(RoomRate::class, RoomRatePolicy::class);
        Gate::policy(Setting::class, SettingPolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
        Gate::policy(Booking::class, BookingPolicy::class);
        Gate::policy(BookingPayment::class, BookingPaymentPolicy::class);
        Gate::policy(Folio::class, FolioPolicy::class);
        Gate::policy(FolioEntry::class, FolioEntryPolicy::class);
        Gate::policy(RoomAssignment::class, RoomAssignmentPolicy::class);
        Gate::policy(Stay::class, StayPolicy::class);

        User::observe(AuditObserver::class);
        Role::observe(AuditObserver::class);
        Permission::observe(AuditObserver::class);
        Floor::observe(AuditObserver::class);
        RoomType::observe(AuditObserver::class);
        Resource::observe(AuditObserver::class);
        Room::observe(AuditObserver::class);
        RoomRate::observe(AuditObserver::class);
        Setting::observe(AuditObserver::class);
        Booking::observe(AuditObserver::class);
        BookingRequirement::observe(AuditObserver::class);
        BookingPayment::observe(AuditObserver::class);
        Folio::observe(AuditObserver::class);
        FolioEntry::observe(AuditObserver::class);
        RoomAssignment::observe(AuditObserver::class);
        Stay::observe(AuditObserver::class);
    }
}
