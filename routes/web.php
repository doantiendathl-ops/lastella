<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\Booking\BookingController;
use App\Http\Controllers\Admin\Booking\BookingPaymentController;
use App\Http\Controllers\Admin\Booking\BookingRequirementController;
use App\Http\Controllers\Admin\Booking\RoomAssignmentController;
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
        Route::resource('bookings', BookingController::class)->except(['destroy']);
        Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel');
        Route::post('bookings/{booking}/requirements', [BookingRequirementController::class, 'store'])->name('bookings.requirements.store');
        Route::put('bookings/{booking}/requirements/{requirement}', [BookingRequirementController::class, 'update'])->name('bookings.requirements.update');
        Route::delete('bookings/{booking}/requirements/{requirement}', [BookingRequirementController::class, 'destroy'])->name('bookings.requirements.destroy');
        Route::post('bookings/{booking}/payments', [BookingPaymentController::class, 'store'])->name('bookings.payments.store');
        Route::post('bookings/{booking}/assignments', [RoomAssignmentController::class, 'store'])->name('bookings.assignments.store');
        Route::post('bookings/{booking}/assignments/{assignment}/release', [RoomAssignmentController::class, 'release'])->name('bookings.assignments.release');
        Route::post('bookings/{booking}/stays/{stay}/check-in', [StayController::class, 'checkIn'])->name('bookings.stays.check-in');
        Route::post('bookings/{booking}/stays/{stay}/check-out', [StayController::class, 'checkOut'])->name('bookings.stays.check-out');
    });
});
