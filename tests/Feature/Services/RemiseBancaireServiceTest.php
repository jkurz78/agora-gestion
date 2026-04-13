<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Models\CompteBancaire;
use App\Models\RemiseBancaire;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RemiseBancaireService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->compteCible = CompteBancaire::factory()->create();
    $this->service = app(RemiseBancaireService::class);
});

describe('creer()', function () {
    it('creates a remise with auto-incremented numero', function () {
        $remise = $this->service->creer([
            'date' => '2025-10-15',
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);

        expect($remise)->toBeInstanceOf(RemiseBancaire::class)
            ->and($remise->numero)->toBe(1)
            ->and($remise->libelle)->toBe('Remise chèques n°1')
            ->and($remise->saisi_par)->toBe($this->user->id);
    });

    it('increments numero globally', function () {
        $this->service->creer([
            'date' => '2025-10-15',
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);

        $remise2 = $this->service->creer([
            'date' => '2025-11-01',
            'mode_paiement' => ModePaiement::Especes->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);

        expect($remise2->numero)->toBe(2)
            ->and($remise2->libelle)->toBe('Remise espèces n°2');
    });
});

describe('enregistrerBrouillon()', function () {
    it('rattache les transactions sélectionnées à la remise', function () {
        $remise = $this->service->creer([
            'date' => now()->toDateString(),
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);

        $tx = Transaction::factory()->asRecette()->create([
            'compte_id' => $this->compteCible->id,
            'mode_paiement' => ModePaiement::Cheque,
            'montant_total' => 120.00,
            'remise_id' => null,
        ]);

        $this->service->enregistrerBrouillon($remise, [$tx->id]);

        $tx->refresh();
        expect($tx->remise_id)->toBe($remise->id);
    });

    it('détache les transactions désélectionnées', function () {
        $remise = $this->service->creer([
            'date' => now()->toDateString(),
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);

        $tx = Transaction::factory()->asRecette()->create([
            'compte_id' => $this->compteCible->id,
            'mode_paiement' => ModePaiement::Cheque,
            'montant_total' => 80.00,
            'remise_id' => $remise->id,
        ]);

        $this->service->enregistrerBrouillon($remise, []);

        $tx->refresh();
        expect($tx->remise_id)->toBeNull();
    });
});

describe('comptabiliser()', function () {
    it('sets statut_reglement=recu and reference on transactions', function () {
        $remise = $this->service->creer([
            'date' => '2025-10-15',
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);

        $tx = Transaction::factory()->asRecette()->create([
            'compte_id' => $this->compteCible->id,
            'mode_paiement' => ModePaiement::Cheque,
            'montant_total' => 30.00,
            'statut_reglement' => StatutReglement::EnAttente,
            'remise_id' => null,
        ]);

        $this->service->comptabiliser($remise, [$tx->id]);

        $tx->refresh();
        expect($tx->statut_reglement)->toBe(StatutReglement::Recu)
            ->and($tx->reference)->toBe('RBC-00001-001')
            ->and($tx->remise_id)->toBe($remise->id);
    });

    it('assigns sequential references for multiple transactions', function () {
        $remise = $this->service->creer([
            'date' => '2025-10-15',
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);

        $tx1 = Transaction::factory()->asRecette()->create([
            'compte_id' => $this->compteCible->id,
            'mode_paiement' => ModePaiement::Cheque,
            'montant_total' => 30.00,
            'statut_reglement' => StatutReglement::EnAttente,
            'remise_id' => null,
        ]);
        $tx2 = Transaction::factory()->asRecette()->create([
            'compte_id' => $this->compteCible->id,
            'mode_paiement' => ModePaiement::Cheque,
            'montant_total' => 20.00,
            'statut_reglement' => StatutReglement::EnAttente,
            'remise_id' => null,
        ]);

        $this->service->comptabiliser($remise, [$tx1->id, $tx2->id]);

        $tx1->refresh();
        $tx2->refresh();
        expect($tx1->reference)->toBe('RBC-00001-001')
            ->and($tx2->reference)->toBe('RBC-00001-002');
    });

    it('uses RBE prefix for espèces', function () {
        $remise = $this->service->creer([
            'date' => '2025-10-15',
            'mode_paiement' => ModePaiement::Especes->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);

        $tx = Transaction::factory()->asRecette()->create([
            'compte_id' => $this->compteCible->id,
            'mode_paiement' => ModePaiement::Especes,
            'montant_total' => 45.00,
            'statut_reglement' => StatutReglement::EnAttente,
            'remise_id' => null,
        ]);

        $this->service->comptabiliser($remise, [$tx->id]);

        $tx->refresh();
        expect($tx->reference)->toStartWith('RBE-');
    });

    it('refuses to comptabiliser a verrouillée remise', function () {
        $remise = $this->service->creer([
            'date' => '2025-10-15',
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);

        // Lock the remise by adding a pointed transaction
        Transaction::factory()->asRecette()->create([
            'compte_id' => $this->compteCible->id,
            'mode_paiement' => ModePaiement::Cheque,
            'montant_total' => 30.00,
            'statut_reglement' => StatutReglement::Pointe,
            'remise_id' => $remise->id,
        ]);

        $tx = Transaction::factory()->asRecette()->create([
            'compte_id' => $this->compteCible->id,
            'mode_paiement' => ModePaiement::Cheque,
            'montant_total' => 20.00,
            'statut_reglement' => StatutReglement::EnAttente,
            'remise_id' => null,
        ]);

        $this->service->comptabiliser($remise->fresh(), [$tx->id]);
    })->throws(RuntimeException::class);

    it('refuses transaction with wrong mode_paiement', function () {
        $remise = $this->service->creer([
            'date' => '2025-10-15',
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);

        $tx = Transaction::factory()->asRecette()->create([
            'compte_id' => $this->compteCible->id,
            'mode_paiement' => ModePaiement::Especes,
            'montant_total' => 30.00,
            'statut_reglement' => StatutReglement::EnAttente,
            'remise_id' => null,
        ]);

        $this->service->comptabiliser($remise, [$tx->id]);
    })->throws(RuntimeException::class);
});

describe('modifier()', function () {
    it('adds a new transaction to a comptabilised remise', function () {
        $remise = $this->service->creer([
            'date' => '2025-10-15',
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);

        $tx1 = Transaction::factory()->asRecette()->create([
            'compte_id' => $this->compteCible->id,
            'mode_paiement' => ModePaiement::Cheque,
            'montant_total' => 30.00,
            'statut_reglement' => StatutReglement::EnAttente,
            'remise_id' => null,
        ]);
        $this->service->comptabiliser($remise, [$tx1->id]);

        $tx2 = Transaction::factory()->asRecette()->create([
            'compte_id' => $this->compteCible->id,
            'mode_paiement' => ModePaiement::Cheque,
            'montant_total' => 25.00,
            'statut_reglement' => StatutReglement::EnAttente,
            'remise_id' => null,
            'reference' => null, // new transaction, no reference yet
        ]);

        $this->service->modifier($remise->fresh(), [$tx1->id, $tx2->id]);

        $tx2->refresh();
        expect($tx2->remise_id)->toBe($remise->id)
            ->and($tx2->statut_reglement)->toBe(StatutReglement::Recu);
    });

    it('removes a transaction from a remise and resets it', function () {
        $remise = $this->service->creer([
            'date' => '2025-10-15',
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);

        $tx1 = Transaction::factory()->asRecette()->create([
            'compte_id' => $this->compteCible->id,
            'mode_paiement' => ModePaiement::Cheque,
            'montant_total' => 30.00,
            'statut_reglement' => StatutReglement::EnAttente,
            'remise_id' => null,
        ]);
        $tx2 = Transaction::factory()->asRecette()->create([
            'compte_id' => $this->compteCible->id,
            'mode_paiement' => ModePaiement::Cheque,
            'montant_total' => 25.00,
            'statut_reglement' => StatutReglement::EnAttente,
            'remise_id' => null,
        ]);

        $this->service->comptabiliser($remise, [$tx1->id, $tx2->id]);

        $this->service->modifier($remise->fresh(), [$tx2->id]);

        $tx1->refresh();
        expect($tx1->remise_id)->toBeNull()
            ->and($tx1->statut_reglement)->toBe(StatutReglement::EnAttente)
            ->and($tx1->reference)->toBeNull();
    });

    it('supprime la remise quand la liste est vide', function () {
        $remise = $this->service->creer([
            'date' => '2025-10-15',
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);

        $tx = Transaction::factory()->asRecette()->create([
            'compte_id' => $this->compteCible->id,
            'mode_paiement' => ModePaiement::Cheque,
            'montant_total' => 30.00,
            'statut_reglement' => StatutReglement::EnAttente,
            'remise_id' => null,
        ]);
        $this->service->comptabiliser($remise, [$tx->id]);

        $this->service->modifier($remise->fresh(), []);

        expect(RemiseBancaire::find($remise->id))->toBeNull();

        $tx->refresh();
        expect($tx->remise_id)->toBeNull()
            ->and($tx->statut_reglement)->toBe(StatutReglement::EnAttente);
    });
});

describe('supprimer()', function () {
    it('soft-deletes remise and resets linked transactions', function () {
        $remise = $this->service->creer([
            'date' => '2025-10-15',
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);

        $tx = Transaction::factory()->asRecette()->create([
            'compte_id' => $this->compteCible->id,
            'mode_paiement' => ModePaiement::Cheque,
            'montant_total' => 30.00,
            'statut_reglement' => StatutReglement::EnAttente,
            'remise_id' => null,
        ]);
        $this->service->comptabiliser($remise, [$tx->id]);

        $this->service->supprimer($remise->fresh());

        expect(RemiseBancaire::find($remise->id))->toBeNull();
        expect(RemiseBancaire::withTrashed()->find($remise->id))->not->toBeNull();

        $tx->refresh();
        expect($tx->remise_id)->toBeNull()
            ->and($tx->statut_reglement)->toBe(StatutReglement::EnAttente)
            ->and($tx->reference)->toBeNull();
    });

    it('can delete a brouillon remise (no transactions)', function () {
        $remise = $this->service->creer([
            'date' => '2025-10-15',
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);

        $this->service->supprimer($remise);

        expect(RemiseBancaire::find($remise->id))->toBeNull();
    });

    it('refuses to supprimer a verrouillée remise', function () {
        $remise = $this->service->creer([
            'date' => '2025-10-15',
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);

        Transaction::factory()->asRecette()->create([
            'compte_id' => $this->compteCible->id,
            'mode_paiement' => ModePaiement::Cheque,
            'montant_total' => 30.00,
            'statut_reglement' => StatutReglement::Pointe,
            'remise_id' => $remise->id,
        ]);

        $this->service->supprimer($remise->fresh());
    })->throws(RuntimeException::class);
});
