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
