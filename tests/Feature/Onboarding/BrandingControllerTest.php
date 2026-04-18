<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->association = Association::factory()->unonboarded()->create([
        'logo_path' => 'logo.png',
        'cachet_signature_path' => 'cachet.png',
    ]);
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);

    Storage::disk('local')->put(
        $this->association->storagePath('branding/logo.png'),
        UploadedFile::fake()->image('logo.png')->get()
    );
    Storage::disk('local')->put(
        $this->association->storagePath('branding/cachet.png'),
        UploadedFile::fake()->image('cachet.png')->get()
    );
});

it('sert le logo du tenant courant', function () {
    $this->actingAs($this->user)
        ->get('/onboarding/branding/logo')
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');
});

it('sert le cachet du tenant courant', function () {
    $this->actingAs($this->user)
        ->get('/onboarding/branding/cachet')
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');
});

it('retourne 404 si l\'association n\'a pas de logo', function () {
    $this->association->update(['logo_path' => null]);

    $this->actingAs($this->user)
        ->get('/onboarding/branding/logo')
        ->assertNotFound();
});

it('rejette un kind inconnu', function () {
    $this->actingAs($this->user)
        ->get('/onboarding/branding/autre')
        ->assertNotFound();
});

it('exige l\'authentification', function () {
    auth()->logout();
    $this->get('/onboarding/branding/logo')
        ->assertRedirect(route('login'));
});

it('refuse l\'accès aux images d\'une autre association', function () {
    $attacker = Association::factory()->unonboarded()->create([
        'logo_path' => 'logo.png',
    ]);
    $attackerUser = User::factory()->create();
    $attackerUser->associations()->attach($attacker->id, ['role' => 'admin', 'joined_at' => now()]);

    // L'attaquant boote son propre tenant (qui n'a pas le fichier sur disque)
    // alors que $this->association (tenant beforeEach) a bien son logo en storage fake.
    TenantContext::boot($attacker);
    session(['current_association_id' => $attacker->id]);

    // Même si $this->association a un logo sur disque, l'attaquant voit 404
    // car sa propre storagePath pointe ailleurs (aucun fichier).
    $this->actingAs($attackerUser)
        ->get('/onboarding/branding/logo')
        ->assertNotFound();
});
