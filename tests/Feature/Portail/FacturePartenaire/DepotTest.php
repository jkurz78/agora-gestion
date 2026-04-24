<?php

declare(strict_types=1);

use App\Livewire\Portail\FacturePartenaire\Depot;
use App\Models\Association;
use App\Models\FacturePartenaireDeposee;
use App\Models\Tiers;
use App\Tenant\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    TenantContext::clear();
    $this->asso = Association::factory()->create();
    TenantContext::boot($this->asso);
    $this->tiers = Tiers::factory()->create(['association_id' => $this->asso->id, 'pour_depenses' => true]);
    Auth::guard('tiers-portail')->login($this->tiers);
    Storage::fake('local');
});

// ---------------------------------------------------------------------------
// Test 1 : Affichage du formulaire (3 champs visibles)
// ---------------------------------------------------------------------------

it('depot: formulaire affiché avec les 3 champs', function () {
    $this->get("/{$this->asso->slug}/portail/factures/depot")
        ->assertStatus(200)
        ->assertSee('date_facture')
        ->assertSee('numero_facture')
        ->assertSee('pdf');
});

// ---------------------------------------------------------------------------
// Test 2a : Validation — sans PDF → erreur
// ---------------------------------------------------------------------------

it('depot: validation sans PDF retourne erreur', function () {
    TenantContext::boot($this->asso);

    $component = new Depot;
    $component->mount($this->asso);
    $component->date_facture = now()->subDay()->format('Y-m-d');
    $component->numero_facture = 'FACT-001';
    $component->pdf = null;

    expect(fn () => $component->submit())->toThrow(\Illuminate\Validation\ValidationException::class);
});

// ---------------------------------------------------------------------------
// Test 2b : Validation — sans date → erreur
// ---------------------------------------------------------------------------

it('depot: validation sans date retourne erreur', function () {
    TenantContext::boot($this->asso);

    $component = new Depot;
    $component->mount($this->asso);
    $component->date_facture = null;
    $component->numero_facture = 'FACT-001';
    $component->pdf = UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf');

    expect(fn () => $component->submit())->toThrow(\Illuminate\Validation\ValidationException::class);
});

// ---------------------------------------------------------------------------
// Test 2c : Validation — date future → erreur
// ---------------------------------------------------------------------------

it('depot: validation avec date future retourne erreur', function () {
    TenantContext::boot($this->asso);

    $component = new Depot;
    $component->mount($this->asso);
    $component->date_facture = now()->addDay()->format('Y-m-d');
    $component->numero_facture = 'FACT-001';
    $component->pdf = UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf');

    expect(fn () => $component->submit())->toThrow(\Illuminate\Validation\ValidationException::class);
});

// ---------------------------------------------------------------------------
// Test 2d : Validation — sans numéro → erreur
// ---------------------------------------------------------------------------

it('depot: validation sans numéro retourne erreur', function () {
    TenantContext::boot($this->asso);

    $component = new Depot;
    $component->mount($this->asso);
    $component->date_facture = now()->subDay()->format('Y-m-d');
    $component->numero_facture = null;
    $component->pdf = UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf');

    expect(fn () => $component->submit())->toThrow(\Illuminate\Validation\ValidationException::class);
});

// ---------------------------------------------------------------------------
// Test 2e : Validation — numéro > 50 caractères → erreur
// ---------------------------------------------------------------------------

it('depot: validation avec numéro > 50 caractères retourne erreur', function () {
    TenantContext::boot($this->asso);

    $component = new Depot;
    $component->mount($this->asso);
    $component->date_facture = now()->subDay()->format('Y-m-d');
    $component->numero_facture = str_repeat('A', 51);
    $component->pdf = UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf');

    expect(fn () => $component->submit())->toThrow(\Illuminate\Validation\ValidationException::class);
});

// ---------------------------------------------------------------------------
// Test 2f : Validation — fichier non-PDF → erreur
// ---------------------------------------------------------------------------

it('depot: validation avec fichier non-PDF retourne erreur', function () {
    TenantContext::boot($this->asso);

    $component = new Depot;
    $component->mount($this->asso);
    $component->date_facture = now()->subDay()->format('Y-m-d');
    $component->numero_facture = 'FACT-001';
    $component->pdf = UploadedFile::fake()->create('image.jpg', 100, 'image/jpeg');

    expect(fn () => $component->submit())->toThrow(\Illuminate\Validation\ValidationException::class);
});

// ---------------------------------------------------------------------------
// Test 2g : Validation — PDF > 10 Mo → erreur
// ---------------------------------------------------------------------------

it('depot: validation avec PDF > 10 Mo retourne erreur', function () {
    TenantContext::boot($this->asso);

    $component = new Depot;
    $component->mount($this->asso);
    $component->date_facture = now()->subDay()->format('Y-m-d');
    $component->numero_facture = 'FACT-001';
    $component->pdf = UploadedFile::fake()->create('gros.pdf', 10241, 'application/pdf');

    expect(fn () => $component->submit())->toThrow(\Illuminate\Validation\ValidationException::class);
});

// ---------------------------------------------------------------------------
// Test 3 : Soumission valide → dépôt créé, redirection avec flash
// ---------------------------------------------------------------------------

it('depot: soumission valide crée un dépôt et redirige avec flash', function () {
    TenantContext::boot($this->asso);
    Auth::guard('tiers-portail')->login($this->tiers);

    $component = new Depot;
    $component->mount($this->asso);
    $component->date_facture = now()->subDay()->format('Y-m-d');
    $component->numero_facture = 'FACT-VALID-001';
    $component->pdf = UploadedFile::fake()->create('facture.pdf', 500, 'application/pdf');

    $component->submit();

    $depot = FacturePartenaireDeposee::first();
    expect($depot)->not->toBeNull()
        ->and($depot->numero_facture)->toBe('FACT-VALID-001')
        ->and((int) $depot->tiers_id)->toBe((int) $this->tiers->id)
        ->and((int) $depot->association_id)->toBe((int) $this->asso->id);

    // Fichier stocké
    Storage::disk('local')->assertExists($depot->pdf_path);
});

// ---------------------------------------------------------------------------
// Test 4 : Soumission appelle FacturePartenaireService::submit (vérifié via BDD)
// ---------------------------------------------------------------------------

it('depot: soumission appelle bien le service (dépôt persisté en BDD)', function () {
    TenantContext::boot($this->asso);
    Auth::guard('tiers-portail')->login($this->tiers);

    $component = new Depot;
    $component->mount($this->asso);
    $component->date_facture = '2026-03-15';
    $component->numero_facture = 'FACT-SERVICE-001';
    $component->pdf = UploadedFile::fake()->create('facture.pdf', 200, 'application/pdf');

    $component->submit();

    expect(FacturePartenaireDeposee::count())->toBe(1);
    $depot = FacturePartenaireDeposee::first();
    expect($depot->date_facture->format('Y-m-d'))->toBe('2026-03-15')
        ->and($depot->numero_facture)->toBe('FACT-SERVICE-001');
});
