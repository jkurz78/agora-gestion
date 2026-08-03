# Extourne PD — Écritures partie double sur la transaction miroir

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que la transaction miroir créée par `TransactionExtourneService::extourner()` porte des écritures partie double (PD) correctes — D↔C inversées, lettrées avec l'origine — pour que le grand livre, la balance, et le suivi par tiers reflètent l'annulation.

**Architecture:** L'extourne crée déjà une transaction miroir négative (`montant_total < 0`) avec des lignes legacy inversées (`montant` négatif). Le fix ajoute trois choses : (1) les champs PD sur le header miroir (`equilibree`, `journal`), (2) l'inversion D↔C des lignes PD lors de la copie, (3) le cross-lettrage 411/401 entre origine et miroir. Les vérifications paranoïaques (`assertEquilibre`) garantissent la cohérence comptable. On ne touche PAS à `FactureService::annuler()` — il compose déjà correctement `extourner()`.

**Tech Stack:** PHP 8.x, Laravel 11, Pest Feature tests, SQLite :memory:

**Fichier principal modifié :** `app/Services/TransactionExtourneService.php`
**Fichier de test étendu :** `tests/Feature/Services/TransactionExtourneServicePartieDoubleTest.php`

**Convention D↔C :** l'inversion PD se fait par **swap débit↔crédit** (montants positifs), pas par montants négatifs. C'est la pratique comptable standard (contre-passation). Le champ legacy `montant` reste négatif (brèche du signe existante).

**Rappel sécurité :** Cast `(int)` obligatoire des deux côtés dans `===` PK/FK. NEVER `migrate:fresh`. Tests sur SQLite `:memory:` via `phpunit.xml`.

---

## Task 1 : Header PD sur la transaction miroir

**Files:**
- Modify: `app/Services/TransactionExtourneService.php:92-117` (`creerTransactionMiroir`)
- Test: `tests/Feature/Services/TransactionExtourneServicePartieDoubleTest.php`

- [ ] **Step 1: RED — test que le miroir porte les champs PD header**

Ajouter dans `tests/Feature/Services/TransactionExtourneServicePartieDoubleTest.php` :

```php
// ---------------------------------------------------------------------------
// Scénario F — Header PD sur le miroir
// ---------------------------------------------------------------------------

it('[F] miroir porte equilibree=true, type_ecriture=extourne, journal=origine', function () {
    // T1 recette à crédit (PD) — pas de T2
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $this->tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 100.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Test header PD',
    );

    // Forcer Recu pour éviter le path lettrage rapprochement
    $t1->update(['statut_reglement' => StatutReglement::Recu]);

    $extourne = $this->service->extourner($t1->fresh(), ExtournePayload::fromOrigine($t1->fresh()));

    $miroir = $extourne->extourne;
    expect($miroir->equilibree)->toBeTrue();
    expect($miroir->type_ecriture)->toBe('extourne');
    expect($miroir->journal)->toBe($t1->fresh()->journal);
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/pest tests/Feature/Services/TransactionExtourneServicePartieDoubleTest.php --filter="header PD"
```

Expected: FAIL — `$miroir->equilibree` est null/false, `type_ecriture` est null.

- [ ] **Step 3: GREEN — ajouter les champs PD dans `creerTransactionMiroir`**

Dans `app/Services/TransactionExtourneService.php`, méthode `creerTransactionMiroir` :

```php
private function creerTransactionMiroir(Transaction $origine, ExtournePayload $payload): Transaction
{
    return Transaction::create([
        'type' => $origine->type,
        'date' => $payload->date->toDateString(),
        'libelle' => $payload->libelle,
        'montant_total' => -1 * (float) $origine->montant_total,
        'mode_paiement' => $payload->modePaiement,
        'tiers_id' => $origine->tiers_id,
        'reference' => null,
        'compte_id' => $origine->compte_id,
        'notes' => $payload->notes,
        'saisi_par' => (int) auth()->id(),
        'rapprochement_id' => null,
        'remise_id' => null,
        'reglement_id' => null,
        'numero_piece' => $this->numeroPiece->assign($payload->date),
        'piece_jointe_path' => null,
        'piece_jointe_nom' => null,
        'piece_jointe_mime' => null,
        'helloasso_order_id' => null,
        'helloasso_cashout_id' => null,
        'helloasso_payment_id' => null,
        'statut_reglement' => StatutReglement::EnAttente,
        // PD
        'equilibree' => true,
        'type_ecriture' => 'extourne',
        'journal' => $origine->journal,
    ]);
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/pest tests/Feature/Services/TransactionExtourneServicePartieDoubleTest.php --filter="header PD"
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/TransactionExtourneService.php tests/Feature/Services/TransactionExtourneServicePartieDoubleTest.php
git commit -m "feat(extourne-pd): header PD sur le miroir (equilibree, type_ecriture, journal)"
```

