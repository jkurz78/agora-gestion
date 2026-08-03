# Chantier 4 — Statut de règlement dérivé du grand livre (411/401) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Le `statut_reglement` cesse d'être un enum posé à la main et devient dérivé du grand livre partie double (source de vérité unique), symétrique recette (411) / dépense (401).

**Architecture:** Un service `EtatReglementResolver` (lecture seule, déterministe) dérive le statut en marchant le chaînage de lettrage `411/401 → 5112/530 → 512X` (multi-hop, robuste aux structures lumpé ET T2 séparée, fallback colonne stockée pour legacy/HelloAsso). La colonne reste un **miroir** recalculé par `syncer()` à chaque transition. Migration **additive** (ajout de la seule valeur `en_main`, zéro rename). Une commande de réconciliation garde la cohérence miroir↔ledger.

**Tech Stack:** Laravel 11, PHP 8 backed enum, Pest, MySQL enum natif, tests sqlite `:memory:`.

**Spec :** [docs/specs/2026-06-04-statut-reglement-derive-grand-livre.md](../docs/specs/2026-06-04-statut-reglement-derive-grand-livre.md)

**⚠️ Garde-fou DB (incident clone-wipe 2026-06-02) :** tests = sqlite `:memory:` (phpunit.xml force `DB_CONNECTION=sqlite`). JAMAIS `migrate:fresh`, JAMAIS `sail test` non borné, JAMAIS de commande DB destructrice. Vérifier l'absence de `bootstrap/cache/config.php` figé sur mysql (`config:clear` si présent).

**Convention de lancement des tests :** `./vendor/bin/sail test --filter=<NomTest>` (sqlite mémoire). Si Sail indisponible, `php artisan test --filter=<NomTest>`.

---

## File Structure

| Fichier | Responsabilité | Action |
|---|---|---|
| `app/Enums/StatutReglement.php` | Ajout case `EnMain`, label direction-aware, helpers | Modify |
| `app/Services/Compta/EtatReglementResolver.php` | `resolve()` (dérivation pure) + `syncer()` (miroir gardé) | **Create** |
| `database/migrations/2026_06_04_120000_add_en_main_to_statut_reglement.php` | `ALTER ENUM ADD 'en_main'` (additif) | **Create** |
| `database/migrations/2026_06_04_120100_reclasser_statuts_en_main.php` | Data-migration one-shot (reclasse `recu` en-main → `en_main`) | **Create** |
| `app/Services/TransactionService.php` | `syncer($t1)` après update (création + réversion) | Modify |
| `app/Services/RapprochementBancaireService.php` | `syncer($model)` aux 3 points de pointage (remplace statut manuel) | Modify |
| `app/Services/ReglementOperationService.php` | `syncer($t1)` dans marquerRecu/marquerPaye (remplace `= Recu`) | Modify |
| `app/Console/Commands/ReconcilierStatutsCommand.php` | Commande `compta:reconcilier-statuts` (rempart anti-dérive) | **Create** |
| `tests/Feature/Services/Compta/EtatReglementResolverTest.php` | Matrice de dérivation (recette+dépense, lumpé+séparé, réversion) | **Create** |
| `tests/Feature/Console/ReconcilierStatutsCommandTest.php` | Réconciliation colonne===dérivé | **Create** |

---

## Task 1 : Enum `StatutReglement` — case `EnMain` + label direction-aware + helpers

**Files:**
- Modify: `app/Enums/StatutReglement.php`
- Test: `tests/Unit/Enums/StatutReglementTest.php` (Create)

- [ ] **Step 1.1 : Écrire le test enum (RED)**

Create `tests/Unit/Enums/StatutReglementTest.php` :

```php
<?php

declare(strict_types=1);

use App\Enums\Sens;
use App\Enums\StatutReglement;

it('expose la nouvelle valeur en_main', function () {
    expect(StatutReglement::EnMain->value)->toBe('en_main');
});

it('label direction-aware : ouvert = Dû dans les deux sens', function () {
    expect(StatutReglement::EnAttente->label(Sens::Recette))->toBe('Dû');
    expect(StatutReglement::EnAttente->label(Sens::Depense))->toBe('Dû');
});

it('label direction-aware : dénoué = Remis (recette) / Réglé (dépense)', function () {
    expect(StatutReglement::Recu->label(Sens::Recette))->toBe('Remis');
    expect(StatutReglement::Recu->label(Sens::Depense))->toBe('Réglé');
});

it('label : en main = À remettre, pointé = Pointé', function () {
    expect(StatutReglement::EnMain->label())->toBe('À remettre');
    expect(StatutReglement::Pointe->label(Sens::Recette))->toBe('Pointé');
    expect(StatutReglement::Pointe->label(Sens::Depense))->toBe('Pointé');
});

it('helpers de position : estOuvert / estEnMain / estDenoue', function () {
    expect(StatutReglement::EnAttente->estOuvert())->toBeTrue();
    expect(StatutReglement::EnMain->estEnMain())->toBeTrue();
    expect(StatutReglement::Recu->estDenoue())->toBeTrue();
    expect(StatutReglement::Pointe->estOuvert())->toBeFalse();
});

it('isEncaisse inchangé : tout sauf EnAttente est encaissé (EnMain inclus)', function () {
    expect(StatutReglement::EnAttente->isEncaisse())->toBeFalse();
    expect(StatutReglement::EnMain->isEncaisse())->toBeTrue();
    expect(StatutReglement::Recu->isEncaisse())->toBeTrue();
    expect(StatutReglement::Pointe->isEncaisse())->toBeTrue();
});
```

- [ ] **Step 1.2 : Lancer le test → échec attendu**

