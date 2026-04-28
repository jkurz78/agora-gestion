<?php

declare(strict_types=1);

use App\Exceptions\DemoOperationBlockedException;
use App\Livewire\Parametres\HelloassoForm;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function (): void {
    $association = TenantContext::current();

    $this->adminUser = User::factory()->create();
    $this->adminUser->associations()->attach($association->id, [
        'role' => 'admin',
        'joined_at' => now(),
    ]);
    $this->adminUser->update(['derniere_association_id' => $association->id]);
});

afterEach(function (): void {
    app()->detectEnvironment(fn (): string => 'testing');
});

// ─── Test 1 : env=demo → bandeau lecture seule visible, bouton Enregistrer absent ───

it('shows read-only banner and hides save button in demo env', function (): void {
    app()->detectEnvironment(fn (): string => 'demo');

    $this->actingAs($this->adminUser);

    Livewire::test(HelloassoForm::class)
        ->assertSeeHtml('Lecture seule')
        ->assertDontSee('Enregistrer');
});

// ─── Test 2 : env=demo → appel sauvegarder() → DemoOperationBlockedException ───

it('throws DemoOperationBlockedException when sauvegarder is called in demo env', function (): void {
    app()->detectEnvironment(fn (): string => 'demo');

    $this->actingAs($this->adminUser);

    expect(function (): void {
        Livewire::test(HelloassoForm::class)
            ->call('sauvegarder');
    })->toThrow(DemoOperationBlockedException::class);
});

// ─── Test 3 : env=local → bouton Enregistrer présent ───

it('shows save button in local env', function (): void {
    app()->detectEnvironment(fn (): string => 'local');

    $this->actingAs($this->adminUser);

    Livewire::test(HelloassoForm::class)
        ->assertSee('Enregistrer');
});
