<?php

use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

/**
 * 'guest' middleware and 'portal/payzum' prefix applied to all routes (including names)
 *
 * @see \App\Providers\Route::register
 */

Route::portal('payzum', function () {
    Route::get('invoices/{invoice}', 'Payment@show')->name('invoices.show');
    Route::post('invoices/{invoice}/confirm', 'Payment@confirm')->withoutMiddleware(VerifyCsrfToken::class)->name('invoices.confirm');
    Route::get('invoices/{invoice}/return', 'Payment@return')->name('invoices.return');
    Route::get('invoices/{invoice}/cancel', 'Payment@cancel')->name('invoices.cancel');
}, ['middleware' => 'guest']);