Run: `./vendor/bin/sail test --filter=StatutReglementTest`
Expected: FAIL (`EnMain` n'existe pas, `label()` n'accepte pas d'argument).

- [ ] **Step 1.3 : Implémenter l'enum**

Replace the body of `app/Enums/StatutReglement.php` :

```php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Position d'un règlement dans le grand livre partie double (chantier 4).
 *
 * Les noms de cases sont conservés (zéro rename — décision planning) ; ils
 * décrivent une position ledger, neutre au sens :
 *   - EnAttente = « ouvert / dû »   : 411/401 non lettré.
 *   - EnMain    = « à remettre »     : portage 5112/530 en main, pas de 512X.
 *   - Recu      = « dénoué »          : 512X présent (recette : remis ; dépense : réglé).
 *   - Pointe    = « pointé »          : la transaction porteuse du 512X est rapprochée.
 *
 * Le statut est dérivé du ledger par EtatReglementResolver ; cet enum n'est
 * plus posé à la main (la colonne est un miroir recalculé).
 */
enum StatutReglement: string
{
    case EnAttente = 'en_attente';
    case EnMain = 'en_main';
    case Recu = 'recu';
    case Pointe = 'pointe';

    /**
     * Libellé utilisateur, direction-aware (sans jargon comptable).
     *
     * Les extrémités (EnAttente, Pointe) sont identiques dans les deux sens ;
     * seul l'état « dénoué » diffère (Remis pour une recette, Réglé pour une dépense).
     */
    public function label(?Sens $sens = null): string
    {
        return match ($this) {
            self::EnAttente => 'Dû',
            self::EnMain => 'À remettre',
            self::Recu => $sens === Sens::Depense ? 'Réglé' : 'Remis',
            self::Pointe => 'Pointé',
        };
    }

    public function estOuvert(): bool
    {
        return $this === self::EnAttente;
    }

    public function estEnMain(): bool
    {
        return $this === self::EnMain;
    }

    public function estDenoue(): bool
    {
        return $this === self::Recu;
    }

    public function isEncaisse(): bool
    {
        return $this !== self::EnAttente;
    }
}
```

- [ ] **Step 1.4 : Lancer le test → succès**

Run: `./vendor/bin/sail test --filter=StatutReglementTest`
Expected: PASS.

- [ ] **Step 1.5 : Recenser et corriger les assertions de label() existantes**

Le label de `EnAttente`/`Recu`/`Pointe` change (`En attente`→`Dû`, `Reçu`→`Remis`, `Pointé` inchangé). Trouver les sites :

Run: `grep -rn "statut_reglement->label\|StatutReglement::.*->label\|'En attente'\|'Reçu'" app/ tests/`

Pour chaque assertion de test sur l'ancien libellé (`'En attente'`, `'Reçu'`), mettre à jour vers le nouveau (`'Dû'`, `'Remis'`/`'Réglé'`). Pour les sites d'affichage `app/` qui appellent `->label()` sur un statut **de recette ou dépense**, passer le `Sens` (voir Task 8). Les sites neutres (sans contexte de sens) gardent `->label()` (défaut → 'Remis' pour `Recu`).

- [ ] **Step 1.6 : Lancer la suite ciblée enum + sites label**

Run: `./vendor/bin/sail test --filter=StatutReglement`
Expected: PASS (0 failed).

- [ ] **Step 1.7 : Commit**

```bash
git add app/Enums/StatutReglement.php tests/Unit/Enums/StatutReglementTest.php
git commit -m "feat(v5): chantier 4 — enum StatutReglement + case EnMain + label direction-aware"
```

---

## Task 2 : Migration additive `ALTER ENUM ADD 'en_main'`

**Files:**
- Create: `database/migrations/2026_06_04_120000_add_en_main_to_statut_reglement.php`

- [ ] **Step 2.1 : Écrire la migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chantier 4 — ajoute la valeur 'en_main' à l'enum statut_reglement (additif).
 *
 * Migration additive : aucune valeur existante n'est renommée, donc aucun SQL
 * brut ni filtre n'est cassé. La data-migration 2026_06_04_120100 reclasse
 * ensuite les 'recu' encore en-main vers 'en_main'.
 *
 * sqlite (tests) ignore les contraintes enum → MODIFY conditionné à MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return; // sqlite : pas de contrainte enum, rien à faire
        }

        DB::statement(
            "ALTER TABLE transactions MODIFY COLUMN statut_reglement "
            ."ENUM('en_attente', 'recu', 'pointe', 'en_main') NOT NULL DEFAULT 'en_attente'"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Pré-condition au down : aucune ligne ne doit rester 'en_main'
        // (sinon la valeur tomberait hors enum). On les rebascule en 'recu'.
        DB::table('transactions')->where('statut_reglement', 'en_main')->update(['statut_reglement' => 'recu']);

        DB::statement(
            "ALTER TABLE transactions MODIFY COLUMN statut_reglement "
            ."ENUM('en_attente', 'recu', 'pointe') NOT NULL DEFAULT 'en_attente'"
        );
    }
};
```

- [ ] **Step 2.2 : Vérifier que la suite passe toujours (sqlite ignore l'enum)**

Run: `./vendor/bin/sail test --filter=StatutReglement`
Expected: PASS (la migration est un no-op sous sqlite).

- [ ] **Step 2.3 : Commit**

```bash
git add database/migrations/2026_06_04_120000_add_en_main_to_statut_reglement.php
git commit -m "feat(v5): chantier 4 — migration additive ENUM statut_reglement + en_main"
```

---

## Task 3 : `EtatReglementResolver::resolve()` — dérivation pure (le cœur)

**Files:**
- Create: `app/Services/Compta/EtatReglementResolver.php`
- Test: `tests/Feature/Services/Compta/EtatReglementResolverTest.php` (Create)

> **Fixtures** : réutiliser le trait `Tests\Support\CreatesPartieDoubleContext` (`$this->setupPartieDoubleContext()` pose le flag PD, `$this->association`, `$this->user`, `$this->compteBancaire`, `$this->sc706`, helper global `compteSysteme('411')`). Voir `tests/Feature/Services/RecetteComptantT2SepareeTest.php` pour le modèle exact.

- [ ] **Step 3.1 : Écrire les tests de dérivation recette (RED)**

Create `tests/Feature/Services/Compta/EtatReglementResolverTest.php` :

```php
<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Services\Compta\EtatReglementResolver;
use App\Services\TransactionService;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

beforeEach(function () {
    $this->setupPartieDoubleContext();
    $this->service = app(TransactionService::class);
    $this->resolver = app(EtatReglementResolver::class);
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
});

function recetteData(object $ctx, ?string $mode): array
{
    return [
        'data' => [
            'type' => TypeTransaction::Recette->value,
            'date' => '2025-10-15',
            'libelle' => 'Recette test',
            'montant_total' => '100.00',
            'mode_paiement' => $mode,
            'tiers_id' => $ctx->tiers->id,
            'compte_id' => $mode === null ? null : $ctx->compteBancaire->id,
        ],
        'lignes' => [[
            'sous_categorie_id' => $ctx->sc706->id,
            'montant' => '100.00',
            'operation_id' => null,
            'seance' => null,
            'notes' => null,
        ]],
    ];
}

it('recette créance (411 non lettré) → EnAttente (ouvert)', function () {
    ['data' => $data, 'lignes' => $lignes] = recetteData($this, null);
    $t1 = $this->service->create($data, $lignes);

    expect($this->resolver->resolve($t1->fresh()))->toBe(StatutReglement::EnAttente);
});

it('recette chèque comptant (411 lettré, 5112 non remis) → EnMain (à remettre)', function () {
    ['data' => $data, 'lignes' => $lignes] = recetteData($this, ModePaiement::Cheque->value);
    $t1 = $this->service->create($data, $lignes);

    expect($this->resolver->resolve($t1->fresh()))->toBe(StatutReglement::EnMain);
});

it('recette virement comptant (411 lettré, 512X direct non rapproché) → Recu (dénoué)', function () {
    ['data' => $data, 'lignes' => $lignes] = recetteData($this, ModePaiement::Virement->value);
    $t1 = $this->service->create($data, $lignes);

    expect($this->resolver->resolve($t1->fresh()))->toBe(StatutReglement::Recu);
});

