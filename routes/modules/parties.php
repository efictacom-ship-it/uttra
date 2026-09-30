<?php

use App\Modules\Parties\Controllers\PartyController;
use Illuminate\Support\Facades\Route;

Route::get('/parties', [PartyController::class, 'index'])->name('parties.index');
Route::post('/parties', [PartyController::class, 'store'])->name('parties.store');
Route::get('/parties/{party}', [PartyController::class, 'show'])->name('parties.show');
Route::patch('/parties/{party}', [PartyController::class, 'update'])->name('parties.update');
