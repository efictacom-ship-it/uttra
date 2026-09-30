<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Settings\Models\BusinessSetting;
use Closure;
use Illuminate\Http\Request;

/** لا تشغيل قبل إتمام إعداد المنشأة والحسابات. */
class EnsureSetupCompleted
{
    public function handle(Request $request, Closure $next)
    {
        $settings = BusinessSetting::query()->find(1);

        if (! $settings || $settings->setup_completed_at === null) {
            return redirect()->route('setup.show');
        }

        return $next($request);
    }
}
