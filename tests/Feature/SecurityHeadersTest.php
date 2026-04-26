<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

it('sets X-Frame-Options to SAMEORIGIN to allow same-origin iframe framing', function (): void {
    Route::get('/_test-security-headers', fn () => 'ok');

    $response = $this->get('/_test-security-headers');

    $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});
