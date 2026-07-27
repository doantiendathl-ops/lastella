<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames()->values(),
                    'permissions' => $user->getAllPermissions()->pluck('name')->values(),
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                // Pre-existing bug fix: StayController already sets this via redirect()->with(...)
                // but it never reached the frontend — this key was missing from the shared 'flash'
                // prop, so RoomBoardPanel.vue's final-checkout-confirmation watcher never fired.
                'final_checkout_confirmation_required' => fn () => $request->session()->get('final_checkout_confirmation_required'),
            ],
        ];
    }
}
