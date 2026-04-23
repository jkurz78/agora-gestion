<?php

declare(strict_types=1);

use App\Http\Middleware\MonoAssociationResolver;
use App\Models\Association;
use App\Support\MonoAssociation;
use App\Tenant\TenantContext;
use Illuminate\Http\Request;
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
    Route::get('/test/mono-resolver', function (Request $request) {
        $id = TenantContext::currentId();
        $routeParam = $request->route('association');
        $paramSignal = $routeParam instanceof Association
            ? 'param:'.(int) $routeParam->id
            : 'param:null';

        return ($id !== null ? (string) $id : 'null').'|'.$paramSignal;
    })->middleware(['web', MonoAssociationResolver::class]);
});

afterEach(function () {
    TenantContext::clear();
    MonoAssociation::flush();
});

it('boots TenantContext and injects the route parameter when mono mode is active', function () {
    // GIVEN exactly 1 association (mono mode), no prior context.
    $asso = Association::factory()->create();

    $response = $this->get('/test/mono-resolver');

    $response->assertOk();
    $response->assertSeeText((string) $asso->id.'|param:'.(int) $asso->id);
});

it('does not boot TenantContext when two associations exist (multi mode)', function () {
    // GIVEN 2 associations (multi mode).
    Association::factory()->count(2)->create();

    $response = $this->get('/test/mono-resolver');

    // THEN middleware is a no-op (both context id and route param unset).
    $response->assertOk();
    $response->assertSeeText('null|param:null');
});

it('still injects the route parameter even when TenantContext was already booted', function () {
    // Regression: when a user is already web-authenticated, ResolveTenant boots
    // TenantContext before MonoAssociationResolver runs. The resolver used to
    // early-return, leaving the {association} route parameter unset — which made
    // Livewire mount(Association) resolve an empty model and crash the portail
    // layout on route('portail.logo') with a null slug.
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $response = $this->get('/test/mono-resolver');

    // Context still points to $asso AND route parameter is now bound.
    $response->assertOk();
    $response->assertSeeText((string) $asso->id.'|param:'.(int) $asso->id);
});
