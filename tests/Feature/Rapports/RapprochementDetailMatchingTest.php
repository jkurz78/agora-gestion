<?php

declare(strict_types=1);

use App\Enums\StatutRapprochement;
use App\Livewire\RapprochementDetail;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RapprochementBancaireService;
use App\Tenant\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

// Pointage automatique par IA (lecture du relevé bancaire) — voir
// App\Livewire\RapprochementDetail::lancerMatchingAutomatique() et
// validerPointageAutomatique(). ReleveOcrService est une final class : elle
// n'est pas mockable via Mockery (cf. tests/Feature/Livewire/BackOffice/
// FacturePartenaire/IndexTest.php, test 17 bis skippé pour la même raison
// sur FacturePartenaireService). On fake donc l'appel HTTP sortant vers
// l'API Anthropic, comme le fait déjà RapprochementExtraitCompteTest.

beforeEach(function (): void {
    Storage::fake('local');

    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    $this->compte = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
    ]);
});

afterEach(fn () => TenantContext::clear());

it('masque le bouton de pointage assisté sans piece jointe', function (): void {
    $rapprochement = RapprochementBancaire::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compte->id,
        'statut' => StatutRapprochement::EnCours,
        'date_fin' => '2026-01-31',
        'saisi_par' => $this->user->id,
    ]);

    expect($rapprochement->hasPieceJointe())->toBeFalse();

    Livewire::test(RapprochementDetail::class, ['rapprochement' => $rapprochement])
        ->assertDontSee('Pointage assisté');
});

it('masque le bouton de pointage assisté quand le rapprochement est verrouille', function (): void {
    $this->association->update(['anthropic_api_key' => 'sk-test-key']);

    $rapprochement = RapprochementBancaire::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compte->id,
        'statut' => StatutRapprochement::Verrouille,
        'verrouille_at' => now(),
        'date_fin' => '2026-01-31',
        'saisi_par' => $this->user->id,
        'piece_jointe_path' => 'releve.pdf',
        'piece_jointe_nom' => 'releve.pdf',
        'piece_jointe_mime' => 'application/pdf',
    ]);

    expect($rapprochement->hasPieceJointe())->toBeTrue();

    Livewire::test(RapprochementDetail::class, ['rapprochement' => $rapprochement])
        ->assertDontSee('Pointage assisté');
});

it('le pointage assisté pré-associe puis pointe les transactions correspondantes', function (): void {
    $this->association->update(['anthropic_api_key' => 'sk-test-key']);

    $rapprochement = RapprochementBancaire::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compte->id,
        'statut' => StatutRapprochement::EnCours,
        'date_fin' => '2026-01-31',
        'solde_ouverture' => 1000.00,
        'solde_fin' => 965.00,
        'saisi_par' => $this->user->id,
    ]);

    $file = UploadedFile::fake()->create('releve.pdf', 50, 'application/pdf');
    app(RapprochementBancaireService::class)->storePieceJointe($rapprochement, $file);
    $rapprochement->refresh();

    $depense = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compte->id,
        'rapprochement_id' => null,
        'date' => '2026-01-10',
        'montant_total' => 85.00,
        'libelle' => 'Achat fournitures',
    ]);
    $recette = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compte->id,
        'rapprochement_id' => null,
        'date' => '2026-01-15',
        'montant_total' => 50.00,
        'libelle' => 'Cotisation adhérent',
    ]);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'solde_ouverture' => 1000.00,
                    'solde_cloture' => 965.00,
                    'date_cloture' => '2026-01-31',
                    'banque' => 'Banque Test',
                    'numero_compte' => null,
                    'mouvements' => [
                        ['date' => '2026-01-10', 'libelle' => 'Achat fournitures', 'montant' => -85.00],
                        ['date' => '2026-01-15', 'libelle' => 'Cotisation adherent', 'montant' => 50.00],
                    ],
                    'warnings' => [],
                ]),
            ]],
        ]),
    ]);

    $component = Livewire::test(RapprochementDetail::class, ['rapprochement' => $rapprochement])
        ->call('lancerMatchingAutomatique');

    $component->assertSet('matchingErreur', null);
    expect($component->get('mouvementsReleve'))->toHaveCount(2);
    expect($component->get('associationsPointage'))->toHaveCount(2);

    $associations = $component->get('associationsPointage');
    $txIds = collect($associations)->pluck('transaction_id')->sort()->values()->all();
    expect($txIds)->toBe(collect([$depense->id, $recette->id])->sort()->values()->all());

    $component->call('validerAssociations')
        ->assertSee('2 écriture(s) pointée(s).');

    expect($depense->fresh()->rapprochement_id)->toBe($rapprochement->id)
        ->and($recette->fresh()->rapprochement_id)->toBe($rapprochement->id);

    $component->assertSet('mouvementsReleve', null);
});

it('une erreur de l\'API IA ne bloque pas — matchingErreur est renseigne sans proposition', function (): void {
    $this->association->update(['anthropic_api_key' => 'sk-test-key']);

    $rapprochement = RapprochementBancaire::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compte->id,
        'statut' => StatutRapprochement::EnCours,
        'date_fin' => '2026-01-31',
        'saisi_par' => $this->user->id,
    ]);

    $file = UploadedFile::fake()->create('releve.pdf', 50, 'application/pdf');
    app(RapprochementBancaireService::class)->storePieceJointe($rapprochement, $file);
    $rapprochement->refresh();

    Http::fake([
        'api.anthropic.com/*' => Http::response('Internal Server Error', 500),
    ]);

    $component = Livewire::test(RapprochementDetail::class, ['rapprochement' => $rapprochement])
        ->call('lancerMatchingAutomatique');

    expect($component->get('matchingErreur'))->not->toBeNull()
        ->and($component->get('matchingErreur'))->toBeString()
        ->and($component->get('matchingErreur'))->not->toBe('');

    $component->assertSet('mouvementsReleve', null);
});
