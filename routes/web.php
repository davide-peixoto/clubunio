<?php

use App\ClubUnio\Http\BankAccountPortalController;
use App\ClubUnio\Http\BexioConnectionController;
use App\ClubUnio\Http\BusReservationController;
use App\ClubUnio\Http\ExpensePortalController;
use App\ClubUnio\Http\InvoicePortalController;
use App\ClubUnio\Http\NotificationPortalController;
use App\ClubUnio\Http\PortalController;
use App\ClubUnio\Http\RegistrationPortalController;
use App\ClubUnio\Http\TeamPlanningController;
use App\ClubUnio\Http\TrainingPlanController;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\InvoiceAccessService;
use App\ClubUnio\Services\InvoicePdfService;
use App\ClubUnio\Services\MemberDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/portal/login', [PortalController::class, 'loginForm'])->name('clubunio.portal.login');
Route::post('/portal/login', [PortalController::class, 'login']);
Route::get('/portal/{tenant:slug}/members', [PortalController::class, 'members'])
    ->middleware('auth:portal')->name('clubunio.portal.members');
Route::post('/portal/logout', [PortalController::class, 'logout'])
    ->middleware('auth:portal')->name('clubunio.portal.logout');

Route::middleware('auth:portal')->group(function () {
    Route::get('/portal/{tenant:slug}/invoices', [InvoicePortalController::class, 'index'])->name('clubunio.portal.invoices');
    Route::get('/portal/{tenant:slug}/invoices/{invoice}', [InvoicePortalController::class, 'show'])->whereNumber('invoice')->name('clubunio.portal.invoice');
    Route::get('/portal/{tenant:slug}/bank-accounts', [BankAccountPortalController::class, 'index'])->name('clubunio.portal.banks');
    Route::post('/portal/{tenant:slug}/bank-accounts/{account?}', [BankAccountPortalController::class, 'save'])->whereNumber('account')->name('clubunio.portal.bank.save');
    Route::get('/portal/{tenant:slug}/expenses', [ExpensePortalController::class, 'index'])->name('clubunio.portal.expenses');
    Route::get('/portal/{tenant:slug}/expenses/new', [ExpensePortalController::class, 'create'])->name('clubunio.portal.expense.create');
    Route::post('/portal/{tenant:slug}/expenses', [ExpensePortalController::class, 'store'])->name('clubunio.portal.expense.store');
    Route::get('/portal/{tenant:slug}/expenses/{expense}', [ExpensePortalController::class, 'show'])->whereNumber('expense')->name('clubunio.portal.expense');
    Route::post('/portal/{tenant:slug}/expenses/{expense}/status', [ExpensePortalController::class, 'transition'])->whereNumber('expense')->name('clubunio.portal.expense.transition');
    Route::post('/portal/{tenant:slug}/expenses/{expense}/lines/{line?}', [ExpensePortalController::class, 'line'])->whereNumber('expense')->whereNumber('line')->name('clubunio.portal.expense.line');
    Route::get('/portal/{tenant:slug}/expense-documents/{line}', [ExpensePortalController::class, 'document'])->whereNumber('line')->name('clubunio.portal.expense.document');
    Route::get('/portal/{tenant:slug}/notifications', [NotificationPortalController::class, 'index'])->name('clubunio.portal.notifications');
    Route::post('/portal/{tenant:slug}/notifications/read-all', [NotificationPortalController::class, 'readAll'])->name('clubunio.portal.notifications.read-all');
    Route::post('/portal/{tenant:slug}/notifications/{notification}/read', [NotificationPortalController::class, 'read'])->whereNumber('notification')->name('clubunio.portal.notifications.read');
    Route::get('/portal/{tenant:slug}/registrations', [RegistrationPortalController::class, 'index'])->name('clubunio.portal.registrations');
    Route::get('/portal/{tenant:slug}/registrations/{registration}', [RegistrationPortalController::class, 'show'])->whereNumber('registration')->name('clubunio.portal.registration');
    Route::post('/portal/{tenant:slug}/registrations/{registration}', [RegistrationPortalController::class, 'update'])->whereNumber('registration')->name('clubunio.portal.registration.update');
    Route::get('/portal/{tenant:slug}/registration-documents/{document}', [RegistrationPortalController::class, 'document'])->whereNumber('document')->name('clubunio.portal.registration.document');
});

Route::post('/tenant/switch', [\App\ClubUnio\Http\TenantNavigationController::class, 'switch'])
    ->middleware('auth:clubunio')->name('clubunio.tenant.switch');