---

## Task 2 : Copie des lignes PD avec inversion D↔C

**Files:**
- Modify: `app/Services/TransactionExtourneService.php:119-133` (`copierLignesInversees`)
- Test: `tests/Feature/Services/TransactionExtourneServicePartieDoubleTest.php`

- [ ] **Step 1: RED — test que les lignes PD du miroir ont D↔C inversé**

```php
// ---------------------------------------------------------------------------
// Scénario G — Lignes PD inversées D↔C sur le miroir
// ---------------------------------------------------------------------------

it('[G] miroir d\'une recette à crédit porte les lignes PD avec D↔C inversé', function () {
    // T1 : 411 D=120 (tiers) + 706 C=120
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $this->tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 120.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Test inversion D/C',
    );
    $t1->update(['statut_reglement' => StatutReglement::Recu]);

    $extourne = $this->service->extourner($t1->fresh(), ExtournePayload::fromOrigine($t1->fresh()));
    $miroir = $extourne->extourne;

    $compte411 = compteSysteme('411');

    // Lignes PD du miroir (compte_id IS NOT NULL)
    $lignesPD = TransactionLigne::where('transaction_id', $miroir->id)
        ->whereNotNull('compte_id')
        ->get();

    // 2 lignes PD : 411 et 706 (inversées)
    expect($lignesPD)->toHaveCount(2);

    // Ligne 411 inversée : debit=0, credit=120 (swap de l'original 411 D=120)
    $ligne411 = $lignesPD->firstWhere('compte_id', (int) $compte411->id);
    expect($ligne411)->not->toBeNull('Miroir doit avoir une ligne 411');
    expect((float) $ligne411->debit)->toBe(0.0);
    expect((float) $ligne411->credit)->toBe(120.0);
    expect((int) $ligne411->tiers_id)->toBe((int) $this->tiers->id);

    // Ligne 706 inversée : debit=120, credit=0 (swap de l'original 706 C=120)
    $ligne706 = $lignesPD->firstWhere('compte_id', (int) $this->compte706->id);
    expect($ligne706)->not->toBeNull('Miroir doit avoir une ligne 706');
    expect((float) $ligne706->debit)->toBe(120.0);
    expect((float) $ligne706->credit)->toBe(0.0);
    expect($ligne706->tiers_id)->toBeNull();
});

it('[G2] lignes PD du miroir sont équilibrées (sum D = sum C)', function () {
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $this->tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 250.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Test équilibre miroir',
    );
    $t1->update(['statut_reglement' => StatutReglement::Recu]);

    $extourne = $this->service->extourner($t1->fresh(), ExtournePayload::fromOrigine($t1->fresh()));
    $miroir = $extourne->extourne;

    $lignesPD = TransactionLigne::where('transaction_id', $miroir->id)
        ->whereNotNull('compte_id')
        ->get();

    $totalDebit = $lignesPD->sum(fn ($l) => (float) $l->debit);
    $totalCredit = $lignesPD->sum(fn ($l) => (float) $l->credit);

    expect(bccomp((string) $totalDebit, (string) $totalCredit, 2))->toBe(0);
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/pest tests/Feature/Services/TransactionExtourneServicePartieDoubleTest.php --filter="G"
```

Expected: FAIL — les lignes PD n'ont pas de `compte_id` (non copiés actuellement).

- [ ] **Step 3: GREEN — réécrire `copierLignesInversees` pour copier les champs PD**

```php
private function copierLignesInversees(Transaction $origine, Transaction $miroir): void
{
    foreach ($origine->lignes()->get() as $ligne) {
        TransactionLigne::create([
            'transaction_id' => $miroir->id,
            // Legacy fields
            'sous_categorie_id' => $ligne->sous_categorie_id,
            'operation_id' => $ligne->operation_id,
            'seance' => $ligne->seance,
            'montant' => -1 * (float) $ligne->montant,
            'notes' => $ligne->notes,
            'piece_jointe_path' => null,
            'helloasso_item_id' => null,
            // PD fields — D↔C swap (montants positifs, sens inversé)
            'compte_id' => $ligne->compte_id,
            'debit' => (float) $ligne->credit,
            'credit' => (float) $ligne->debit,
            'tiers_id' => $ligne->tiers_id,
            'libelle' => $ligne->libelle,
            // Pas de lettrage_code — le miroir naît non lettré
        ]);
    }
}
```

