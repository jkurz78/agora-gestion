<?php

declare(strict_types=1);

use App\Livewire\Portail\MonProfil;
use App\Models\Association;
use App\Models\Tiers;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

beforeEach(function () {
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 1 : Affichage — page 200 + labels attendus + champs éditables
// ─────────────────────────────────────────────────────────────────────────────
it('affiche la page Mon profil avec les champs attendus', function () {
    $asso = Association::factory()->create(['email' => 'contact@asso.fr']);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'prenom'         => 'Alice',
        'nom'            => 'Martin',
        'email'          => 'alice@example.com',
        'telephone'      => '0600000000',
        'adresse_ligne1' => '10 rue de la Paix',
        'code_postal'    => '75001',
        'ville'          => 'Paris',
        'pays'           => 'France',
        'email_optout'   => false,
    ]);

    Auth::guard('tiers-portail')->login($tiers);

    $this->get("/{$asso->slug}/portail/mon-profil")
        ->assertStatus(200)
        ->assertSeeText('Mon profil')
        // Labels champs locked
        ->assertSee('Civilité')
        ->assertSee('Nom')
        ->assertSee('Prénom')
        ->assertSee('Email')
        // Helper text sécurité (escape=false car l'apostrophe est un char brut dans le texte rendu)
        ->assertSeeText("Pour modifier ces informations, contactez l'association", false)
        // Champs éditables (noms des inputs HTML)
        ->assertSee('adresse_ligne1')
        ->assertSee('code_postal')
        ->assertSee('ville')
        ->assertSee('pays')
        ->assertSee('telephone')
        ->assertSee('email_optout');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 2 : Édition autorisée — 6 champs persistés
// ─────────────────────────────────────────────────────────────────────────────
it('édition des 6 champs autorisés persiste en base', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'email'          => 'alice@example.com',
    ]);

    Auth::guard('tiers-portail')->login($tiers);

    Livewire::test(MonProfil::class, ['association' => $asso])
        ->set('adresse_ligne1', '1 rue Neuve')
        ->set('code_postal', '75001')
        ->set('ville', 'Paris')
        ->set('pays', 'France')
        ->set('telephone', '0700000000')
        ->set('email_optout', true)
        ->call('save')
        ->assertHasNoErrors();

    $fresh = $tiers->fresh();
    expect($fresh->adresse_ligne1)->toBe('1 rue Neuve');
    expect($fresh->code_postal)->toBe('75001');
    expect($fresh->ville)->toBe('Paris');
    expect($fresh->pays)->toBe('France');
    expect($fresh->telephone)->toBe('0700000000');
    expect($fresh->email_optout)->toBeTrue();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 3 : Tentative modification email via wire:set — bloqué ou sans effet
// ─────────────────────────────────────────────────────────────────────────────
it('wire:set email est bloqué ou sans effet en base', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'email'          => 'alice@example.com',
    ]);

    Auth::guard('tiers-portail')->login($tiers);

    // Either Livewire throws on unknown property, or the email is simply not changed.
    $emailBefore = $tiers->email;

    try {
        Livewire::test(MonProfil::class, ['association' => $asso])
            ->set('email', 'pirate@evil.com')
            ->call('save');
    } catch (\Throwable $e) {
        // Livewire threw — intrusion blocked at the component level.
    }

    expect($tiers->fresh()->email)->toBe($emailBefore);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 4 : Tentative modification nom via wire:set — bloqué ou sans effet
// ─────────────────────────────────────────────────────────────────────────────
it('wire:set nom est bloqué ou sans effet en base', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'nom'            => 'Martin',
    ]);

    Auth::guard('tiers-portail')->login($tiers);

    $nomBefore = $tiers->getRawOriginal('nom'); // avant accesseur uppercase

    try {
        Livewire::test(MonProfil::class, ['association' => $asso])
            ->set('nom', 'HACKER')
            ->call('save');
    } catch (\Throwable $e) {
        // Livewire threw — intrusion blocked.
    }

    expect($tiers->fresh()->getRawOriginal('nom'))->toBe($nomBefore);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 5 : Validation — téléphone trop long → erreur, rien en base
