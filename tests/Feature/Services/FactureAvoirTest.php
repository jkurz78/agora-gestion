<?php

declare(strict_types=1);

use App\Enums\StatutFacture;
use App\Enums\StatutRapprochement;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\RapprochementBancaire;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ExerciceService;
use App\Services\FactureService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    $this->actingAs($this->user);
    $this->service = app(FactureService::class);
});

afterEach(function () {
    TenantContext::clear();
});

it('annule une facture validée et attribue un numéro avoir', function () {
    $exercice = app(ExerciceService::class)->current();
    $tiers = Tiers::factory()->create(['pour_recettes' => true]);
    $compte = CompteBancaire::factory()->create();

    $facture = Facture::create([
        'numero' => sprintf('F-%d-0001', $exercice),
        'date' => now(),
        'statut' => StatutFacture::Validee,
        'tiers_id' => $tiers->id,
        'compte_bancaire_id' => $compte->id,
        'montant_total' => 100.00,
        'saisi_par' => $this->user->id,
        'exercice' => $exercice,
    ]);

    FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => 'montant',
        'libelle' => 'Prestation',
        'montant' => 100.00,
        'ordre' => 1,
    ]);

    $this->service->annuler($facture);

    $facture->refresh();
    expect($facture->statut)->toBe(StatutFacture::Annulee);
    expect($facture->numero_avoir)->toStartWith('AV-');
    expect($facture->date_annulation)->not->toBeNull();
    expect($facture->numero)->toBe(sprintf('F-%d-0001', $exercice));
});

it('refuse annulation sur un brouillon', function () {
    $facture = Facture::create([
        'date' => now(),
        'statut' => StatutFacture::Brouillon,
        'tiers_id' => Tiers::factory()->create()->id,
        'montant_total' => 0,
        'saisi_par' => $this->user->id,
        'exercice' => app(ExerciceService::class)->current(),
    ]);

    expect(fn () => $this->service->annuler($facture))
        ->toThrow(RuntimeException::class, 'Seule une facture validée');
});

// Comportement modifié S2 : guard isLockedByRapprochement supprimé.
// La primitive S1 gère le cas via extourne EnAttente (si la TX est MontantManuel générée).
// Pour une TX "Montant ref" (préexistante), S2 ne crée pas d'extourne — elle est simplement
// détachée du pivot. Ce test vérifie l'absence de l'exception legacy + le détachement pivot.
it('n\'empeche plus annulation si une transaction est rapprochée — comportement S2', function () {
    $exercice = app(ExerciceService::class)->current();
    $tiers = Tiers::factory()->create(['pour_recettes' => true]);
    $compte = CompteBancaire::factory()->create();

    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id' => $compte->id,
        'statut' => StatutRapprochement::Verrouille,
        'verrouille_at' => now(),
    ]);

    $facture = Facture::create([
        'numero' => sprintf('F-%d-0099', $exercice),
        'date' => now(),
        'statut' => StatutFacture::Validee,
        'tiers_id' => $tiers->id,
        'compte_bancaire_id' => $compte->id,
        'montant_total' => 50.00,
        'saisi_par' => $this->user->id,
        'exercice' => $exercice,
    ]);

    // TX de type recette préexistante (Montant ref, pas MontantManuel générée) —
    // S2 ne crée PAS d'extourne pour ce cas, détache simplement le pivot.
    $tx = Transaction::create([
        'tiers_id' => $tiers->id,
        'compte_id' => $compte->id,
        'type' => 'recette',
        'date' => now(),
        'libelle' => 'Paiement test',
        'montant_total' => 50.00,
        'mode_paiement' => 'virement',
        'saisi_par' => $this->user->id,
        'rapprochement_id' => $rapprochement->id,
        'statut_reglement' => 'pointe',
    ]);

    $facture->transactions()->attach($tx->id);

    // Guard legacy supprimé : l'annulation ne lève plus d'exception
    $this->service->annuler($facture);

    $facture->refresh();
    expect($facture->statut)->toBe(StatutFacture::Annulee);

    // La TX référencée est détachée du pivot (comportement S2 pour les Montant ref)
    expect($facture->transactions->contains(fn ($t) => (int) $t->id === (int) $tx->id))->toBeFalse();

    // La TX Montant ref reste intacte (pas d'extourne, statut inchangé)
    expect($tx->fresh()->statut_reglement->value)->toBe('pointe');
    expect($tx->fresh()->extournee_at)->toBeNull();
});

it('libère les transactions après annulation', function () {
    $exercice = app(ExerciceService::class)->current();
    $tiers = Tiers::factory()->create(['pour_recettes' => true]);
    $compte = CompteBancaire::factory()->create();

    $facture = Facture::create([
        'numero' => sprintf('F-%d-0050', $exercice),
        'date' => now(),
        'statut' => StatutFacture::Validee,
        'tiers_id' => $tiers->id,
        'compte_bancaire_id' => $compte->id,
        'montant_total' => 75.00,
        'saisi_par' => $this->user->id,
        'exercice' => $exercice,
    ]);

    $tx = Transaction::create([
        'tiers_id' => $tiers->id,
        'compte_id' => $compte->id,
        'type' => 'recette',
        'date' => now(),
        'libelle' => 'Paiement',
        'montant_total' => 75.00,
        'mode_paiement' => 'cb',
        'saisi_par' => $this->user->id,
    ]);
    $facture->transactions()->attach($tx->id);

    expect($tx->fresh()->isLockedByFacture())->toBeTrue();

    $this->service->annuler($facture);

    expect($tx->fresh()->isLockedByFacture())->toBeFalse();
});