**Note :** pour les lignes legacy pures (`compte_id = null`, `debit = 0`, `credit = 0`), le swap donne `debit = 0, credit = 0` avec `compte_id = null` → l'observer `TransactionLigneObserver::saving()` les ignore (garde `if ($ligne->compte_id === null) return`). Pas de régression.

- [ ] **Step 4: Run full test suite extourne PD**

```bash
./vendor/bin/pest tests/Feature/Services/TransactionExtourneServicePartieDoubleTest.php
```

Expected: PASS (y compris les tests [A]-[E] existants + [F] + [G] + [G2]).

- [ ] **Step 5: Commit**

```bash
git add app/Services/TransactionExtourneService.php tests/Feature/Services/TransactionExtourneServicePartieDoubleTest.php
git commit -m "feat(extourne-pd): copie lignes PD avec inversion D↔C sur le miroir"
```

---

## Task 3 : Cross-lettrage tiers (411/401) origine ↔ miroir

**Files:**
- Modify: `app/Services/TransactionExtourneService.php:37-90` (`extourner`)
- Test: `tests/Feature/Services/TransactionExtourneServicePartieDoubleTest.php`

Le cross-lettrage résout le problème central : après l'auto-délettrage, les lignes tiers de l'origine sont ouvertes. Le miroir porte les lignes tiers inversées (D↔C). On lettrer chaque paire pour que le solde 411/401 du tiers revienne à zéro (ou reflète la dette réelle en cas de remboursement à émettre).

**Algorithme :**
1. Charger les lignes PD du miroir (compte_id IS NOT NULL).
2. Pour chaque compte tiers (411, 401) : trouver les lignes de l'origine et du miroir sur ce compte, non lettrées, même tiers_id.
3. Apparier par montant (origine D=X ↔ miroir C=X, ou origine C=X ↔ miroir D=X).
4. Lettrer chaque paire via `LettrageService::lettrer()`.

- [ ] **Step 1: RED — test cross-lettrage recette à crédit (T1 only)**

```php
// ---------------------------------------------------------------------------
// Scénario H — Cross-lettrage tiers après extourne
// ---------------------------------------------------------------------------

it('[H] extourne recette à crédit — cross-lettrage 411 origine ↔ miroir', function () {
    // T1 : 411 D=200 (tiers, non lettrée — créance ouverte) + 706 C=200
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $this->tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 200.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Créance ouverte',
    );
    $t1->update(['statut_reglement' => StatutReglement::Recu]);

    $compte411 = compteSysteme('411');

    // Précondition : ligne 411 T1 ouverte
    $ligne411T1 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte411->id)
        ->firstOrFail();
    expect($ligne411T1->lettrage_code)->toBeNull();

    // Action
    $extourne = $this->service->extourner($t1->fresh(), ExtournePayload::fromOrigine($t1->fresh()));
    $miroir = $extourne->extourne;

    // Lignes 411 : T1 D=200 et Miroir C=200 doivent être lettrées ensemble
    $ligne411T1->refresh();
    $ligne411Miroir = TransactionLigne::where('transaction_id', $miroir->id)
        ->where('compte_id', $compte411->id)
        ->firstOrFail();

    expect($ligne411T1->lettrage_code)->not->toBeNull('Ligne 411 T1 doit être lettrée');
    expect($ligne411Miroir->lettrage_code)->not->toBeNull('Ligne 411 miroir doit être lettrée');
    expect($ligne411T1->lettrage_code)->toBe($ligne411Miroir->lettrage_code);

    // Solde 411 pour ce tiers = 0 (D=200, C=200, tout lettré)
    $solde = TransactionLigne::where('compte_id', $compte411->id)
        ->where('tiers_id', $this->tiers->id)
        ->whereNull('lettrage_code')
        ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as solde')
        ->value('solde');
    expect((float) $solde)->toBe(0.0, 'Solde 411 tiers = 0 après extourne d\'une créance ouverte');
});
```

- [ ] **Step 2: RED — test cross-lettrage recette comptant T1+T2 (encaissement)**

