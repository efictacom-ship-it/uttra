<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Settings\Models\BusinessSetting;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $settings = BusinessSetting::query()->find(1);

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'role' => $request->user()->role,
                ] : null,
            ],
            'business' => $settings ? [
                'legal_name' => $settings->legal_name,
                'trade_name' => $settings->trade_name,
                'vat_registered' => (bool) $settings->vat_registered,
                'vat_number' => $settings->vat_number,
                'currency' => $settings->currency,
                'einvoice_phase' => $settings->einvoice_phase,
                'setup_completed' => $settings->setup_completed_at !== null,
            ] : null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ]);
    }
}
