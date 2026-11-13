<?php

use App\Http\Controllers\PrintController;
use App\Http\Controllers\ReportViewerController;
use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::get('/locale/{locale}', function (string $locale) {
    abort_unless(array_key_exists($locale, config('erp.locales')), 404);
    session(['locale' => $locale]);
    auth()->user()?->update(['locale' => $locale]);

    return back();
})->middleware('web')->name('locale.switch');

Route::middleware(['web', 'auth', SetLocale::class])->group(function () {
    Route::get('/print/{type}/{id}', [PrintController::class, 'show'])->name('print');
    // Report keys are "<group>/<report>" (e.g. accounts/account-statement).
    Route::get('/report-viewer/{report}/export/{format}', [ReportViewerController::class, 'export'])
        ->where(['report' => '[a-z0-9-]+/[a-z0-9-]+', 'format' => 'xlsx|csv'])
        ->name('report.export');
    Route::get('/report-viewer/{report}', [ReportViewerController::class, 'show'])
        ->where('report', '[a-z0-9-]+/[a-z0-9-]+')
        ->name('report.viewer');
});
