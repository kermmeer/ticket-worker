<?php

use App\Http\Controllers\DocsController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\SystemController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Overview')->name('overview');
Route::get('/systems', [SystemController::class, 'index'])->name('systems.index');
Route::post('/systems', [SystemController::class, 'store'])->name('systems.store');
Route::get('/setup', SetupController::class)->name('setup');
Route::get('/docs/{doc}', DocsController::class)->name('docs');
Route::inertia('/design', 'Design')->name('design');