Route::redirect('/clubunio/login', '/login');
Route::redirect('/clubunio', '/');
Route::get('/clubunio/{tenant}/{path?}', [\App\ClubUnio\Http\TenantNavigationController::class, 'legacy'])
    ->where('path', '.*')->middleware('auth:clubunio');

// Preserve bookmarks without changing the registered Bexio callback or replaying writes.
Route::get('/clubora/{path?}', function (Request $request, ?string $path = null) {
    $target = '/clubunio'.($path !== null ? '/'.$path : '');

    return redirect($target.($request->getQueryString() ? '?'.$request->getQueryString() : ''), 302);
})->where('path', '.*');

Route::middleware('auth:clubunio,portal')->prefix('/bus/{tenant:slug}')->name('clubunio.bus.')->group(function () {
    $controller = BusReservationController::class;
    Route::get('/', [$controller, 'index'])->name('index');
    Route::get('/new', [$controller, 'create'])->name('create');
    Route::post('/', [$controller, 'store'])->name('store');
    Route::get('/options', [$controller, 'options'])->name('options');
    Route::post('/options', [$controller, 'saveOption']);
    Route::get('/{reservation}', [$controller, 'show'])->whereNumber('reservation')->name('show');
    Route::get('/{reservation}/edit', [$controller, 'edit'])->whereNumber('reservation')->name('edit');
    Route::post('/{reservation}/reschedule', [$controller, 'reschedule'])->whereNumber('reservation')->name('reschedule');
    Route::post('/{reservation}/decision', [$controller, 'decide'])->whereNumber('reservation')->name('decide');
    Route::get('/{reservation}/assignment', [$controller, 'assignment'])->whereNumber('reservation')->name('assignment');
    Route::post('/{reservation}/assignment', [$controller, 'assign'])->whereNumber('reservation')->name('assign');
});

Route::middleware('auth:clubunio,portal')->prefix('/planning/{tenant:slug}/training-plans')->name('clubunio.training.')->group(function () {
    $controller = TrainingPlanController::class;
    Route::get('/', [$controller, 'index'])->name('index');
    Route::get('/new', [$controller, 'create'])->name('create');
    Route::post('/', [$controller, 'generate'])->middleware('throttle:3,1')->name('generate');
    Route::get('/{plan}', [$controller, 'show'])->whereNumber('plan')->name('show');
    Route::post('/{plan}', [$controller, 'save'])->whereNumber('plan')->name('save');
    Route::post('/{plan}/review', [$controller, 'review'])->whereNumber('plan')->middleware('throttle:3,1')->name('review');
    Route::post('/{plan}/apply', [$controller, 'apply'])->whereNumber('plan')->name('apply');
    Route::get('/{plan}/pdf', [$controller, 'pdf'])->whereNumber('plan')->name('pdf');
    Route::post('/{plan}/archive', [$controller, 'archive'])->whereNumber('plan')->name('archive');
    Route::post('/{plan}/copy', [$controller, 'copy'])->whereNumber('plan')->name('copy');
});

Route::middleware('auth:clubunio,portal')->prefix('/planning/{tenant:slug}')->name('clubunio.planning.')->group(function () {
    $controller = TeamPlanningController::class;
    Route::get('/', [$controller, 'index'])->name('index');
    Route::get('/calendar', [$controller, 'calendar'])->name('calendar');
    Route::get('/calendar/export', [$controller, 'calendarExport'])->name('calendar.export');
    Route::get('/new', [$controller, 'form'])->name('create');
    Route::post('/events', [$controller, 'save'])->name('store');
    Route::get('/absences', [$controller, 'absences'])->name('absences');
    Route::post('/absences/{absence?}', [$controller, 'saveAbsence'])->whereNumber('absence')->name('absence.save');
    Route::delete('/absences/{absence}', [$controller, 'saveAbsence'])->whereNumber('absence')->name('absence.delete');
    Route::get('/options', [$controller, 'options'])->name('options');
    Route::post('/options', [$controller, 'saveOption']);
    Route::get('/sfv', [$controller, 'sfv'])->name('sfv');
    Route::post('/sfv/refresh', [$controller, 'sfvRefresh'])->middleware('throttle:3,1')->name('sfv.refresh');
    Route::get('/sfv/{match}', [$controller, 'sfvForm'])->whereNumber('match')->name('sfv.form');
    Route::post('/sfv/{match}', [$controller, 'sfvImport'])->whereNumber('match')->name('sfv.import');
    Route::match(['GET', 'POST'], '/events/{event}/series', [$controller, 'series'])->whereNumber('event')->name('series');
    Route::post('/events/{event}/series/apply', [$controller, 'seriesApply'])->whereNumber('event')->name('series.apply');
    Route::post('/events/{event}/guests/{guest?}', [$controller, 'guest'])->whereNumber('event')->whereNumber('guest')->name('guest');
    Route::delete('/events/{event}/guests/{guest}', [$controller, 'guest'])->whereNumber('event')->whereNumber('guest')->name('guest.delete');
    Route::post('/events/{event}/invite/{member}', [$controller, 'invite'])->whereNumber('event')->whereNumber('member')->name('invite');
    Route::delete('/events/{event}/invite/{member}', [$controller, 'invite'])->whereNumber('event')->whereNumber('member')->name('invite.delete');
    Route::get('/events/{event}', [$controller, 'show'])->whereNumber('event')->name('show');
    Route::get('/events/{event}/edit', [$controller, 'form'])->whereNumber('event')->name('edit');
    Route::post('/events/{event}', [$controller, 'save'])->whereNumber('event')->name('save');
    Route::post('/events/{event}/members/{member}', [$controller, 'participate'])->whereNumber('event')->whereNumber('member')->name('participate');
});

