<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\NoteDeFraisLigneType;
use App\Enums\StatutNoteDeFrais;
use App\Enums\StatutReglement;
use App\Enums\TypeCategorie;
use App\Enums\TypeTransaction;
use App\Enums\UsageComptable;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\UsageSousCategorie;
use App\Services\NoteDeFrais\NoteDeFraisValidationService;
use App\Services\NoteDeFrais\ValidationData;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Storage;

// ---------------------------------------------------------------------------
// Setup helpers
// ---------------------------------------------------------------------------

/**
 * Crée une NDF Soumise avec N lignes standard (sans PJ).
 */
function makeNdfSoumise(
    Association $asso,
    Tiers $tiers,
    SousCategorie $sc,
    int $count = 1,
    float $montantParLigne = 100.0,
): NoteDeFrais {
    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'date' => '2025-10-01',
        'libelle' => 'Frais mission',
    ]);

    for ($i = 0; $i < $count; $i++) {
        NoteDeFraisLigne::factory()->create([
            'note_de_frais_id' => $ndf->id,
            'type' => NoteDeFraisLigneType::Standard->value,
            'sous_categorie_id' => $sc->id,
            'libelle' => "Ligne $i",
            'montant' => $montantParLigne,
            'piece_jointe_path' => null,
        ]);
    }

    return $ndf;
}

beforeEach(function (): void {
    Storage::fake('local');

    $this->asso = Association::factory()->create();
    TenantContext::boot($this->asso);

    $this->tiers = Tiers::factory()->create(['association_id' => $this->asso->id]);

    // Sous-catégorie Dépense (pour les lignes NDF)
    $this->catDepense = Categorie::factory()->create([
        'association_id' => $this->asso->id,
        'type' => TypeCategorie::Depense->value,
    ]);
    $this->scDepense = SousCategorie::factory()->create([
        'association_id' => $this->asso->id,
        'categorie_id' => $this->catDepense->id,
        'nom' => 'Frais divers',
    ]);

    // Sous-catégorie Recette pour AbandonCreance
    $this->catRecette = Categorie::factory()->create([
        'association_id' => $this->asso->id,
        'type' => TypeCategorie::Recette->value,
    ]);
    $this->scAbandon = SousCategorie::factory()->pourAbandonCreance()->create([
        'association_id' => $this->asso->id,
        'categorie_id' => $this->catRecette->id,
        'nom' => 'Abandon de creance',
    ]);

    $this->compte = CompteBancaire::factory()->create(['association_id' => $this->asso->id]);

    $this->data = new ValidationData(
        compte_id: (int) $this->compte->id,
        mode_paiement: ModePaiement::Virement,
        date: '2025-10-15',
    );

    $this->service = app(NoteDeFraisValidationService::class);
});

// ---------------------------------------------------------------------------
// 1. Cas nominal complet
// ---------------------------------------------------------------------------

it('cree deux transactions (depense + don) reglees pour un abandon de creance', function (): void {
    $ndf = makeNdfSoumise($this->asso, $this->tiers, $this->scDepense, montantParLigne: 150.0);
    $dateDon = '2025-10-20';

    $txDon = $this->service->validerAvecAbandonCreance($ndf, $this->data, $dateDon);

    // 2 transactions créées
    expect(Transaction::count())->toBe(2);

    // Transaction Don retournée est du bon type
    expect($txDon)->toBeInstanceOf(Transaction::class);
    expect($txDon->type)->toBe(TypeTransaction::Recette);
    expect($txDon->statut_reglement)->toBe(StatutReglement::Recu);

    // Transaction Dépense
    $ndf->refresh();
    $txDepense = Transaction::find($ndf->transaction_id);
    expect($txDepense)->not->toBeNull();
    expect($txDepense->type)->toBe(TypeTransaction::Depense);
    expect($txDepense->statut_reglement)->toBe(StatutReglement::Recu);

    // NDF en DonParAbandonCreances
    expect($ndf->getRawOriginal('statut'))->toBe(StatutNoteDeFrais::DonParAbandonCreances->value);
    expect((int) $ndf->transaction_id)->toBe((int) $txDepense->id);
    expect((int) $ndf->don_transaction_id)->toBe((int) $txDon->id);
    expect($ndf->validee_at)->not->toBeNull();

    // Montants cohérents des deux côtés
    expect((float) $txDepense->montant_total)->toBe(150.0);
    expect((float) $txDon->montant_total)->toBe(150.0);
});

