<?php

use App\Modules\EInvoicing\Controllers\EInvoiceController;
use Illuminate\Support\Facades\Route;

Route::get('/einvoicing', [EInvoiceController::class, 'index'])->name('einvoicing.index');
Route::get('/einvoices', [EInvoiceController::class, 'index'])->name('einvoicing.index.alias');
Route::post('/einvoicing/outbox/process', [EInvoiceController::class, 'processOutbox'])->name('einvoicing.outbox.process');
Route::post('/documents/{document}/einvoice', [EInvoiceController::class, 'generate'])->name('einvoicing.generate');
Route::post('/einvoicing/{einvoiceDocument}/submit', [EInvoiceController::class, 'submit'])->name('einvoicing.submit');
Route::post('/einvoicing/{einvoiceDocument}/retry', [EInvoiceController::class, 'retry'])->name('einvoicing.retry');
