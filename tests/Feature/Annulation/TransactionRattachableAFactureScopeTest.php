<?php

declare(strict_types=1);

use App\DataTransferObjects\ExtournePayload;
use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Extourne;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\TransactionExtourneService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─── Setup ───────────────────────────────────────────────────────────────────

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, [
        'role' => 'comptable',
        'joined_at' => now(),
    ]);
    $this->user->update(['derniere_association_id' => $this->association->id]);
    TenantContext::boot($this->association);
    $this->actingAs($this->user);
    $this->compte = CompteBancaire::factory()->create();
});

afterEach(function (): void {
    TenantContext::clear();
});

// ─── Helpers ─────────────────────────────────────────────────────────────────

function rattachableCreateRecette(CompteBancaire $compte, float $montant = 80.0, StatutReglement $statut = StatutReglement::EnAttente): Transaction
{
    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'libelle' => 'Recette test',
        'montant_total' => $montant,
        'mode_paiement' => ModePaiement::Cheque,
        'statut_reglement' => $statut,
        'compte_id' => $compte->id,
    ]);

    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => null,
        'montant' => $montant,
    ]);

    return $tx;
}

// ─── Tests ───────────────────────────────────────────────────────────────────

test('scope_exclut_tx_extournee_origine', function (): void {
    $tg = rattachableCreateRecette($this->compte);

    // Extourner via le service S1
    $extourne = app(TransactionExtourneService::class)
        ->extourner($tg, ExtournePayload::fromOrigine($tg));

    $tg->refresh();

    // Tg doit avoir extournee_at non nul
    expect($tg->extournee_at)->not->toBeNull();

    // Le scope doit exclure Tg
    $ids = Transaction::rattachableAFacture()->pluck('id')->map(fn ($id) => (int) $id)->all();
    expect($ids)->not->toContain((int) $tg->id);
});

test('scope_exclut_tx_miroir_extourne', function (): void {
    $origine = rattachableCreateRecette($this->compte);

    // Extourner pour créer le miroir
    $extourneRecord = app(TransactionExtourneService::class)
        ->extourner($origine, ExtournePayload::fromOrigine($origine));

    // Récupère le miroir (transaction_extourne_id dans la table extournes)
    $entry = Extourne::where('transaction_origine_id', $origine->id)->first();
    expect($entry)->not->toBeNull();

    $mirrorId = (int) $entry->transaction_extourne_id;

    // Le scope doit exclure le miroir
    $ids = Transaction::rattachableAFacture()->pluck('id')->map(fn ($id) => (int) $id)->all();
    expect($ids)->not->toContain($mirrorId);
});

test('scope_inclut_tx_libre', function (): void {
    $tx = rattachableCreateRecette($this->compte, 100.0, StatutReglement::EnAttente);

    $ids = Transaction::rattachableAFacture()->pluck('id')->map(fn ($id) => (int) $id)->all();
    expect($ids)->toContain((int) $tx->id);
});

test('scope_inclut_tx_recu_normale', function (): void {
    $tx = rattachableCreateRecette($this->compte, 200.0, StatutReglement::Recu);

    $ids = Transaction::rattachableAFacture()->pluck('id')->map(fn ($id) => (int) $id)->all();
    expect($ids)->toContain((int) $tx->id);
});
