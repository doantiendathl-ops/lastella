<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\ReconciliationController;
use App\Http\Controllers\Admin\RevenueReportController;
use App\Http\Controllers\Admin\HotelSettingsController;
use App\Http\Controllers\Admin\NightAuditController;
use App\Http\Controllers\Admin\ServiceRateController;
use App\Http\Controllers\Admin\Booking\BookingController;
use App\Http\Controllers\Admin\RoomAvailabilityController;
use App\Http\Controllers\Admin\Booking\BookingPaymentController;
use App\Http\Controllers\Admin\Booking\BookingRequirementController;
use App\Http\Controllers\Admin\Booking\FolioController;
use App\Http\Controllers\Admin\Booking\FolioEntryController;
use App\Http\Controllers\Admin\Booking\RoomAssignmentController;
use App\Http\Controllers\Admin\Booking\BookingPackageController;
use App\Http\Controllers\Admin\Booking\StayController;
use App\Http\Controllers\Admin\FloorController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\RoleController;
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
    Route::resource('room-rates', RoomRateController::class)->except(['show'])->parameters(['room-rates' => 'roomRate']);
    Route::resource('settings', SettingController::class)->except(['show']);
    Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');

    Route::prefix('admin')->name('admin.')->group(function (): void {
        Route::get('hotel-settings', [HotelSettingsController::class, 'index'])->name('hotel-settings.index');
        Route::patch('hotel-settings', [HotelSettingsController::class, 'update'])->name('hotel-settings.update');

        Route::get('service-rates', [ServiceRateController::class, 'index'])->name('service-rates.index');
        Route::post('service-rates', [ServiceRateController::class, 'store'])->name('service-rates.store');
        Route::patch('service-rates/{serviceRate}', [ServiceRateController::class, 'update'])->name('service-rates.update');
        Route::patch('service-rates/{serviceRate}/toggle', [ServiceRateController::class, 'toggleActive'])->name('service-rates.toggle');

        Route::get('night-audit', [NightAuditController::class, 'index'])->name('night-audit.index');
        Route::get('night-audit/{nightAuditRun}', [NightAuditController::class, 'show'])->name('night-audit.show');
        Route::post('night-audit/run', [NightAuditController::class, 'run'])->name('night-audit.run');
        Route::post('night-audit/trigger', [NightAuditController::class, 'trigger'])->name('night-audit.trigger');
        Route::post('night-audit/{nightAuditRun}/retry', [NightAuditController::class, 'retry'])->name('night-audit.retry');

        Route::get('room-availability', [RoomAvailabilityController::class, 'index'])->name('room-availability.index');

        Route::get('reports/revenue', [RevenueReportController::class, 'index'])->name('reports.revenue.index');
        Route::get('reports/revenue/export', [RevenueReportController::class, 'export'])->name('reports.revenue.export');

        Route::get('reconciliation', [ReconciliationController::class, 'index'])->name('reconciliation.index');
        Route::get('reconciliation/voids', [ReconciliationController::class, 'voids'])->name('reconciliation.voids');
        Route::get('reconciliation/export', [ReconciliationController::class, 'exportOutstanding'])->name('reconciliation.export');
        Route::get('reconciliation/voids/export', [ReconciliationController::class, 'exportVoids'])->name('reconciliation.voids-export');
        Route::resource('bookings', BookingController::class)->except(['destroy']);
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
        Route::post('bookings/{booking}/stays/{stay}/check-in', [StayController::class, 'checkIn'])->name('bookings.stays.check-in');
        Route::post('bookings/{booking}/stays/{stay}/check-out', [StayController::class, 'checkOut'])->name('bookings.stays.check-out');
        Route::post('bookings/{booking}/packages', [BookingPackageController::class, 'enroll'])->name('bookings.packages.enroll');
        Route::delete('bookings/{booking}/packages/{packageKey}', [BookingPackageController::class, 'unenroll'])->name('bookings.packages.unenroll');
    });
});
