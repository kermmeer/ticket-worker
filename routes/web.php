<?php

use App\Http\Controllers\DocsController;
use App\Http\Controllers\SetupController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Overview')->name('overview');
Route::get('/setup', SetupController::class)->name('setup');
Route::get('/docs/{doc}', DocsController::class)->name('docs');
Route::inertia('/design', 'Design')->name('design');
