# Task 6 — Modales Livewire de règlement tiers

## Résultat

Deux composants Livewire réutilisables ont été ajoutés :

- `PosteTiersReglementModal` ouvre un poste tiers, préremplit son solde, borne la date au périmètre de l’exercice, valide les champs et enregistre via `PosteTiersReglementService::regler()`.
- `AnnulationReglementTiersModal` présente la date et le montant de la T2, puis annule explicitement via `PosteTiersReglementService::annuler()`.

Les deux vues utilisent des modales Bootstrap pilotées par événements Livewire/Alpine, sans confirmation native.

## Preuve TDD

### RED

Commande exécutée avant toute création de composant :

```bash
php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/Livewire/PosteTiersReglementModalTest.php
```

Résultat : échec attendu, 9 scénarios en `ComponentNotFoundException` car `App\Livewire\Compta\PosteTiersReglementModal` et `AnnulationReglementTiersModal` n’existaient pas encore.

### GREEN

Commande exécutée après l’implémentation, puis à nouveau après Pint :

```bash
php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/Livewire/PosteTiersReglementModalTest.php
```

Résultat : succès, 9 tests et 36 assertions.

## Fichiers modifiés

- `app/Livewire/Compta/PosteTiersReglementModal.php`
- `app/Livewire/Compta/AnnulationReglementTiersModal.php`
- `resources/views/livewire/compta/poste-tiers-reglement-modal.blade.php`
- `resources/views/livewire/compta/annulation-reglement-tiers-modal.blade.php`
- `tests/Feature/Livewire/PosteTiersReglementModalTest.php`

## Vérifications complémentaires

```bash
./vendor/bin/pint app/Livewire/Compta/PosteTiersReglementModal.php app/Livewire/Compta/AnnulationReglementTiersModal.php tests/Feature/Livewire/PosteTiersReglementModalTest.php
git diff --check
```

Les deux commandes réussissent.

## Point d’attention

Le test runner affiche une dépréciation par scénario, issue de Laravel sous PHP 8.5 : `PDO::MYSQL_ATTR_SSL_CA` est dépréciée. Elle est localisée dans `vendor/laravel/framework/config/database.php` et ne provient pas des changements de cette tâche.
