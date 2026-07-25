# Task 9 — Date et état de règlement dans le formulaire

## RED

- Ajout de `TransactionFormReglementDateTest` avant le code de production.
- Échec observé : `Public property [$dateReglement] not found on component: [transaction-form]`.

## GREEN

- T1 créée comme créance/dette (mode nul), avec T2 de règlement séparée et atomique.
- Date de T2, règlement à l'édition, état ouvert/partiel/soldé, historique, reliquat et ouverture de l'annulation couverts.
- Libellé modifiable sans casser le lettrage après un règlement.

## Fichiers touchés

- `app/Livewire/TransactionForm.php`
- `resources/views/livewire/transaction-form.blade.php`
- `app/Services/Compta/TransactionAvecReglementService.php`
- `tests/Feature/Livewire/TransactionFormReglementDateTest.php`
- `tests/Feature/Livewire/TransactionFormStatutReglementTest.php`

## Décisions

- L'orchestrateur enveloppe `TransactionService` et `PosteTiersReglementService` dans une transaction SQL afin qu'un échec T2 annule T1.
- Les modales communes sont conservées via les événements `poste-tiers-reglement:ouvrir` et `poste-tiers-reglement:annuler`.
- Les champs comptables sont signalés/verrouillés après règlement ; le libellé et les notes restent éditables.

## Tests exacts

```text
./vendor/bin/pint app/Livewire/TransactionForm.php app/Services/Compta/TransactionAvecReglementService.php tests/Feature/Livewire/TransactionFormReglementDateTest.php tests/Feature/Livewire/TransactionFormStatutReglementTest.php
php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/Livewire/TransactionFormReglementDateTest.php tests/Feature/Livewire/TransactionFormStatutReglementTest.php tests/Feature/Livewire/TransactionFormSensTresorerieTest.php
git diff --check
```

Résultat : Pint passe ; Pest passe (18 tests dépréciés, 51 assertions) ; `git diff --check` passe.

## Commit SHA

Non créé : le `git commit` a été bloqué par la limite d'usage lors de l'escalade nécessaire pour écrire `.git/index.lock`.

## Concerns

- Le commit demandé reste à créer quand l'accès Git sera à nouveau disponible.