it('fallback : transaction sans ligne PD (legacy) → renvoie la colonne stockée', function () {
    $legacy = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'type' => TypeTransaction::Recette->value,
        'statut_reglement' => StatutReglement::Recu->value,
        'compte_id' => $this->compteBancaire->id,
    ]);

    expect($this->resolver->resolve($legacy))->toBe(StatutReglement::Recu);
});
```

- [ ] **Step 3.2 : Lancer → échec attendu**

Run: `./vendor/bin/sail test --filter=EtatReglementResolverTest`
Expected: FAIL (classe `EtatReglementResolver` absente).

- [ ] **Step 3.3 : Implémenter le resolver (resolve uniquement)**

Create `app/Services/Compta/EtatReglementResolver.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\Enums\Sens;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\Compte;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\ReglementOperationService;

/**
 * Dérive le statut de règlement d'une transaction depuis le grand livre PD
 * (chantier 4). Lecture seule, déterministe, source de vérité unique.
 *
 * Marche le chaînage de lettrage tiers → trésorerie (multi-hop) :
 *   - chèque recette :   411 → 5112 (encaissement) → 512X (remise)
 *   - espèces recette :  411 → 530  → 512X
 *   - virement/CB :      411 → 512X (direct)
 *   - dépense :          401 → 512X (règlement)
 *
 * Robuste aux deux structures (encaissement lumpé sur la T1 OU T2 séparée) et
 * retombe sur la colonne stockée pour les tx sans lignes PD (legacy/HelloAsso).
 */
final class EtatReglementResolver
{
    public function __construct(
        private readonly ReglementOperationService $reglementService,
    ) {}

    public function resolve(Transaction $t1): StatutReglement
    {
        $sens = match ($t1->type) {
            TypeTransaction::Recette => Sens::Recette,
            TypeTransaction::Depense => Sens::Depense,
            default => null,
        };

        if ($sens === null) {
            return $t1->statut_reglement; // type hors recette/dépense → inchangé
        }

        $numeroTiers = $sens === Sens::Recette ? '411' : '401';
        $compteTiers = Compte::ofNumero($numeroTiers);

        if ($compteTiers === null) {
            return $t1->statut_reglement; // tenant sans schéma PD
        }

        $ligneTiers = TransactionLigne::where('transaction_id', (int) $t1->id)
            ->where('compte_id', (int) $compteTiers->id)
            ->first();

        if ($ligneTiers === null) {
            return $t1->statut_reglement; // legacy/HelloAsso : pas de ligne PD
        }

        // Étape « ouvert » : ligne de tiers non lettrée.
        if ($ligneTiers->lettrage_code === null) {
            return StatutReglement::EnAttente;
        }

        // Lettré → localiser la transaction qui porte la trésorerie.
        // T2 séparée si elle existe, sinon la T1 elle-même (encaissement lumpé).
        $t2 = $sens === Sens::Recette
            ? $this->reglementService->trouverEncaissementT2($t1, $compteTiers)
            : $this->reglementService->trouverReglementT2($t1, $compteTiers);

        $txPortage = $t2 ?? $t1;

        // Ligne de trésorerie (classe 5) de la transaction portage.
        $ligneTresorerie = TransactionLigne::with('compte')
            ->where('transaction_id', (int) $txPortage->id)
            ->get()
            ->first(fn (TransactionLigne $l) => $l->compte !== null && $l->compte->classe === 5);

        if ($ligneTresorerie === null) {
            return $t1->statut_reglement; // structure inattendue → fallback prudent
        }

        return $this->statutDepuisTresorerie($ligneTresorerie, $txPortage);
    }

    /**
     * Statue sur le terme du chaînage à partir de la ligne de trésorerie atteinte.
     */
    private function statutDepuisTresorerie(TransactionLigne $ligneTresorerie, Transaction $txPortage): StatutReglement
    {
        $compte = $ligneTresorerie->compte;

        // 512X (banque physique) atteint → dénoué, pointé si la tx porteuse est rapprochée.
        if ($compte !== null && $compte->estBancaire()) {
            return $txPortage->rapprochement_id !== null
                ? StatutReglement::Pointe
                : StatutReglement::Recu;
        }

        // 5112 / 530 (en main) — non déposé → à remettre.
        if ($ligneTresorerie->lettrage_code === null) {
            return StatutReglement::EnMain;
        }

        // Remis : suivre vers la T4 (autre ligne 5112/530 partageant le code).
        $ligneT4 = TransactionLigne::where('lettrage_code', $ligneTresorerie->lettrage_code)
            ->where('compte_id', (int) $ligneTresorerie->compte_id)
            ->where('transaction_id', '!=', (int) $ligneTresorerie->transaction_id)
            ->first();

        if ($ligneT4 === null) {
            return StatutReglement::EnMain; // remise introuvable → dégradation prudente
        }

        $t4 = Transaction::find($ligneT4->transaction_id);

        if ($t4 === null) {
            return StatutReglement::EnMain;
        }

        $ligne512X = TransactionLigne::with('compte')
            ->where('transaction_id', (int) $t4->id)
            ->get()
            ->first(fn (TransactionLigne $l) => $l->compte !== null && $l->compte->estBancaire());

        if ($ligne512X === null) {
            return StatutReglement::Recu; // remis mais 512X introuvable → dénoué
        }

        return $t4->rapprochement_id !== null
            ? StatutReglement::Pointe
            : StatutReglement::Recu;
    }
}
```

- [ ] **Step 3.4 : Lancer → succès recette**

Run: `./vendor/bin/sail test --filter=EtatReglementResolverTest`
Expected: PASS (4 tests recette).

- [ ] **Step 3.5 : Ajouter les tests dépense + remise chèque + pointé (RED)**

Append to `tests/Feature/Services/Compta/EtatReglementResolverTest.php` :

```php
function depenseData(object $ctx, ?string $mode): array
{
    return [
        'data' => [
            'type' => TypeTransaction::Depense->value,
            'date' => '2025-10-15',
            'libelle' => 'Dépense test',
            'montant_total' => '50.00',
            'mode_paiement' => $mode,
            'tiers_id' => $ctx->tiers->id,
            'compte_id' => $mode === null ? null : $ctx->compteBancaire->id,
        ],
        'lignes' => [[
            'sous_categorie_id' => $ctx->sc606->id,
            'montant' => '50.00',
            'operation_id' => null,
            'seance' => null,
            'notes' => null,
        ]],
    ];
}

it('dépense dette (401 non lettré) → EnAttente (dû)', function () {
    ['data' => $data, 'lignes' => $lignes] = depenseData($this, null);
    $t1 = $this->service->create($data, $lignes);

    expect($this->resolver->resolve($t1->fresh()))->toBe(StatutReglement::EnAttente);
});

