<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\CasebookController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\HiddenTicketController;
use App\Http\Controllers\HyperModeController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\NoticeController;
use App\Http\Controllers\OverviewController;
use App\Http\Controllers\QuickScanBatchController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\SpaceController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'attempt'])->middleware('throttle:6,1')->name('login.attempt');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

Route::controller(AccountController::class)->group(function () {
    Route::get('/sign-in', 'signIn')->name('account.sign-in');
    Route::post('/sign-in', 'attempt')->middleware('throttle:6,1')->name('account.sign-in.attempt');
    Route::post('/first-account', 'first')->middleware('throttle:6,1')->name('account.first');
    Route::get('/welcome/{user}', 'welcome')->name('account.welcome');
    Route::post('/welcome/{user}', 'saveWelcome')->middleware('throttle:6,1')->name('account.welcome.save');
    Route::post('/sign-out', 'signOut')->name('account.sign-out');
    Route::get('/account', 'show')->name('account.show');
    Route::put('/account', 'update')->name('account.update');
    Route::put('/account/claude', 'claude')->name('account.claude');
    Route::put('/account/password', 'password')->name('account.password');
    Route::put('/account/jira', 'jira')->name('account.jira');
    Route::get('/account/jira/find', 'findJira')->middleware('throttle:30,1')->name('account.jira.find');
    Route::post('/people', 'invite')->name('people.invite');
    Route::post('/people/{user}/link', 'newLink')->name('people.link');
    Route::put('/people/{user}/role', 'role')->name('people.role');
    Route::put('/people/{user}/spaces', 'spaces')->name('people.spaces');
    Route::put('/people/{user}/budget', 'budget')->name('people.budget');
    Route::delete('/people/{user}', 'remove')->name('people.remove');
});

Route::get('/', OverviewController::class)->name('overview');
Route::post('/quick-scans', [QuickScanBatchController::class, 'store'])->name('quick-scans.store');
Route::delete('/quick-scans', [QuickScanBatchController::class, 'destroy'])->name('quick-scans.destroy');
Route::controller(TicketController::class)->prefix('tickets/{ticket}')->name('tickets.')->group(function () {
    Route::get('/', 'show')->name('show');
    Route::post('/analyse', 'analyse')->name('analyse');
    Route::post('/messages', 'message')->name('message');
    Route::post('/restate', 'restate')->name('restate');
    Route::post('/case', 'draftCase')->name('case');
    Route::post('/patch', 'patch')->name('patch');
    Route::post('/quick-scan', 'quickScan')->name('quick-scan');
    Route::get('/turns/{turn}/patch', 'patchFile')->name('patch.file');
    Route::post('/stop', 'stop')->name('stop');
    Route::post('/close', 'close')->name('close');
    Route::post('/take-over', 'takeOver')->name('take-over');
    Route::post('/draft', 'draft')->name('draft');
    Route::post('/assign-to-me', 'assignToMe')->name('assign-to-me');
});
Route::get('/tickets/{ticket}/attachments/{attachment}', AttachmentController::class)->where('attachment', '[0-9]+')->name('tickets.attachment');
Route::post('/tickets/{ticket}/hide', [HiddenTicketController::class, 'store'])->name('tickets.hide');
Route::delete('/tickets/{ticket}/hide', [HiddenTicketController::class, 'destroy'])->name('tickets.unhide');
Route::get('/notices', NoticeController::class)->name('notices');

// Everyone may look at the spaces and follow them; changing them is for editors.
Route::get('/spaces', [SpaceController::class, 'index'])->name('spaces.index');
Route::put('/spaces/{space}/follow', [SpaceController::class, 'follow'])->name('spaces.follow');

// Reading the casebook and proposing a case is for everyone; it counts once an editor approves.
Route::get('/casebook', [CasebookController::class, 'index'])->name('casebook.index');
Route::get('/casebook/create', [CasebookController::class, 'create'])->name('casebook.create');
Route::post('/casebook', [CasebookController::class, 'store'])->name('casebook.store');

// Editors and admins: spaces, systems and their APIs and databases, the casebook, hyper mode.
Route::middleware('role:editor')->group(function () {
    Route::post('/hyper', HyperModeController::class)->name('hyper');

    Route::controller(SpaceController::class)->prefix('spaces')->name('spaces.')->group(function () {
        Route::get('/create', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('/{space}/edit', 'edit')->name('edit');
        Route::put('/{space}', 'update')->name('update');
        Route::delete('/{space}', 'destroy')->name('destroy');
        Route::get('/{space}/preview', 'preview')->name('preview');
        Route::post('/{space}/activate', 'activate')->name('activate');
        Route::post('/{space}/pause', 'pause')->name('pause');
        Route::post('/{space}/sync', 'sync')->name('sync');
        Route::put('/{space}/people', 'people')->name('people');
        Route::put('/{space}/webhook', 'webhook')->name('webhook');
    });

    Route::controller(CasebookController::class)->prefix('casebook')->name('casebook.')->group(function () {
        Route::get('/{entry}/edit', 'edit')->name('edit');
        Route::put('/{entry}', 'update')->name('update');
        Route::delete('/{entry}', 'destroy')->name('destroy');
    });

    Route::controller(SystemController::class)->prefix('systems')->name('systems.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::post('/{system}/scan', 'scan')->name('scan');
        Route::post('/{system}/scan/stop', 'stopScan')->name('scan.stop');
        Route::put('/{system}/context', 'updateContext')->name('context');
        Route::post('/{system}/apis', 'storeApi')->name('apis.store');
        Route::put('/{system}/apis/{api}', 'updateApi')->name('apis.update');
        Route::delete('/{system}/apis/{api}', 'destroyApi')->name('apis.destroy');
        Route::post('/{system}/apis/{api}/try', 'tryApi')->name('apis.try');
        Route::put('/{system}/pull', 'pull')->name('pull');
        Route::post('/{system}/pull/key', 'pullKey')->name('pull.key');
        Route::post('/{system}/pull/now', 'pullNow')->name('pull.now');
        Route::post('/{system}/databases', 'storeDb')->name('databases.store');
        Route::put('/{system}/databases/{db}', 'updateDb')->name('databases.update');
        Route::delete('/{system}/databases/{db}', 'destroyDb')->name('databases.destroy');
        Route::post('/{system}/databases/{db}/try', 'tryDb')->name('databases.try');
    });
});

// Admins: the global setup, the docs, and (in AccountController) the people.
Route::middleware('role:admin')->group(function () {
    Route::get('/setup', SetupController::class)->name('setup');
    Route::put('/setup/reply-rules', [SetupController::class, 'replyRules'])->name('setup.reply-rules');
    Route::put('/setup/answer-rules', [SetupController::class, 'answerRules'])->name('setup.answer-rules');
    Route::put('/setup/auto-patch', [SetupController::class, 'autoPatch'])->name('setup.auto-patch');
    Route::put('/setup/settings', [SetupController::class, 'settings'])->name('setup.settings');
    Route::put('/setup/claude', [SetupController::class, 'claude'])->name('setup.claude');
    Route::post('/setup/jira-sites', [SetupController::class, 'addSite'])->name('setup.jira-sites.store');
    Route::delete('/setup/jira-sites/{site}', [SetupController::class, 'removeSite'])->name('setup.jira-sites.destroy');
    Route::get('/audit', AuditController::class)->name('audit');
    Route::get('/docs/{doc}', DocsController::class)->name('docs');
    Route::inertia('/design', 'Design')->name('design');
});