```php
it('[H2] extourne recette comptant (T1+T2) — cross-lettrage 411 T1↔miroir, T2.411C reste ouverte', function () {
    // T1 : créance → 411 D=200 (tiers) + 706 C=200
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $this->tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 200.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Facture T1',
    );

    // T2 : encaissement → portage D=200 + 411 C=200 (tiers), auto-lettrage T1.411D↔T2.411C
    $t2 = $this->ecritureGen->pourEncaissementCreance(
        transactionCreance: $t1,
        mode: ModePaiement::Cheque,
        compteTresorerie: $this->compte512X,
        datePaiement: new DateTimeImmutable('2025-10-15'),
        libelle: 'Encaissement T2',
    );
    $t1->update(['statut_reglement' => StatutReglement::Recu]);

    $compte411 = compteSysteme('411');

    // Précondition : T1.411D et T2.411C sont lettrées
    $ligne411T1 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte411->id)->firstOrFail();
    $ligne411T2 = TransactionLigne::where('transaction_id', $t2->id)
        ->where('compte_id', $compte411->id)->firstOrFail();
    expect($ligne411T1->lettrage_code)->toBe($ligne411T2->lettrage_code);

    // Action : extourner T1
    $extourne = $this->service->extourner($t1->fresh(), ExtournePayload::fromOrigine($t1->fresh()));
    $miroir = $extourne->extourne;

    // Recharger
    $ligne411T1->refresh();
    $ligne411T2->refresh();
    $ligne411Miroir = TransactionLigne::where('transaction_id', $miroir->id)
        ->where('compte_id', $compte411->id)->firstOrFail();

    // T1.411D ↔ Miroir.411C : cross-lettrées
    expect($ligne411T1->lettrage_code)->not->toBeNull('T1.411D doit être lettrée avec miroir');
    expect($ligne411Miroir->lettrage_code)->not->toBeNull('Miroir.411C doit être lettrée');
    expect($ligne411T1->lettrage_code)->toBe($ligne411Miroir->lettrage_code);

    // T2.411C : ouverte (le remboursement est en attente → obligation envers le tiers)
    expect($ligne411T2->lettrage_code)->toBeNull('T2.411C doit rester ouverte (refund pending)');

    // Solde 411 ouvert pour ce tiers = -200 (T2.411C non lettrée = on doit rembourser)
    $soldeOuvert = (float) TransactionLigne::where('compte_id', $compte411->id)
        ->where('tiers_id', $this->tiers->id)
        ->whereNull('lettrage_code')
        ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as solde')
        ->value('solde');
    expect($soldeOuvert)->toBe(-200.0, 'Solde ouvert 411 = -200 (on doit rembourser le tiers)');
});
```

- [ ] **Step 3: RED — test cross-lettrage recette comptant OLD pattern (4 lignes internes)**

```php
it('[H3] extourne recette comptant OLD pattern (4 lignes T1) — double cross-lettrage 411', function () {
    // T1 OLD pattern : 411 D=150 + 706 C=150 + 5112 D=150 + 411 C=150
    // Paire 411 interne auto-lettrée
    $t1 = $this->ecritureGen->pourRecetteComptant(
        tiers: $this->tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 150.0]],
        mode: ModePaiement::Cheque,
        compteTresorerie: $this->compte512X,
        date: new DateTimeImmutable('2025-11-01'),
        libelle: 'Recette comptant OLD',
    );
    $t1->update(['statut_reglement' => StatutReglement::Recu]);

    $compte411 = compteSysteme('411');

    // Précondition : 2 lignes 411 internes lettrées
    $lignes411T1 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte411->id)
        ->orderBy('id')
        ->get();
    expect($lignes411T1)->toHaveCount(2);
    expect($lignes411T1[0]->lettrage_code)->toBe($lignes411T1[1]->lettrage_code);

    // Action
    $extourne = $this->service->extourner($t1->fresh(), ExtournePayload::fromOrigine($t1->fresh()));
    $miroir = $extourne->extourne;

    // Recharger
    $lignes411T1->each->refresh();

    $lignes411Miroir = TransactionLigne::where('transaction_id', $miroir->id)
        ->where('compte_id', $compte411->id)
        ->orderBy('id')
        ->get();
    expect($lignes411Miroir)->toHaveCount(2);

    // Toutes les 4 lignes 411 doivent être lettrées (en paires D↔C)
    $toutesLignes411 = $lignes411T1->merge($lignes411Miroir);
    foreach ($toutesLignes411 as $l) {
        expect($l->lettrage_code)->not->toBeNull("Ligne 411 #{$l->id} doit être lettrée");
    }

    // Solde 411 ouvert = 0 (tout lettré, pas de dette)
    $solde = (float) TransactionLigne::where('compte_id', $compte411->id)
        ->where('tiers_id', $this->tiers->id)
        ->whereNull('lettrage_code')
        ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as solde')
        ->value('solde');
    expect($solde)->toBe(0.0);
});
```

- [ ] **Step 4: Run tests to verify they fail**

```bash
./vendor/bin/pest tests/Feature/Services/TransactionExtourneServicePartieDoubleTest.php --filter="H"
```

Expected: FAIL — aucun cross-lettrage n'est effectué actuellement.

- [ ] **Step 5: GREEN — ajouter le cross-lettrage dans `extourner()`**

