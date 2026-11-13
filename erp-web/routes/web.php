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
    Route::get('/report-viewer/{report}', [ReportViewerController::class, 'show'])->name('report.viewer');
    Route::get('/report-viewer/{report}/export/{format}', [ReportViewerController::class, 'export'])->name('report.export');
});
