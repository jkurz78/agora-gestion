# Task 7 — Écran des postes tiers ouverts

## TDD

- **RED** — `php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/Livewire/PostesTiersOuvertsTest.php`
  a échoué comme attendu : route `comptabilite.postes-tiers-ouverts` absente et composant
  `App\\Livewire\\Compta\\PostesTiersOuverts` introuvable.
- **GREEN** — la même commande passe avec 2 tests et 15 assertions.

## Fichiers modifiés

- `app/Livewire/Compta/PostesTiersOuverts.php`
- `resources/views/livewire/compta/postes-tiers-ouverts.blade.php`
- `routes/web.php`
- `resources/views/components/sidebar.blade.php`
- `tests/Feature/Livewire/PostesTiersOuvertsTest.php`

## Couverture fonctionnelle

- Route authentifiée et écran Livewire dédié dans l’espace Comptabilité.
- Projection paginée via `PostesTiersOuvertsService::paginer(...)`.
- Filtres compte 401/411, tiers, exercice d’origine et recherche ; chaque modification réinitialise la pagination.
- Références de la transaction d’origine, badge `Report AN`, données de tri date/centimes et action de règlement.
- Évènements de rafraîchissement après enregistrement ou annulation ; modales de règlement et d’annulation incluses une seule fois.

## Vérifications

- `php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/Livewire/PostesTiersOuvertsTest.php`
  — succès (2 tests, 15 assertions).
- `./vendor/bin/pint app/Livewire/Compta/PostesTiersOuverts.php routes/web.php tests/Feature/Livewire/PostesTiersOuvertsTest.php`
  — succès.
- `php artisan route:list --path=comptabilite/postes-tiers-ouverts`
  — route GET/HEAD présente.
- `git diff --check` — succès.

## Point d’attention

L’environnement PHP 8.5 signale une dépréciation provenant de la configuration Laravel de
`PDO::MYSQL_ATTR_SSL_CA`. Elle est préexistante et sans lien avec Task 7.