// ---------------------------------------------------------------------------
// 2. Pas de sous-cat AbandonCreance désignée → DomainException + rollback
// ---------------------------------------------------------------------------

it('leve DomainException si aucune sous-categorie AbandonCreance configuree', function (): void {
    // Supprimer le lien pivot créé dans beforeEach
    UsageSousCategorie::where('sous_categorie_id', $this->scAbandon->id)
        ->where('usage', UsageComptable::AbandonCreance->value)
        ->delete();

    $ndf = makeNdfSoumise($this->asso, $this->tiers, $this->scDepense);

    expect(fn () => $this->service->validerAvecAbandonCreance($ndf, $this->data, '2025-10-20'))
        ->toThrow(DomainException::class, 'Aucune sous-categorie');

    // Aucune transaction créée (rollback)
    expect(Transaction::count())->toBe(0);

    // NDF reste Soumise
    $ndf->refresh();
    expect($ndf->getRawOriginal('statut'))->toBe(StatutNoteDeFrais::Soumise->value);
});

// ---------------------------------------------------------------------------
// 3. NDF pas Soumise → DomainException
// ---------------------------------------------------------------------------

it('leve DomainException si la NDF nest pas en statut Soumise', function (): void {
    $ndf = NoteDeFrais::factory()->validee()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
    ]);

    expect(fn () => $this->service->validerAvecAbandonCreance($ndf, $this->data, '2025-10-20'))
        ->toThrow(DomainException::class, 'Seule une NDF soumise peut être validée');
});

// ---------------------------------------------------------------------------
// 4. Plusieurs sous-cat AbandonCreance → DomainException (cas pathologique)
// ---------------------------------------------------------------------------

it('leve DomainException si plusieurs sous-categories AbandonCreance sont designees', function (): void {
    // Créer une seconde sous-cat avec le même usage pour l'asso
    SousCategorie::factory()->pourAbandonCreance()->create([
        'association_id' => $this->asso->id,
        'categorie_id' => $this->catRecette->id,
        'nom' => 'Abandon de creance bis',
    ]);

    $ndf = makeNdfSoumise($this->asso, $this->tiers, $this->scDepense);

    expect(fn () => $this->service->validerAvecAbandonCreance($ndf, $this->data, '2025-10-20'))
        ->toThrow(DomainException::class, 'Plusieurs sous-categories');
});

// ---------------------------------------------------------------------------
// 5. Rollback atomique : Transaction dépense ne persiste pas si Don échoue
// ---------------------------------------------------------------------------