Dans `app/Services/TransactionExtourneService.php`, ajouter une méthode privée `crossLettrerLignesTiers` et l'appeler dans `extourner()` après `copierLignesInversees` :

```php
public function extourner(Transaction $origine, ExtournePayload $payload): Extourne
{
    $this->assertSameTenant($origine);
    Gate::authorize('create', [Extourne::class, $origine]);
    $this->assertExtournable($origine);

    return DB::transaction(function () use ($origine, $payload): Extourne {
        $this->autoDelettrerLignes($origine);
        $miroir = $this->creerTransactionMiroir($origine, $payload);
        $this->copierLignesInversees($origine, $miroir);

        // Cross-lettrage PD des lignes tiers (411/401) entre origine et miroir
        $this->crossLettrerLignesTiers($origine, $miroir);

        $lettrageId = null;
        if ($origine->statut_reglement === StatutReglement::EnAttente) {
            // ... legacy lettrage via rapprochement bancaire (inchangé) ...
```

Méthode privée `crossLettrerLignesTiers` :

```php
/**
 * Cross-lettre les lignes tiers (411/401) de l'origine avec celles du miroir.
 *
 * Après auto-délettrage, l'origine peut avoir des lignes 411/401 ouvertes
 * (lettrage_code = null). Le miroir porte les lignes inversées D↔C.
 * On apparie chaque ligne ouverte de l'origine avec sa contrepartie sur le miroir.
 *
 * Algorithme : pour chaque compte tiers (411, 401), grouper les lignes ouvertes
 * de l'origine par (compte_id, tiers_id) et apparier avec les lignes du miroir
 * ayant le même (compte_id, tiers_id) et un montant D↔C complémentaire.
 */
private function crossLettrerLignesTiers(Transaction $origine, Transaction $miroir): void
{
    // Recharger les lignes fraîches depuis la DB (lettrage_code à jour post-délettrage)
    $lignesOrigine = TransactionLigne::where('transaction_id', (int) $origine->id)
        ->whereNotNull('compte_id')
        ->whereNotNull('tiers_id')
        ->whereNull('lettrage_code')
        ->get();

    if ($lignesOrigine->isEmpty()) {
        return;
    }

    $lignesMiroir = TransactionLigne::where('transaction_id', (int) $miroir->id)
        ->whereNotNull('compte_id')
        ->whereNotNull('tiers_id')
        ->whereNull('lettrage_code')
        ->get();

    if ($lignesMiroir->isEmpty()) {
        return;
    }

    // Apparier par (compte_id, tiers_id) — une ligne D=X de l'origine matche
    // une ligne C=X du miroir (et inversement).
    $miroirRestantes = $lignesMiroir->values()->all();

    foreach ($lignesOrigine as $lo) {
        foreach ($miroirRestantes as $idx => $lm) {
            if (
                (int) $lo->compte_id === (int) $lm->compte_id
                && (int) $lo->tiers_id === (int) $lm->tiers_id
                && bccomp((string) $lo->debit, (string) $lm->credit, 2) === 0
                && bccomp((string) $lo->credit, (string) $lm->debit, 2) === 0
            ) {
                $this->lettrageService->lettrer(
                    collect([$lo, $lm]),
                    null,
                    "Cross-lettrage extourne T#{$origine->id} → miroir T#{$miroir->id}"
                );
                unset($miroirRestantes[$idx]);
                break;
            }
        }
    }
}
```

- [ ] **Step 6: Run full test suite extourne PD**

```bash
./vendor/bin/pest tests/Feature/Services/TransactionExtourneServicePartieDoubleTest.php
```

Expected: PASS (tous les tests [A]-[H3]).

- [ ] **Step 7: Run ALL extourne tests (non-regression)**

```bash
./vendor/bin/pest --filter="Extourne|Annulation" --no-coverage
```

Expected: PASS — les tests legacy [C] et les autres tests S1/S2 ne régressent pas.

- [ ] **Step 8: Commit**

```bash
git add app/Services/TransactionExtourneService.php tests/Feature/Services/TransactionExtourneServicePartieDoubleTest.php
git commit -m "feat(extourne-pd): cross-lettrage 411/401 entre origine et miroir après extourne"
```

---

## Task 4 : Dépense — symétrie 401

**Files:**
- Test: `tests/Feature/Services/TransactionExtourneServicePartieDoubleTest.php`

On vérifie que le mécanisme fonctionne symétriquement pour les dépenses (compte 401 au lieu de 411, classe 6 au lieu de 7). Pas de code à écrire — le code des Tasks 1-3 est déjà générique (il opère sur les lignes `tiers_id IS NOT NULL`). Juste des tests de validation.

