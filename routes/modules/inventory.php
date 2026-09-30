<?php

use App\Modules\Inventory\Controllers\InventoryController;
use App\Modules\Inventory\Controllers\StockCountController;
use Illuminate\Support\Facades\Route;

Route::get('/inventory/balances', [InventoryController::class, 'balances'])->name('inventory.balances');
Route::get('/inventory/movements', [InventoryController::class, 'movements'])->name('inventory.movements');
Route::get('/inventory/adjustments', [InventoryController::class, 'adjustments'])->name('inventory.adjustments');
Route::post('/inventory/opening', [InventoryController::class, 'storeOpening'])->name('inventory.opening');
Route::post('/inventory/adjustments', [InventoryController::class, 'storeAdjustment'])->name('inventory.adjustments.store');

Route::get('/stock-counts', [StockCountController::class, 'index'])->name('stock-counts.index');
Route::post('/stock-counts', [StockCountController::class, 'store'])->name('stock-counts.store');
Route::get('/stock-counts/{stockCount}', [StockCountController::class, 'show'])->name('stock-counts.show');
Route::patch('/stock-counts/{stockCount}', [StockCountController::class, 'update'])->name('stock-counts.update');
Route::post('/stock-counts/{stockCount}/post', [StockCountController::class, 'post'])->name('stock-counts.post');
Route::post('/stock-counts/{stockCount}/cancel', [StockCountController::class, 'cancel'])->name('stock-counts.cancel');
