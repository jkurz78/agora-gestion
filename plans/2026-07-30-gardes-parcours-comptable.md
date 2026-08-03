# Gardes du parcours comptable — plan d'implémentation (tranche 1)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Empêcher qu'une association clôture un exercice sans avoir repris ses soldes historiques, en dérivant son étape comptable des données et en refusant les enchaînements incorrects.

**Architecture:** Un résolveur en lecture seule (`EtatComptaResolver`) déduit l'étape courante du tenant à partir des données existantes — transactions non converties, soldes bancaires non repris, divergences miroir/grand livre. Aucune table nouvelle, aucun état stocké. Trois consommateurs : les gardes de l'assistant de clôture, le service de clôture lui-même (défense en profondeur, car les gardes de l'assistant sont consultatives), et une commande de diagnostic `compta:etat`. Chaque règle réutilise le critère déjà en place plutôt que d'en écrire un second.

**Tech Stack:** Laravel 11, Pest, MySQL/MariaDB (SQLite en test), multi-tenant via `TenantContext`.

**Spec:** [docs/specs/2026-07-30-gardes-parcours-comptable.md](../docs/specs/2026-07-30-gardes-parcours-comptable.md)

**Écart assumé vis-à-vis de la spec :** la garde de clôture s'appelle « Préalables comptables » et non « Soldes historiques repris ».

> ## ⚠️ Plan clos par anticipation le 2026-07-30
>
> Livrées : tasks 1 à 4 (dont le contrôle de couverture, plus large que prévu), 7 et 8.
> **Abandonnées : tasks 5, 6 (repliée dans la 8), 9, 10 et 11.**
>
> Motif : le volume de construction était disproportionné au risque. L'association compte un seul tenant en production, et l'argument « chaque nouvelle association » qui justifiait une fonction permanente était spéculatif. Ce qui empêche la répétition du 2026-07-29, c'est la séquence d'exploitation corrigée dans les scripts de déploiement — déjà committée — et une garde bloquante à la clôture. Le reste relevait du « tant qu'on y est ».
>
> Voir le § 8 de la spec pour le détail de chaque abandon. Les tasks qui suivent sont conservées comme trace de ce qui avait été prévu ; **ne pas les exécuter sans rouvrir la décision**.

---

## Conventions du dépôt à respecter

- `declare(strict_types=1)` + `final class` + type hints partout.
- PSR-12 via `./vendor/bin/pint <fichiers>` avant chaque commit.
- Locale `fr` : tout message utilisateur en français.
- Cast `(int)` des deux côtés dans les `===` sur PK/FK (MySQL prod renvoie des strings).
- Tout modèle tenant-scopé étend `TenantModel` (scope global fail-closed). Ne jamais passer une `Association` à un service : booter `TenantContext` et lire le tenant courant.
- Suite complète : `./vendor/bin/sail exec -T laravel.test php -d memory_limit=1G ./vendor/bin/pest --compact` (512 Mo insuffisant).
- `git checkout -- config/version.php` avant chaque commit (le fichier est réécrit au boot).
- Ne rien pousser sur `origin`.

## Structure des fichiers

**Créés :**

| Fichier | Responsabilité |
|---|---|
| `app/Enums/EtapeCompta.php` | Les quatre étapes et leur libellé français (aucune commande) |
| `app/Services/Compta/EtatCompta.php` | Objet-valeur immuable : les blocages, dont l'étape se déduit |
| `app/Services/Compta/EtatComptaResolver.php` | La déduction, en lecture seule |
| `app/Exceptions/Compta/EtapeComptaRequiseException.php` | Refus porteur de l'état |
| `app/Console/Commands/EtatComptaCommand.php` | `compta:etat`, diagnostic |
| `tests/Feature/Compta/EtatComptaResolverTest.php` | Une transition par test |
| `tests/Feature/Compta/ClotureGardePrealablesTest.php` | Régression préprod du 2026-07-29 |
| `tests/Feature/Compta/ClotureServiceRefusTest.php` | Refus au niveau du service (défense en profondeur) |
| `tests/Feature/Console/EtatComptaCommandTest.php` | Sorties et code de retour |

**Modifiés :**

| Fichier | Modification |
|---|---|
| `app/Services/ClotureCheckService.php` | Ajout de la garde bloquante « Préalables comptables » |
| `app/Services/ExerciceService.php` | `cloturer()` refuse lui-même : les gardes de l'assistant sont consultatives |
| `app/Console/Commands/BootstrapANouveauCommand.php` | Refus tant que le backfill n'est pas terminé |
| `tests/Feature/Console/BootstrapANouveauCommandTest.php` | Test du nouveau refus |

---

## Task 1 : L'énumération des étapes

**Files:**
- Create: `app/Enums/EtapeCompta.php`
- Test: `tests/Feature/Compta/EtatComptaResolverTest.php` (créé ici, complété aux tasks suivantes)

- [ ] **Step 1: Écrire le test qui échoue**

Créer `tests/Feature/Compta/EtatComptaResolverTest.php` :

```php
<?php

declare(strict_types=1);

use App\Enums\EtapeCompta;

it('nomme chaque étape en français, sans jargon de migration', function (): void {
    expect(EtapeCompta::BackfillRequis->label())
        ->toBe('Écritures comptables incomplètes')
        ->and(EtapeCompta::RepriseInitialeRequise->label())
        ->toBe('Soldes d’ouverture non repris')
        ->and(EtapeCompta::ReconciliationRequise->label())
        ->toBe('Statuts de règlement à mettre à jour')
        ->and(EtapeCompta::Operationnel->label())
        ->toBe('Opérationnel');
});

it('ne porte aucune commande : le remède appartient à l’appelant', function (): void {
    expect(method_exists(EtapeCompta::class, 'geste'))->toBeFalse();

    foreach (EtapeCompta::cases() as $etape) {
        expect($etape->label())->not->toContain('artisan');
    }
});
```

- [ ] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `./vendor/bin/sail exec -T laravel.test php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Compta/EtatComptaResolverTest.php`

Expected: FAIL — `Class "App\Enums\EtapeCompta" not found`

- [ ] **Step 3: Écrire l'implémentation minimale**

Créer `app/Enums/EtapeCompta.php` :

```php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Étapes ordonnées du parcours comptable d'une association.
 *
 * L'étape n'est jamais stockée : elle est dérivée des données par
 * App\Services\Compta\EtatComptaResolver. Une seconde source de vérité
 * finirait par diverger — c'est la leçon de la recette du 2026-07-29.
 *
 * Chaque cas porte son libellé, et rien d'autre. Le remède — quelle commande
 * lancer, avec quel tenant, ou quel écran ouvrir — appartient à la couche qui
 * connaît le support et l'association. Faire porter une commande artisan par
 * l'énumération la faisait remonter jusque dans l'assistant de clôture, où le
 * trésorier lisait une ligne de console qu'il ne pouvait pas exécuter.
 *
 * Les libellés évitent « conversion » et « backfill » (vocabulaire de migration,
 * opération que le trésorier n'a pas déclenchée) et « réconciliation », qui en
 * français comptable désigne la même chose que « rapprochement » — mot déjà pris
 * par la garde « Rapprochements en cours » de la même checklist, qui parle de
 * banque et non de statuts.
 */
enum EtapeCompta: string
{
    case BackfillRequis = 'backfill_requis';
    case RepriseInitialeRequise = 'reprise_initiale_requise';
    case ReconciliationRequise = 'reconciliation_requise';
    case Operationnel = 'operationnel';

    public function label(): string
    {
        return match ($this) {
            self::BackfillRequis => 'Écritures comptables incomplètes',
            self::RepriseInitialeRequise => 'Soldes d’ouverture non repris',
            self::ReconciliationRequise => 'Statuts de règlement à mettre à jour',
            self::Operationnel => 'Opérationnel',
        };
    }
}
```

- [ ] **Step 4: Lancer le test pour vérifier qu'il passe**

Run: `./vendor/bin/sail exec -T laravel.test php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Compta/EtatComptaResolverTest.php`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
./vendor/bin/sail exec -T laravel.test ./vendor/bin/pint app/Enums/EtapeCompta.php tests/Feature/Compta/EtatComptaResolverTest.php
git checkout -- config/version.php
git add app/Enums/EtapeCompta.php tests/Feature/Compta/EtatComptaResolverTest.php
git commit -m "feat(compta): énumère les étapes du parcours comptable"
```

---

## Task 2 : L'objet-valeur d'état

**Files:**
- Create: `app/Services/Compta/EtatCompta.php`
- Test: `tests/Feature/Compta/EtatComptaResolverTest.php` (ajout)

- [ ] **Step 1: Écrire le test qui échoue**

Ajouter à `tests/Feature/Compta/EtatComptaResolverTest.php` :

```php
it('expose l’étape, ses blocages et la nature opérationnelle', function (): void {
    $bloque = new App\Services\Compta\EtatCompta(
        EtapeCompta::RepriseInitialeRequise,
        [EtapeCompta::RepriseInitialeRequise->value => '2 compte(s) bancaire(s) portent un solde historique jamais entré dans le grand livre.'],
    );

    expect($bloque->estOperationnel())->toBeFalse()
        ->and($bloque->blocages)->toHaveCount(1);

    $ok = new App\Services\Compta\EtatCompta(EtapeCompta::Operationnel, []);

    expect($ok->estOperationnel())->toBeTrue()
        ->and($ok->blocages)->toBe([]);
});

it('répond sur une condition précise, pas seulement sur la première', function (): void {
    // Deux blocages : l'étape courante est le premier, mais le second doit rester
    // interrogeable — sinon une garde qui vise le backfill deviendrait aveugle
    // dès qu'un blocage antérieur apparaît.
    $etat = new App\Services\Compta\EtatCompta(
        EtapeCompta::BackfillRequis,
        [
            EtapeCompta::BackfillRequis->value => '3 transaction(s) ne sont pas converties en partie double.',
            EtapeCompta::ReconciliationRequise->value => '2 transaction(s) portent un statut en désaccord avec le grand livre.',
        ],
    );

    expect($etat->exige(EtapeCompta::BackfillRequis))->toBeTrue()
        ->and($etat->exige(EtapeCompta::ReconciliationRequise))->toBeTrue()
        ->and($etat->exige(EtapeCompta::RepriseInitialeRequise))->toBeFalse();
});

it('énonce ses causes sans jamais prescrire de commande', function (): void {
    $etat = new App\Services\Compta\EtatCompta(
        EtapeCompta::BackfillRequis,
        [EtapeCompta::BackfillRequis->value => '3 transaction(s) ne sont pas converties en partie double.'],
    );

    expect($etat->causes())
        ->toContain('3 transaction(s)')
        ->not->toContain('artisan');
});
```

- [ ] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `./vendor/bin/sail exec -T laravel.test php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Compta/EtatComptaResolverTest.php`

Expected: FAIL — `Class "App\Services\Compta\EtatCompta" not found`

- [ ] **Step 3: Écrire l'implémentation minimale**

Créer `app/Services/Compta/EtatCompta.php` :

> Forme révisée après la revue : un seul champ, l'étape déduite, les clés validées.
> Le code livré fait foi — voir le commit `de3da6ed`.

```php
final readonly class EtatCompta
{
    /** @param  array<string, string>  $blocages  Indexés par EtapeCompta::value, sans commande. */
    public function __construct(public array $blocages)
    {
        // Rejette toute clé inconnue et Operationnel : sans ce contrôle, une clé
        // mal orthographiée est affichée par le diagnostic mais invisible
        // d'exige(), et une garde laisse passer l'opération sans rien signaler.
    }

    public function etape(): EtapeCompta      // premier blocage dans l'ordre déclaré
    public function estOperationnel(): bool   // $this->blocages === []
    public function exige(EtapeCompta $condition): bool
    public function causes(): string
}
```

- [ ] **Step 4: Lancer le test pour vérifier qu'il passe**

Run: `./vendor/bin/sail exec -T laravel.test php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Compta/EtatComptaResolverTest.php`

Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
./vendor/bin/sail exec -T laravel.test ./vendor/bin/pint app/Services/Compta/EtatCompta.php tests/Feature/Compta/EtatComptaResolverTest.php
git checkout -- config/version.php
git add app/Services/Compta/EtatCompta.php tests/Feature/Compta/EtatComptaResolverTest.php
git commit -m "feat(compta): objet-valeur de l'état comptable d'une association"
```

---

## Task 3 : Le résolveur — règle du backfill

Le critère est celui du backfill lui-même (`compta:backfill-partie-double` convertit les transactions `equilibree = false`) et l'exclusion HelloAsso est celle d'`compta:assert-pd-complete` (`whereNull('helloasso_order_id')`). Aucun troisième critère n'est écrit.

> **Livré et corrigé en revue — le code fait foi, voir `c9a41357` puis `a4bcc2c7`.** Trois écarts par rapport au bloc ci-dessous :
> le résolveur **échoue fermé** (il exige un `TenantContext` booté, sinon il ne verrait aucune donnée et se dirait opérationnel) ;
> la cause parle d'« opération(s) sans écriture comptable complète » et non de conversion, vocabulaire que l'énumération s'interdit ;
> le helper de test s'appelle `etatComptaIsolerSoldes()`, sans argument, et se place **après** la création des fixtures — `TransactionFactory` frappe un second compte bancaire au solde aléatoire qu'une remise à zéro anticipée manquait.

**Files:**
- Create: `app/Services/Compta/EtatComptaResolver.php`
- Test: `tests/Feature/Compta/EtatComptaResolverTest.php` (ajout)

- [ ] **Step 1: Écrire le test qui échoue**

Ajouter en tête de `tests/Feature/Compta/EtatComptaResolverTest.php`, après les `use` :

```php
use App\Models\Transaction;
use App\Services\Compta\EtatComptaResolver;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);
```

Puis ajouter les tests :

```php
/**
 * Neutralise les règles dont le test courant n'est pas le sujet.
 *
 * CompteBancaireFactory tire solde_initial au hasard entre 0 et 10 000 : sans
 * remise à zéro, la règle de reprise (task 4) se déclencherait sur toutes les
 * fixtures et ferait échouer les tests des autres règles — un échec piloté par
 * un tirage aléatoire, donc intermittent, exactement la mine que la recette du
 * 2026-07-24 avait mis une journée à identifier sur mode_paiement.
 */
function etatComptaIsolerSoldes(): void
{
    CompteBancaire::query()->update(['solde_initial' => 0]);
}

it('exige le backfill quand des transactions ne sont pas en partie double', function (): void {
    $this->setupPartieDoubleContext();

    Transaction::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteBancaire->id,
        'equilibree' => false,
        'helloasso_order_id' => null,
    ]);

    etatComptaIsolerSoldes();

    $etat = app(EtatComptaResolver::class)->pourTenantCourant();

    expect($etat->exige(EtapeCompta::BackfillRequis))->toBeTrue()
        ->and($etat->etape())->toBe(EtapeCompta::BackfillRequis);
});

it('n’exige pas le backfill pour une transaction HelloAsso restée legacy', function (): void {
    $this->setupPartieDoubleContext();

    Transaction::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteBancaire->id,
        'equilibree' => false,
        'helloasso_order_id' => 'HA-12345',
    ]);

    etatComptaIsolerSoldes();

    $etat = app(EtatComptaResolver::class)->pourTenantCourant();

    // exige() plutôt que etape() : l'assertion reste juste et pour la bonne
    // raison quand d'autres règles s'ajouteront au résolveur.
    expect($etat->exige(EtapeCompta::BackfillRequis))->toBeFalse();
});
```

- [ ] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `./vendor/bin/sail exec -T laravel.test php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Compta/EtatComptaResolverTest.php`

Expected: FAIL — `Class "App\Services\Compta\EtatComptaResolver" not found`

- [ ] **Step 3: Écrire l'implémentation minimale**

Créer `app/Services/Compta/EtatComptaResolver.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\Enums\EtapeCompta;
use App\Models\Transaction;

/**
 * Déduit l'étape comptable du tenant courant à partir des données.
 *
 * Ne prend pas d'association en paramètre : les modèles étant protégés par un
 * scope global fail-closed sur association_id, c'est à l'appelant de booter
 * TenantContext — comme le font compta:check-integrity et
 * compta:reconcilier-statuts en itérant sur les associations.
 *
 * Lecture seule : ce service n'écrit rien et ne corrige rien.
 */
final class EtatComptaResolver
{
    public function pourTenantCourant(): EtatCompta
    {
        $blocages = [];

        $legacy = $this->transactionsHorsPartieDouble();
        if ($legacy > 0) {
            $blocages[EtapeCompta::BackfillRequis->value] = sprintf(
                '%d transaction(s) ne sont pas converties en partie double.',
                $legacy,
            );
        }

        return new EtatCompta($blocages);
    }

    /**
     * Critère du backfill lui-même (equilibree = false), avec l'exclusion
     * HelloAsso d'assert-pd-complete : ces transactions restent legacy par
     * construction, leur enrichissement PD est best-effort au sync.
     */
    private function transactionsHorsPartieDouble(): int
    {
        return Transaction::query()
            ->whereNull('helloasso_order_id')
            ->where('equilibree', false)
            ->count();
    }
}
```

- [ ] **Step 4: Lancer le test pour vérifier qu'il passe**

Run: `./vendor/bin/sail exec -T laravel.test php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Compta/EtatComptaResolverTest.php`

Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
./vendor/bin/sail exec -T laravel.test ./vendor/bin/pint app/Services/Compta/EtatComptaResolver.php tests/Feature/Compta/EtatComptaResolverTest.php
git checkout -- config/version.php
git add app/Services/Compta/EtatComptaResolver.php tests/Feature/Compta/EtatComptaResolverTest.php
git commit -m "feat(compta): détecte les transactions restant à convertir"
```

---

## Task 4 : Le résolveur — règle de la reprise initiale

C'est la règle qui ferme le défaut du 2026-07-29. Lecture sur `comptes_bancaires`, source de vérité — jamais sur `comptes.solde_initial`, copie que rien ne relit et qui peut être périmée.

**Files:**
- Modify: `app/Services/Compta/EtatComptaResolver.php`
- Test: `tests/Feature/Compta/EtatComptaResolverTest.php` (ajout)

- [ ] **Step 1: Écrire le test qui échoue**

Ajouter les `use` nécessaires en tête du fichier de test :

```php
use App\Enums\OrigineANouveau;
use App\Enums\StatutANouveau;
use App\Models\ANouveauGeneration;
```

Puis ajouter les tests :

```php
it('exige la reprise initiale quand un solde bancaire historique n’est pas repris', function (): void {
    $this->setupPartieDoubleContext();

    $this->compteBancaire->update([
        'solde_initial' => 2388.82,
        'date_solde_initial' => '2024-08-31',
    ]);

    $etat = app(EtatComptaResolver::class)->pourTenantCourant();

    expect($etat->exige(EtapeCompta::RepriseInitialeRequise))->toBeTrue()
        ->and($etat->etape())->toBe(EtapeCompta::RepriseInitialeRequise)
        ->and($etat->causes())->toContain('solde historique');
});

it('n’exige pas de reprise quand tous les soldes bancaires sont à zéro', function (): void {
    $this->setupPartieDoubleContext();

    $this->compteBancaire->update(['solde_initial' => 0]);

    $etat = app(EtatComptaResolver::class)->pourTenantCourant();

    expect($etat->exige(EtapeCompta::RepriseInitialeRequise))->toBeFalse();
});

it('considère la reprise faite quand une génération reprise_initiale est active', function (): void {
    $this->setupPartieDoubleContext();

    $this->compteBancaire->update(['solde_initial' => 2388.82]);

    ANouveauGeneration::create([
        'association_id' => $this->association->id,
        'exercice_source' => 2023,
        'exercice_cible' => 2024,
        'transaction_id' => null,
        'origine' => OrigineANouveau::RepriseInitiale,
        'statut' => StatutANouveau::Active,
        'cree_par_id' => $this->user->id,
    ]);

    $etat = app(EtatComptaResolver::class)->pourTenantCourant();

    expect($etat->exige(EtapeCompta::RepriseInitialeRequise))->toBeFalse();
});
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `./vendor/bin/sail exec -T laravel.test php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Compta/EtatComptaResolverTest.php`

Expected: FAIL sur le premier — l'étape retournée est `Operationnel` au lieu de `RepriseInitialeRequise`.

(`a_nouveau_generations.transaction_id` est nullable — `'transaction_id' => null` est valide.)

- [ ] **Step 3: Écrire l'implémentation minimale**

Dans `app/Services/Compta/EtatComptaResolver.php`, ajouter les imports :

```php
use App\Enums\OrigineANouveau;
use App\Enums\StatutANouveau;
use App\Models\ANouveauGeneration;
use App\Models\CompteBancaire;
```

Puis, dans `pourTenantCourant()`, après le bloc du backfill et avant le `return` :

```php
        $comptesNonRepris = $this->comptesBancairesNonRepris();
        if ($comptesNonRepris > 0) {
            $blocages[EtapeCompta::RepriseInitialeRequise->value] = sprintf(
                '%d compte(s) bancaire(s) portent un solde historique jamais entré dans le grand livre.',
                $comptesNonRepris,
            );
        }
```

Et ajouter la méthode privée :

```php
    /**
     * Soldes historiques non repris : des comptes bancaires portent un solde
     * initial non nul et aucune reprise initiale n'a jamais été créée.
     *
     * Lecture sur comptes_bancaires, la source de vérité — comme
     * BootstrapANouveauService. La copie dans comptes.solde_initial n'est
     * rafraîchie par rien et peut être périmée.
     *
     * Une association qui démarre à zéro ne porte aucun solde non nul et
     * traverse cette étape sans rien faire : c'est le cas nominal, pas une
     * exception.
     */
    private function comptesBancairesNonRepris(): int
    {
        $avecSolde = CompteBancaire::query()
            ->whereNotNull('solde_initial')
            ->where('solde_initial', '<>', 0)
            ->count();

        if ($avecSolde === 0) {
            return 0;
        }

        $repriseFaite = ANouveauGeneration::query()
            ->where('origine', OrigineANouveau::RepriseInitiale)
            ->where('statut', StatutANouveau::Active)
            ->exists();

        return $repriseFaite ? 0 : $avecSolde;
    }
```

- [ ] **Step 4: Lancer les tests pour vérifier qu'ils passent**

Run: `./vendor/bin/sail exec -T laravel.test php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Compta/EtatComptaResolverTest.php`

Expected: PASS (7 tests)

- [ ] **Step 5: Commit**

```bash
./vendor/bin/sail exec -T laravel.test ./vendor/bin/pint app/Services/Compta/EtatComptaResolver.php tests/Feature/Compta/EtatComptaResolverTest.php
git checkout -- config/version.php
git add app/Services/Compta/EtatComptaResolver.php tests/Feature/Compta/EtatComptaResolverTest.php
git commit -m "feat(compta): détecte les soldes historiques non repris"
```

---

## Task 5 : Le résolveur — règle de la réconciliation

Même périmètre que `compta:reconcilier-statuts` : `Transaction::scopeOperationnel()`, journaux `vente` et `achat`. Les écritures techniques (T2/T4 du journal `banque`, OD, à-nouveaux) n'ont pas d'état de règlement métier.

**Files:**
- Modify: `app/Services/Compta/EtatComptaResolver.php`
- Test: `tests/Feature/Compta/EtatComptaResolverTest.php` (ajout)

- [ ] **Step 1: Écrire le test qui échoue**

Ajouter les `use` en tête du fichier de test :

```php
use App\Enums\JournalComptable;
use App\Enums\StatutReglement;
use App\Models\Compte;
use App\Models\TransactionLigne;
use App\Tenant\TenantContext;
```

Puis un helper et deux tests :

```php
/**
 * Transaction dont le miroir contredit le grand livre : ligne 411 non lettrée
 * (le resolver en dérive EnAttente) mais colonne à Recu.
 */
function transactionMiroirDivergent(string $journal): Transaction
{
    $compte411 = Compte::where('numero_pcg', '411')->sole();

    $tx = Transaction::forceCreate([
        'association_id' => (int) TenantContext::currentId(),
        'type' => 'recette',
        'date' => '2025-10-15',
        'libelle' => 'Miroir divergent '.$journal,
        'montant_total' => '100.00',
        'journal' => $journal,
        'type_ecriture' => 'normale',
        'equilibree' => true,
        'statut_reglement' => StatutReglement::Recu->value,
    ]);

    TransactionLigne::forceCreate([
        'transaction_id' => (int) $tx->id,
        'compte_id' => (int) $compte411->id,
        'montant' => 0,
        'debit' => '100.00',
        'credit' => 0,
        'lettrage_code' => null,
    ]);

    return $tx;
}

it('exige la réconciliation quand le miroir diverge sur une écriture métier', function (): void {
    $this->setupPartieDoubleContext();
    transactionMiroirDivergent(JournalComptable::Vente->value);
    etatComptaIsolerSoldes();

    $etat = app(EtatComptaResolver::class)->pourTenantCourant();

    expect($etat->exige(EtapeCompta::ReconciliationRequise))->toBeTrue()
        ->and($etat->etape())->toBe(EtapeCompta::ReconciliationRequise)
        ->and($etat->causes())->toContain('désaccord avec le grand livre');
});

it('ignore une divergence portée par une écriture technique', function (): void {
    $this->setupPartieDoubleContext();
    transactionMiroirDivergent(JournalComptable::Banque->value);
    etatComptaIsolerSoldes();

    $etat = app(EtatComptaResolver::class)->pourTenantCourant();

    // Aucun blocage du tout : c'est le seul test qui vérifie l'état Opérationnel
    // de bout en bout sur des données réelles.
    expect($etat->estOperationnel())->toBeTrue()
        ->and($etat->etape())->toBe(EtapeCompta::Operationnel);
});
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `./vendor/bin/sail exec -T laravel.test php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Compta/EtatComptaResolverTest.php`

Expected: FAIL sur le premier — étape `Operationnel` au lieu de `ReconciliationRequise`.

- [ ] **Step 3: Écrire l'implémentation minimale**

Dans `app/Services/Compta/EtatComptaResolver.php`, injecter le résolveur de statut :

```php
    public function __construct(
        private readonly EtatReglementResolver $etatReglement,
    ) {}
```

Aucun import à ajouter : `EtatReglementResolver` vit dans `App\Services\Compta`, le même namespace que `EtatComptaResolver`.

Dans `pourTenantCourant()`, après le bloc de la reprise :

```php
        $divergences = $this->divergencesMiroir();
        if ($divergences > 0) {
            $blocages[EtapeCompta::ReconciliationRequise->value] = sprintf(
                '%d transaction(s) portent un statut de règlement en désaccord avec le grand livre.',
                $divergences,
            );
        }
```

Et la méthode privée :

```php
    /**
     * Divergences miroir / grand livre sur le périmètre métier uniquement
     * (journaux vente et achat, via scopeOperationnel) — même périmètre que
     * compta:reconcilier-statuts, pour que les deux ne puissent pas se
     * contredire.
     *
     * Coût linéaire dans le nombre de transactions métier, comme la commande
     * de réconciliation elle-même.
     */
    private function divergencesMiroir(): int
    {
        $divergences = 0;

        Transaction::query()
            ->operationnel()
            ->each(function (Transaction $tx) use (&$divergences): void {
                if ($tx->statut_reglement !== $this->etatReglement->resolve($tx)) {
                    $divergences++;
                }
            });

        return $divergences;
    }
```

- [ ] **Step 4: Lancer les tests pour vérifier qu'ils passent**

Run: `./vendor/bin/sail exec -T laravel.test php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Compta/EtatComptaResolverTest.php`

Expected: PASS (9 tests)

- [ ] **Step 5: Commit**

```bash
./vendor/bin/sail exec -T laravel.test ./vendor/bin/pint app/Services/Compta/EtatComptaResolver.php tests/Feature/Compta/EtatComptaResolverTest.php
git checkout -- config/version.php
git add app/Services/Compta/EtatComptaResolver.php tests/Feature/Compta/EtatComptaResolverTest.php
git commit -m "feat(compta): détecte les statuts de règlement en désaccord avec le grand livre"
```

---

## Task 6 : L'exception de refus

**Files:**
- Create: `app/Exceptions/Compta/EtapeComptaRequiseException.php`
- Test: `tests/Feature/Compta/EtatComptaResolverTest.php` (ajout)

- [ ] **Step 1: Écrire le test qui échoue**

Ajouter à `tests/Feature/Compta/EtatComptaResolverTest.php` :

```php
it('produit un refus qui nomme le blocage sans prescrire de commande', function (): void {
    $etat = new App\Services\Compta\EtatCompta(
        EtapeCompta::RepriseInitialeRequise,
        [EtapeCompta::RepriseInitialeRequise->value => '2 compte(s) bancaire(s) portent un solde historique jamais entré dans le grand livre.'],
    );

    $exception = App\Exceptions\Compta\EtapeComptaRequiseException::pour($etat);

    expect($exception->getMessage())
        ->toContain('Soldes d’ouverture non repris')
        ->toContain('solde historique')
        ->not->toContain('artisan')
        ->and($exception->etat->etape)->toBe(EtapeCompta::RepriseInitialeRequise);
});
```

- [ ] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `./vendor/bin/sail exec -T laravel.test php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Compta/EtatComptaResolverTest.php`

Expected: FAIL — `Class "App\Exceptions\Compta\EtapeComptaRequiseException" not found`

- [ ] **Step 3: Écrire l'implémentation minimale**

Créer `app/Exceptions/Compta/EtapeComptaRequiseException.php` :

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Compta;

use App\Services\Compta\EtatCompta;
use RuntimeException;

/**
 * Refus d'une opération dont les préalables comptables ne sont pas réunis.
 *
 * Le message nomme la cause — l'étape manquante et ce qui la bloque — et jamais
 * le remède : celui-ci dépend du support et du tenant, c'est à l'appelant de le
 * composer. Un refus muet est un refus raté, mais un refus qui prescrit un geste
 * que son destinataire ne peut pas accomplir est pire.
 */
final class EtapeComptaRequiseException extends RuntimeException
{
    private function __construct(string $message, public readonly EtatCompta $etat)
    {
        parent::__construct($message);
    }

    public static function pour(EtatCompta $etat): self
    {
        return new self($etat->etape()->label().' — '.$etat->causes(), $etat);
    }
}
```

- [ ] **Step 4: Lancer le test pour vérifier qu'il passe**

Run: `./vendor/bin/sail exec -T laravel.test php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Compta/EtatComptaResolverTest.php`

Expected: PASS (10 tests)

- [ ] **Step 5: Commit**

```bash
./vendor/bin/sail exec -T laravel.test ./vendor/bin/pint app/Exceptions/Compta/EtapeComptaRequiseException.php tests/Feature/Compta/EtatComptaResolverTest.php
git checkout -- config/version.php
git add app/Exceptions/Compta/EtapeComptaRequiseException.php tests/Feature/Compta/EtatComptaResolverTest.php
git commit -m "feat(compta): exception de refus porteuse de l'état comptable"
```

---

## Task 7 : La garde de clôture — régression du 2026-07-29

C'est le cœur du plan. Le test reproduit la situation exacte de la préprod, avec ses chiffres.

**Files:**
- Modify: `app/Services/ClotureCheckService.php`
- Create: `tests/Feature/Compta/ClotureGardePrealablesTest.php`

- [ ] **Step 1: Écrire le test qui échoue**

Créer `tests/Feature/Compta/ClotureGardePrealablesTest.php` :

```php
<?php

declare(strict_types=1);

use App\Enums\OrigineANouveau;
use App\Enums\StatutANouveau;
use App\Models\ANouveauGeneration;
use App\Models\Exercice;
use App\Models\Transaction;
use App\Services\ClotureCheckService;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

/*
 * Régression de la recette préprod du 2026-07-29.
 *
 * L'exercice 2024 a été clôturé sans reprise initiale : l'à-nouveau produit
 * portait 130 € sur le 5121 au lieu de 2 518,82 €, et le Livret Épargne —
 * 24 010 € — était absent du bilan d'ouverture. Aucune garde ne l'a signalé :
 * « Soldes d'ouverture » sortait au vert parce que l'exercice 2023 n'existe pas,
 * et « Aperçu des à-nouveaux » ne teste que l'équilibre débit/crédit.
 */

beforeEach(function (): void {
    $this->setupPartieDoubleContext();

    // Soldes historiques réels de la préprod, jamais repris dans le grand livre.
    $this->compteBancaire->update([
        'solde_initial' => 2388.82,
        'date_solde_initial' => '2024-08-31',
    ]);

    // L'exercice clôturé, sans prédécesseur — le cas de toute première clôture.
    Exercice::create(['annee' => 2024, 'statut' => 'ouvert']);
});

it('refuse la clôture quand les soldes historiques ne sont pas repris', function (): void {
    $resultat = app(ClotureCheckService::class)->executer(2024);

    $garde = collect($resultat->bloquants)->firstWhere('nom', 'Préalables comptables');

    expect($garde)->not->toBeNull()
        ->and($garde->ok)->toBeFalse()
        ->and($garde->message)->toContain('solde historique')
        ->and($garde->message)->not->toContain('artisan');
});

it('autorise la clôture une fois la reprise initiale créée', function (): void {
    ANouveauGeneration::create([
        'association_id' => $this->association->id,
        'exercice_source' => 2023,
        'exercice_cible' => 2024,
        'transaction_id' => Transaction::factory()->create([
            'association_id' => $this->association->id,
            'equilibree' => true,
        ])->id,
        'origine' => OrigineANouveau::RepriseInitiale,
        'statut' => StatutANouveau::Active,
        'cree_par_id' => $this->user->id,
    ]);

    $resultat = app(ClotureCheckService::class)->executer(2024);

    $garde = collect($resultat->bloquants)->firstWhere('nom', 'Préalables comptables');

    expect($garde->ok)->toBeTrue();
});
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `./vendor/bin/sail exec -T laravel.test php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Compta/ClotureGardePrealablesTest.php`

Expected: FAIL — `expect(null)->not->toBeNull()` : la garde « Préalables comptables » n'existe pas.

- [ ] **Step 3: Écrire l'implémentation minimale**

Dans `app/Services/ClotureCheckService.php`, ajouter les imports :

```php
use App\Services\Compta\EtatComptaResolver;
```

Injecter le résolveur dans le constructeur :

```php
    public function __construct(
        private readonly ExerciceService $exerciceService,
        private readonly SoldeService $soldeService,
        private readonly EtatComptaResolver $etatCompta,
    ) {}
```

Ajouter la garde à la liste des bloquants, en première position (elle conditionne tout le reste) :

```php
            bloquants: [
                $this->checkPrealablesComptables(),
                $this->checkOuverturePrecedente($annee),
                $this->checkRapprochementsEnCours($start, $end),
                $this->checkLignesSansCompte($annee),
                $this->checkVirementsDesequilibres($start, $end),
                $this->checkExerciceCible($annee),
                $this->checkANouveau($annee),
            ],
```

Et la méthode :

```php
    /**
     * Les préalables comptables sont-ils réunis ?
     *
     * Garde ajoutée après la recette du 2026-07-29 : une clôture avait été
     * acceptée sans reprise initiale, produisant un à-nouveau amputé des soldes
     * bancaires historiques. checkOuverturePrecedente ne pouvait pas le voir —
     * elle sort au vert dès que l'exercice précédent n'existe pas, ce qui est le
     * cas de toute première clôture, et checkANouveau ne teste que l'équilibre
     * débit/crédit d'un aperçu par ailleurs incomplet.
     */
    private function checkPrealablesComptables(): CheckItem
    {
        $etat = $this->etatCompta->pourTenantCourant();

        return new CheckItem(
            nom: 'Préalables comptables',
            ok: $etat->estOperationnel(),
            message: $etat->estOperationnel()
                ? 'Conversion, reprise des soldes et statuts de règlement sont à jour'
                : $etat->causes().' Ces préalables doivent être traités avant la clôture.',
        );
    }
```

- [ ] **Step 4: Lancer les tests pour vérifier qu'ils passent**

Run: `./vendor/bin/sail exec -T laravel.test php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Compta/ClotureGardePrealablesTest.php`

Expected: PASS (2 tests)

- [ ] **Step 5: Lancer la suite des tests de clôture pour vérifier l'absence de régression**

Run: `./vendor/bin/sail exec -T laravel.test php -d memory_limit=1G ./vendor/bin/pest --filter=Cloture`

Expected: PASS. Si des tests existants échouent parce que leur fixture n'est pas opérationnelle (soldes bancaires non nuls sans reprise), **ne pas affaiblir la garde** : ajouter dans ces fixtures soit un `solde_initial` à 0, soit une génération `reprise_initiale`, selon ce que le test veut démontrer. Consigner chaque fixture ajustée dans le message de commit.

- [ ] **Step 6: Commit**

```bash
./vendor/bin/sail exec -T laravel.test ./vendor/bin/pint app/Services/ClotureCheckService.php tests/Feature/Compta/ClotureGardePrealablesTest.php
git checkout -- config/version.php
git add app/Services/ClotureCheckService.php tests/Feature/Compta/ClotureGardePrealablesTest.php
git commit -m "fix(compta): refuse la clôture sans reprise des soldes historiques"
```

---

## Task 8 : Le refus au niveau du service de clôture

`ExerciceService::cloturer()` ne consulte pas `ClotureCheckService` : les gardes de l'assistant sont **consultatives**. Un appel direct au service — un test, un futur bouton, une requête forgée — clôture sans elles. Cette task donne son appelant à `EtapeComptaRequiseException` et rend la garde réelle.

**Files:**
- Modify: `app/Services/ExerciceService.php:149-163`
- Create: `tests/Feature/Compta/ClotureServiceRefusTest.php`

- [ ] **Step 1: Écrire le test qui échoue**

Créer `tests/Feature/Compta/ClotureServiceRefusTest.php` :

```php
<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Exceptions\Compta\EtapeComptaRequiseException;
use App\Models\Exercice;
use App\Services\ExerciceService;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

it('refuse de clôturer quand les préalables comptables ne sont pas réunis', function (): void {
    $this->setupPartieDoubleContext();

    $this->compteBancaire->update([
        'solde_initial' => 2388.82,
        'date_solde_initial' => '2024-08-31',
    ]);

    $exercice = Exercice::create(['annee' => 2024, 'statut' => 'ouvert']);

    expect(fn () => app(ExerciceService::class)->cloturer($exercice, $this->user))
        ->toThrow(EtapeComptaRequiseException::class);

    expect($exercice->fresh()->statut)->toBe(StatutExercice::Ouvert);
});

it('clôture normalement quand les préalables sont réunis', function (): void {
    $this->setupPartieDoubleContext();

    $this->compteBancaire->update(['solde_initial' => 0]);

    $exercice = Exercice::create(['annee' => 2024, 'statut' => 'ouvert']);

    app(ExerciceService::class)->cloturer($exercice, $this->user);

    expect($exercice->fresh()->statut)->toBe(StatutExercice::Cloture);
});
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `./vendor/bin/sail exec -T laravel.test php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Compta/ClotureServiceRefusTest.php`

Expected: FAIL sur le premier — aucune exception n'est levée, l'exercice passe à `cloture`.

- [ ] **Step 3: Écrire l'implémentation minimale**

Dans `app/Services/ExerciceService.php`, ajouter les imports :

```php
use App\Exceptions\Compta\EtapeComptaRequiseException;
use App\Services\Compta\EtatComptaResolver;
```

Puis, dans `cloturer()`, immédiatement après le verrou sur l'exercice (`$exerciceVerrouille = ...->firstOrFail();`) et avant le bloc `if (config('compta.use_partie_double'))` :

```php
            // Défense en profondeur : les gardes de l'assistant de clôture sont
            // consultatives, ce service peut être appelé directement. Refuser ici
            // rend la garde réelle. Un utilisateur normal ne voit jamais cette
            // exception : l'assistant l'a arrêté avant.
            $etatCompta = app(EtatComptaResolver::class)->pourTenantCourant();
            if (! $etatCompta->estOperationnel()) {
                throw EtapeComptaRequiseException::pour($etatCompta);
            }
```

- [ ] **Step 4: Lancer les tests pour vérifier qu'ils passent**

Run: `./vendor/bin/sail exec -T laravel.test php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Compta/ClotureServiceRefusTest.php`

Expected: PASS (2 tests)

- [ ] **Step 5: Lancer les suites clôture et exercice pour vérifier l'absence de régression**

Run: `./vendor/bin/sail exec -T laravel.test php -d memory_limit=1G ./vendor/bin/pest --filter=Cloture` puis `--filter=Exercice`

Expected: PASS. Même consigne qu'à la task précédente : si une fixture existante n'est pas opérationnelle, **ajuster la fixture, jamais affaiblir la garde**.

- [ ] **Step 6: Commit**

```bash
./vendor/bin/sail exec -T laravel.test ./vendor/bin/pint app/Services/ExerciceService.php tests/Feature/Compta/ClotureServiceRefusTest.php
git checkout -- config/version.php
git add app/Services/ExerciceService.php tests/Feature/Compta/ClotureServiceRefusTest.php
git commit -m "fix(compta): le service de clôture refuse lui-même sans préalables"
```

---

## Task 9 : La commande `compta:etat`

**Files:**
- Create: `app/Console/Commands/EtatComptaCommand.php`
- Create: `tests/Feature/Console/EtatComptaCommandTest.php`

- [ ] **Step 1: Écrire le test qui échoue**

Créer `tests/Feature/Console/EtatComptaCommandTest.php` :

```php
<?php

declare(strict_types=1);

use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

beforeEach(function (): void {
    $this->setupPartieDoubleContext();
});

it('sort en code 0 et annonce l’état opérationnel', function (): void {
    $this->compteBancaire->update(['solde_initial' => 0]);

    $this->artisan('compta:etat')
        ->expectsOutputToContain('Opérationnel')
        ->assertExitCode(0);
});

it('sort en code non nul avec --check quand un préalable manque', function (): void {
    $this->compteBancaire->update(['solde_initial' => 2388.82]);

    $this->artisan('compta:etat', ['--check' => true])
        ->expectsOutputToContain('compta:bootstrap-an')
        ->assertExitCode(1);
});

it('sort en code 0 sans --check même quand un préalable manque', function (): void {
    $this->compteBancaire->update(['solde_initial' => 2388.82]);

    $this->artisan('compta:etat')->assertExitCode(0);
});

it('affiche la valeur effective du drapeau partie double', function (): void {
    config()->set('compta.use_partie_double', true);

    $this->artisan('compta:etat')
        ->expectsOutputToContain('use_partie_double')
        ->assertExitCode(0);
});
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `./vendor/bin/sail exec -T laravel.test php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Console/EtatComptaCommandTest.php`

Expected: FAIL — `The command "compta:etat" does not exist.`

- [ ] **Step 3: Écrire l'implémentation minimale**

Créer `app/Console/Commands/EtatComptaCommand.php` :

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EtapeCompta;
use App\Models\Association;
use App\Services\Compta\EtatComptaResolver;
use App\Tenant\TenantContext;
use Illuminate\Console\Command;

/**
 * Diagnostic du parcours comptable, en lecture seule.
 *
 * Répond à deux questions par association : où en est-elle, et quel est le
 * geste légitime suivant. Aucune orchestration : l'opérateur garde la main.
 */
final class EtatComptaCommand extends Command
{
    protected $signature = 'compta:etat
                            {--association= : Restreindre à une association (ID)}
                            {--check : Sortir en code non nul si une association n’est pas opérationnelle}';

    protected $description = 'Affiche l’étape comptable de chaque association et le geste suivant.';

    public function handle(EtatComptaResolver $resolver): int
    {
        $associations = Association::query()
            ->when(
                $this->option('association') !== null,
                fn ($query) => $query->where('id', (int) $this->option('association')),
            )
            ->get();

        if ($associations->isEmpty()) {
            $this->error('Aucune association à diagnostiquer.');

            return self::FAILURE;
        }

        // Valeur effective lue par l'application — pas le contenu du .env.
        // L'écart entre les deux est passé inaperçu le 2026-07-29 (true dans le
        // fichier, false dans le conteneur, faute de recréation de celui-ci).
        $this->line(sprintf(
            'compta.use_partie_double (valeur effective) : %s',
            config('compta.use_partie_double') ? 'true' : 'false',
        ));
        $this->newLine();

        $tousOperationnels = true;
        $precedent = TenantContext::current();

        try {
            foreach ($associations as $association) {
                TenantContext::clear();
                TenantContext::boot($association);

                $etat = $resolver->pourTenantCourant();

                $this->line(sprintf(
                    'Association #%d (%s) — %s',
                    (int) $association->id,
                    $association->nom,
                    $etat->etape()->label(),
                ));

                // Un remède par blocage, et non un seul pour l'étape courante :
                // quand deux préalables manquent, l'opérateur doit voir les deux
                // gestes. La clé du tableau porte l'étape concernée.
                foreach ($etat->blocages as $cle => $blocage) {
                    $this->line('  ⚠️  '.$blocage);

                    $remede = $this->remede(EtapeCompta::from((string) $cle), (int) $association->id);
                    if ($remede !== null) {
                        $this->line('     → '.$remede);
                    }
                }

                if (! $etat->estOperationnel()) {
                    $tousOperationnels = false;
                }
            }
        } finally {
            TenantContext::clear();
            if ($precedent !== null) {
                TenantContext::boot($precedent);
            }
        }

        return $tousOperationnels || ! $this->option('check')
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * Le remède, composé ici — la console est la seule couche qui connaisse à la
     * fois le support et le tenant. L'énumération ne porte que la cause : une
     * commande artisan placée là remontait jusque dans l'assistant de clôture,
     * sous les yeux d'un trésorier qui ne peut pas l'exécuter.
     *
     * Deux des trois gestes se restreignent à une association — le backfill via
     * `--asso`, la reprise via `--association` (obligatoire). La réconciliation
     * n'a pas d'option et reste nécessairement globale : on le dit plutôt que de
     * laisser croire le contraire.
     */
    private function remede(EtapeCompta $etape, int $associationId): ?string
    {
        return match ($etape) {
            EtapeCompta::BackfillRequis => sprintf(
                'php artisan compta:backfill-partie-double --all --asso=%d',
                $associationId,
            ),
            EtapeCompta::RepriseInitialeRequise => sprintf(
                'php artisan compta:bootstrap-an --association=%d --exercice=<année> --dry-run,'
                .' puis --confirmer — voir docs/runbooks/2026-07-22-reprise-initiale-a-nouveaux.md',
                $associationId,
            ),
            EtapeCompta::ReconciliationRequise => 'php artisan compta:reconcilier-statuts'
                .' (global : cette commande n’a pas d’option par association)',
            EtapeCompta::Operationnel => null,
        };
    }
}
```

- [ ] **Step 4: Lancer les tests pour vérifier qu'ils passent**

Run: `./vendor/bin/sail exec -T laravel.test php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Console/EtatComptaCommandTest.php`

Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
./vendor/bin/sail exec -T laravel.test ./vendor/bin/pint app/Console/Commands/EtatComptaCommand.php tests/Feature/Console/EtatComptaCommandTest.php
git checkout -- config/version.php
git add app/Console/Commands/EtatComptaCommand.php tests/Feature/Console/EtatComptaCommandTest.php
git commit -m "feat(compta): commande compta:etat, diagnostic du parcours comptable"
```

---

## Task 10 : La garde sur `compta:bootstrap-an`

L'ordre est backfill puis reprise : reprendre des soldes sur un grand livre encore incomplet produirait des montants faux.

**Files:**
- Modify: `app/Console/Commands/BootstrapANouveauCommand.php`
- Modify: `tests/Feature/Console/BootstrapANouveauCommandTest.php`

- [ ] **Step 1: Écrire le test qui échoue**

Ajouter à `tests/Feature/Console/BootstrapANouveauCommandTest.php`. Ce fichier n'utilise pas `CreatesPartieDoubleContext` : son `beforeEach` expose `$this->associationBootstrap` et `$this->acteurBootstrap`.

```php
it('refuse la reprise tant que des transactions ne sont pas converties', function (): void {
    Transaction::factory()->create([
        'association_id' => $this->associationBootstrap->id,
        'equilibree' => false,
        'helloasso_order_id' => null,
    ]);

    $this->artisan('compta:bootstrap-an', [
        '--association' => $this->associationBootstrap->id,
        '--acteur' => $this->acteurBootstrap->id,
        '--exercice' => 2025,
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('compta:backfill-partie-double')
        ->assertExitCode(1);
});
```

- [ ] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `./vendor/bin/sail exec -T laravel.test php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Console/BootstrapANouveauCommandTest.php`

Expected: FAIL — la commande ne mentionne pas le backfill et sort en 0.

- [ ] **Step 3: Écrire l'implémentation minimale**

Dans `app/Console/Commands/BootstrapANouveauCommand.php`, ajouter les imports :

```php
use App\Enums\EtapeCompta;
use App\Services\Compta\EtatComptaResolver;
```

Puis, dans `handle()`, **après** le boot de `TenantContext` sur l'association cible et **avant** toute lecture comptable :

```php
        // Ordre imposé : convertir avant de reprendre. Reprendre des soldes sur
        // un grand livre incomplet produirait des montants faux.
        $etat = app(EtatComptaResolver::class)->pourTenantCourant();
        if ($etat->exige(EtapeCompta::BackfillRequis)) {
            $this->error($etat->etape()->label().' — '.$etat->causes());
            $this->line('  → php artisan compta:backfill-partie-double --all --asso='.(int) $associationOption);

            return self::FAILURE;
        }
```

- [ ] **Step 4: Lancer le test pour vérifier qu'il passe**

Run: `./vendor/bin/sail exec -T laravel.test php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Console/BootstrapANouveauCommandTest.php`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
./vendor/bin/sail exec -T laravel.test ./vendor/bin/pint app/Console/Commands/BootstrapANouveauCommand.php tests/Feature/Console/BootstrapANouveauCommandTest.php
git checkout -- config/version.php
git add app/Console/Commands/BootstrapANouveauCommand.php tests/Feature/Console/BootstrapANouveauCommandTest.php
git commit -m "fix(compta): refuse la reprise initiale avant la fin du backfill"
```

---

## Task 11 : Documentation de la séquence et clôture

**Files:**
- Modify: `docs/compta-partie-double.md` (§ 8)

- [ ] **Step 1: Documenter la séquence dans le runbook de cutover**

Dans `docs/compta-partie-double.md`, § 8 « Activer la partie double en production », ajouter en tête de la séquence :

```markdown
0. Diagnostiquer l'état de départ :
   ```bash
   php artisan compta:etat
   ```
   La commande nomme l'étape de chaque association et le geste suivant. Elle
   affiche aussi la valeur **effective** de `compta.use_partie_double` telle que
   l'application la lit — en déploiement conteneurisé, éditer le `.env` ne suffit
   pas, il faut recréer le conteneur.

   Reprendre `compta:etat` après chaque étape : elle est la source unique de
   vérité sur ce qui reste à faire. L'assistant de clôture refuse désormais de
   clôturer tant qu'elle n'annonce pas « Opérationnel ».
```

- [ ] **Step 2: Lancer la suite complète**

Run: `./vendor/bin/sail exec -T laravel.test php -d memory_limit=1G ./vendor/bin/pest --compact`

Expected: 0 failed. Conserver la sortie complète pour diagnostiquer un échec éventuel.

- [ ] **Step 3: Commit**

```bash
git checkout -- config/version.php
git add docs/compta-partie-double.md
git commit -m "docs(compta): compta:etat en tête de la séquence de bascule"
```

---

## Vérification finale

- [ ] `./vendor/bin/sail exec -T laravel.test php -d memory_limit=1G ./vendor/bin/pest --compact` → 0 failed
- [ ] `./vendor/bin/sail artisan compta:etat` sur la base locale → affiche une étape cohérente avec les données
- [ ] Aucun `git push` n'a été fait
- [ ] `config/version.php` n'apparaît dans aucun commit
