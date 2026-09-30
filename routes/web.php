<?php

use App\Modules\Identity\Controllers\AuthController;
use App\Modules\Settings\Controllers\SetupController;
use App\Modules\Settings\Controllers\TaxCodeController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

// الدخول
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// الإعداد الأولي: متاح قبل وجود مستخدم (لإنشاء المستخدم الأول) وبعده للمالك
Route::get('/setup', [SetupController::class, 'show'])->name('setup.show');
Route::post('/setup/first-user', [SetupController::class, 'createFirstUser'])->name('setup.first-user');

Route::middleware('auth')->group(function (): void {
    Route::post('/setup/business', [SetupController::class, 'saveBusiness'])->name('setup.business');
    Route::post('/setup/chart', [SetupController::class, 'installChart'])->name('setup.chart');
    Route::post('/setup/mappings', [SetupController::class, 'saveMappings'])->name('setup.mappings');
    Route::post('/setup/periods', [SetupController::class, 'savePeriod'])->name('setup.periods');
    Route::post('/setup/complete', [SetupController::class, 'complete'])->name('setup.complete');

    Route::post('/settings/tax-codes', [TaxCodeController::class, 'store'])->name('tax-codes.store');
    Route::patch('/settings/tax-codes/{taxCode}', [TaxCodeController::class, 'update'])->name('tax-codes.update');

    Route::get('/account/password', [AuthController::class, 'showPassword'])->name('password.edit');
    Route::patch('/account/password', [AuthController::class, 'updatePassword'])->name('password.update');
});

require __DIR__.'/modules.php';
