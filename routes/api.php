<?php

use App\Http\Controllers\Api\NewsletterSubscriptionController;
use App\Http\Controllers\HelloAssoCallbackController;
use App\Http\Middleware\Api\BootTenantFromNewsletterOrigin;
use Illuminate\Support\Facades\Route;

Route::post('/helloasso/callback/{token}', HelloAssoCallbackController::class)
    ->middleware('throttle:60,1')
    ->name('api.helloasso.callback');

Route::middleware([
    BootTenantFromNewsletterOrigin::class,
    'throttle:newsletter',
])->group(function () {
    Route::post('/newsletter/subscribe', [NewsletterSubscriptionController::class, 'subscribe'])
        ->name('api.newsletter.subscribe');
});