it('dépense réglée par virement (401 lettré, 512X non rapproché) → Recu (réglé)', function () {
    ['data' => $data, 'lignes' => $lignes] = depenseData($this, ModePaiement::Virement->value);
    $t1 = $this->service->create($data, $lignes);

    expect($this->resolver->resolve($t1->fresh()))->toBe(StatutReglement::Recu);
});

it('recette chèque remis en banque (5112 lettré vers T4 512X non rapproché) → Recu (remis)', function () {
    ['data' => $data, 'lignes' => $lignes] = recetteData($this, ModePaiement::Cheque->value);
    $t1 = $this->service->create($data, $lignes);

    // Comptabiliser une remise → pose le 512X sur T4, lettre le 5112 de T2.
    $remise = \App\Models\RemiseBancaire::create([
        'association_id' => $this->association->id,
        'numero' => 2001,
        'date' => '2025-10-20',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $this->compteBancaire->id,
        'libelle' => 'Remise resolver test',
        'saisi_par' => $this->user->id,
    ]);
    app(\App\Services\RemiseBancaireService::class)->comptabiliser($remise, [$t1->id]);

    expect($this->resolver->resolve($t1->fresh()))->toBe(StatutReglement::Recu);
});
```

> Note : si `$ctx->sc606` n'existe pas dans le trait, le créer dans le `beforeEach` (sous-catégorie classe 6, miroir de `sc706`). Vérifier le contenu de `Tests\Support\CreatesPartieDoubleContext` et l'étendre si besoin (ajouter `$this->sc606` à la création du contexte).

- [ ] **Step 3.6 : Lancer → succès**

Run: `./vendor/bin/sail test --filter=EtatReglementResolverTest`
Expected: PASS (tous scénarios).

- [ ] **Step 3.7 : Commit**

```bash
git add app/Services/Compta/EtatReglementResolver.php tests/Feature/Services/Compta/EtatReglementResolverTest.php
git commit -m "feat(v5): chantier 4 — EtatReglementResolver::resolve (dérivation multi-hop 411/401)"
```

---

## Task 4 : `EtatReglementResolver::syncer()` — miroir gardé par le flag PD

**Files:**
- Modify: `app/Services/Compta/EtatReglementResolver.php`
- Test: `tests/Feature/Services/Compta/EtatReglementResolverTest.php`

- [ ] **Step 4.1 : Écrire les tests syncer (RED)**

Append to the test file :

```php
it('syncer persiste le statut dérivé quand il diffère du miroir', function () {
    ['data' => $data, 'lignes' => $lignes] = recetteData($this, ModePaiement::Cheque->value);
    $t1 = $this->service->create($data, $lignes);

    // Forcer un miroir périmé (chèque en main mais colonne = Recu).
    $t1->forceFill(['statut_reglement' => StatutReglement::Recu->value])->save();

    $this->resolver->syncer($t1);

    expect($t1->fresh()->statut_reglement)->toBe(StatutReglement::EnMain);
});

it('syncer est idempotent (deux appels = même résultat, pas de drift)', function () {
    ['data' => $data, 'lignes' => $lignes] = recetteData($this, ModePaiement::Cheque->value);
    $t1 = $this->service->create($data, $lignes);

    $this->resolver->syncer($t1);
    $premier = $t1->fresh()->statut_reglement;
    $this->resolver->syncer($t1->fresh());
    $second = $t1->fresh()->statut_reglement;

    expect($second)->toBe($premier);
});

it('syncer est un no-op en mode legacy (use_partie_double=false)', function () {
    config()->set('compta.use_partie_double', false);

    $t1 = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'type' => TypeTransaction::Recette->value,
        'statut_reglement' => StatutReglement::Recu->value,
    ]);

    $this->resolver->syncer($t1);

    expect($t1->fresh()->statut_reglement)->toBe(StatutReglement::Recu);
});
```

- [ ] **Step 4.2 : Lancer → échec attendu**

Run: `./vendor/bin/sail test --filter=EtatReglementResolverTest`
Expected: FAIL (`syncer` n'existe pas).

- [ ] **Step 4.3 : Implémenter `syncer()`**

Add to `app/Services/Compta/EtatReglementResolver.php`, after `resolve()` :

```php
    /**
     * Recalcule et persiste le statut miroir d'une T1 depuis le ledger.
     *
     * No-op en mode legacy (use_partie_double=false) : la colonne reste gérée
     * à l'ancienne. Idempotent : ne sauvegarde que si la valeur dérivée diffère.
     */
    public function syncer(Transaction $t1): void
    {
        if (! config('compta.use_partie_double')) {
            return;
        }

        $derive = $this->resolve($t1);

        if ($t1->statut_reglement !== $derive) {
            $t1->statut_reglement = $derive;
            $t1->save();
        }
    }
```

- [ ] **Step 4.4 : Lancer → succès**

Run: `./vendor/bin/sail test --filter=EtatReglementResolverTest`
Expected: PASS.

- [ ] **Step 4.5 : Commit**

```bash
git add app/Services/Compta/EtatReglementResolver.php tests/Feature/Services/Compta/EtatReglementResolverTest.php
git commit -m "feat(v5): chantier 4 — EtatReglementResolver::syncer (miroir gardé par flag PD)"
```

---

## Task 5 : Câbler `syncer` dans `TransactionService` (création + réversion = corrige le bug)

**Files:**
- Modify: `app/Services/TransactionService.php`
- Test: `tests/Feature/Services/TransactionServiceStatutDeriveTest.php` (Create)

- [ ] **Step 5.1 : Écrire le test du bug réversion (RED)**

Create `tests/Feature/Services/TransactionServiceStatutDeriveTest.php` :

```php
<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\Tiers;
use App\Services\TransactionService;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

beforeEach(function () {
    $this->setupPartieDoubleContext();
    $this->service = app(TransactionService::class);
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
});

