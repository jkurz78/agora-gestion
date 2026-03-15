# SVS Accounting — Laravel Rewrite Design

## Overview

Rebuild the SVS Accounting application from procedural PHP to Laravel 11. The app is an internal accounting tool for Association Soigner Vivre Sourire (SVS), a French loi 1901 non-profit. It tracks expenses, income, donations, members, budgets, and produces CERFA-compliant financial reports.

## Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Framework | Laravel 11, PHP 8.2+ | Latest stable, long-term support |
| Frontend | Blade + Livewire 3 (islands) | Reactivity where needed, standard MVC elsewhere |
| CSS | Bootstrap 5.3 (CDN) | Matches existing app, internal tool |
| Auth | Laravel Breeze (no registration) | Login, password reset out of the box |
| Database | MySQL/MariaDB | Same as current app |
| Queue | Sync driver | Low-traffic internal tool, no background jobs needed |
| Testing | Pest PHP | Clean syntax, Laravel-native |
| Code style | Laravel Pint (PSR-12) | Standard |
| Hosting | o2switch VPS, Apache | No special requirements |
| Locale | French (`fr`) | All UI, validation, dates in French |

## Stack & Infrastructure

- **Laravel 11**, PHP 8.2+
- **Blade + Livewire 3** (islands approach — standard controllers for simple CRUD, Livewire for dynamic forms)
- **Laravel Breeze** for auth, views reskinned to Bootstrap 5. Registration route removed (users created in Parametres).
- **MySQL/MariaDB**, sync queue driver
- **Apache** on o2switch VPS
- **Pest PHP** for testing, **Laravel Pint** for code style
- Application locale set to `fr`

## Database Schema & Eloquent Models

### 14 Models + 2 Laravel Tables (16 total)

14 application models map 1:1 to 14 tables. Laravel adds `password_reset_tokens` (Breeze) and `sessions`, bringing the total to 16. The original design stored `reset_token`/`reset_expires_at` on `users` — in Laravel, these are replaced by the `password_reset_tokens` table.

| Model | Table | Soft Delete | Key Relationships |
|---|---|---|---|
| `User` | `users` | No | hasMany Depense, Recette, Don |
| `CompteBancaire` | `comptes_bancaires` | No | hasMany Depense, Recette, Don, Cotisation |
| `Categorie` | `categories` | No | hasMany SousCategorie |
| `SousCategorie` | `sous_categories` | No | belongsTo Categorie, hasMany BudgetLine, DepenseLigne, RecetteLigne |
| `Operation` | `operations` | No | hasMany DepenseLigne, RecetteLigne, Don |
| `Depense` | `depenses` | Yes | belongsTo User (`saisi_par`), CompteBancaire; hasMany DepenseLigne |
| `DepenseLigne` | `depense_lignes` | Yes | belongsTo Depense, SousCategorie, Operation |
| `Recette` | `recettes` | Yes | belongsTo User (`saisi_par`), CompteBancaire; hasMany RecetteLigne |
| `RecetteLigne` | `recette_lignes` | Yes | belongsTo Recette, SousCategorie, Operation |
| `Membre` | `membres` | No | hasMany Cotisation |
| `Cotisation` | `cotisations` | Yes | belongsTo Membre, CompteBancaire. Has `exercice` (INT), `mode_paiement`, `pointe` columns. |
| `Donateur` | `donateurs` | No | hasMany Don |
| `Don` | `dons` | Yes | belongsTo Donateur (nullable), User (`saisi_par`), CompteBancaire, Operation. Has `seance` (INT NULL), `recu_emis` (boolean, for V2 fiscal receipts). |
| `BudgetLine` | `budget_lines` | No | belongsTo SousCategorie. Has `exercice` (INT) — filtered by `WHERE exercice = ?`, not by date range. |

Soft deletes apply to financial records only: depenses, depense_lignes, recettes, recette_lignes, dons, cotisations.

### `saisi_par` Auto-population

The `depenses`, `recettes`, and `dons` tables have a `saisi_par` FK to `users.id`. This is automatically set to `auth()->id()` in the corresponding service's create method.

### PHP Backed Enums

- `TypeCategorie`: `depense`, `recette`
- `ModePaiement`: `virement`, `cheque`, `especes`, `cb`, `prelevement`
- `StatutOperation`: `en_cours`, `cloturee`
- `StatutMembre`: `actif`, `inactif`

