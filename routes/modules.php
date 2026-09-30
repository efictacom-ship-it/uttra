<?php

/**
 * مسارات الموديولات. كل موديول في ملف مستقل، وكلها تحت auth + إتمام الإعداد.
 */

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'setup.completed'])->group(function (): void {
    foreach (['dashboard', 'catalog', 'parties', 'sales', 'purchasing', 'inventory', 'finance', 'accounting', 'einvoicing', 'reports'] as $module) {
        $path = __DIR__.'/modules/'.$module.'.php';
        if (file_exists($path)) {
            require $path;
        }
    }
});
