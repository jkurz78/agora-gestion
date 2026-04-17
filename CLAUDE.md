# AgoraGestion — Conventions développement

## Stack

- Laravel 11 + Livewire 4 + Bootstrap 5 (CDN, pas de Vite/npm)
- MySQL via Docker (Laravel Sail)
- Tests : Pest PHP

## Démarrage rapide

```bash
# Démarrer l'environnement Docker
./vendor/bin/sail up -d

# Migrations + seeds
./vendor/bin/sail artisan migrate:fresh --seed

# App sur http://localhost (port 80)
```

Comptes dev : `admin@monasso.fr / password` (Admin), `jean@monasso.fr / password` (Utilisateur).

## Conventions de code

- `declare(strict_types=1)` + `final class` + type hints sur toutes les méthodes.
- PSR-12 via `./vendor/bin/sail artisan pint`.
- Locale `fr` partout (labels, validation, Faker).
- SoftDeletes sur modèles financiers (`Depense`, `Recette`, `Don`).
- En-têtes de tableaux : `table-dark` + `style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880"`.
- Tri de colonnes : JS côté client, `data-sort` sur les `<td>` pour dates (ISO Y-m-d) et nombres.
- `wire:confirm` : toujours via modale Bootstrap — jamais `confirm()` natif.
- Cast `(int)` obligatoire des deux côtés dans les `===` PK/FK (MySQL prod retourne des strings).
- TinyMCE 6 self-hosted dans `public/vendor/tinymce/`, chargé dans `layouts/app.blade.php`.

## Architecture

- Services métier dans `app/Services/` avec `DB::transaction()`.
- Composants Livewire dans `app/Livewire/`.
- Exercice comptable : 1er sept → 31 août, scope `forExercice(int $annee)`.

## Multi-tenant (depuis v3.0)

- Tout modèle tenant-scopé étend `App\Models\TenantModel` (scope global fail-closed sur `association_id` — retourne `WHERE 1 = 0` si `TenantContext` non booté).
- `TenantContext::currentId()` disponible partout — s'appuyer dessus pour toute query brute.
- Tests feature qui touchent aux données tenant-scopées : étendre `Tests\Support\TenantTestCase` (ou laisser le bootstrap global `tests/Pest.php` s'en occuper).
- Jobs : capturer `association_id` en propriété, booter `TenantContext::boot($association)` dans `handle()`.
- URLs dans emails/PDFs : `App\Support\TenantUrl` (prépare v3.1 sous-domaines) — jamais `route()` directement.
- Stockage : `storage/app/associations/{id}/…` (ID numérique, pas slug — immuable).
- Cache keys : inclure `association_id` dans la clé.
- Logger : `Log::info(...)` porte automatiquement `association_id` + `user_id` (via `App\Support\LogContext` + middleware `BootTenantConfig`).
- Super-admin : `$user->role_systeme === RoleSysteme::SuperAdmin` (helper `$user->isSuperAdmin()`).
- Doc complète : [docs/multi-tenancy.md](docs/multi-tenancy.md).
- Runbook onboarding : [docs/onboarding-new-tenant.md](docs/onboarding-new-tenant.md).