### Exercice Logic

The financial year runs September 1 to August 31, identified by the start year (e.g., `2025` = 01/09/2025 - 31/08/2026).

An `ExerciceService` provides:
- `current()`: if `month >= 9` then `year`, else `year - 1`
- `dateRange(int $exercice)`: returns start/end dates
- `scopeForExercice(Builder $query, int $exercice)`: Eloquent scope filtering `WHERE date BETWEEN ...` — used on models with a `date` column (depenses, recettes, dons, cotisations via `date_paiement`)
- `BudgetLine` and `Cotisation` also have an `exercice` INT column filtered with a simple `WHERE exercice = ?` scope (not the date-range scope)

## Authentication & Middleware

- **Breeze** scaffolds login, password reset (token + expiring email link)
- **Registration removed** — users created by existing users in Parametres
- **Breeze views reskinned** from Tailwind to Bootstrap 5
- **All routes** wrapped in `auth` middleware except login and password reset
- **No roles** — all authenticated users have equal access
- **CSRF** handled by Laravel's `@csrf` directive
- **Locale** — `app.locale` = `fr`, French validation messages via `lang/fr`

## Routing & Controllers

### Resource Controllers (standard CRUD)

| Controller | Routes | Notes |
|---|---|---|
| `MembreController` | `membres.*` | Index, create, store, show, edit, update, destroy. Cotisation handled on show page. |
| `OperationController` | `operations.*` | Index, show (linked depenses + recettes + dons summary with solde), create, store, edit, update |
| `CategorieController` | `parametres/categories.*` | CRUD under parametres |
| `SousCategorieController` | `parametres/sous-categories.*` | CRUD under parametres |
| `CompteBancaireController` | `parametres/comptes-bancaires.*` | CRUD under parametres |
| `UserController` | `parametres/utilisateurs.*` | Create & delete only |

Parametres page uses tabs to group all settings controllers.

### Livewire Components (reactive islands)

| Component | Page | Why Livewire |
|---|---|---|
| `DepenseForm` | depenses create/edit | Dynamic ventilation lines, auto-sum, seance selector |
| `RecetteForm` | recettes create/edit | Same pattern, symmetrical |
| `DepenseList` | depenses index | Live filters (periode, categorie, operation, compte, pointe) |
| `RecetteList` | recettes index | Same pattern |
| `DonForm` | dons create/edit | Donateur search/create inline, seance selector |
| `DonList` | dons index | Filtered list, click donateur name to see fiche donateur (modal or inline) with full donation history |
| `CotisationForm` | membre show | Quick inline cotisation entry |
| `Rapprochement` | rapprochement | Toggle pointe across 4 transaction types, live solde theorique |
| `Dashboard` | dashboard | KPIs, recent transactions, pending cotisations, exercice selector |
| `BudgetTable` | budget | Add/edit/delete budget lines, inline edit prevu amounts, live prevu vs realise |
| `RapportCompteResultat` | rapports | Exercice + operation filters, CERFA output, CSV export |
| `RapportSeances` | rapports | Operation selector, pivot table, CSV export |

### Route Structure

All routes under `auth` middleware. Dashboard is home (`/`). French URL slugs: `/depenses`, `/recettes`, `/membres`, `/dons`, `/operations`, `/budget`, `/rapprochement`, `/rapports`, `/parametres`.

## Service Layer

Controllers stay thin. Business logic lives in services:

| Service | Responsibility |
|---|---|
| `ExerciceService` | Calculate current exercice, date ranges, list available exercices |
| `DepenseService` | Create/update depense + lignes in DB transaction, validate lignes sum = montant_total, set `saisi_par` |
| `RecetteService` | Mirror of DepenseService |
| `DonService` | Create/update don with donateur lookup/creation, link to operation/seance, set `saisi_par` |
| `CotisationService` | Create/delete cotisation for a membre, link to compte bancaire |
| `RapprochementService` | Toggle pointe on any of the 4 transaction types (depense, recette, don, cotisation). Compute solde theorique: `solde_initial + pointed_recettes + pointed_dons + pointed_cotisations - pointed_depenses` |
| `RapportService` | Aggregate compte de resultat by code_cerfa, build seance pivot table, generate CSV |
| `BudgetService` | Compute realise amounts per sous-categorie + exercice |