it('rollback atomique : aucune transaction si la creation du don echoue', function (): void {
    // Supprimer le pivot pour déclencher DomainException dans la transaction
    UsageSousCategorie::where('sous_categorie_id', $this->scAbandon->id)->delete();

    $ndf = makeNdfSoumise($this->asso, $this->tiers, $this->scDepense);
    $initial = Transaction::count();

    expect(fn () => $this->service->validerAvecAbandonCreance($ndf, $this->data, '2025-10-20'))
        ->toThrow(DomainException::class);

    // Aucune transaction persistée
    expect(Transaction::count())->toBe($initial);

    // NDF reste Soumise
    $ndf->refresh();
    expect($ndf->getRawOriginal('statut'))->toBe(StatutNoteDeFrais::Soumise->value);
    expect($ndf->transaction_id)->toBeNull();
    expect($ndf->don_transaction_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// 6. Copie des PJ
// ---------------------------------------------------------------------------

it('copie les pieces jointes des lignes NDF dans le repertoire de la transaction depense', function (): void {
    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'date' => '2025-10-01',
        'libelle' => 'Mission avec PJ',
    ]);

    $assocId = (int) $this->asso->id;

    $path1 = "associations/{$assocId}/notes-de-frais/{$ndf->id}/ligne-1.pdf";
    $path2 = "associations/{$assocId}/notes-de-frais/{$ndf->id}/ligne-2.pdf";
    Storage::disk('local')->put($path1, 'pdf1');
    Storage::disk('local')->put($path2, 'pdf2');

    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'type' => NoteDeFraisLigneType::Standard->value,
        'sous_categorie_id' => $this->scDepense->id,
        'libelle' => 'Repas client',
        'montant' => 45.0,
        'piece_jointe_path' => $path1,
    ]);
    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'type' => NoteDeFraisLigneType::Standard->value,
        'sous_categorie_id' => $this->scDepense->id,
        'libelle' => 'Transport',
        'montant' => 30.0,
        'piece_jointe_path' => $path2,
    ]);

    $this->service->validerAvecAbandonCreance($ndf, $this->data, '2025-10-20');

    $ndf->refresh();
    $txDepense = Transaction::find($ndf->transaction_id);
    $lignesTx = $txDepense->lignes()->orderBy('id')->get();

    expect($lignesTx)->toHaveCount(2);

    foreach ($lignesTx as $ligneTx) {
        expect($ligneTx->piece_jointe_path)->not->toBeNull();
        Storage::disk('local')->assertExists($ligneTx->piece_jointe_path);
    }
});

// ---------------------------------------------------------------------------
// 7. Date du don respectée
// ---------------------------------------------------------------------------

it('la transaction don a la date dateDon, la transaction depense a data->date', function (): void {
    $ndf = makeNdfSoumise($this->asso, $this->tiers, $this->scDepense);
    $dateDon = '2025-11-01'; // différente de $data->date = 2025-10-15

    $txDon = $this->service->validerAvecAbandonCreance($ndf, $this->data, $dateDon);

    $ndf->refresh();
    $txDepense = Transaction::find($ndf->transaction_id);

    expect($txDon->date->format('Y-m-d'))->toBe($dateDon);
    expect($txDepense->date->format('Y-m-d'))->toBe($this->data->date);
});

// ---------------------------------------------------------------------------
// 8. Libellé Transaction Don
// ---------------------------------------------------------------------------

it('le libelle de la transaction don contient Don par abandon de creance NDF id', function (): void {
    $ndf = makeNdfSoumise($this->asso, $this->tiers, $this->scDepense);

    $txDon = $this->service->validerAvecAbandonCreance($ndf, $this->data, '2025-10-20');

    expect($txDon->libelle)->toContain('Don par abandon de créance — NDF #');
    expect($txDon->libelle)->toContain((string) $ndf->id);
});

// ---------------------------------------------------------------------------
// 9. Tiers bénéficiaire : les 2 transactions ont tiers_id = $ndf->tiers_id
// ---------------------------------------------------------------------------

it('les deux transactions ont le meme tiers_id que la NDF', function (): void {
    $ndf = makeNdfSoumise($this->asso, $this->tiers, $this->scDepense);

    $txDon = $this->service->validerAvecAbandonCreance($ndf, $this->data, '2025-10-20');

    $ndf->refresh();
    $txDepense = Transaction::find($ndf->transaction_id);

    expect((int) $txDepense->tiers_id)->toBe((int) $this->tiers->id);
    expect((int) $txDon->tiers_id)->toBe((int) $this->tiers->id);
});

// ---------------------------------------------------------------------------
// 10. Isolation tenant — validerAvecAbandonCreance
// ---------------------------------------------------------------------------