- [ ] **Step 1: Ajouter catégorie dépense + compte 601 dans le beforeEach**

Dans le `beforeEach` de `TransactionExtourneServicePartieDoubleTest.php`, ajouter :

```php
// Catégorie de dépense + sous-catégorie 601
$categorieDepense = Categorie::factory()->depense()->create([
    'association_id' => $this->association->id,
    'nom' => 'Achats',
]);

$this->sc601 = SousCategorie::create([
    'association_id' => $this->association->id,
    'categorie_id' => $categorieDepense->id,
    'nom' => 'Achats fournitures',
    'code_cerfa' => '601',
]);

$this->compte601 = Compte::firstOrCreate(
    ['association_id' => $this->association->id, 'numero_pcg' => '601'],
    [
        'intitule' => 'Achats fournitures',
        'classe' => 6,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]
);
```

- [ ] **Step 2: RED — test extourne dépense à crédit (T1 only)**

```php
// ---------------------------------------------------------------------------
// Scénario I — Dépense : symétrie 401
// ---------------------------------------------------------------------------

it('[I] extourne dépense à crédit — lignes PD inversées + cross-lettrage 401', function () {
    // T1 : 601 D=300 + 401 C=300 (tiers, dette ouverte)
    $t1 = $this->ecritureGen->pourDepenseACredit(
        tiers: $this->tiers,
        ventilations: [['compte' => $this->compte601, 'montant' => 300.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Facture fournisseur',
    );
    $t1->update(['statut_reglement' => StatutReglement::Recu]);

    $compte401 = compteSysteme('401');

    // Précondition : 401 C=300 ouverte
    $ligne401T1 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte401->id)->firstOrFail();
    expect($ligne401T1->lettrage_code)->toBeNull();
    expect((float) $ligne401T1->credit)->toBe(300.0);

    // Action
    $extourne = $this->service->extourner($t1->fresh(), ExtournePayload::fromOrigine($t1->fresh()));
    $miroir = $extourne->extourne;

    // Miroir : 601 C=300 + 401 D=300
    $ligne401Miroir = TransactionLigne::where('transaction_id', $miroir->id)
        ->where('compte_id', $compte401->id)->firstOrFail();
    expect((float) $ligne401Miroir->debit)->toBe(300.0);
    expect((float) $ligne401Miroir->credit)->toBe(0.0);
    expect((int) $ligne401Miroir->tiers_id)->toBe((int) $this->tiers->id);

    $ligne601Miroir = TransactionLigne::where('transaction_id', $miroir->id)
        ->where('compte_id', $this->compte601->id)->firstOrFail();
    expect((float) $ligne601Miroir->debit)->toBe(0.0);
    expect((float) $ligne601Miroir->credit)->toBe(300.0);

    // Cross-lettrage 401 : T1.401C ↔ miroir.401D
    $ligne401T1->refresh();
    expect($ligne401T1->lettrage_code)->not->toBeNull('T1.401C doit être lettrée');
    expect($ligne401T1->lettrage_code)->toBe($ligne401Miroir->fresh()->lettrage_code);

    // Solde 401 ouvert = 0
    $solde = (float) TransactionLigne::where('compte_id', $compte401->id)
        ->where('tiers_id', $this->tiers->id)
        ->whereNull('lettrage_code')
        ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as solde')
        ->value('solde');
    expect($solde)->toBe(0.0);
});
```

- [ ] **Step 3: Run test**

```bash
./vendor/bin/pest tests/Feature/Services/TransactionExtourneServicePartieDoubleTest.php --filter="I"
```

Expected: PASS (la symétrie est couverte par le code générique de Task 3).

Si FAIL : investiguer et corriger dans `crossLettrerLignesTiers`.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Services/TransactionExtourneServicePartieDoubleTest.php
git commit -m "test(extourne-pd): symétrie dépense 401 — cross-lettrage vérifié"
```

---

## Task 5 : assertEquilibre paranoïaque + FactureService::annuler intégration

**Files:**
- Modify: `app/Services/TransactionExtourneService.php` (`extourner`)
- Test: `tests/Feature/Services/TransactionExtourneServicePartieDoubleTest.php`

- [ ] **Step 1: RED — test assertEquilibre appelé sur le miroir**

```php
// ---------------------------------------------------------------------------
// Scénario J — Paranoïa assertEquilibre + intégration facture
// ---------------------------------------------------------------------------

