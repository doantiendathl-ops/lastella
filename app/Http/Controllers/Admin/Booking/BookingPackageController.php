<?php

namespace App\Http\Controllers\Admin\Booking;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingPackageFlag;
use App\Services\PackageEnrollmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BookingPackageController extends Controller
{
    public function __construct(private readonly PackageEnrollmentService $packages) {}

    public function enroll(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorize('enroll', BookingPackageFlag::class);

        $data = $request->validate([
            'package_key' => [
                'required',
                'string',
                Rule::in([PackageEnrollmentService::BREAKFAST_PER_NIGHT]),
            ],
        ]);

        $this->packages->enroll($booking, $data['package_key'], $request->user());

        return back()->with('success', 'Đã đăng ký gói dịch vụ.');
    }

    public function unenroll(Request $request, Booking $booking, string $packageKey): RedirectResponse
    {
        $this->authorize('unenroll', BookingPackageFlag::class);

        $this->packages->unenroll($booking, $packageKey);

        return back()->with('success', 'Đã hủy đăng ký gói dịch vụ.');
    }
}
