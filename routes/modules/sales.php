<?php

use App\Modules\Sales\Controllers\SalesController;
use App\Modules\Sales\Controllers\SalesReturnController;
use Illuminate\Support\Facades\Route;

Route::get('/pos', [SalesController::class, 'cashier'])->name('pos');
Route::get('/sales', [SalesController::class, 'index'])->name('sales.index');
Route::post('/sales', [SalesController::class, 'store'])->name('sales.store');
Route::patch('/sales/{sale}', [SalesController::class, 'update'])->name('sales.update');
Route::post('/sales/{sale}/post', [SalesController::class, 'post'])->name('sales.post');
Route::get('/sales/{sale}', [SalesController::class, 'show'])->name('sales.show');
Route::post('/sales/{sale}/returns', [SalesReturnController::class, 'store'])->name('sales.returns');
