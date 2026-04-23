<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\User;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Laravel's test client bypasses the real VerifyCsrfToken middleware in the
// testing environment, so we simulate a stale token by registering test routes
// that throw TokenMismatchException directly. The exception is caught by the
// app's registered exception handler — which is where the custom branch lives.

beforeEach(function () {
    Route::post('/logout', function () {
        throw new TokenMismatchException('CSRF token mismatch.');
    })->name('logout.csrf-test')->middleware('web');

    Route::post('/__test/csrf-non-logout', function () {
        throw new TokenMismatchException('CSRF token mismatch.');
    })->middleware('web');
});

it('stale CSRF on /logout still logs the user out and redirects to /login', function () {
    $asso = Association::factory()->create();
    $user = User::factory()->create(['derniere_association_id' => $asso->id]);
    $asso->users()->attach($user->id, ['role' => 'admin']);

    $this->actingAs($user);
    expect(Auth::guard('web')->check())->toBeTrue();

    $response = $this->post('/logout');

    $response->assertRedirect('/login');
    expect(Auth::guard('web')->check())->toBeFalse();
});

it('stale CSRF on a non-logout route keeps the user logged in and redirects to /', function () {
    $asso = Association::factory()->create();
    $user = User::factory()->create(['derniere_association_id' => $asso->id]);
    $asso->users()->attach($user->id, ['role' => 'admin']);

    $this->actingAs($user);

    $response = $this->post('/__test/csrf-non-logout');

    $response->assertRedirect('/');
    expect(Auth::guard('web')->check())->toBeTrue();
});
