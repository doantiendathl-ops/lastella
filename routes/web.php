<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\PostingTimelineController;
use App\Http\Controllers\Admin\ReconciliationController;
use App\Http\Controllers\Admin\RevenueReportController;
use App\Http\Controllers\Admin\HotelSettingsController;
use App\Http\Controllers\Admin\HousekeepingBulkActionController;
use App\Http\Controllers\Admin\NightAuditController;
use App\Http\Controllers\Admin\ServiceRateController;
use App\Http\Controllers\Admin\ServiceCategoryController;
use App\Http\Controllers\Admin\ServiceCatalogController;
use App\Http\Controllers\Admin\ServicePriceController;
use App\Http\Controllers\Admin\BookingServiceController;
use App\Http\Controllers\Admin\Booking\BookingController;
use App\Http\Controllers\Admin\RoomAvailabilityController;
use App\Http\Controllers\Admin\Booking\BookingPaymentController;
use App\Http\Controllers\Admin\Booking\BookingRequirementController;
use App\Http\Controllers\Admin\Booking\FolioController;
use App\Http\Controllers\Admin\Booking\FolioEntryController;
use App\Http\Controllers\Admin\Booking\RoomAssignmentController;
use App\Http\Controllers\Admin\Booking\BookingPackageController;
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
use App\Http\Controllers\Admin\RoomOperationsController;
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

        // Unified Services & Requests (docs/yeucaumoi.txt) — the legacy
        // "Gói dịch vụ" admin catalog (ServicePackageController/
        // ServicePackageRateController, routes service-packages.*) has been
        // decommissioned — fully superseded by the unified catalog below.
        // service-rates.* above is KEPT: it manages several ChargeTypes
        // (City Tax, Late Checkout, Early Checkin, Spa, Laundry...) that
        // were never part of Gói dịch vụ and have no equivalent in the new
        // catalog yet.
        Route::resource('service-categories', ServiceCategoryController::class)->except(['show'])->parameters(['service-categories' => 'serviceCategory']);
        Route::get('services', [ServiceCatalogController::class, 'index'])->name('services.index');
        Route::post('services', [ServiceCatalogController::class, 'store'])->name('services.store');
        Route::patch('services/{service}', [ServiceCatalogController::class, 'update'])->name('services.update');
        Route::patch('services/{service}/toggle', [ServiceCatalogController::class, 'toggle'])->name('services.toggle');
        Route::post('services/{service}/prices', [ServicePriceController::class, 'store'])->name('services.prices.store');
        Route::patch('services/{service}/prices/{price}/toggle', [ServicePriceController::class, 'toggle'])->name('services.prices.toggle');

        Route::get('night-audit', [NightAuditController::class, 'index'])->name('night-audit.index');
        Route::get('night-audit/{nightAuditRun}', [NightAuditController::class, 'show'])->name('night-audit.show');
        Route::post('night-audit/run', [NightAuditController::class, 'run'])->name('night-audit.run');
        Route::post('night-audit/trigger', [NightAuditController::class, 'trigger'])->name('night-audit.trigger');
        Route::post('night-audit/{nightAuditRun}/retry', [NightAuditController::class, 'retry'])->name('night-audit.retry');

        Route::get('room-availability', [RoomAvailabilityController::class, 'index'])->name('room-availability.index');

        // Daily Room Operations Board ("Sơ đồ thao tác") — orchestration-only
        // screen, does NOT replace room-availability (Kiểm tra phòng) or the
        // per-booking Room Board panel. Reuses existing permissions only, no
        // new permission strings introduced.
        Route::prefix('room-operations')->name('room-operations.')->group(function (): void {
            Route::get('/', [RoomOperationsController::class, 'index'])->name('index');
            Route::post('swap/preview', [RoomOperationsController::class, 'swapPreview'])->name('swap.preview');
            Route::post('swap/execute', [RoomOperationsController::class, 'swapExecute'])->name('swap.execute');
            Route::patch('assignments/{assignment}/quick-note', [RoomOperationsController::class, 'updateQuickNote'])->name('assignments.quick-note');
            Route::post('check-in', [RoomOperationsController::class, 'checkIn'])->name('check-in');
            Route::post('check-out', [RoomOperationsController::class, 'checkOut'])->name('check-out');
        });

        Route::prefix('checkout-inspections')->name('checkout-inspections.')->group(function (): void {
            Route::get('/', [CheckoutInspectionController::class, 'index'])->name('index');
            Route::post('stays/{stay}/draft', [CheckoutInspectionController::class, 'draft'])->name('draft');
            Route::patch('{checkoutInspection}/save', [CheckoutInspectionController::class, 'saveDraft'])->name('save');
            Route::post('{checkoutInspection}/complete', [CheckoutInspectionController::class, 'complete'])->name('complete');
            // Inspection Financial Correction — pre-checkout edit of an already-Completed sheet.
            Route::patch('{checkoutInspection}/edit-completed', [CheckoutInspectionController::class, 'editCompleted'])->name('edit-completed');
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
        // Room Demand/Room Board Unification M4: atomic bulk room release —
        // separate route/action from the single-assignment release above, same
        // bulkReleaseAssignments() service core shared with it via
        // releaseAssignmentWithinTransaction().
        Route::post('bookings/{booking}/assignments/bulk-release', [RoomAssignmentController::class, 'bulkRelease'])->name('bookings.assignments.bulk-release');
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
        Route::patch('bookings/{booking}/stays/{stay}/actual-check-in', [StayController::class, 'updateActualCheckIn'])->name('bookings.stays.actual-check-in');
        Route::patch('bookings/{booking}/stays/{stay}/actual-check-out', [StayController::class, 'updateActualCheckOut'])->name('bookings.stays.actual-check-out');
        // Unified Services & Requests (docs/yeucaumoi.txt) — legacy Package
        // Enrollment (bookings.packages.*) and Special Requests
        // (bookings.special-requests.*) routes decommissioned — fully
        // superseded by bookings.services.* below (Admin/Booking/Services.vue),
        // which already covers chargeable + free, per-room + per-booking.
        // Underlying models/services/legacy posting jobs (PackageEnrollmentService,
        // SpecialRequestService, BookingSpecialRequest, ServicePackage,
        // ExtraBedPostingJob, ServicePackagePostingJob...) are left in place —
        // they still own historical data other code reads (e.g.
        // RoomOperationsBoardService's bed-join board merge) — only the
        // create/edit HTTP entry points are removed.

        // Unified Services & Requests (docs/yeucaumoi.txt) — Slice 1. New,
        // separate booking-side screen; existing bookings.packages.* and
        // bookings.special-requests.* routes above stay untouched.
        Route::get('bookings/{booking}/services', [BookingServiceController::class, 'show'])->name('bookings.services.show');
        Route::post('bookings/{booking}/services', [BookingServiceController::class, 'store'])->name('bookings.services.store');
        Route::patch('bookings/{booking}/services/{bookingService}/confirm', [BookingServiceController::class, 'confirm'])->name('bookings.services.confirm');
        Route::patch('bookings/{booking}/services/{bookingService}/complete', [BookingServiceController::class, 'complete'])->name('bookings.services.complete');
        Route::patch('bookings/{booking}/services/{bookingService}/cancel', [BookingServiceController::class, 'cancel'])->name('bookings.services.cancel');

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
