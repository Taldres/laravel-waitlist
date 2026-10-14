<?php

use Illuminate\Support\Facades\Route;
use Taldres\Waitlist\Enums\Page;
use Taldres\Waitlist\Http\Controllers\ConfirmController;
use Taldres\Waitlist\Http\Controllers\CountController;
use Taldres\Waitlist\Http\Controllers\ManageController;
use Taldres\Waitlist\Http\Controllers\ManageLinkByTokenController;
use Taldres\Waitlist\Http\Controllers\ManageLinkController;
use Taldres\Waitlist\Http\Controllers\PositionController;
use Taldres\Waitlist\Http\Controllers\PurposesController;
use Taldres\Waitlist\Http\Controllers\SubscribeController;
use Taldres\Waitlist\Http\Controllers\UnsubscribeController;
use Taldres\Waitlist\Http\RouteRegistration;

Route::group(RouteRegistration::attributes(), function () {
    // A group whose settings do not read is left out, see RouteRegistration.
    $signup = RouteRegistration::signupMiddleware();
    $links = RouteRegistration::linksMiddleware();

    if ($signup !== null) {
        Route::middleware($signup)->group(function () {
            Route::post('/', SubscribeController::class)->name('subscribe');
            Route::get('/purposes', PurposesController::class)->name('purposes');
            Route::get('/count', CountController::class)->name('count');
            Route::post('/manage-link', ManageLinkController::class)->name('manage-link');
        });
    }

    if ($links !== null) {
        Route::middleware($links)->group(function () {
            // GET only reports state; POST acts, from your page or an RFC 8058 one-click request.
            Route::get('/confirm/{token}', [ConfirmController::class, 'show'])->name(Page::Confirm->value);
            Route::post('/confirm/{token}', [ConfirmController::class, 'store'])->name('confirm.store');
            Route::get('/unsubscribe/{token}', [UnsubscribeController::class, 'show'])->name(Page::Unsubscribe->value);
            Route::post('/unsubscribe/{token}', [UnsubscribeController::class, 'store'])->name('unsubscribe.store');
            Route::post('/unsubscribe/{token}/manage-link', ManageLinkByTokenController::class)->name('unsubscribe.manage-link');
            Route::get('/unsubscribe/{token}/position', PositionController::class)->name('unsubscribe.position');

            // The short-lived manage token, mailed on request; mails carry the unsubscribe token.
            Route::controller(ManageController::class)->group(function () {
                Route::get('/manage/{token}', 'show')->name(Page::Manage->value);
                Route::post('/manage/{token}/data', 'data')->name('manage.data');
                Route::put('/manage/{token}/purposes', 'purposes')->name('manage.purposes');
                Route::post('/manage/{token}/unsubscribe', 'unsubscribe')->name('manage.unsubscribe');
                Route::post('/manage/{token}/erase', 'erase')->name('manage.erase');
            });
        });
    }
});
