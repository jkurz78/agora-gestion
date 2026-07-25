# Task 5 — Annulation sécurisée des règlements tiers

Date : 2026-07-25
Branche : `feat/compta-v5`
Base : `0cbd9f26`

## Résultat

Ajout de l’annulation atomique des T2 de règlement non verrouillées et de la
protection du grand livre lors de l’édition d’une transaction déjà réglée.

## TDD

### RED

Commande exécutée avant toute modification de production :

```bash
php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/Services/Compta/AnnulationReglementTiersTest.php
```

Résultat attendu obtenu : 6 échecs, tous causés par
`Call to undefined method App\Services\Compta\PosteTiersReglementService::annuler()`.

Le test de modification du libellé d’une transaction partiellement réglée a
également été ajouté avant le chemin de production correspondant.

### GREEN

```bash
php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/Services/Compta/AnnulationReglementTiersTest.php tests/Feature/Services/TransactionServicePartieDoubleTest.php
```

Résultat : succès, 115 assertions. Les 17 dépréciations affichées sont
préexistantes et non bloquantes.

## Implémentation

- `PosteTiersReglementService::annuler()` verrouille T2/lignes, protège les
  rapprochements, remises et portages 5112/530 déjà lettrés, délettrage via
  `LettrageService`, supprime physiquement la T2, recompose ou rouvre la
  fraction selon l’état du parent, puis resynchronise le statut de la T1.
- `Transaction::aUnReglementTiers()` identifie un règlement direct ou porté
  par une fraction de poste tiers.
- `TransactionService::update()` ne réécrit plus les écritures d’une
  transaction réglée : seuls libellé, référence et notes peuvent changer.

## Tests ajoutés

- annulation totale ;
- annulation partielle avec parent encore ouvert ;
- annulation d’une fraction quand le parent a ensuite été soldé ;
- refus d’une T2 rapprochée ;
- refus d’une T2 liée à une remise ;
- rollback complet si la suppression échoue après le délettrage ;
- modification de libellé/référence/notes conservant parent, fraction, T2 et
  lettrage.

## Fichiers modifiés

- `app/Services/Compta/PosteTiersReglementService.php`
- `app/Models/Transaction.php`
- `app/Services/TransactionService.php`
- `tests/Feature/Services/Compta/AnnulationReglementTiersTest.php`
- `tests/Feature/Services/TransactionServicePartieDoubleTest.php`
- `.superpowers/sdd/task-5-report.md`

## Qualité

```bash
./vendor/bin/pint --test app/Services/Compta/PosteTiersReglementService.php app/Models/Transaction.php app/Services/TransactionService.php tests/Feature/Services/Compta/AnnulationReglementTiersTest.php tests/Feature/Services/TransactionServicePartieDoubleTest.php
git diff --check
```

Résultat : succès.

## Point d’attention

`fresh()` recharge une ligne soft-delete hors scope dans cette version de
Laravel ; le test de fusion vérifie donc explicitement son absence du scope
normal et son état `trashed()` au lieu de l’attendre à `null`.

## Correctifs de revue — concurrence et groupe de lettrage

### RED

Les deux régressions ont été ajoutées avant les changements de production :

```bash
php -d memory_limit=1G ./vendor/bin/pest --compact --filter='refuse un groupe de lettrage tiers à trois lignes' tests/Feature/Services/Compta/AnnulationReglementTiersTest.php
php -d memory_limit=1G ./vendor/bin/pest --compact --filter='relit le poste tiers verrouillé' tests/Feature/Services/TransactionServicePartieDoubleTest.php
```

Résultats RED obtenus : l’annulation ne levait aucune exception face au groupe
à trois lignes ; le contrôle d’édition ne verrouillait pas le lot canonique,
donc le point d’injection déterministe ne se déclenchait pas.

### GREEN

`TransactionService::update()` reprend l’ordre de verrouillage du règlement :
exercice, lot canonique 401/411 (racine et fractions), puis T1. L’état est
ensuite relu avant toute suppression/recréation de ligne. Le test utilise un
listener SQL, accroché au chargement ordonné du lot canonique ; il crée une T2
à cet instant et vérifie que l’édition préserve T2, fraction et lettrage.

