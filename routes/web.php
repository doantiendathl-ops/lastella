<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\PostingTimelineController;
use App\Http\Controllers\Admin\ReconciliationController;
use App\Http\Controllers\Admin\RevenueReportController;
use App\Http\Controllers\Admin\HotelSettingsController;
use App\Http\Controllers\Admin\HousekeepingBulkActionController;
use App\Http\Controllers\Admin\NightAuditController;
use App\Http\Controllers\Admin\ServicePackageController;
use App\Http\Controllers\Admin\ServicePackageRateController;
use App\Http\Controllers\Admin\ServiceRateController;
use App\Http\Controllers\Admin\Booking\BookingController;
use App\Http\Controllers\Admin\RoomAvailabilityController;
use App\Http\Controllers\Admin\Booking\BookingPaymentController;
use App\Http\Controllers\Admin\Booking\BookingRequirementController;
use App\Http\Controllers\Admin\Booking\FolioController;
use App\Http\Controllers\Admin\Booking\FolioEntryController;
use App\Http\Controllers\Admin\Booking\RoomAssignmentController;
use App\Http\Controllers\Admin\Booking\BookingPackageController;
use App\Http\Controllers\Admin\PackageEnrollmentController;
use App\Http\Controllers\Admin\Booking\BookingSpecialRequestController;
use App\Http\Controllers\Admin\Booking\StayController;
use App\Http\Controllers\Admin\CheckoutInspectionController;
use App\Http\Controllers\Admin\FloorController;
use App\Http\Controllers\Admin\HousekeepingController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\ProductServiceCategoryController;
use App\Http\Controllers\Admin\ProductServiceController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\RoomBulkActionController;
use App\Http\Controllers\Admin\RoomController;
use App\Http\Controllers\Admin\RoomRateController;
use App\Http\Controllers\Admin\RoomTypeController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);
});