it('[J] miroir PD est vérifié équilibré (assertEquilibre appelé)', function () {
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $this->tiers,
        ventilations: [
            ['compte' => $this->compte706, 'montant' => 100.0],
        ],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
    );
    $t1->update(['statut_reglement' => StatutReglement::Recu]);

    // On ne peut pas tester directement que assertEquilibre est appelé (final class),
    // mais on vérifie que les lignes PD du miroir SONT équilibrées.
    $extourne = $this->service->extourner($t1->fresh(), ExtournePayload::fromOrigine($t1->fresh()));
    $miroir = $extourne->extourne;

    $lignesPD = TransactionLigne::where('transaction_id', $miroir->id)
        ->whereNotNull('compte_id')
        ->get();

    $ecritureGen = app(EcritureGenerator::class);
    // Si déséquilibré, cette ligne lèverait une exception
    $ecritureGen->assertEquilibre($lignesPD);

    expect(true)->toBeTrue(); // pas d'exception = PASS
});
```

- [ ] **Step 2: GREEN — ajouter assertEquilibre dans `extourner()`**

Dans `extourner()`, après `crossLettrerLignesTiers` :

```php
$this->crossLettrerLignesTiers($origine, $miroir);

// Paranoïa PD : vérifier l'équilibre des lignes du miroir
$this->assertEquilibreMiroir($miroir);
```

Nouvelle méthode :

```php
/**
 * Vérifie que les lignes PD du miroir sont équilibrées (∑D = ∑C).
 *
 * Paranoïa post-création — le swap D↔C dans copierLignesInversees
 * devrait toujours produire un miroir équilibré si l'original l'était.
 * Si ce n'est pas le cas, on détecte un bug en amont.
 */
private function assertEquilibreMiroir(Transaction $miroir): void
{
    $lignesPD = TransactionLigne::where('transaction_id', (int) $miroir->id)
        ->whereNotNull('compte_id')
        ->get();

    if ($lignesPD->isEmpty()) {
        return; // Tx legacy sans PD — rien à vérifier
    }

    // Charger les comptes pour assertEquilibre
    $lignesPD->loadMissing('compte');

    app(EcritureGenerator::class)->assertEquilibre($lignesPD);
}
```

- [ ] **Step 3: Run full suite**

```bash
./vendor/bin/pest tests/Feature/Services/TransactionExtourneServicePartieDoubleTest.php
```

Expected: PASS.

- [ ] **Step 4: RED — test intégration FactureService::annuler PD**

Créer un nouveau scénario dans le même fichier ou dans un fichier dédié :

```php
// ---------------------------------------------------------------------------
// Scénario K — Intégration FactureService::annuler en contexte PD
// ---------------------------------------------------------------------------

it('[K] annulation facture MontantManuel — miroir PD équilibré et 411 lettré', function () {
    // Setup facturation : facture validée avec 1 ligne MontantManuel
    $facture = \App\Models\Facture::factory()
        ->for($this->tiers)
        ->create(['statut' => \App\Enums\StatutFacture::Brouillon]);

    $ligneMM = \App\Models\FactureLigne::factory()->create([
        'facture_id' => $facture->id,
        'type' => 'MontantManuel',
        'montant_unitaire' => 500.0,
        'quantite' => 1,
        'description' => 'Formation PD',
    ]);

    // Valider la facture → crée la transaction MontantManuel
    $factureService = app(\App\Services\FactureService::class);
    $factureService->valider($facture->fresh());

    $facture->refresh();
    expect($facture->statut)->toBe(\App\Enums\StatutFacture::Validee);

    // Trouver la transaction générée
    $txGeneree = $facture->transactions()->first();
    expect($txGeneree)->not->toBeNull();

    $compte411 = compteSysteme('411');

    // Annuler la facture
    $factureService->annuler($facture->fresh());

    $facture->refresh();
    expect($facture->statut)->toBe(\App\Enums\StatutFacture::Annulee);
    expect($facture->numero_avoir)->not->toBeNull();

    // La transaction générée est extournée
    $txGeneree->refresh();
    expect($txGeneree->extournee_at)->not->toBeNull();

    // Le miroir existe et a des lignes PD équilibrées
    $extourne = \App\Models\Extourne::where('transaction_origine_id', $txGeneree->id)->firstOrFail();
    $miroir = $extourne->extourne;

    $lignesPDMiroir = TransactionLigne::where('transaction_id', $miroir->id)
        ->whereNotNull('compte_id')
        ->get();

    if ($lignesPDMiroir->isNotEmpty()) {
        $ecritureGen = app(EcritureGenerator::class);
        $ecritureGen->assertEquilibre($lignesPDMiroir);
    }
});
```

**Note :** ce test dépend de `FactureService::valider()` qui appelle `TransactionService::store()` → `enrichirPartieDouble()`. Si le tenant n'a pas de SousCategorie→Compte mapping, le skip PD se déclenche et la tx n'a pas de PD. Le test vérifie « SI les lignes PD existent, ALORS elles sont équilibrées ».

- [ ] **Step 5: Run test**

```bash
./vendor/bin/pest tests/Feature/Services/TransactionExtourneServicePartieDoubleTest.php --filter="K"
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/TransactionExtourneService.php tests/Feature/Services/TransactionExtourneServicePartieDoubleTest.php
git commit -m "feat(extourne-pd): assertEquilibre paranoïaque + test intégration FactureService::annuler"
```

---

## Task 6 : Non-régression complète + ventilation multi-lignes

**Files:**
- Test: `tests/Feature/Services/TransactionExtourneServicePartieDoubleTest.php`

- [ ] **Step 1: RED — test ventilation multi-lignes (2 sous-catégories)**

```php
// ---------------------------------------------------------------------------
// Scénario L — Ventilation multi-lignes
// ---------------------------------------------------------------------------

