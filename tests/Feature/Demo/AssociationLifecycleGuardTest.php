<?php

declare(strict_types=1);

use App\Enums\RoleSysteme;
use App\Exceptions\DemoOperationBlockedException;
use App\Models\Association;
use App\Models\User;
use App\Services\AssociationService;

afterEach(function () {
    app()->detectEnvironment(fn (): string => 'testing');
});

it('blocks suspend in demo env', function (): void {
    app()->detectEnvironment(fn (): string => 'demo');

    $asso = Association::factory()->create(['statut' => 'actif']);
    $service = new AssociationService;

    expect(fn () => $service->suspend($asso))
        ->toThrow(DemoOperationBlockedException::class);
});

it('blocks archive in demo env', function (): void {
    app()->detectEnvironment(fn (): string => 'demo');

    $asso = Association::factory()->create(['statut' => 'suspendu']);
    $service = new AssociationService;

    expect(fn () => $service->archive($asso))
        ->toThrow(DemoOperationBlockedException::class);
});

it('does not block suspend in local env', function (): void {
    app()->detectEnvironment(fn (): string => 'local');

    $superAdmin = User::factory()->create(['role_systeme' => RoleSysteme::SuperAdmin]);
    $this->actingAs($superAdmin);

    $asso = Association::factory()->create(['statut' => 'actif']);
    $service = new AssociationService;

    // Should not throw — just execute the transition
    $service->suspend($asso);

    expect($asso->fresh()->statut)->toBe('suspendu');
});

it('does not block archive in local env', function (): void {
    app()->detectEnvironment(fn (): string => 'local');

    $superAdmin = User::factory()->create(['role_systeme' => RoleSysteme::SuperAdmin]);
    $this->actingAs($superAdmin);

    $asso = Association::factory()->create(['statut' => 'suspendu']);
    $service = new AssociationService;

    // Should not throw — just execute the transition
    $service->archive($asso);

    expect($asso->fresh()->statut)->toBe('archive');
});