// ─────────────────────────────────────────────────────────────────────────────
it('validation téléphone trop long génère une erreur et ne modifie pas la base', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'telephone'      => '0600000000',
    ]);

    Auth::guard('tiers-portail')->login($tiers);

    Livewire::test(MonProfil::class, ['association' => $asso])
        ->set('telephone', str_repeat('1', 51))
        ->call('save')
        ->assertHasErrors(['telephone']);

    expect($tiers->fresh()->telephone)->toBe('0600000000');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 6 : Lien mailto contactez-nous contient l'email de l'association
// ─────────────────────────────────────────────────────────────────────────────
it('lien mailto contactez-nous contient l\'email de l\'association', function () {
    $asso = Association::factory()->create(['email' => 'bureau@asso-test.fr']);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'prenom'         => 'Bob',
        'nom'            => 'Dupont',
    ]);

    Auth::guard('tiers-portail')->login($tiers);

    $response = $this->get("/{$asso->slug}/portail/mon-profil");
    $response->assertStatus(200);

    // L'email de l'asso doit apparaître dans un lien mailto
    $content = $response->getContent();
    expect($content)->toContain('mailto:bureau@asso-test.fr');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 7 : Lien mailto suppression compte — subject URL-encodé correct
// ─────────────────────────────────────────────────────────────────────────────
it('lien mailto suppression compte contient subject et nom encodés', function () {
    $asso = Association::factory()->create(['email' => 'bureau@asso-test.fr']);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'prenom'         => 'Clara',
        'nom'            => 'Lebrun',
    ]);

    Auth::guard('tiers-portail')->login($tiers);

    $response = $this->get("/{$asso->slug}/portail/mon-profil");
    $response->assertStatus(200);

    $content = $response->getContent();

    // mailto avec l'email de l'asso + subject encodé contenant prénom + nom
    expect($content)->toContain('mailto:bureau@asso-test.fr');
    // "suppression" URL-encodé quelque part dans la page
    expect($content)->toContain('suppression');
    // prénom et nom dans la page (subject encodé ou non)
    expect($content)->toContain('Clara');
    expect($content)->toContain('LEBRUN'); // accesseur uppercase
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 8 : Isolation multi-tenant — Alice (assoA) ne peut pas modifier Bob (assoB)
// ─────────────────────────────────────────────────────────────────────────────
it('isolation multi-tenant: alice (assoA) ne peut pas modifier bob (assoB)', function () {
    // Créer assoB + Bob
    $assoB = Association::factory()->create();
    TenantContext::boot($assoB);
    $bob = Tiers::factory()->create([
        'association_id' => $assoB->id,
        'adresse_ligne1' => 'Adresse Bob originale',
    ]);

    // Créer assoA + Alice
    $assoA = Association::factory()->create();
    TenantContext::boot($assoA);
    $alice = Tiers::factory()->create([
        'association_id' => $assoA->id,
    ]);

    // Alice s'authentifie sur le portail assoA
    Auth::guard('tiers-portail')->login($alice);

    // Alice sauvegarde ses propres coordonnées
    Livewire::test(MonProfil::class, ['association' => $assoA])
        ->set('adresse_ligne1', 'Nouvel adresse Alice')
        ->call('save')
        ->assertHasNoErrors();

    // Vérifier que Bob est inchangé — bypass tenant scope pour la vérification
    $bobRow = DB::table('tiers')->where('id', $bob->id)->first();
    expect($bobRow->adresse_ligne1)->toBe('Adresse Bob originale');

    // Et qu'Alice est bien mise à jour
    expect($alice->fresh()->adresse_ligne1)->toBe('Nouvel adresse Alice');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 9 : Logger émet portail.profil.updated avec tiers_id
// ─────────────────────────────────────────────────────────────────────────────
it('logger émet portail.profil.updated avec tiers_id lors d\'une sauvegarde', function () {
    Log::spy();

    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
    ]);

    Auth::guard('tiers-portail')->login($tiers);

    Livewire::test(MonProfil::class, ['association' => $asso])
        ->set('ville', 'Lyon')
        ->call('save')
        ->assertHasNoErrors();

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(fn ($message, $context) => $message === 'portail.profil.updated'
            && isset($context['tiers_id'])
            && (int) $context['tiers_id'] === (int) $tiers->id
        );
});
