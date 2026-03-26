<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\TypeTransaction;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\RemiseBancaire;
use App\Models\Seance;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VirementInterne;
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
            ->and($remise->virement_id)->toBeNull()
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

describe('comptabiliser()', function () {
    beforeEach(function () {
        $this->sousCategorie = SousCategorie::factory()->create();
        $this->operation = Operation::factory()->create(['sous_categorie_id' => $this->sousCategorie->id]);
        $this->tiers = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean']);
        $this->participant = Participant::create([
            'operation_id' => $this->operation->id,
            'tiers_id' => $this->tiers->id,
            'date_inscription' => now()->toDateString(),
        ]);
        $this->seance = Seance::create([
            'operation_id' => $this->operation->id,
            'numero' => 1,
            'date' => '2025-10-01',
        ]);
        $this->reglement = Reglement::create([
            'participant_id' => $this->participant->id,
            'seance_id' => $this->seance->id,
            'mode_paiement' => ModePaiement::Cheque->value,
            'montant_prevu' => 30.00,
        ]);
    });

    it('creates transactions and virement for selected reglements', function () {
        $remise = $this->service->creer([
            'date' => '2025-10-15',
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);

        $this->service->comptabiliser($remise, [$this->reglement->id]);

        $remise->refresh();

        // Virement created
        expect($remise->virement_id)->not->toBeNull();
        $virement = $remise->virement;
        expect((float) $virement->montant)->toBe(30.0)
            ->and($virement->reference)->toBe('RBC-00001');

        // Transaction created on intermediary account
        $transactions = Transaction::where('remise_id', $remise->id)->get();
        expect($transactions)->toHaveCount(1);
        $tx = $transactions->first();
        expect($tx->type)->toBe(TypeTransaction::Recette)
            ->and((float) $tx->montant_total)->toBe(30.0)
            ->and($tx->tiers_id)->toBe($this->tiers->id)
            ->and($tx->reference)->toBe('RBC-00001-001')
            ->and($tx->numero_piece)->not->toBeNull();

        // Transaction has one ligne with correct operation/seance/sous_categorie
        $ligne = $tx->lignes->first();
        expect($ligne->sous_categorie_id)->toBe($this->sousCategorie->id)
            ->and($ligne->operation_id)->toBe($this->operation->id)
            ->and($ligne->seance)->toBe(1);

        // Reglement is linked
        $this->reglement->refresh();
        expect($this->reglement->remise_id)->toBe($remise->id);
    });

    it('uses intermediary system account for transactions', function () {
        $compteIntermediaire = CompteBancaire::where('est_systeme', true)
            ->where('nom', 'Remises en banque')
            ->first();

        $remise = $this->service->creer([
            'date' => '2025-10-15',
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);
        $this->service->comptabiliser($remise, [$this->reglement->id]);

        $tx = Transaction::where('remise_id', $remise->id)->first();
        expect($tx->compte_id)->toBe($compteIntermediaire->id);

        // Virement goes from intermediary to target
        $virement = $remise->fresh()->virement;
        expect($virement->compte_source_id)->toBe($compteIntermediaire->id)
            ->and($virement->compte_destination_id)->toBe($this->compteCible->id);
    });

    it('refuses to comptabiliser an already-comptabilised remise', function () {
        $remise = $this->service->creer([
            'date' => '2025-10-15',
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);
        $this->service->comptabiliser($remise, [$this->reglement->id]);

        $this->service->comptabiliser($remise->fresh(), [$this->reglement->id]);
    })->throws(RuntimeException::class);

    it('refuses reglements with wrong mode_paiement', function () {
        $this->reglement->update(['mode_paiement' => ModePaiement::Especes->value]);

        $remise = $this->service->creer([
            'date' => '2025-10-15',
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);

        $this->service->comptabiliser($remise, [$this->reglement->id]);
    })->throws(RuntimeException::class);

    it('refuses reglements already linked to another remise', function () {
        // Create another remise to use as a valid FK target
        $autreRemise = $this->service->creer([
            'date' => '2025-10-01',
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);
        $this->reglement->update(['remise_id' => $autreRemise->id]);

        $remise = $this->service->creer([
            'date' => '2025-10-15',
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);

        $this->service->comptabiliser($remise, [$this->reglement->id]);
    })->throws(RuntimeException::class);

    it('throws when operation has no sous_categorie', function () {
        $this->operation->update(['sous_categorie_id' => null]);

        $remise = $this->service->creer([
            'date' => '2025-10-15',
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);

        $this->service->comptabiliser($remise, [$this->reglement->id]);
    })->throws(RuntimeException::class);
});

describe('modifier()', function () {
    beforeEach(function () {
        $this->sousCategorie = SousCategorie::factory()->create();
        $this->operation = Operation::factory()->create(['sous_categorie_id' => $this->sousCategorie->id]);
        $this->tiers1 = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean']);
        $this->tiers2 = Tiers::factory()->create(['nom' => 'Martin', 'prenom' => 'Sophie']);
        $this->participant1 = Participant::create([
            'operation_id' => $this->operation->id,
            'tiers_id' => $this->tiers1->id,
            'date_inscription' => now()->toDateString(),
        ]);
        $this->participant2 = Participant::create([
            'operation_id' => $this->operation->id,
            'tiers_id' => $this->tiers2->id,
            'date_inscription' => now()->toDateString(),
        ]);
        $this->seance = Seance::create([
            'operation_id' => $this->operation->id,
            'numero' => 1,
            'date' => '2025-10-01',
        ]);
        $this->reg1 = Reglement::create([
            'participant_id' => $this->participant1->id,
            'seance_id' => $this->seance->id,
            'mode_paiement' => ModePaiement::Cheque->value,
            'montant_prevu' => 30.00,
        ]);
        $this->reg2 = Reglement::create([
            'participant_id' => $this->participant2->id,
            'seance_id' => $this->seance->id,
            'mode_paiement' => ModePaiement::Cheque->value,
            'montant_prevu' => 25.00,
        ]);

        // Create and comptabiliser with reg1 only
        $this->remise = $this->service->creer([
            'date' => '2025-10-15',
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);
        $this->service->comptabiliser($this->remise, [$this->reg1->id]);
        $this->remise->refresh();
    });

    it('adds a reglement and updates virement montant', function () {
        $this->service->modifier($this->remise, [$this->reg1->id, $this->reg2->id]);

        $this->remise->refresh();
        expect(Transaction::where('remise_id', $this->remise->id)->count())->toBe(2);
        expect((float) $this->remise->virement->montant)->toBe(55.0);

        $this->reg2->refresh();
        expect($this->reg2->remise_id)->toBe($this->remise->id);
    });

    it('removes a reglement and updates virement montant', function () {
        // First add reg2
        $this->service->modifier($this->remise, [$this->reg1->id, $this->reg2->id]);
        $this->remise->refresh();

        // Now remove reg1
        $this->service->modifier($this->remise, [$this->reg2->id]);

        $this->remise->refresh();
        expect(Transaction::where('remise_id', $this->remise->id)->count())->toBe(1);
        expect((float) $this->remise->virement->montant)->toBe(25.0);

        $this->reg1->refresh();
        expect($this->reg1->remise_id)->toBeNull();
    });

    it('deletes remise when empty list', function () {
        $this->service->modifier($this->remise, []);

        expect(RemiseBancaire::find($this->remise->id))->toBeNull();
    });
});

describe('supprimer()', function () {
    beforeEach(function () {
        $this->sousCategorie = SousCategorie::factory()->create();
        $this->operation = Operation::factory()->create(['sous_categorie_id' => $this->sousCategorie->id]);
        $this->tiers = Tiers::factory()->create();
        $this->participant = Participant::create([
            'operation_id' => $this->operation->id,
            'tiers_id' => $this->tiers->id,
            'date_inscription' => now()->toDateString(),
        ]);
        $this->seance = Seance::create([
            'operation_id' => $this->operation->id,
            'numero' => 1,
            'date' => '2025-10-01',
        ]);
        $this->reglement = Reglement::create([
            'participant_id' => $this->participant->id,
            'seance_id' => $this->seance->id,
            'mode_paiement' => ModePaiement::Cheque->value,
            'montant_prevu' => 30.00,
        ]);
    });

    it('soft-deletes remise, transactions, virement and frees reglements', function () {
        $remise = $this->service->creer([
            'date' => '2025-10-15',
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);
        $this->service->comptabiliser($remise, [$this->reglement->id]);
        $remise->refresh();

        $virementId = $remise->virement_id;
        $transactionIds = Transaction::where('remise_id', $remise->id)->pluck('id')->toArray();

        $this->service->supprimer($remise);

        // Remise soft-deleted
        expect(RemiseBancaire::find($remise->id))->toBeNull();
        expect(RemiseBancaire::withTrashed()->find($remise->id))->not->toBeNull();

        // Transactions soft-deleted
        foreach ($transactionIds as $txId) {
            expect(Transaction::find($txId))->toBeNull();
            expect(Transaction::withTrashed()->find($txId))->not->toBeNull();
        }

        // Virement soft-deleted
        expect(VirementInterne::find($virementId))->toBeNull();
        expect(VirementInterne::withTrashed()->find($virementId))->not->toBeNull();

        // Reglement freed
        $this->reglement->refresh();
        expect($this->reglement->remise_id)->toBeNull();
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
});
