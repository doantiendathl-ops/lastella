<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\Floor;
use App\Models\Resource;
use App\Models\Room;
use App\Models\RoomRate;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Policies\AuditLogPolicy;
use App\Policies\FloorPolicy;
use App\Policies\PermissionPolicy;
use App\Policies\ResourcePolicy;
use App\Policies\RolePolicy;
use App\Policies\RoomPolicy;
use App\Policies\RoomRatePolicy;
use App\Policies\RoomTypePolicy;
use App\Policies\SettingPolicy;
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

        User::observe(AuditObserver::class);
        Role::observe(AuditObserver::class);
        Permission::observe(AuditObserver::class);
        Floor::observe(AuditObserver::class);
        RoomType::observe(AuditObserver::class);
        Resource::observe(AuditObserver::class);
        Room::observe(AuditObserver::class);
        RoomRate::observe(AuditObserver::class);
        Setting::observe(AuditObserver::class);
    }
}
