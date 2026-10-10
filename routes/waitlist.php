<?php

use Illuminate\Support\Facades\Route;
use Taldres\Waitlist\Enums\Page;
use Taldres\Waitlist\Http\Controllers\ConfirmController;
use Taldres\Waitlist\Http\Controllers\ManageController;
use Taldres\Waitlist\Http\Controllers\ManageLinkController;
use Taldres\Waitlist\Http\Controllers\PurposesController;
use Taldres\Waitlist\Http\Controllers\SubscribeController;
use Taldres\Waitlist\Http\Controllers\UnsubscribeController;
use Taldres\Waitlist\Http\RouteRegistration;

Route::group(RouteRegistration::attributes(), function () {
    Route::middleware(RouteRegistration::signupMiddleware())->group(function () {
        Route::post('/', SubscribeController::class)->name('subscribe');
        Route::get('/purposes', PurposesController::class)->name('purposes');
        Route::post('/manage-link', ManageLinkController::class)->name('manage-link');
    });

    Route::middleware(RouteRegistration::linksMiddleware())->group(function () {
        // GET only reports state; POST acts, from your page or an RFC 8058 one-click request.
        Route::match(['GET', 'POST'], '/confirm/{token}', ConfirmController::class)->name(Page::Confirm->value);
        Route::match(['GET', 'POST'], '/unsubscribe/{token}', UnsubscribeController::class)->name(Page::Unsubscribe->value);

        // The short-lived manage token, mailed on request; mails carry the unsubscribe token.
        Route::get('/manage/{token}', [ManageController::class, 'show'])->name(Page::Manage->value);
        Route::post('/manage/{token}/data', [ManageController::class, 'data'])->name('manage.data');
        Route::put('/manage/{token}/purposes', [ManageController::class, 'purposes'])->name('manage.purposes');
        Route::post('/manage/{token}/unsubscribe', [ManageController::class, 'unsubscribe'])->name('manage.unsubscribe');
        Route::post('/manage/{token}/erase', [ManageController::class, 'erase'])->name('manage.erase');
    });
});
