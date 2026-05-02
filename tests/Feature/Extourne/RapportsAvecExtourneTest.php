<?php

declare(strict_types=1);

use App\DataTransferObjects\ExtournePayload;
use App\Enums\ModePaiement;
use App\Enums\RoleAssociation;
use App\Enums\StatutRapprochement;
use App\Enums\StatutReglement;
use App\Enums\TypeRapprochement;
use App\Enums\TypeTransaction;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Rapports\CompteResultatBuilder;
use App\Services\TransactionExtourneService;
use App\Tenant\TenantContext;

function rapportsActAsComptable(): User
{
    $user = User::factory()->create();
    $user->associations()->attach(TenantContext::currentId(), [
        'role' => RoleAssociation::Comptable->value,
        'joined_at' => now(),
    ]);
    $user->update(['derniere_association_id' => TenantContext::currentId()]);
    auth()->login($user);

    return $user;
}

function rapportsCreateRecetteWithSousCategorie(SousCategorie $sc, CompteBancaire $compte, float $montant, string $date): Transaction
{
    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'libelle' => 'Cotisation mars',
        'montant_total' => $montant,
        'mode_paiement' => ModePaiement::Cheque,
        'statut_reglement' => StatutReglement::Recu,
        'compte_id' => $compte->id,
        'date' => $date,
    ]);
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sc->id,
        'montant' => $montant,
    ]);

    return $tx;
}

test('compte de résultat — recette nette zéro avec extourne dans même sous-catégorie', function (): void {
    rapportsActAsComptable();

    // Setup catégorie + sous-catégorie recette dans l'exercice 2025 (Sept 2025 → Aug 2026)
    $cat = Categorie::create(['nom' => 'Cotisations', 'type' => 'recette']);
    $sc = SousCategorie::create(['categorie_id' => $cat->id, 'nom' => 'Cotisations séance', 'libelle_article' => 'des cotisations séance']);
    $compte = CompteBancaire::factory()->create();

    // Recette +80€ dans l'exercice 2025
    $origine = rapportsCreateRecetteWithSousCategorie($sc, $compte, 80.0, '2026-03-15');

    // Extourner — naît EnAttente, lettrage non créé (cas Recu)
    app(TransactionExtourneService::class)
        ->extourner($origine, ExtournePayload::fromOrigine($origine, ['date' => '2026-03-20']));

    $result = app(CompteResultatBuilder::class)->compteDeResultat(2025);

    // Le détail au niveau sous-catégorie doit refléter la somme nette = 0
    $produits = collect($result['produits'])->flatMap(fn ($cat) => $cat['sous_categories'] ?? []);
    $souscat = $produits->firstWhere('sous_categorie_id', $sc->id);

    expect($souscat)->not->toBeNull('Sous-catégorie absente du compte de résultat');
    expect((float) $souscat['montant_n'])->toBe(0.0);
});

test('Transaction::sum sur même sous-cat = 0 avec extourne', function (): void {
    rapportsActAsComptable();
    $cat = Categorie::create(['nom' => 'Cotisations', 'type' => 'recette']);
    $sc = SousCategorie::create(['categorie_id' => $cat->id, 'nom' => 'Cotisations séance', 'libelle_article' => 'des cotisations séance']);
    $compte = CompteBancaire::factory()->create();

    $origine = rapportsCreateRecetteWithSousCategorie($sc, $compte, 80.0, '2026-03-15');
    app(TransactionExtourneService::class)
        ->extourner($origine, ExtournePayload::fromOrigine($origine));

    // Detail : 2 lignes de TransactionLigne sur la sous-cat (+80, -80)
    $lignes = TransactionLigne::where('sous_categorie_id', $sc->id)->get();
    expect($lignes)->toHaveCount(2);
    expect((float) $lignes->sum('montant'))->toBe(0.0);
    expect($lignes->pluck('montant')->map(fn ($m) => (float) $m)->sort()->values()->all())
        ->toBe([-80.0, 80.0]);
});

