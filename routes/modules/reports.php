<?php

use App\Modules\Reports\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
Route::get('/reports/sales', [ReportController::class, 'sales'])->name('reports.sales');
Route::get('/reports/inventory', [ReportController::class, 'inventory'])->name('reports.inventory');
Route::get('/reports/item-ledger', [ReportController::class, 'itemLedger'])->name('reports.item-ledger');
Route::get('/reports/purchases', [ReportController::class, 'purchases'])->name('reports.purchases');
Route::get('/reports/aging', [ReportController::class, 'aging'])->name('reports.aging');
Route::get('/reports/reconciliation', [ReportController::class, 'reconciliation'])->name('reports.reconciliation');
Route::get('/reports/trial-balance', [ReportController::class, 'trialBalance'])->name('reports.trial-balance');
Route::get('/reports/general-ledger', [ReportController::class, 'generalLedger'])->name('reports.general-ledger');
Route::get('/reports/profit-and-loss', [ReportController::class, 'profitAndLoss'])->name('reports.profit-and-loss');
Route::get('/reports/balance-sheet', [ReportController::class, 'balanceSheet'])->name('reports.balance-sheet');
Route::get('/reports/vat', [ReportController::class, 'vat'])->name('reports.vat');
Route::get('/reports/einvoice-status', [ReportController::class, 'einvoiceStatus'])->name('reports.einvoice-status');
