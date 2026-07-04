<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\BookingStatus;
use App\Enums\ChargeType;
use App\Exceptions\BreakfastAlreadyPostedException;
use App\Exceptions\PackageAlreadyPostedException;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\NightAuditBookingLog;
use App\Services\BusinessDateService;
use App\Services\HotelSettingsService;
use App\Services\PackageEnrollmentService;
use App\Services\Posting\BreakfastPostingJob;
use App\Services\Posting\ExtraBedPostingJob;
use App\Services\Posting\ExtraPersonPostingJob;
use App\Services\ServiceRateService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PackageEnrollmentController extends Controller
{
    public function __construct(
        private readonly PackageEnrollmentService $enrollmentService,
        private readonly ServiceRateService $rateService,
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

        return Inertia::render('Admin/Booking/Packages', [
            'booking' => [
                'id'            => $booking->id,
                'booking_code'  => $booking->booking_code,
                'customer_name' => $booking->customer_name,
                'status'        => $booking->status,
            ],
            'enrollments'        => $this->enrollmentService->getEnrollmentSummary($booking),
            'available_packages' => $this->buildAvailablePackages($businessDate),
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
            'package_key' => ['required', 'string', Rule::in(PackageEnrollmentService::ALLOWED_PACKAGES)],
            'quantity'    => ['sometimes', 'integer', 'min:1', 'max:4'],
        ]);

        $this->enrollmentService->enroll(
            $booking,
            $data['package_key'],
            $request->user(),
            $data['quantity'] ?? 1
        );

        return back()->with('success', 'Đã đăng ký gói dịch vụ.');
    }

    public function unenroll(Request $request, Booking $booking, string $packageKey): RedirectResponse
    {
        abort_unless($request->user()->can('booking.package.manage'), 403);

        validator(['package_key' => $packageKey], [
            'package_key' => ['required', 'string', Rule::in(PackageEnrollmentService::ALLOWED_PACKAGES)],
        ])->validate();

        try {
            $this->enrollmentService->unenroll($booking, $packageKey);
        } catch (BreakfastAlreadyPostedException|PackageAlreadyPostedException $e) {
            return back()->withErrors(['package' => $e->getMessage()]);
        }

        return back()->with('success', 'Đã hủy đăng ký gói dịch vụ.');
    }

    private function buildAvailablePackages(Carbon $businessDate): array
    {
        $packageMap = [
            PackageEnrollmentService::BREAKFAST_PER_NIGHT    => [
                'charge_type'  => ChargeType::FoodBeverage,
                'label'        => 'Ăn sáng mỗi đêm',
                'charge_label' => 'Ăn & uống',
            ],
            PackageEnrollmentService::EXTRA_PERSON_PER_NIGHT => [
                'charge_type'  => ChargeType::ExtraPerson,
                'label'        => 'Người thêm / đêm',
                'charge_label' => 'Dịch vụ bổ sung',
            ],
            PackageEnrollmentService::EXTRA_BED_PER_NIGHT    => [
                'charge_type'  => ChargeType::ExtraBed,
                'label'        => 'Giường phụ / đêm',
                'charge_label' => 'Dịch vụ bổ sung',
            ],
        ];

        $result = [];
        foreach ($packageMap as $key => $meta) {
            $rate     = $this->rateService->resolveFor($meta['charge_type'], $businessDate);
            $result[] = [
                'key'          => $key,
                'label'        => $meta['label'],
                'charge_label' => $meta['charge_label'],
                'current_rate' => $rate !== null ? (float) $rate->unit_price : null,
            ];
        }

        return $result;
    }
}