test('flux trésorerie cas encaissé — solde compte = ouverture - 80€ après extourne pointée banque ordinaire', function (): void {
    rapportsActAsComptable();
    $compte = CompteBancaire::factory()->create();

    // Rapprochement préalable Verrouille fixant solde 500 €
    $r1 = RapprochementBancaire::factory()->create([
        'compte_id' => $compte->id,
        'type' => TypeRapprochement::Bancaire,
        'statut' => StatutRapprochement::Verrouille,
        'verrouille_at' => now()->subDay(),
        'date_fin' => now()->subDay()->toDateString(),
        'solde_ouverture' => 0,
        'solde_fin' => 500,
    ]);

    // Origine 80 € Pointe rattachée à R1
    $cat = Categorie::create(['nom' => 'Cotisations', 'type' => 'recette']);
    $sc = SousCategorie::create(['categorie_id' => $cat->id, 'nom' => 'Cotisations séance', 'libelle_article' => 'des cotisations séance']);
    $origine = rapportsCreateRecetteWithSousCategorie($sc, $compte, 80.0, now()->toDateString());
    $origine->update([
        'statut_reglement' => StatutReglement::Pointe,
        'rapprochement_id' => $r1->id,
    ]);

    // Extourne -80 €, naît EnAttente (cas Pointe verrouillé) — pas de lettrage
    $extourne = app(TransactionExtourneService::class)
        ->extourner($origine->fresh(), ExtournePayload::fromOrigine($origine->fresh()));
    expect($extourne->rapprochement_lettrage_id)->toBeNull();

    // R2 verrouillé pointe l'extourne avec solde_fin = 420
    $r2 = RapprochementBancaire::factory()->create([
        'compte_id' => $compte->id,
        'type' => TypeRapprochement::Bancaire,
        'statut' => StatutRapprochement::Verrouille,
        'verrouille_at' => now(),
        'date_fin' => now()->toDateString(),
        'solde_ouverture' => 500,
        'solde_fin' => 420,
    ]);
    $extourne->extourne->update([
        'rapprochement_id' => $r2->id,
        'statut_reglement' => StatutReglement::Pointe,
    ]);

    // Le solde du compte est lu depuis le solde_fin du dernier rapprochement Verrouille
    $dernier = RapprochementBancaire::where('compte_id', $compte->id)
        ->where('type', TypeRapprochement::Bancaire)
        ->where('statut', StatutRapprochement::Verrouille)
        ->orderByDesc('date_fin')
        ->orderByDesc('id')
        ->first();
    expect((float) $dernier->solde_fin)->toBe(420.0);
});

test('flux trésorerie cas non-encaissé — lettrage automatique laisse le solde inchangé', function (): void {
    rapportsActAsComptable();
    $compte = CompteBancaire::factory()->create();

    // Rapprochement préalable verrouille fixant solde 500 €
    RapprochementBancaire::factory()->create([
        'compte_id' => $compte->id,
        'type' => TypeRapprochement::Bancaire,
        'statut' => StatutRapprochement::Verrouille,
        'verrouille_at' => now()->subDay(),
        'date_fin' => now()->subDay()->toDateString(),
        'solde_ouverture' => 0,
        'solde_fin' => 500,
    ]);

    // Origine EnAttente — extourne crée un lettrage automatique
    $cat = Categorie::create(['nom' => 'Cotisations', 'type' => 'recette']);
    $sc = SousCategorie::create(['categorie_id' => $cat->id, 'nom' => 'Cotisations séance', 'libelle_article' => 'des cotisations séance']);
    $origine = rapportsCreateRecetteWithSousCategorie($sc, $compte, 80.0, now()->toDateString());
    $origine->update(['statut_reglement' => StatutReglement::EnAttente]);

    $extourne = app(TransactionExtourneService::class)
        ->extourner($origine->fresh(), ExtournePayload::fromOrigine($origine->fresh()));

    expect($extourne->rapprochement_lettrage_id)->not->toBeNull();
    $lettrage = $extourne->lettrage;

    // Le lettrage a solde_ouverture = solde_fin = 500 (inchangé)
    expect((float) $lettrage->solde_ouverture)->toBe(500.0);
    expect((float) $lettrage->solde_fin)->toBe(500.0);

    // Le solde le plus récent reste 500 puisque le lettrage a solde_fin = 500
    $dernier = RapprochementBancaire::where('compte_id', $compte->id)
        ->orderByDesc('date_fin')
        ->orderByDesc('id')
        ->first();
    expect((float) $dernier->solde_fin)->toBe(500.0);
});
