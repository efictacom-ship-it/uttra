<?php

use App\Modules\Finance\Controllers\CashSessionController;
use App\Modules\Finance\Controllers\ExpenseController;
use App\Modules\Finance\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
Route::get('/expenses/{expense}', [ExpenseController::class, 'show'])->name('expenses.show');

Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');
Route::post('/payments', [PaymentController::class, 'store'])->name('payments.store');
Route::post('/payments/transfer', [PaymentController::class, 'transfer'])->name('payments.transfer');
Route::get('/payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
Route::post('/payments/{payment}/allocate', [PaymentController::class, 'allocate'])->name('payments.allocate');

Route::get('/cash-sessions', [CashSessionController::class, 'index'])->name('cash-sessions.index');
Route::post('/cash-sessions/open', [CashSessionController::class, 'open'])->name('cash-sessions.open');
Route::post('/cash-sessions/{cashSession}/close', [CashSessionController::class, 'close'])->name('cash-sessions.close');
