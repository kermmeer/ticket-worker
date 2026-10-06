<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\CasebookController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\HiddenTicketController;
use App\Http\Controllers\HyperModeController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\OverviewController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\SpaceController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'attempt'])->middleware('throttle:6,1')->name('login.attempt');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

Route::get('/', OverviewController::class)->name('overview');
Route::post('/hyper', HyperModeController::class)->name('hyper');
Route::controller(TicketController::class)->prefix('tickets/{ticket}')->name('tickets.')->group(function () {
    Route::get('/', 'show')->name('show');
    Route::post('/analyse', 'analyse')->name('analyse');
    Route::post('/messages', 'message')->name('message');
    Route::post('/restate', 'restate')->name('restate');
    Route::post('/case', 'draftCase')->name('case');
    Route::post('/patch', 'patch')->name('patch');
    Route::get('/turns/{turn}/patch', 'patchFile')->name('patch.file');
    Route::post('/stop', 'stop')->name('stop');
    Route::post('/close', 'close')->name('close');
    Route::post('/draft', 'draft')->name('draft');
});
Route::get('/tickets/{ticket}/attachments/{attachment}', AttachmentController::class)->where('attachment', '[0-9]+')->name('tickets.attachment');
Route::post('/tickets/{ticket}/hide', [HiddenTicketController::class, 'store'])->name('tickets.hide');
Route::delete('/tickets/{ticket}/hide', [HiddenTicketController::class, 'destroy'])->name('tickets.unhide');

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
Route::post('/systems/{system}/scan', [SystemController::class, 'scan'])->name('systems.scan');
Route::post('/systems/{system}/scan/stop', [SystemController::class, 'stopScan'])->name('systems.scan.stop');
Route::put('/systems/{system}/context', [SystemController::class, 'updateContext'])->name('systems.context');
Route::get('/setup', SetupController::class)->name('setup');
Route::put('/setup/reply-rules', [SetupController::class, 'replyRules'])->name('setup.reply-rules');
Route::put('/setup/auto-patch', [SetupController::class, 'autoPatch'])->name('setup.auto-patch');
Route::get('/docs/{doc}', DocsController::class)->name('docs');
Route::inertia('/design', 'Design')->name('design');