### Form Requests

Dedicated `FormRequest` for controller-based routes: `StoreMembreRequest`, `StoreOperationRequest`, etc. For Livewire components (DepenseForm, RecetteForm, DonForm), validation is handled inside the component's `save()` method using Livewire's `$this->validate()`, including the sum constraint (`lignes.*.montant` must equal `montant_total`). The service layer then performs the DB write.

### DB Transactions

Multi-table writes (depense + lignes, recette + lignes) wrapped in `DB::transaction()`.

## Frontend & Layout

### Layout

Single Blade layout `layouts/app.blade.php`:
- Bootstrap 5.3 + Bootstrap Icons via CDN
- Livewire styles/scripts directives
- Responsive navbar with module links
- Flash message component
- Exercice selector in navbar

### View Organization

```
resources/views/
├── layouts/
│   └── app.blade.php
├── auth/                    # Breeze views reskinned to Bootstrap
│   ├── login.blade.php
│   ├── forgot-password.blade.php
│   └── reset-password.blade.php
├── membres/
│   ├── index.blade.php
│   ├── create.blade.php
│   ├── edit.blade.php
│   └── show.blade.php      # includes CotisationForm Livewire component
├── operations/
│   ├── index.blade.php
│   ├── create.blade.php
│   ├── edit.blade.php
│   └── show.blade.php
├── parametres/
│   └── index.blade.php     # tabbed: categories, sous-categories, comptes, users
├── depenses/
│   └── index.blade.php     # DepenseList + DepenseForm components
├── recettes/
│   └── index.blade.php     # RecetteList + RecetteForm components
├── dons/
│   └── index.blade.php     # DonList + DonForm components
├── budget/
│   └── index.blade.php     # BudgetTable component
├── rapprochement/
│   └── index.blade.php     # Rapprochement component
├── rapports/
│   └── index.blade.php     # RapportCompteResultat + RapportSeances components
└── dashboard.blade.php     # Dashboard component
```

### UI Approach

- Bootstrap 5 look and feel — internal tool, no custom design system
- All labels, buttons, validation messages in French
- Responsive tables with `.table-responsive`
- Modals for delete confirmations
- No custom CSS framework — Bootstrap utilities sufficient

## Testing Strategy

### Pest PHP

| Type | Scope | Coverage |
|---|---|---|
| Feature tests | HTTP tests for every controller route (index, store, update, destroy). Assert redirects, DB state, auth. | Every route |
| Livewire tests | `Livewire::test()` for each component — add/remove lines, validation, submit, filter. | Every component |
| Unit tests | ExerciceService (Aug/Sep boundary), RapprochementService (solde calc), RapportService (CERFA aggregation), BudgetService (realise). | Every service method |

### Factories

Every model gets a factory. Notable ones with custom states or hooks:
- `DepenseFactory` with `afterCreating` generating 1-3 `DepenseLigne` records
- `RecetteFactory` — same pattern
- `MembreFactory` with `withCotisation(exercice)` state
- `OperationFactory` with `withSeances(n)` state
- `DonFactory` with optional donateur and operation linking
- `CotisationFactory` with exercice and mode_paiement
- Standard factories for: `UserFactory` (Breeze default), `CompteBancaireFactory`, `CategorieFactory`, `SousCategorieFactory`, `DonateurFactory`, `BudgetLineFactory`

### Not Tested

- Breeze auth internals (tested upstream)
- Eloquent relationship definitions (covered implicitly by feature tests)

## Migration Strategy

This is a **greenfield rebuild** — the Laravel app is built from scratch on the `laravel-rewrite` branch. The existing procedural PHP code on `main` serves as reference only and will be replaced.

If there is existing production data to migrate, a one-time migration script will be written after the Laravel app is functional. This is not part of the MVP build — it will be handled separately once the schema is finalized and tested.

## Out of Scope (V2)

- PDF fiscal receipts (recus fiscaux) — `dons.recu_emis` column is included in the schema for future use
- File attachments / justificatifs
- Full CERFA export (bilan + compte de resultat)
- Bank statement CSV import
- Role-based access control (not in original design, added as future consideration)
