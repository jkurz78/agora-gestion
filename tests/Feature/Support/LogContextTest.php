<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\User;
use App\Support\LogContext;
use Illuminate\Support\Facades\Log;

it('injects association_id and user_id in log context', function () {
    $spy = Log::spy();

    LogContext::boot(42, 7);
    Log::info('ping');

    $spy->shouldHaveReceived('withContext')->once()->with([
        'association_id' => 42,
        'user_id' => 7,
    ]);
});

it('injects association_id only when user is null', function () {
    $spy = Log::spy();

    LogContext::boot(42, null);

    $spy->shouldHaveReceived('withContext')->once()->with([
        'association_id' => 42,
        'user_id' => null,
    ]);
});

it('populates log context via BootTenantConfig middleware', function () {
    $asso = Association::factory()->create();
    $user = User::factory()->create();
    $user->associations()->attach($asso->id, ['role' => 'admin', 'joined_at' => now()]);
    $user->update(['derniere_association_id' => $asso->id]);

    $spy = Log::spy();

    $this->actingAs($user)
        ->withSession(['current_association_id' => $asso->id])
        ->get('/dashboard')
        ->assertOk();

    $spy->shouldHaveReceived('withContext')->with([
        'association_id' => $asso->id,
        'user_id' => $user->id,
    ]);
});
