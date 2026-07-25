# Task 7 — Écran des postes tiers ouverts

## TDD

- **RED** — `php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/Livewire/PostesTiersOuvertsTest.php`
  a échoué comme attendu : route `comptabilite.postes-tiers-ouverts` absente et composant
  `App\\Livewire\\Compta\\PostesTiersOuverts` introuvable.
- **GREEN** — la même commande passe avec 2 tests et 22 assertions.

## Complément de couverture des filtres (revue Task 7)

- Le filtre `filtreTiersId` est vérifié avec une seconde dette 401 appartenant à un
  autre fournisseur : elle est absente après sélection du fournisseur attendu.
- La recherche est vérifiée avec un libellé distinct : la dette non correspondante
  est absente après recherche de `dette écran`.
- Le filtre `filtreExerciceOrigine` est vérifié dans l'exercice 2026 avec un report
  d'origine 2025 et une créance directe d'origine 2026 : cette dernière est absente
  après sélection de 2025.
- Le filtre de compte conserve les assertions sur les deux dettes 401 et l'exclusion
  de la créance 411.

Commit de couverture : `096fc2db` (`test(compta): couvrir les filtres des postes tiers ouverts`).

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
  — succès (2 tests, 22 assertions ; 2 dépréciations préexistantes).
- `./vendor/bin/pint tests/Feature/Livewire/PostesTiersOuvertsTest.php`
  — succès.
- `php artisan route:list --path=comptabilite/postes-tiers-ouverts`
  — route GET/HEAD présente.
- `git diff --check` — succès.

## Point d’attention

L’environnement PHP 8.5 signale une dépréciation provenant de la configuration Laravel de
`PDO::MYSQL_ATTR_SSL_CA`. Elle est préexistante et sans lien avec Task 7.
