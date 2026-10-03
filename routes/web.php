<?php

use App\Http\Controllers\CasebookController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\HyperModeController;
use App\Http\Controllers\OverviewController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\SpaceController;
use App\Http\Controllers\SystemController;
use Illuminate\Support\Facades\Route;

Route::get('/', OverviewController::class)->name('overview');
Route::post('/hyper', HyperModeController::class)->name('hyper');

Route::controller(SpaceController::class)->prefix('spaces')->name('spaces.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/create', 'create')->name('create');
    Route::post('/', 'store')->name('store');
    Route::get('/{space}/edit', 'edit')->name('edit');
    Route::put('/{space}', 'update')->name('update');
    Route::delete('/{space}', 'destroy')->name('destroy');
    Route::get('/{space}/preview', 'preview')->name('preview');
    Route::post('/{space}/activate', 'activate')->name('activate');
    Route::post('/{space}/pause', 'pause')->name('pause');
    Route::post('/{space}/sync', 'sync')->name('sync');
});

Route::controller(CasebookController::class)->prefix('casebook')->name('casebook.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/create', 'create')->name('create');
    Route::post('/', 'store')->name('store');
    Route::get('/{entry}/edit', 'edit')->name('edit');
    Route::put('/{entry}', 'update')->name('update');
    Route::delete('/{entry}', 'destroy')->name('destroy');
});

Route::get('/systems', [SystemController::class, 'index'])->name('systems.index');
Route::post('/systems', [SystemController::class, 'store'])->name('systems.store');
Route::get('/setup', SetupController::class)->name('setup');
Route::get('/docs/{doc}', DocsController::class)->name('docs');
Route::inertia('/design', 'Design')->name('design');
