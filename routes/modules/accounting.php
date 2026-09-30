<?php

use App\Modules\Accounting\Controllers\AccountingPeriodController;
use Illuminate\Support\Facades\Route;

Route::redirect('/accounting/journals', '/reports/general-ledger')->name('accounting.journals');
Route::get('/accounting/periods', [AccountingPeriodController::class, 'index'])->name('periods.index');
Route::post('/accounting/periods', [AccountingPeriodController::class, 'store'])->name('periods.store');
Route::post('/accounting/periods/{period}/close', [AccountingPeriodController::class, 'close'])->name('periods.close');
Route::post('/accounting/periods/{period}/reopen', [AccountingPeriodController::class, 'reopen'])->name('periods.reopen');