Route::middleware('auth:clubunio')->prefix('/integrations/bexio')->name('clubunio.bexio.')->group(function () {
    Route::get('/', [BexioConnectionController::class, 'centralShow'])->name('show');
    Route::post('/connect', [BexioConnectionController::class, 'centralStart'])->middleware('throttle:5,1')->name('start');
    Route::get('/callback', [BexioConnectionController::class, 'centralCallback'])->middleware('throttle:10,1')->name('callback');
});

Route::middleware('auth:clubunio')->prefix('/clubora-bexio/{tenant:slug}')->name('clubunio.bexio.legacy.')->group(function () {
    $controller = BexioConnectionController::class;
    Route::get('/', [$controller, 'show'])->name('show');
    Route::post('/connect', [$controller, 'start'])->middleware('throttle:5,1')->name('start');
    Route::get('/callback', [$controller, 'callback'])->middleware('throttle:10,1')->name('callback');
});

Route::get('/clubora-invoice-preview/{tenant:slug}/{invoice}/{source}', function (Request $request, Tenant $tenant, int $invoice, string $source) {
    abort_unless($request->user('clubunio')->canAccessTenant($tenant), 403);
    app(InvoiceAccessService::class)->visible($request->user('clubunio'), $tenant)->findOrFail($invoice);

    return response()->view('clubunio.invoices.viewer', [
        'url' => route('clubunio.invoices.pdf', compact('tenant', 'invoice', 'source')),
    ])->header('Cache-Control', 'private, no-store')->header('X-Frame-Options', 'SAMEORIGIN')
        ->header('X-Content-Type-Options', 'nosniff');
})->middleware('auth:clubunio')->whereNumber('invoice')->whereIn('source', ['bexio', 'local'])->name('clubunio.invoices.preview');

Route::get('/clubora-invoice-pdf/{tenant:slug}/{invoice}/{source}', function (Request $request, Tenant $tenant, int $invoice, string $source) {
    abort_unless($request->user('clubunio')->canAccessTenant($tenant), 403);

    return app(InvoicePdfService::class)->open($request->user('clubunio'), $tenant, $invoice, $source === 'bexio', $request->boolean('download'));
})->middleware('auth:clubunio')->whereNumber('invoice')->whereIn('source', ['bexio', 'local'])->name('clubunio.invoices.pdf');

Route::get('/clubora-expense-documents/{tenant:slug}/{line}', function (Request $request, Tenant $tenant, int $line) {
    $data = $request->validate(['index' => 'nullable|integer|min:0|max:100']);

    return app(MemberDocumentService::class)->openExpense($request->user('clubunio'), $tenant, $line, $request->boolean('download'), (int) ($data['index'] ?? 0));
})->middleware('auth:clubunio')->whereNumber('line')->name('clubunio.expenses.document');
Route::get('/admin/{path?}', fn () => redirect('/'))->where('path', '.*');

Route::get('/clubora-documents/{tenant:slug}/{document}', function (Request $request, Tenant $tenant, int $document) {
    return app(MemberDocumentService::class)->open($request->user('clubunio'), $tenant, $document, $request->boolean('download'));
})->middleware('auth:clubunio')->whereNumber('document')->name('clubunio.documents.open');

Route::get('/clubora-registration-documents/{tenant:slug}/{document}', function (Request $request, Tenant $tenant, int $document) {
    return app(MemberDocumentService::class)->openRegistration($request->user('clubunio'), $tenant, $document, $request->boolean('download'));
})->middleware('auth:clubunio')->whereNumber('document')->name('clubunio.registrations.document');
