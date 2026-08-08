<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\BookingStatus;
use App\Enums\ChargeType;
use App\Exceptions\BreakfastAlreadyPostedException;
use App\Exceptions\PackageAlreadyPostedException;
use App\Exceptions\PackageNotEnrollableException;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\NightAuditBookingLog;
use App\Models\ServicePackage;
use App\Services\BusinessDateService;
use App\Services\HotelSettingsService;
use App\Services\PackageEnrollmentService;
use App\Services\Posting\BreakfastPostingJob;
use App\Services\Posting\ExtraBedPostingJob;
use App\Services\Posting\ExtraPersonPostingJob;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PackageEnrollmentController extends Controller
{
    public function __construct(
        private readonly PackageEnrollmentService $enrollmentService,
        private readonly BusinessDateService $businessDateService,
        private readonly HotelSettingsService $settingsService,
    ) {}

    public function show(Request $request, Booking $booking): Response
    {
        abort_unless($request->user()->can('folio.view'), 403);

        $booking->load(['folio', 'stays']);

        $businessDate = $this->businessDateService->currentBusinessDate();

        $lastAuditLogs = NightAuditBookingLog::where('booking_id', $booking->id)
            ->whereIn('job_class', [
                BreakfastPostingJob::class,
                ExtraPersonPostingJob::class,
                ExtraBedPostingJob::class,
            ])
            ->orderByDesc('created_at')
            ->get()
            ->unique('job_class')
            ->map(fn ($log) => [
                'job_class' => $log->job_class,
                'result'    => $log->result,
                'posted_at' => $log->created_at->format('d/m/Y H:i'),
            ])
            ->values();

        $catalog = $this->enrollmentService->catalogForBooking($booking);

        return Inertia::render('Admin/Booking/Packages', [
            'booking' => [
                'id'            => $booking->id,
                'booking_code'  => $booking->booking_code,
                'customer_name' => $booking->customer_name,
                'status'        => $booking->status,
            ],
            'enrollments'        => $this->enrollmentService->getEnrollmentSummary($booking, $catalog),
            'available_packages' => $this->buildAvailablePackages($catalog, $businessDate),
            'last_audit_logs'    => $lastAuditLogs,
            'city_tax_enabled'   => $this->settingsService->getBool('city_tax_enabled', false),
            'can'                => [
                'manage_packages' => $request->user()->can('booking.package.manage'),
            ],
        ]);
    }

    public function enroll(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->can('booking.package.manage'), 403);
        abort_if(
            in_array($booking->status, [BookingStatus::CheckedOut, BookingStatus::Cancelled, BookingStatus::NoShow], true),
            403,
            'Đặt phòng đã kết thúc.'
        );

        $data = $request->validate([
            'package_key' => ['required', 'string', Rule::exists('service_packages', 'code')],
            'quantity'    => ['sometimes', 'integer', 'min:1', 'max:4'],
        ]);

        try {
            $this->enrollmentService->enroll(
                $booking,
                $data['package_key'],
                $request->user(),
                $data['quantity'] ?? 1
            );
        } catch (PackageNotEnrollableException $e) {
            return back()->withErrors(['package_key' => $e->getMessage()]);
        }

        return back()->with('success', 'Đã đăng ký gói dịch vụ.');
    }

    public function unenroll(Request $request, Booking $booking, string $packageKey): RedirectResponse
    {
        abort_unless($request->user()->can('booking.package.manage'), 403);

        // Deliberately does not require active/bookable here: a booking must
        // always be able to unenroll a package it already holds, even after
        // that package is deactivated in the catalog. Only real package
        // codes are accepted, so it cannot be used to probe arbitrary keys.
        validator(['package_key' => $packageKey], [
            'package_key' => ['required', 'string', Rule::exists('service_packages', 'code')],
        ])->validate();

        try {
            $this->enrollmentService->unenroll($booking, $packageKey);
        } catch (BreakfastAlreadyPostedException|PackageAlreadyPostedException $e) {
            return back()->withErrors(['package' => $e->getMessage()]);
        }

        return back()->with('success', 'Đã hủy đăng ký gói dịch vụ.');
    }

    /**
     * @param Collection<int, ServicePackage> $catalog
     */
    private function buildAvailablePackages(Collection $catalog, Carbon $businessDate): array
    {
        return $catalog
            ->map(function (ServicePackage $package) use ($businessDate): array {
                $rate = $package->currentRate($businessDate->toDateString());

                return [
                    'key'           => $package->code,
                    'code'          => $package->code,
                    'label'         => $package->name,
                    'description'   => $package->description,
                    'charge_label'  => ChargeType::tryFrom($package->charge_type)?->label() ?? $package->charge_type,
                    'unit_label'    => $package->unit_label,
                    'quantity_mode' => $package->quantity_mode,
                    'is_active'     => $package->is_active,
                    'is_bookable'   => $package->is_bookable,
                    'current_rate'  => $rate !== null ? (float) $rate->unit_price : null,
                ];
            })
            ->values()
            ->all();
    }
}