`PosteTiersReglementService::annuler()` verrouille et valide le groupe complet
avant toute mutation : deux lignes exactement, une unique ligne tiers de la
T2, et une contrepartie dont la filiation de fraction est cohérente.

```bash
php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/Services/Compta/AnnulationReglementTiersTest.php tests/Feature/Services/TransactionServicePartieDoubleTest.php
./vendor/bin/pint --test app/Services/Compta/PosteTiersReglementService.php app/Services/TransactionService.php tests/Feature/Services/Compta/AnnulationReglementTiersTest.php tests/Feature/Services/TransactionServicePartieDoubleTest.php
git diff --check
```

Résultat : succès, 129 assertions (19 dépréciations préexistantes), Pint et
contrôle de diff réussis.

Commit des correctifs : `3b3453be`.

## Correctif de revue — paire active avant annulation

### RED

Les régressions suivantes ont été ajoutées avant la modification du service :

- une contrepartie source/fraction soft-delete qui conserve le même compte et
  code de lettrage ;
- une paire de deux lignes dont les montants diffèrent ;
- une paire de deux lignes de même sens.

```bash
php -d memory_limit=1G ./vendor/bin/pest --compact --filter='contrepartie de lettrage supprimée|paire de lettrage tiers incohérente' tests/Feature/Services/Compta/AnnulationReglementTiersTest.php
```

Résultat RED obtenu : 3 échecs. La ligne supprimée était acceptée jusqu'au
recalcul du poste, tandis que les paires déséquilibrées ou de même sens étaient
délettrées puis annulées.

### GREEN

Avant `delettrerParLigne()`, `annuler()` charge sous verrou toutes les lignes
du même compte/code, y compris les soft-delete, et refuse toute ligne
supprimée. Il ne poursuit qu'avec exactement deux lignes actives : l'unique
ligne tiers de la T2 et son unique contrepartie active, de filiation de poste
tiers cohérente. Les montants en centimes et les sens débit/crédit sont aussi
validés avant toute mutation.

```bash
php -d memory_limit=1G ./vendor/bin/pest --compact --filter='contrepartie de lettrage supprimée|paire de lettrage tiers incohérente' tests/Feature/Services/Compta/AnnulationReglementTiersTest.php
php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/Services/Compta/AnnulationReglementTiersTest.php tests/Feature/Services/TransactionServicePartieDoubleTest.php
```

Résultat : succès, respectivement 18 et 147 assertions ; les 22 dépréciations
du périmètre complet sont préexistantes et non bloquantes.

### Concurrence

Les tests Pest courants utilisent SQLite en mémoire. Le listener SQL
déterministe couvre donc le chemin de relecture après verrouillage, sans simuler
artificiellement deux connexions concurrentes. Une validation complémentaire
sur MySQL avec deux connexions et les verrous réels reste un candidat de CI ou
de recette manuelle ; aucun changement du mécanisme de verrouillage n'est
nécessaire pour cette correction.

## Correctif de revue — unicité de la ligne tiers lettrée de T2

### RED

La régression crée une T2 malformée avec une seconde ligne 411 lettrée sous un
code distinct et sa contrepartie source. Avant le correctif, `annuler()`
sélectionnait seulement la première ligne de T2 et ne levait aucune exception.

```bash
php -d memory_limit=1G ./vendor/bin/pest --compact --filter='refuse une T2 avec deux lettrages tiers distincts' tests/Feature/Services/Compta/AnnulationReglementTiersTest.php
```

Résultat RED : 1 échec, `RuntimeException` non levée.

### GREEN

Avant toute mutation, `annuler()` collecte les lignes 401/411 de T2 portant un
code de lettrage et exige qu'il y en ait exactement une. La validation du groupe
de ce code unique est ensuite conservée. La régression vérifie que T2 et les
deux paires de lignes conservent leurs codes après le refus.

```bash
php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/Services/Compta/AnnulationReglementTiersTest.php tests/Feature/Services/TransactionServicePartieDoubleTest.php
./vendor/bin/pint --test app/Services/Compta/PosteTiersReglementService.php tests/Feature/Services/Compta/AnnulationReglementTiersTest.php
git diff --check
```

Résultat : succès, 155 assertions et 23 dépréciations préexistantes ; Pint et
contrôle de diff réussis.

Commit du correctif : `a3a9ad0c`.
