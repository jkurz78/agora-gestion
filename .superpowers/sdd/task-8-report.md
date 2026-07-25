# Task 8 — Reports AN et règlement daté dans Transactions

## Résultat

- Les postes tiers reportés sont exposés dans `TransactionUniverselleService` sous la source virtuelle `report_an`.
- Les actions de règlement de Transactions et de la grille Règlements ouvrent la modale partagée `poste-tiers-reglement` ; elles ne créent plus de T2 immédiatement.
- Les reports affichent le badge `Report AN`, gardent l'expansion et le règlement, et ne proposent pas les actions d'édition, de suppression ou d'extourne.

## Preuves TDD

### RED

1. `php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/TransactionUniverselleServiceTest.php`
   - Échec attendu : `Expecting null not to be null` sur la ligne `report_an` absente.
2. `php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/Livewire/TransactionUniverselleMarquerRecuTest.php`
   - Échec attendu : événement `poste-tiers-reglement:ouvrir` absent.
3. `php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/ReglementTableTest.php`
   - Échec attendu : événement `poste-tiers-reglement:ouvrir` absent.

### GREEN final

```text
php -d memory_limit=1G ./vendor/bin/pest --compact \
  tests/Feature/TransactionUniverselleServiceTest.php \
  tests/Feature/Livewire/TransactionUniverselleMarquerRecuTest.php \
  tests/Feature/Livewire/TransactionUniverselleTest.php \
  tests/Feature/ReglementTableTest.php

42 tests passed, 89 assertions
```

`./vendor/bin/pint` a été exécuté sur tous les fichiers PHP modifiés, puis `git diff --check` est passé sans sortie.

## Fichiers modifiés

- `app/Services/Compta/PostesTiersOuvertsService.php`
- `app/Services/TransactionUniverselleService.php`
- `app/Livewire/TransactionUniverselle.php`
- `resources/views/livewire/transaction-universelle.blade.php`
- `app/Livewire/ReglementTable.php`
- `resources/views/livewire/reglement-table.blade.php`
- `tests/Feature/TransactionUniverselleServiceTest.php`
- `tests/Feature/Livewire/TransactionUniverselleMarquerRecuTest.php`
- `tests/Feature/Livewire/TransactionUniverselleTest.php`
- `tests/Feature/ReglementTableTest.php`

## Points d'attention

- La branche `report_an` dépend de l'exercice affiché (`ExerciceService::current()`), conformément aux autres listes comptables.
- Les filtres compte, usage comptable et NDF excluent volontairement les reports ; les filtres de période, tiers, référence, pièce et sens sont couverts par les tests.
