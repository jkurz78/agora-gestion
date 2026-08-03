<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Enums\ModePaiement;
use App\Enums\NoteDeFraisLigneType;
use App\Enums\StatutNoteDeFrais;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Enums\UsageComptable;
use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use App\Models\Operation;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\UsageCompte;
use App\Services\Compta\Migrations\SystemeSeeder;
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
    Compte $sc,
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
            'compte_id' => $sc->id,
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

    // Infrastructure partie double — comptes système 411, 401, 467 requis par abandonCreancePd()
    SystemeSeeder::seed();

    // DC-10a : compte de charge (classe 6) pour les lignes NDF
    $this->scDepense = Compte::firstOrCreate(
        ['association_id' => $this->asso->id, 'numero_pcg' => '625'],
        ['intitule' => 'Frais missions déplacements', 'classe' => 6, 'lettrable' => false, 'actif' => true, 'est_systeme' => false, 'pour_inscriptions' => false]
    );

    // Compte recette flaggé AbandonCreance (classe 7)
    $this->scAbandon = Compte::firstOrCreate(
        ['association_id' => $this->asso->id, 'numero_pcg' => '771'],
        ['intitule' => 'Dons et abandons de créances', 'classe' => 7, 'lettrable' => false, 'actif' => true, 'est_systeme' => false, 'pour_inscriptions' => false]
    );
    $this->scAbandon->usages()->create(['usage' => UsageComptable::AbandonCreance->value]);

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

    // 4 transactions créées en mode PD : T1-dépense, T1-don, OD-compensation × 2
    expect(Transaction::count())->toBe(4);

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
// 1b. Le Don clone les lignes de la Dépense (operation, seance, notes, montant)
//     seule le compte diffère → pointe sur AbandonCreance
// ---------------------------------------------------------------------------

it('clone les lignes de la Depense vers le Don (operation, seance, notes, montant)', function (): void {
    // Setup : une opération + deux séances
    $operation = Operation::factory()->create(['association_id' => $this->asso->id]);

    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'date' => '2025-10-01',
        'libelle' => 'Frais mission avec opération',
    ]);

    // 2 lignes avec opération + séances distinctes
    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'type' => NoteDeFraisLigneType::Standard->value,
        'compte_id' => $this->scDepense->id,
        'operation_id' => $operation->id,
        'seance' => 1,
        'libelle' => 'Transport',
        'montant' => 42.0,
        'piece_jointe_path' => null,
    ]);
    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'type' => NoteDeFraisLigneType::Standard->value,
        'compte_id' => $this->scDepense->id,
        'operation_id' => $operation->id,
        'seance' => 2,
        'libelle' => 'Hébergement',
        'montant' => 58.0,
        'piece_jointe_path' => null,
    ]);

    $txDon = $this->service->validerAvecAbandonCreance($ndf, $this->data, '2025-10-20');

    $ndf->refresh();
    $txDepense = Transaction::find($ndf->transaction_id);

    // En mode PD, les lignes incluent des lignes comptables pures (401 C, 411 D).
    // On compare uniquement les lignes de ventilation (classe 6/7).
    $lignesDepense = $txDepense->lignes()->ventilation()->orderBy('id')->get();
    $lignesDon = $txDon->lignes()->ventilation()->orderBy('id')->get();

    expect($lignesDon)->toHaveCount($lignesDepense->count());

    foreach ($lignesDepense as $i => $ligneDepense) {
        $ligneDon = $lignesDon[$i];

        // Champs clonés identiquement
        expect((int) $ligneDon->operation_id)->toBe((int) $ligneDepense->operation_id);
        expect($ligneDon->seance)->toBe($ligneDepense->seance);
        expect($ligneDon->notes)->toBe($ligneDepense->notes);
        expect((float) $ligneDon->montant)->toBe((float) $ligneDepense->montant);

        // Le compte pointe vers AbandonCreance (pas le compte Dépense d'origine)
        expect((int) $ligneDon->compte_id)->toBe((int) $this->scAbandon->id);
        expect((int) $ligneDon->compte_id)->not->toBe((int) $ligneDepense->compte_id);
    }
});

// ---------------------------------------------------------------------------
// 1c. La Transaction Don hérite du compte bancaire de la Dépense
// ---------------------------------------------------------------------------

