<?php

use App\Modules\Reports\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/dashboard', DashboardController::class)->name('dashboard');