it('réversion recette reçue→non-reçue : le statut dérivé repasse EnAttente (bug recette 2a)', function () {
    $data = [
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Recette réversible',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ];
    $lignes = [[
        'sous_categorie_id' => $this->sc706->id,
        'montant' => '100.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]];

    $t1 = $this->service->create($data, $lignes);
    expect($t1->fresh()->statut_reglement)->not()->toBe(StatutReglement::EnAttente);

    // Réversion : repasser en mode null (non reçue).
    $this->service->update($t1, [...$data, 'mode_paiement' => null, 'compte_id' => null], [[
        'id' => null,
        'sous_categorie_id' => $this->sc706->id,
        'montant' => '100.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]]);

    expect($t1->fresh()->statut_reglement)->toBe(StatutReglement::EnAttente);
});

it('réversion dépense réglée→non-payée : le statut dérivé repasse EnAttente (symétrie 401)', function () {
    $data = [
        'type' => TypeTransaction::Depense->value,
        'date' => '2025-10-15',
        'libelle' => 'Dépense réversible',
        'montant_total' => '50.00',
        'mode_paiement' => ModePaiement::Virement->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ];
    $lignes = [[
        'id' => null,
        'sous_categorie_id' => $this->sc606->id,
        'montant' => '50.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]];

    $t1 = $this->service->create($data, [array_diff_key($lignes[0], ['id' => null])]);
    expect($t1->fresh()->statut_reglement)->not()->toBe(StatutReglement::EnAttente);

    $this->service->update($t1, [...$data, 'mode_paiement' => null, 'compte_id' => null], $lignes);

    expect($t1->fresh()->statut_reglement)->toBe(StatutReglement::EnAttente);
});
```

> Prérequis : `$this->sc606` (sous-catégorie classe 6) doit exister dans `CreatesPartieDoubleContext` — l'ajouter au trait si absent (miroir de `$this->sc706`).

- [ ] **Step 5.2 : Lancer → échec attendu**

Run: `./vendor/bin/sail test --filter=TransactionServiceStatutDeriveTest`
Expected: FAIL (statut reste `Recu`/`EnMain` après réversion — le bug).

- [ ] **Step 5.3 : Injecter le resolver + câbler `syncer` en fin d'`update()`**

Dans `app/Services/TransactionService.php` :

1. Ajouter l'import et la dépendance constructeur. Repérer le constructeur existant et y ajouter `private readonly EtatReglementResolver $etatReglementResolver`. Ajouter `use App\Services\Compta\EtatReglementResolver;` en tête.

2. À la fin de la closure de `update()` (juste avant `return $transaction->fresh();`, ligne ~401), insérer :

```php
            // Chantier 4 — recalcul du statut miroir depuis le ledger (couvre la
            // réversion reçu→non-reçu : le 411/401 redevient non lettré → EnAttente).
            $this->etatReglementResolver->syncer($transaction);

            return $transaction->fresh();
```

3. De même, à la fin de `create()` (après l'enrichissement PD), ajouter `$this->etatReglementResolver->syncer($transaction);` avant le retour. Repérer le point de retour de `create()` et insérer le sync sur la transaction fraîchement enrichie.

- [ ] **Step 5.4 : Lancer → succès du test réversion**

Run: `./vendor/bin/sail test --filter=TransactionServiceStatutDeriveTest`
Expected: PASS.

- [ ] **Step 5.5 : Non-régression du service de transaction**

Run: `./vendor/bin/sail test --filter=TransactionService`
Expected: PASS (0 failed).

- [ ] **Step 5.6 : Commit**

```bash
git add app/Services/TransactionService.php tests/Feature/Services/TransactionServiceStatutDeriveTest.php
git commit -m "feat(v5): chantier 4 — syncer statut sur create/update (corrige bug réversion)"
```

---

## Task 6 : Câbler `syncer` dans `RapprochementBancaireService` (pointage/dépointage) + `RemiseBancaireService::comptabiliser`

**Files:**
- Modify: `app/Services/RapprochementBancaireService.php`
- Modify: `app/Services/RemiseBancaireService.php`
- Test: `tests/Feature/Services/RapprochementStatutDeriveTest.php` (Create)

- [ ] **Step 6.1 : Écrire le test pointage→Pointe / dépointage→Recu (RED)**

Create `tests/Feature/Services/RapprochementStatutDeriveTest.php`. S'inspirer de `tests/Feature/Services/RapprochementBancaireServicePartieDoubleTest.php` pour le montage d'un rapprochement. Scénario : recette virement comptant (512X direct) → `toggleTransaction(recette)` pour pointer → statut `Pointe` ; re-toggle pour dépointer → statut `Recu` (512X présent, non rapproché).

```php
<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\RapprochementBancaire;
use App\Models\Tiers;
use App\Services\RapprochementBancaireService;
use App\Services\TransactionService;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

beforeEach(function () {
    $this->setupPartieDoubleContext();
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
});

it('pointage d\'une recette virement → statut dérivé Pointe ; dépointage → Recu', function () {
    $t1 = app(TransactionService::class)->create([
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Virement à pointer',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Virement->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ], [[
        'sous_categorie_id' => $this->sc706->id,
        'montant' => '100.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]]);

    $rappro = RapprochementBancaire::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteBancaire->id,
    ]);

    $service = app(RapprochementBancaireService::class);

    $service->toggleTransaction($rappro, 'recette', (int) $t1->id);
    expect($t1->fresh()->statut_reglement)->toBe(StatutReglement::Pointe);

    $service->toggleTransaction($rappro, 'recette', (int) $t1->id);
    expect($t1->fresh()->statut_reglement)->toBe(StatutReglement::Recu);
});
```

> Vérifier la signature exacte de la factory `RapprochementBancaire` et les champs requis (`solde_debut`, `solde_fin`, `date_fin`, `statut`) dans `tests/Feature/Services/RapprochementBancaireServicePartieDoubleTest.php` et adapter.

- [ ] **Step 6.2 : Lancer → échec ou faux-vert ?**

Run: `./vendor/bin/sail test --filter=RapprochementStatutDeriveTest`
Expected: à ce stade, le pointage pose déjà `Pointe` à la main (donc ce volet peut passer), mais le dépointage pose `EnAttente`/`Recu` selon `remise_id` — la dérivation peut diverger. L'objectif du câblage est de rendre la dérivation **autoritaire** et de supprimer les assignations manuelles.

- [ ] **Step 6.3 : Injecter le resolver + remplacer les assignations manuelles par `syncer`**

Dans `app/Services/RapprochementBancaireService.php` :

1. Ajouter `use App\Services\Compta\EtatReglementResolver;` et la dépendance constructeur `private readonly EtatReglementResolver $etatReglementResolver`.

2. Dans `toggleTransaction()` — **dépointage** (lignes ~274-278), remplacer :

```php
                $model->rapprochement_id = null;
                $model->statut_reglement = $model->remise_id !== null
                    ? StatutReglement::Recu
                    : StatutReglement::EnAttente;
                $model->save();
```

par :

```php
                $model->rapprochement_id = null;
                $model->save();
                // Chantier 4 — statut dérivé du ledger (autoritaire), après effacement du rapprochement_id.
                $this->etatReglementResolver->syncer($model);
```

3. Dans `toggleTransaction()` — **pointage** (lignes ~290-292), remplacer :

```php
                $model->rapprochement_id = $rapprochement->id;
                $model->statut_reglement = StatutReglement::Pointe;
                $model->save();
```

par :

```php
                $model->rapprochement_id = $rapprochement->id;
                $model->save();
                // Chantier 4 — statut dérivé du ledger (autoritaire).
                $this->etatReglementResolver->syncer($model);
```

> Garder le placement : le `syncer` doit s'exécuter **après** la propagation du `rapprochement_id` sur la T2 séparée (le resolver lit `rapprochement_id` sur la tx porteuse du 512X, qui est la T2 pour une recette comptant séparée). Déplacer l'appel `syncer($model)` après le bloc `if ($t2 !== null) { ... $t2->save(); }`.

4. Dans `toggleRemise()` (lignes ~317-326), remplacer le corps de la boucle :

```php
        foreach ($transactions as $tx) {
            if ($allPointed) {
                $tx->rapprochement_id = null;
            } else {
                $tx->rapprochement_id = $rapprochement->id;
            }
            $tx->save();
            // Chantier 4 — statut dérivé (les T4 remise portent le 512X rapproché).
            $this->etatReglementResolver->syncer($tx);
        }
```

5. Dans `supprimer()` (lignes ~364-371), remplacer le `each` :

```php
            Transaction::where('rapprochement_id', $id)->each(function (Transaction $tx): void {
                $tx->update(['rapprochement_id' => null]);
                $this->etatReglementResolver->syncer($tx);
            });
```

- [ ] **Step 6.4 : Câbler `syncer` dans `RemiseBancaireService::comptabiliser` (transition EnMain→Recu)**

La comptabilisation d'une remise pose le 512X (T4) et lettre le 5112 des T2 source → le statut dérivé de chaque T1 source passe `EnMain → Recu` (remis). Sans `syncer` ici, le miroir reste `EnMain` jusqu'au pointage (divergence transitoire signalée par la réconciliation).

Dans `app/Services/RemiseBancaireService.php` :
1. Injecter `EtatReglementResolver` (import + constructeur).
2. À la fin de `comptabiliser()` (après création/lettrage des T4), pour chaque transaction source T1 traitée, appeler `$this->etatReglementResolver->syncer($t1->fresh());`. Repérer la collection des sources dans `comptabiliser()` et itérer (réutiliser la liste d'IDs sources déjà parcourue).

Ajouter au test `RapprochementStatutDeriveTest` un scénario : recette chèque (`EnMain`) → `comptabiliser($remise, [$t1->id])` → statut dérivé **et miroir** = `Recu`.

```php
it('comptabilisation d\'une remise chèque → statut miroir passe EnMain à Recu', function () {
    $t1 = app(\App\Services\TransactionService::class)->create([
        'type' => \App\Enums\TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Chèque à remettre',
        'montant_total' => '100.00',
        'mode_paiement' => \App\Enums\ModePaiement::Cheque->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ], [[
        'sous_categorie_id' => $this->sc706->id,
        'montant' => '100.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]]);
    expect($t1->fresh()->statut_reglement)->toBe(\App\Enums\StatutReglement::EnMain);

    $remise = \App\Models\RemiseBancaire::create([
        'association_id' => $this->association->id,
        'numero' => 3001, 'date' => '2025-10-20',
        'mode_paiement' => \App\Enums\ModePaiement::Cheque->value,
        'compte_cible_id' => $this->compteBancaire->id,
        'libelle' => 'Remise statut test', 'saisi_par' => $this->user->id,
    ]);
    app(\App\Services\RemiseBancaireService::class)->comptabiliser($remise, [(int) $t1->id]);

    expect($t1->fresh()->statut_reglement)->toBe(\App\Enums\StatutReglement::Recu);
});
```

- [ ] **Step 6.5 : Lancer le test ciblé + non-régression rappro/remise**

Run: `./vendor/bin/sail test --filter=RapprochementStatutDeriveTest`
Run: `./vendor/bin/sail test --filter=Rapprochement`
Run: `./vendor/bin/sail test --filter=Remise`
Expected: PASS (0 failed). Adapter les éventuels tests qui asseyaient l'ancien `statut_reglement` manuel (ils doivent désormais refléter le dérivé — équivalent).

- [ ] **Step 6.6 : Commit**

```bash
git add app/Services/RapprochementBancaireService.php app/Services/RemiseBancaireService.php tests/Feature/Services/RapprochementStatutDeriveTest.php
git commit -m "feat(v5): chantier 4 — syncer statut sur pointage/remise/comptabilisation/suppression"
```

---

## Task 7 : Câbler `syncer` dans `ReglementOperationService` (marquerRecu / marquerPaye)

**Files:**
- Modify: `app/Services/ReglementOperationService.php`
- Test: `tests/Feature/Services/ReglementOperationStatutDeriveTest.php` (Create)

- [ ] **Step 7.1 : Écrire le test (RED)**

Create `tests/Feature/Services/ReglementOperationStatutDeriveTest.php`. Scénario : créance recette (mode null) → `marquerRecu(mode: Cheque)` → statut dérivé `EnMain` (chèque en main) ; créance recette → `marquerRecu(mode: Virement)` → `Recu`. Symétrique dépense : dette → `marquerPaye(mode: Virement)` → `Recu`.

```php
<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\Tiers;
use App\Services\ReglementOperationService;
use App\Services\TransactionService;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

beforeEach(function () {
    $this->setupPartieDoubleContext();
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
});

it('marquerRecu chèque sur créance → statut dérivé EnMain', function () {
    $creance = app(TransactionService::class)->create([
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Créance à encaisser',
        'montant_total' => '100.00',
        'mode_paiement' => null,
        'tiers_id' => $this->tiers->id,
        'compte_id' => null,
    ], [[
        'sous_categorie_id' => $this->sc706->id,
        'montant' => '100.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]]);

    app(ReglementOperationService::class)->marquerRecu(
        $creance->fresh(), ModePaiement::Cheque, (int) $this->compteBancaire->id
    );

    expect($creance->fresh()->statut_reglement)->toBe(StatutReglement::EnMain);
});
```

- [ ] **Step 7.2 : Lancer → échec attendu**

Run: `./vendor/bin/sail test --filter=ReglementOperationStatutDeriveTest`
Expected: FAIL (marquerRecu pose `Recu` en dur, pas `EnMain`).

- [ ] **Step 7.3 : Injecter le resolver + remplacer l'assignation `Recu` en dur**

Dans `app/Services/ReglementOperationService.php` :

1. Ajouter `use App\Services\Compta\EtatReglementResolver;` et la dépendance constructeur `private readonly EtatReglementResolver $etatReglementResolver` (s'ajoute aux deux deps existantes).

2. Dans `marquerRecu()` (lignes ~155-172), retirer le hard-set `statut_reglement = Recu` de `$updateData` et le remplacer par un `syncer` après encaissement :

```php
        DB::transaction(function () use ($transaction, $compte411, $mode, $compteId): void {
            $updateData = [];
            if ($transaction->mode_paiement === null && $mode !== null) {
                $updateData['mode_paiement'] = $mode->value;
                if ($compteId !== null) {
                    $updateData['compte_id'] = $compteId;
                }
            }

            if ($updateData !== []) {
                $transaction->update($updateData);
                $transaction->refresh();
            }

            $this->encaisserSiNonEncaisse($transaction, $compte411);

            // Chantier 4 — statut dérivé du ledger (remplace le hard-set Recu).
            $this->etatReglementResolver->syncer($transaction->fresh());
        });
```

> Le guard d'entrée `if ($transaction->statut_reglement !== StatutReglement::EnAttente) return;` (ligne 144) reste valide : `EnAttente` = ouvert, donc « ne marquer reçu que si encore dû ». Inchangé.

3. Symétrique dans `marquerPaye()` (lignes ~297-314) : retirer le hard-set `Recu`, appeler `reglerSiNonRegle()` puis `$this->etatReglementResolver->syncer($transaction->fresh());`.

- [ ] **Step 7.4 : Lancer le test ciblé + non-régression**

Run: `./vendor/bin/sail test --filter=ReglementOperationStatutDeriveTest`
Run: `./vendor/bin/sail test --filter=ReglementOperation`
Expected: PASS (0 failed). Adapter les tests existants qui asseyaient `statut_reglement = Recu` après `marquerRecu` chèque → ils doivent désormais attendre `EnMain` (sémantiquement correct : un chèque reçu non remis est « à remettre »).

- [ ] **Step 7.5 : Commit**

```bash
git add app/Services/ReglementOperationService.php tests/Feature/Services/ReglementOperationStatutDeriveTest.php
git commit -m "feat(v5): chantier 4 — syncer statut sur marquerRecu/marquerPaye (chèque reçu → EnMain)"
```

---

## Task 8 : Labels direction-aware aux points d'affichage

**Files:**
- Modify: sites d'affichage du statut (à recenser)
- Test: ajustements de tests Livewire existants

- [ ] **Step 8.1 : Recenser les points d'affichage du statut**

Run: `grep -rn "statut_reglement->label\|->statut_reglement\b" app/Livewire app/Http resources/views | grep -i label`

Pour chaque site affichant le statut d'une **recette** ou **dépense**, passer le `Sens` au `label()` :
- recette : `$tx->statut_reglement->label(\App\Enums\Sens::Recette)`
- dépense : `$tx->statut_reglement->label(\App\Enums\Sens::Depense)`

Les sites mixtes (liste recettes+dépenses) dérivent le `Sens` du `type` de la transaction.

- [ ] **Step 8.2 : Ajuster + lancer les tests Livewire concernés**

Run: `./vendor/bin/sail test --filter=TransactionUniverselle`
Run: `./vendor/bin/sail test --filter=ReglementTable`
Expected: PASS (0 failed) après mise à jour des assertions de libellé.

- [ ] **Step 8.3 : Commit**

```bash
git add -A
git commit -m "feat(v5): chantier 4 — libellés statut direction-aware (Dû/Remis/Réglé/Pointé)"
```

---

## Task 9 : Data-migration de reclassement `recu` → `en_main`

**Files:**
- Create: `database/migrations/2026_06_04_120100_reclasser_statuts_en_main.php`
- Test: `tests/Feature/Console/ReclassementEnMainTest.php` (Create)

- [ ] **Step 9.1 : Écrire le test (RED)**

Create `tests/Feature/Console/ReclassementEnMainTest.php` : monter une recette chèque comptant (statut forcé `Recu` façon pré-chantier-4, 5112 non remis), exécuter la logique de reclassement, vérifier `EnMain`. Une recette virement (512X) forcée `Recu` reste `Recu`.

```php
<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\Tiers;
use App\Services\Compta\EtatReglementResolver;
use App\Services\TransactionService;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

beforeEach(function () {
    $this->setupPartieDoubleContext();
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
});

it('reclasse une recette chèque non remise (Recu périmé) → EnMain via resolver', function () {
    $t1 = app(TransactionService::class)->create([
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Chèque en main',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ], [[
        'sous_categorie_id' => $this->sc706->id,
        'montant' => '100.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]]);

    // Simuler l'état pré-chantier-4 : colonne = Recu.
    $t1->forceFill(['statut_reglement' => StatutReglement::Recu->value])->save();

    // La data-migration recalcule via le resolver.
    app(EtatReglementResolver::class)->syncer($t1->fresh());

    expect($t1->fresh()->statut_reglement)->toBe(StatutReglement::EnMain);
});
```

- [ ] **Step 9.2 : Lancer → succès (le syncer fait déjà le travail)**

Run: `./vendor/bin/sail test --filter=ReclassementEnMainTest`
Expected: PASS (valide la logique de reclassement avant de l'emballer en migration).

- [ ] **Step 9.3 : Écrire la migration de données (one-shot, idempotente)**

```php
<?php

declare(strict_types=1);

use App\Models\Transaction;
use App\Services\Compta\EtatReglementResolver;
use App\Tenant\TenantContext;
use App\Models\Association;
use Illuminate\Database\Migrations\Migration;

/**
 * Chantier 4 — reclasse les statuts via le resolver (one-shot, rejouable).
 *
 * En pratique : seules les recettes chèque/espèces reçues mais non remises
 * (colonne 'recu', portage 5112/530 non lettré) basculent vers 'en_main'.
 * Le resolver est idempotent : les autres tx restent inchangées.
 *
 * Itère par association (TenantContext requis pour le scope global).
 * No-op sous tenant sans schéma PD (resolver → fallback colonne).
 */
return new class extends Migration
{
    public function up(): void
    {
        $resolver = app(EtatReglementResolver::class);

        Association::query()->each(function (Association $association) use ($resolver): void {
            TenantContext::boot($association);

            Transaction::query()->each(function (Transaction $tx) use ($resolver): void {
                $resolver->syncer($tx);
            });

            TenantContext::forget();
        });
    }

    public function down(): void
    {
        // Irréversible côté données (le statut redevient dérivable). No-op.
    }
};
```

> Vérifier l'API exacte de `TenantContext` (`boot`/`forget` ou équivalent) dans `app/Tenant/TenantContext.php` et adapter. Si `config('compta.use_partie_double')` est false au moment de la migration prod, `syncer` est un no-op — s'assurer que la migration tourne **après** activation du flag (cohérent avec le cutover).

- [ ] **Step 9.4 : Lancer la suite Console**

Run: `./vendor/bin/sail test --filter=Reclassement`
Expected: PASS.

- [ ] **Step 9.5 : Commit**

```bash
git add database/migrations/2026_06_04_120100_reclasser_statuts_en_main.php tests/Feature/Console/ReclassementEnMainTest.php
git commit -m "feat(v5): chantier 4 — data-migration reclassement recu→en_main (resolver one-shot)"
```

---

## Task 10 : Commande de réconciliation `compta:reconcilier-statuts` (rempart anti-dérive)

**Files:**
- Create: `app/Console/Commands/ReconcilierStatutsCommand.php`
- Test: `tests/Feature/Console/ReconcilierStatutsCommandTest.php` (Create)

- [ ] **Step 10.1 : Écrire le test (RED)**

Create `tests/Feature/Console/ReconcilierStatutsCommandTest.php` : monter quelques recettes/dépenses, forcer un miroir périmé sur l'une, exécuter `--check` → la commande signale 1 divergence (exit code non nul). Puis `compta:reconcilier-statuts` (mode fix) → 0 divergence restante.

```php
<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\Tiers;
use App\Services\TransactionService;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

beforeEach(function () {
    $this->setupPartieDoubleContext();
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
});

it('détecte une divergence miroir↔ledger en --check (exit non nul)', function () {
    $t1 = app(TransactionService::class)->create([
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Divergence',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ], [[
        'sous_categorie_id' => $this->sc706->id,
        'montant' => '100.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]]);

    // Corrompre le miroir.
    $t1->forceFill(['statut_reglement' => StatutReglement::Pointe->value])->save();

    $this->artisan('compta:reconcilier-statuts', ['--check' => true])
        ->assertExitCode(1);
});

it('corrige les divergences sans --check (exit 0, miroir resynchronisé)', function () {
    $t1 = app(TransactionService::class)->create([
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'À corriger',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ], [[
        'sous_categorie_id' => $this->sc706->id,
        'montant' => '100.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]]);

    $t1->forceFill(['statut_reglement' => StatutReglement::Pointe->value])->save();

    $this->artisan('compta:reconcilier-statuts')->assertExitCode(0);

    expect($t1->fresh()->statut_reglement)->toBe(StatutReglement::EnMain);
});
```

- [ ] **Step 10.2 : Lancer → échec attendu**

Run: `./vendor/bin/sail test --filter=ReconcilierStatutsCommandTest`
Expected: FAIL (commande inexistante).

- [ ] **Step 10.3 : Implémenter la commande**

Create `app/Console/Commands/ReconcilierStatutsCommand.php` :

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Association;
use App\Models\Transaction;
use App\Services\Compta\EtatReglementResolver;
use App\Tenant\TenantContext;
use Illuminate\Console\Command;

/**
 * Chantier 4 — rempart anti-dérive du miroir statut_reglement.
 *
 * Parcourt toutes les transactions (par tenant) et compare la colonne stockée
 * au statut dérivé du ledger. --check : signale et sort en erreur si divergence
 * (CI/garde-fou). Sans --check : resynchronise via syncer.
 */
final class ReconcilierStatutsCommand extends Command
{
    protected $signature = 'compta:reconcilier-statuts {--check : Signale les divergences sans corriger}';

    protected $description = 'Réconcilie statut_reglement (miroir) avec le statut dérivé du grand livre';

    public function handle(EtatReglementResolver $resolver): int
    {
        $check = (bool) $this->option('check');
        $divergences = 0;

        Association::query()->each(function (Association $association) use ($resolver, $check, &$divergences): void {
            TenantContext::boot($association);

            Transaction::query()->each(function (Transaction $tx) use ($resolver, $check, &$divergences): void {
                $derive = $resolver->resolve($tx);

                if ($tx->statut_reglement !== $derive) {
                    $divergences++;
                    $this->warn(sprintf(
                        'Tx #%d : miroir=%s ledger=%s',
                        $tx->id,
                        $tx->statut_reglement->value,
                        $derive->value,
                    ));

                    if (! $check) {
                        $resolver->syncer($tx);
                    }
                }
            });

            TenantContext::forget();
        });

        if ($divergences === 0) {
            $this->info('Aucune divergence : miroir aligné sur le ledger.');

            return self::SUCCESS;
        }

        $this->line(sprintf('%d divergence(s) %s.', $divergences, $check ? 'détectée(s)' : 'corrigée(s)'));

        return $check ? self::FAILURE : self::SUCCESS;
    }
}
```

> Vérifier l'API `TenantContext::boot/forget` et le nom de la commande de smoke-test existante (`compta:smoke-test-v5`) — option : ajouter une assertion de réconciliation dans le smoke-test plutôt qu'une commande séparée. Ici on crée une commande dédiée (réutilisable en CI).

- [ ] **Step 10.4 : Lancer → succès**

Run: `./vendor/bin/sail test --filter=ReconcilierStatutsCommandTest`
Expected: PASS.

- [ ] **Step 10.5 : Commit**

```bash
git add app/Console/Commands/ReconcilierStatutsCommand.php tests/Feature/Console/ReconcilierStatutsCommandTest.php
git commit -m "feat(v5): chantier 4 — commande compta:reconcilier-statuts (rempart anti-dérive)"
```

---

## Task 11 : Vérification d'ensemble + équivalence lumpé↔séparé

**Files:**
- Test: `tests/Feature/Services/Compta/EtatReglementResolverTest.php` (append)

- [ ] **Step 11.1 : Test d'équivalence lumpé ↔ séparé (dans le test d'équivalence existant)**

Ce test a son foyer naturel dans `tests/Feature/CR/PartieDoubleEquivalenceTest.php`, qui construit **déjà** les deux structures (live T2 séparée vs backfill lumpé via `TransactionConverter`) à partir du même fait économique. Y **ajouter** une assertion : pour chaque paire (tx live, tx backfillée), `app(EtatReglementResolver::class)->resolve($live)` **===** `resolve($backfille)`.

Lire `tests/Feature/CR/PartieDoubleEquivalenceTest.php` pour récupérer les variables des deux transactions déjà montées, puis ajouter dans le `it` existant qui compare les structures :

```php
$resolver = app(\App\Services\Compta\EtatReglementResolver::class);
expect($resolver->resolve($txLive->fresh()))
    ->toBe($resolver->resolve($txBackfillee->fresh()), 'Statut dérivé identique lumpé vs séparé');
```

(Remplacer `$txLive` / `$txBackfillee` par les noms réels des transactions montées dans ce test.)

- [ ] **Step 11.2 : Suite complète**

Run: `./vendor/bin/sail test`
Expected: PASS (0 failed). Baseline attendue ≥ 12 583 (dernier chiffre chantier 3a) + les nouveaux tests.

> ⚠️ Lancer la suite complète **uniquement** en sqlite mémoire (garde-fou DB). Ne jamais cibler la base mysql clonée.

- [ ] **Step 11.3 : Réconciliation à blanc**

Run: `./vendor/bin/sail test --filter=ReconcilierStatutsCommandTest`
Expected: PASS — confirme le rempart anti-dérive.

- [ ] **Step 11.4 : Commit**

```bash
git add tests/Feature/CR/PartieDoubleEquivalenceTest.php
git commit -m "test(v5): chantier 4 — équivalence lumpé↔séparé du statut dérivé"
```

---

## Pré-PR / fin de chantier

- [ ] Suite complète verte (0 failed), `./vendor/bin/pint` passé.
- [ ] `git log --oneline` : ~11 commits chantier 4.
- [ ] **Recette manuelle localhost (clone prod)** : saisir créance → marquer reçu (chèque) → remettre → rapprocher → vérifier le libellé à chaque étape (Dû → À remettre → Remis → Pointé), **+ réversion** reçu→non-reçu (repasse à « Dû »). Symétrique dépense (Dû → Réglé → Pointé). Lancer `php artisan compta:reconcilier-statuts --check` sur le clone → 0 divergence.
- [ ] Mettre à jour la mémoire `project_compta_v5_*` + la roadmap (`docs/specs/2026-06-03-roadmap-compta-v5.md`, chantier 4 → livré).
- [ ] **NE PAS merger `feat/compta-v5` → `main`** (horizon lointain ; `main` reste v4.3.x).