it('met le Don sur le meme compte que la Depense', function (): void {
    $ndf = makeNdfSoumise($this->asso, $this->tiers, $this->scDepense);

    $txDon = $this->service->validerAvecAbandonCreance($ndf, $this->data, '2025-10-20');

    $ndf->refresh();
    $txDepense = Transaction::find($ndf->transaction_id);

    // En mode PD, txDepense est liée au compte bancaire ; txDon est une écriture comptable
    // pure (411/7xx) sans compte bancaire → compte_id null.
    expect((int) $txDepense->compte_id)->toBe((int) $this->compte->id);
    expect($txDon->compte_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// 2. Pas de compte AbandonCreance désignée → DomainException + rollback
// ---------------------------------------------------------------------------

it('leve DomainException si aucun compte AbandonCreance configuree', function (): void {
    // Supprimer le lien pivot créé dans beforeEach
    UsageCompte::where('compte_id', $this->scAbandon->id)
        ->where('usage', UsageComptable::AbandonCreance->value)
        ->delete();

    $ndf = makeNdfSoumise($this->asso, $this->tiers, $this->scDepense);

    expect(fn () => $this->service->validerAvecAbandonCreance($ndf, $this->data, '2025-10-20'))
        ->toThrow(DomainException::class, 'Aucun compte');

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
// 4. Plusieurs comptes AbandonCreance → DomainException (cas pathologique)
// ---------------------------------------------------------------------------

it('leve DomainException si plusieurs comptes AbandonCreance sont designes', function (): void {
    // Créer un second compte avec le même usage pour l'asso
    $bis = Compte::create([
        'association_id' => $this->asso->id,
        'numero_pcg' => '771B',
        'intitule' => 'Abandon de creance bis',
        'classe' => 7,
        'actif' => true,
    ]);
    $bis->usages()->create(['usage' => UsageComptable::AbandonCreance->value]);

    $ndf = makeNdfSoumise($this->asso, $this->tiers, $this->scDepense);

    expect(fn () => $this->service->validerAvecAbandonCreance($ndf, $this->data, '2025-10-20'))
        ->toThrow(DomainException::class, 'Plusieurs comptes');
});

// ---------------------------------------------------------------------------
// 5. Rollback atomique : Transaction dépense ne persiste pas si Don échoue
// ---------------------------------------------------------------------------

it('rollback atomique : aucune transaction si la creation du don echoue', function (): void {
    // Supprimer le pivot pour déclencher DomainException dans la transaction
    UsageCompte::where('compte_id', $this->scAbandon->id)->delete();

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
        'compte_id' => $this->scDepense->id,
        'libelle' => 'Repas client',
        'montant' => 45.0,
        'piece_jointe_path' => $path1,
    ]);
    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'type' => NoteDeFraisLigneType::Standard->value,
        'compte_id' => $this->scDepense->id,
        'libelle' => 'Transport',
        'montant' => 30.0,
        'piece_jointe_path' => $path2,
    ]);

    $this->service->validerAvecAbandonCreance($ndf, $this->data, '2025-10-20');

    $ndf->refresh();
    $txDepense = Transaction::find($ndf->transaction_id);
    // En mode PD, les lignes incluent une ligne comptable pure (401 C) sans PJ.
    // On vérifie uniquement les lignes ayant une pièce jointe copiée.
    $lignesTx = $txDepense->lignes()->whereNotNull('piece_jointe_path')->orderBy('id')->get();

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

    // Basculer vers asso B et lui configurer SON PROPRE compte AbandonCreance
    // (pour prouver que la garde lève AVANT la résolution du compte, même quand B est bien configuré)
    $assoB = Association::factory()->create();

    $compteAbandonB = Compte::create([
        'association_id' => $assoB->id,
        'numero_pcg' => '771',
        'intitule' => 'Abandon creance B',
        'classe' => 7,
        'actif' => true,
    ]);
    $compteAbandonB->usages()->create([
        'association_id' => $assoB->id,
        'usage' => UsageComptable::AbandonCreance->value,
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

    // En mode PD, les 2 OD de compensation (journal=OD) restent en_attente (écritures comptables
    // internes non soumises au flux de trésorerie). Les T1 txDepense et txDon doivent être Recu.
    $enAttente = Transaction::where('statut_reglement', StatutReglement::EnAttente->value)
        ->where(fn ($q) => $q->whereNull('journal')->orWhere('journal', '!=', JournalComptable::Od->value))
        ->count();
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
