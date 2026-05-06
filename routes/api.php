<?php

use App\Http\Controllers\Api\NewsletterSubscriptionController;
use App\Http\Controllers\HelloAssoCallbackController;
use App\Http\Middleware\Api\VerifyNewsletterHmacSignature;
use App\Http\Middleware\BootTenantConfig;
use Illuminate\Support\Facades\Route;

Route::post('/helloasso/callback/{token}', HelloAssoCallbackController::class)
    ->middleware('throttle:60,1')
    ->name('api.helloasso.callback');

Route::middleware([
    VerifyNewsletterHmacSignature::class,
    // BootTenantConfig charge la config SMTP per-asso depuis smtp_parametres,
    // après que VerifyNewsletterHmacSignature ait booté TenantContext. Sans ça,
    // l'envoi du mail de confirmation utilise le SMTP par défaut (.env), qui
    // peut être lent / mal configuré et bloquer la requête HTTP 30s.
    BootTenantConfig::class,
    'throttle:newsletter',
])->group(function () {
    Route::post('/newsletter/subscribe', [NewsletterSubscriptionController::class, 'subscribe'])
        ->name('api.newsletter.subscribe');
});
