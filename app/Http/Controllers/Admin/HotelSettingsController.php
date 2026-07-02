<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateHotelSettingsRequest;
use App\Models\HotelSetting;
use App\Services\HotelSettingsService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class HotelSettingsController extends Controller
{
    public function __construct(private readonly HotelSettingsService $hotelSettings)
    {
    }

    public function index(): Response
    {
        $this->authorize('viewAny', HotelSetting::class);

        return Inertia::render('Admin/HotelSettings/Index', [
            'settings' => $this->hotelSettings->all(),
        ]);
    }

    public function update(UpdateHotelSettingsRequest $request): RedirectResponse
    {
        $this->authorize('update', HotelSetting::class);

        foreach ($request->validated('settings') as $item) {
            $this->hotelSettings->set($item['key'], $item['value'], $request->user()->id);
        }

        return redirect()
            ->route('admin.hotel-settings.index')
            ->with('success', 'Đã cập nhật cài đặt khách sạn.');
    }
}