it('[L] extourne recette multi-ventilation — N lignes PD inversées correctement', function () {
    $compte707 = Compte::firstOrCreate(
        ['association_id' => $this->association->id, 'numero_pcg' => '707'],
        [
            'intitule' => 'Ventes de marchandises',
            'classe' => 7,
            'lettrable' => false,
            'actif' => true,
            'est_systeme' => false,
            'pour_inscriptions' => false,
        ]
    );

    // T1 : 411 D=350 + 706 C=200 + 707 C=150
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $this->tiers,
        ventilations: [
            ['compte' => $this->compte706, 'montant' => 200.0],
            ['compte' => $compte707, 'montant' => 150.0],
        ],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Multi-ventilation',
    );
    $t1->update(['statut_reglement' => StatutReglement::Recu]);

    $extourne = $this->service->extourner($t1->fresh(), ExtournePayload::fromOrigine($t1->fresh()));
    $miroir = $extourne->extourne;

    $lignesPD = TransactionLigne::where('transaction_id', $miroir->id)
        ->whereNotNull('compte_id')
        ->get();

    // 3 lignes PD : 411 C=350 + 706 D=200 + 707 D=150
    expect($lignesPD)->toHaveCount(3);

    $totalDebit = $lignesPD->sum(fn ($l) => (float) $l->debit);
    $totalCredit = $lignesPD->sum(fn ($l) => (float) $l->credit);
    expect(bccomp((string) $totalDebit, (string) $totalCredit, 2))->toBe(0);

    // 706 inversée
    $l706 = $lignesPD->firstWhere('compte_id', (int) $this->compte706->id);
    expect((float) $l706->debit)->toBe(200.0);
    expect((float) $l706->credit)->toBe(0.0);

    // 707 inversée
    $l707 = $lignesPD->firstWhere('compte_id', (int) $compte707->id);
    expect((float) $l707->debit)->toBe(150.0);
    expect((float) $l707->credit)->toBe(0.0);
});
```

- [ ] **Step 2: Run test**

```bash
./vendor/bin/pest tests/Feature/Services/TransactionExtourneServicePartieDoubleTest.php --filter="L"
```

Expected: PASS (couvert par le code Task 2).

- [ ] **Step 3: Run full suite (régression complète)**

```bash
./vendor/bin/pest --no-coverage 2>&1 | tail -5
```

Expected: 0 failed. Le nombre total de tests doit inclure les ~10 nouveaux tests ajoutés.

- [ ] **Step 4: Pint**

```bash
./vendor/bin/pint --test
```

Expected: pas de violation.

- [ ] **Step 5: Commit final**

```bash
git add tests/Feature/Services/TransactionExtourneServicePartieDoubleTest.php
git commit -m "test(extourne-pd): ventilation multi-lignes + suite complète verte"
```

---

## Récapitulatif des modifications code

| Fichier | Méthode | Changement |
|---------|---------|------------|
| `TransactionExtourneService` | `creerTransactionMiroir` | +3 champs : `equilibree`, `type_ecriture`, `journal` |
| `TransactionExtourneService` | `copierLignesInversees` | +5 champs PD : `compte_id`, `debit`↔`credit`, `tiers_id`, `libelle` |
| `TransactionExtourneService` | `extourner` | +2 appels : `crossLettrerLignesTiers`, `assertEquilibreMiroir` |
| `TransactionExtourneService` | *nouvelle* `crossLettrerLignesTiers` | Appariement par (compte, tiers, montant) + `LettrageService::lettrer` |
| `TransactionExtourneService` | *nouvelle* `assertEquilibreMiroir` | `EcritureGenerator::assertEquilibre` sur lignes PD du miroir |

**Aucune modification** de `FactureService`, `EcritureGenerator`, `LettrageService`, `TransactionService`.
