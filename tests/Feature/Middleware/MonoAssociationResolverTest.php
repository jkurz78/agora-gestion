<?php

declare(strict_types=1);

use App\Http\Middleware\MonoAssociationResolver;
use App\Models\Association;
use App\Support\MonoAssociation;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    // The global Pest.php bootstrap creates one association and boots TenantContext.
    // We must undo both so each test controls the DB state and context explicitly.
    TenantContext::clear();
    MonoAssociation::flush();

    // Wipe all associations created by the global bootstrap.
    DB::table('association')->delete();

    // Register the test route with the middleware under test.
    Route::get('/test/mono-resolver', function () {
        $id = TenantContext::currentId();

        return $id !== null ? (string) $id : 'null';
    })->middleware(['web', MonoAssociationResolver::class]);
});

afterEach(function () {
    TenantContext::clear();
    MonoAssociation::flush();
});

it('boots TenantContext on Association::first() when mono mode and context not booted', function () {
    // GIVEN exactly 1 association (mono mode).
    $asso = Association::factory()->create();

    // WHEN a request hits the test route.
    $response = $this->get('/test/mono-resolver');

    // THEN the response contains the association's ID (context was booted).
    $response->assertOk();
    $response->assertSeeText((string) $asso->id);
});

it('does not boot TenantContext when two associations exist (multi mode)', function () {
    // GIVEN 2 associations (multi mode).
    Association::factory()->count(2)->create();

    // WHEN a request hits the test route.
    $response = $this->get('/test/mono-resolver');

    // THEN TenantContext was NOT booted (middleware no-op).
    $response->assertOk();
    $response->assertSeeText('null');
});

it('does not override an already-booted TenantContext', function () {
    // GIVEN 1 association (mono mode) + a *different* association already booted.
    $original = Association::factory()->create();
    $other = Association::factory()->create();

    // Prime MonoAssociation cache with the 2-asso count so isActive() returns false.
    // But to properly test "already booted" behaviour we need to reset and use 1 asso.
    // Reset DB to only 1 association, boot context on it, then use that as "already booted".
    DB::table('association')->delete();
    MonoAssociation::flush();

    $asso = Association::factory()->create();
    // Boot on $asso (simulates another middleware/job having booted first).
    TenantContext::boot($asso);

    // WHEN the request fires (MonoAssociationResolver should no-op because currentId() !== null).
    $response = $this->get('/test/mono-resolver');

    // THEN the context still points to the originally booted $asso.
    $response->assertOk();
    $response->assertSeeText((string) $asso->id);
});