Route::middleware('auth')->group(function (): void {
    Route::redirect('/', '/dashboard');
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::resource('users', UserController::class)->except(['show']);
    Route::resource('roles', RoleController::class)->except(['show']);
    Route::resource('permissions', PermissionController::class)->except(['show']);
    Route::resource('floors', FloorController::class)->except(['show']);
    Route::resource('room-types', RoomTypeController::class)->except(['show'])->parameters(['room-types' => 'roomType']);
    Route::resource('rooms', RoomController::class)->except(['show']);
    Route::patch('rooms/bulk/out-of-order', [RoomBulkActionController::class, 'markOutOfOrder'])->name('rooms.bulk.out-of-order');
    Route::patch('rooms/bulk/release', [RoomBulkActionController::class, 'release'])->name('rooms.bulk.release');
    Route::resource('room-rates', RoomRateController::class)->except(['show'])->parameters(['room-rates' => 'roomRate']);
    Route::resource('product-service-categories', ProductServiceCategoryController::class)->except(['show'])->parameters(['product-service-categories' => 'productServiceCategory']);
    Route::get('product-services', [ProductServiceController::class, 'index'])->name('product-services.index');
    Route::post('product-services', [ProductServiceController::class, 'store'])->name('product-services.store');
    Route::patch('product-services/{productService}', [ProductServiceController::class, 'update'])->name('product-services.update');
    Route::patch('product-services/{productService}/toggle', [ProductServiceController::class, 'toggleActive'])->name('product-services.toggle');
    Route::resource('settings', SettingController::class)->except(['show']);
    Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');

    Route::prefix('admin')->name('admin.')->group(function (): void {
        Route::get('hotel-settings', [HotelSettingsController::class, 'index'])->name('hotel-settings.index');
        Route::patch('hotel-settings', [HotelSettingsController::class, 'update'])->name('hotel-settings.update');

        Route::get('service-rates', [ServiceRateController::class, 'index'])->name('service-rates.index');
        Route::post('service-rates', [ServiceRateController::class, 'store'])->name('service-rates.store');
        Route::patch('service-rates/{serviceRate}', [ServiceRateController::class, 'update'])->name('service-rates.update');
        Route::patch('service-rates/{serviceRate}/toggle', [ServiceRateController::class, 'toggleActive'])->name('service-rates.toggle');
        Route::get('service-rates/history/{chargeType}', [ServiceRateController::class, 'history'])->name('service-rates.history');

        Route::get('service-packages', [ServicePackageController::class, 'index'])->name('service-packages.index');
        Route::post('service-packages', [ServicePackageController::class, 'store'])->name('service-packages.store');
        Route::patch('service-packages/{servicePackage}', [ServicePackageController::class, 'update'])->name('service-packages.update');
        Route::patch('service-packages/{servicePackage}/toggle', [ServicePackageController::class, 'toggle'])->name('service-packages.toggle');
        Route::get('service-packages/{servicePackage}/history', [ServicePackageController::class, 'history'])->name('service-packages.history');
        Route::post('service-packages/{servicePackage}/rates', [ServicePackageRateController::class, 'store'])->name('service-packages.rates.store');
        Route::patch('service-packages/{servicePackage}/rates/{rate}/toggle', [ServicePackageRateController::class, 'toggle'])->name('service-packages.rates.toggle');

        Route::get('night-audit', [NightAuditController::class, 'index'])->name('night-audit.index');
        Route::get('night-audit/{nightAuditRun}', [NightAuditController::class, 'show'])->name('night-audit.show');
        Route::post('night-audit/run', [NightAuditController::class, 'run'])->name('night-audit.run');
        Route::post('night-audit/trigger', [NightAuditController::class, 'trigger'])->name('night-audit.trigger');
        Route::post('night-audit/{nightAuditRun}/retry', [NightAuditController::class, 'retry'])->name('night-audit.retry');

        Route::get('room-availability', [RoomAvailabilityController::class, 'index'])->name('room-availability.index');

        Route::prefix('checkout-inspections')->name('checkout-inspections.')->group(function (): void {
            Route::get('/', [CheckoutInspectionController::class, 'index'])->name('index');
            Route::post('stays/{stay}/draft', [CheckoutInspectionController::class, 'draft'])->name('draft');
            Route::patch('{checkoutInspection}/save', [CheckoutInspectionController::class, 'saveDraft'])->name('save');
            Route::post('{checkoutInspection}/complete', [CheckoutInspectionController::class, 'complete'])->name('complete');
        });

        Route::get('reports/revenue', [RevenueReportController::class, 'index'])->name('reports.revenue.index');
        Route::get('reports/revenue/export', [RevenueReportController::class, 'export'])->name('reports.revenue.export');

        Route::get('reconciliation', [ReconciliationController::class, 'index'])->name('reconciliation.index');
        Route::get('reconciliation/voids', [ReconciliationController::class, 'voids'])->name('reconciliation.voids');
        Route::get('reconciliation/export', [ReconciliationController::class, 'exportOutstanding'])->name('reconciliation.export');
        Route::get('reconciliation/voids/export', [ReconciliationController::class, 'exportVoids'])->name('reconciliation.voids-export');
        Route::resource('bookings', BookingController::class)->except(['destroy']);
        Route::get('bookings/{booking}/timeline', [PostingTimelineController::class, 'show'])->name('bookings.timeline');
        Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel');
        Route::post('bookings/{booking}/restore', [BookingController::class, 'restore'])->name('bookings.restore');
        Route::post('bookings/{booking}/requirements', [BookingRequirementController::class, 'store'])->name('bookings.requirements.store');
        Route::put('bookings/{booking}/requirements/{requirement}', [BookingRequirementController::class, 'update'])->name('bookings.requirements.update');
        Route::delete('bookings/{booking}/requirements/{requirement}', [BookingRequirementController::class, 'destroy'])->name('bookings.requirements.destroy');
        Route::post('bookings/{booking}/payments', [BookingPaymentController::class, 'store'])->name('bookings.payments.store');
        Route::delete('bookings/{booking}/payments/{payment}', [BookingPaymentController::class, 'destroy'])->name('bookings.payments.destroy');
        Route::patch('bookings/{booking}/folio/close', [FolioController::class, 'close'])->name('bookings.folio.close');
        Route::patch('bookings/{booking}/folio/reopen', [FolioController::class, 'reopen'])->name('bookings.folio.reopen');
        Route::post('bookings/{booking}/folio/entries', [FolioEntryController::class, 'store'])->name('bookings.folio.entries.store');
        Route::patch('bookings/{booking}/folio/entries/{entry}', [FolioEntryController::class, 'void'])->name('bookings.folio.entries.void');
        Route::post('bookings/{booking}/assignments', [RoomAssignmentController::class, 'store'])->name('bookings.assignments.store');
        Route::post('bookings/{booking}/assignments/{assignment}/release', [RoomAssignmentController::class, 'release'])->name('bookings.assignments.release');
        Route::post('bookings/{booking}/room-board/conflict/{assignment}/release', [RoomAssignmentController::class, 'releaseConflict'])->name('bookings.room-board.conflict.release');
        // Room Demand/Room Board Unification M3: Room-Board-first reverse sync —
        // separate route/action from bookings.assignments.store (Implementation
        // Plan Mục XI), same room-board.* naming convention as the conflict-release
        // route above.
        Route::post('bookings/{booking}/room-board/assignments', [RoomAssignmentController::class, 'storeFromRoomBoard'])->name('bookings.room-board.assignments.store');
        Route::post('bookings/{booking}/stays/{stay}/check-in', [StayController::class, 'checkIn'])->name('bookings.stays.check-in');
        Route::post('bookings/{booking}/stays/{stay}/check-out', [StayController::class, 'checkOut'])->name('bookings.stays.check-out');
        Route::post('bookings/{booking}/stays/{stay}/inspection-skip', [StayController::class, 'skipInspection'])->name('bookings.stays.inspection-skip');
        Route::post('bookings/{booking}/stays/{stay}/extend', [StayController::class, 'extend'])->name('bookings.stays.extend');
        Route::post('bookings/{booking}/stays/{stay}/move-room', [StayController::class, 'moveRoom'])->name('bookings.stays.move-room');
        Route::get('bookings/{booking}/packages', [PackageEnrollmentController::class, 'show'])->name('bookings.packages');
        Route::post('bookings/{booking}/packages', [PackageEnrollmentController::class, 'enroll'])->name('bookings.packages.enroll');
        Route::delete('bookings/{booking}/packages/{packageKey}', [PackageEnrollmentController::class, 'unenroll'])->name('bookings.packages.unenroll');

        // Phase 4.1 — Room Setup Requests
        Route::get('bookings/{booking}/special-requests', [BookingSpecialRequestController::class, 'index'])->name('bookings.special-requests.index');
        Route::post('bookings/{booking}/special-requests', [BookingSpecialRequestController::class, 'store'])->name('bookings.special-requests.store');
        Route::patch('bookings/{booking}/special-requests/{specialRequest}/acknowledge', [BookingSpecialRequestController::class, 'acknowledge'])->name('bookings.special-requests.acknowledge');
        Route::patch('bookings/{booking}/special-requests/{specialRequest}/fulfill', [BookingSpecialRequestController::class, 'fulfill'])->name('bookings.special-requests.fulfill');
        Route::delete('bookings/{booking}/special-requests/{specialRequest}', [BookingSpecialRequestController::class, 'destroy'])->name('bookings.special-requests.destroy');

        // Phase 4.2 Milestone 4 — Housekeeping Workflow
        Route::prefix('housekeeping')->name('housekeeping.')->group(function (): void {
            Route::get('/', [HousekeepingController::class, 'index'])->name('index');

            // Literal "bulk/..." routes must be registered before any "{room}/..." PATCH
            // route below — otherwise a request to e.g. PATCH bulk/mark-clean matches
            // {room}/mark-clean first with {room} bound to the literal string "bulk" and
            // 404s on the Room lookup, since Laravel matches routes in registration order.
            Route::patch('bulk/mark-clean', [HousekeepingBulkActionController::class, 'bulkMarkClean'])->name('bulk.mark-clean');
            Route::patch('bulk/mark-dirty', [HousekeepingBulkActionController::class, 'bulkMarkDirty'])->name('bulk.mark-dirty');
            Route::patch('bulk/assign', [HousekeepingBulkActionController::class, 'bulkAssign'])->name('bulk.assign');
            Route::patch('bulk/start', [HousekeepingBulkActionController::class, 'bulkStart'])->name('bulk.start');
            Route::patch('bulk/complete', [HousekeepingBulkActionController::class, 'bulkComplete'])->name('bulk.complete');

            Route::get('{room}/detail', [HousekeepingController::class, 'show'])->name('detail');
            Route::patch('{room}/mark-clean', [HousekeepingController::class, 'markClean'])->name('mark-clean');
            Route::patch('{room}/mark-dirty', [HousekeepingController::class, 'markDirty'])->name('mark-dirty');
            Route::post('{room}/assign', [HousekeepingController::class, 'assign'])->name('assign');
            Route::patch('assignments/{assignment}/start', [HousekeepingController::class, 'startCleaning'])->name('start');
            Route::patch('assignments/{assignment}/complete', [HousekeepingController::class, 'completeCleaning'])->name('complete');
            Route::patch('{room}/pass-inspection', [HousekeepingController::class, 'passInspection'])->name('inspect.pass');
            Route::patch('{room}/fail-inspection', [HousekeepingController::class, 'failInspection'])->name('inspect.fail');
            Route::patch('{room}/skip-inspection', [HousekeepingController::class, 'skipInspection'])->name('inspect.skip');
            Route::patch('{room}/out-of-order', [HousekeepingController::class, 'markOutOfOrder'])->name('out-of-order');
            Route::patch('{room}/release', [HousekeepingController::class, 'releaseFromOutOfOrder'])->name('release');
        });
    });
});
