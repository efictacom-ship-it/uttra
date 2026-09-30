<?php

use App\Modules\Purchasing\Controllers\PurchaseController;
use App\Modules\Purchasing\Controllers\PurchaseReturnController;
use Illuminate\Support\Facades\Route;

Route::get('/purchases', [PurchaseController::class, 'index'])->name('purchases.index');
Route::post('/purchases/drafts', [PurchaseController::class, 'store'])->name('purchases.store');
Route::patch('/purchases/{purchase}/draft', [PurchaseController::class, 'update'])->name('purchases.update');
Route::post('/purchases/{purchase}/post', [PurchaseController::class, 'post'])->name('purchases.post');
Route::get('/purchases/{purchase}', [PurchaseController::class, 'show'])->name('purchases.show');
Route::post('/purchases/{purchase}/returns', [PurchaseReturnController::class, 'store'])->name('purchases.returns');