it('une NDF de asso A est non traitable depuis asso B (isolation tenant - abandon)', function (): void {
    // NDF créée dans le contexte asso A (depuis beforeEach)
    $ndf = makeNdfSoumise($this->asso, $this->tiers, $this->scDepense);

    // Basculer vers asso B et lui configurer SA PROPRE sous-cat AbandonCreance
    // (pour prouver que la garde lève AVANT la résolution de sous-cat, même quand B est bien configuré)
    $assoB = Association::factory()->create();

    $catRecetteB = Categorie::factory()->create([
        'association_id' => $assoB->id,
        'type' => TypeCategorie::Recette->value,
    ]);
    SousCategorie::factory()->pourAbandonCreance()->create([
        'association_id' => $assoB->id,
        'categorie_id' => $catRecetteB->id,
        'nom' => 'Abandon creance B',
    ]);

    TenantContext::boot($assoB);

    // La NDF de A est invisible depuis B via le scope global
    expect(NoteDeFrais::query()->count())->toBe(0);

    // Charger la NDF de A en contournant le scope global (simule un objet déjà en mémoire)
    $ndfHors = NoteDeFrais::withoutGlobalScopes()->find($ndf->id);

    // Tenter de valider la NDF de A depuis le contexte B → garde tenant explicite
    expect(fn () => $this->service->validerAvecAbandonCreance($ndfHors, $this->data, '2025-10-20'))
        ->toThrow(DomainException::class, 'Cette NDF appartient à un autre tenant.');

    // Aucune transaction créée (rollback)
    expect(Transaction::count())->toBe(0);

    // NDF A reste Soumise
    $ndf->refresh();
    expect($ndf->getRawOriginal('statut'))->toBe(StatutNoteDeFrais::Soumise->value);
});

// ---------------------------------------------------------------------------
// 10b. Isolation tenant — valider() (chemin non-abandon)
// ---------------------------------------------------------------------------

it('une NDF de asso A est non traitable depuis asso B (isolation tenant - valider)', function (): void {
    // NDF créée dans le contexte asso A (depuis beforeEach)
    $ndf = makeNdfSoumise($this->asso, $this->tiers, $this->scDepense);

    // Basculer vers asso B
    $assoB = Association::factory()->create();
    TenantContext::boot($assoB);

    // Charger la NDF de A en contournant le scope global
    $ndfHors = NoteDeFrais::withoutGlobalScopes()->find($ndf->id);

    $compteB = CompteBancaire::factory()->create(['association_id' => $assoB->id]);
    $dataB = new ValidationData(
        compte_id: (int) $compteB->id,
        mode_paiement: ModePaiement::Virement,
        date: '2025-10-15',
    );

    // Tenter de valider la NDF de A depuis le contexte B → garde tenant explicite
    expect(fn () => $this->service->valider($ndfHors, $dataB))
        ->toThrow(DomainException::class, 'Cette NDF appartient à un autre tenant.');

    // Aucune transaction créée
    expect(Transaction::count())->toBe(0);

    // NDF A reste Soumise
    $ndf->refresh();
    expect($ndf->getRawOriginal('statut'))->toBe(StatutNoteDeFrais::Soumise->value);
});

// ---------------------------------------------------------------------------
// 11. Transactions absentes des listes "à régler" (statut EnAttente)
// ---------------------------------------------------------------------------

it('apres abandon aucune des deux transactions nest en statut EnAttente', function (): void {
    $ndf = makeNdfSoumise($this->asso, $this->tiers, $this->scDepense);

    $this->service->validerAvecAbandonCreance($ndf, $this->data, '2025-10-20');

    $enAttente = Transaction::where('statut_reglement', StatutReglement::EnAttente->value)->count();
    expect($enAttente)->toBe(0);
});

// ---------------------------------------------------------------------------
// 12. Non-régression : valider() standard reste vert (statut EnAttente)
// ---------------------------------------------------------------------------

it('valider() standard produit toujours statut_reglement EnAttente (non-regression)', function (): void {
    $ndf = makeNdfSoumise($this->asso, $this->tiers, $this->scDepense);

    $tx = $this->service->valider($ndf, $this->data);

    expect($tx->statut_reglement)->toBe(StatutReglement::EnAttente);
    expect($tx->type)->toBe(TypeTransaction::Depense);

    $ndf->refresh();
    expect($ndf->getRawOriginal('statut'))->toBe(StatutNoteDeFrais::Validee->value);
    expect((int) $ndf->transaction_id)->toBe((int) $tx->id);
    expect($ndf->don_transaction_id)->toBeNull();
});
