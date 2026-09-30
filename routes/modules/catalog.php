<?php

use App\Modules\Catalog\Controllers\ItemController;
use Illuminate\Support\Facades\Route;

Route::get('/items', [ItemController::class, 'index'])->name('items.index');
Route::post('/items', [ItemController::class, 'store'])->name('items.store');
Route::patch('/items/{item}', [ItemController::class, 'update'])->name('items.update');
Route::post('/items/{item}/deactivate', [ItemController::class, 'deactivate'])->name('items.deactivate');
Route::post('/items/{item}/activate', [ItemController::class, 'activate'])->name('items.activate');
Route::post('/item-units/{itemUnit}/recipe', [ItemController::class, 'saveRecipe'])->name('items.recipe');
