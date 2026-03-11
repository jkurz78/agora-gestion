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

### 14 Models from 16 Tables

| Model | Table | Soft Delete | Key Relationships |
|---|---|---|---|
| `User` | `users` | No | hasMany Depense, Recette, Don |
| `CompteBancaire` | `comptes_bancaires` | No | hasMany Depense, Recette, Don, Cotisation |
| `Categorie` | `categories` | No | hasMany SousCategorie |
| `SousCategorie` | `sous_categories` | No | belongsTo Categorie, hasMany BudgetLine |
| `Operation` | `operations` | No | hasMany DepenseLigne, RecetteLigne, Don |
| `Depense` | `depenses` | Yes | belongsTo User, CompteBancaire; hasMany DepenseLigne |
| `DepenseLigne` | `depense_lignes` | Yes | belongsTo Depense, SousCategorie, Operation |
| `Recette` | `recettes` | Yes | belongsTo User, CompteBancaire; hasMany RecetteLigne |
| `RecetteLigne` | `recette_lignes` | Yes | belongsTo Recette, SousCategorie, Operation |
| `Membre` | `membres` | No | hasMany Cotisation |
| `Cotisation` | `cotisations` | Yes | belongsTo Membre, CompteBancaire |
| `Donateur` | `donateurs` | No | hasMany Don |
| `Don` | `dons` | Yes | belongsTo Donateur (nullable), User, CompteBancaire, Operation |
| `BudgetLine` | `budget_lines` | No | belongsTo SousCategorie |

Soft deletes apply to financial records only: depenses, depense_lignes, recettes, recette_lignes, dons, cotisations.

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
- `scopeForExercice(Builder $query, int $exercice)`: shared Eloquent scope filtering `WHERE date BETWEEN ...`

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
| `OperationController` | `operations.*` | Index, show (linked transactions summary), create, store, edit, update |
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
| `DonList` | dons index | Filtered list |
| `CotisationForm` | membre show | Quick inline cotisation entry |
| `Rapprochement` | rapprochement | Toggle pointe across 4 transaction types, live solde theorique |
| `Dashboard` | dashboard | KPIs, recent transactions, pending cotisations, exercice selector |
| `BudgetTable` | budget | Inline edit prevu amounts, live prevu vs realise |
| `RapportCompteResultat` | rapports | Exercice + operation filters, CERFA output, CSV export |
| `RapportSeances` | rapports | Operation selector, pivot table, CSV export |

### Route Structure

All routes under `auth` middleware. Dashboard is home (`/`). French URL slugs: `/depenses`, `/recettes`, `/membres`, `/dons`, `/operations`, `/budget`, `/rapprochement`, `/rapports`, `/parametres`.

## Service Layer

Controllers stay thin. Business logic lives in services:

| Service | Responsibility |
|---|---|
| `ExerciceService` | Calculate current exercice, date ranges, list available exercices |
| `DepenseService` | Create/update depense + lignes in DB transaction, validate lignes sum = montant_total |
| `RecetteService` | Mirror of DepenseService |
| `RapprochementService` | Toggle pointe on any transaction type, compute solde theorique for compte + periode |
| `RapportService` | Aggregate compte de resultat by code_cerfa, build seance pivot table, generate CSV |
| `BudgetService` | Compute realise amounts per sous-categorie + exercice |

### Form Requests

Dedicated `FormRequest` for each store/update: `StoreDepenseRequest`, `UpdateDepenseRequest`, `StoreMembreRequest`, etc. Nested validation for ligne arrays (e.g., `lignes.*.montant` must sum to `montant_total`).

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

Every model gets a factory:
- `DepenseFactory` with `afterCreating` generating 1-3 `DepenseLigne` records
- `RecetteFactory` — same pattern
- `MembreFactory` with `withCotisation(exercice)` state
- `OperationFactory` with `withSeances(n)` state

### Not Tested

- Breeze auth internals (tested upstream)
- Eloquent relationship definitions (covered implicitly by feature tests)

## Out of Scope (V2)

- PDF fiscal receipts (recus fiscaux)
- File attachments / justificatifs
- Full CERFA export (bilan + compte de resultat)
- Bank statement CSV import
- Role-based access control
