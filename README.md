# AgoraGestion

Application de comptabilite pour association loi 1901 (non-profit). Construite avec Laravel 11, Livewire 4, et Bootstrap 5.

Licence : [AGPL-3.0](LICENSE) — vous pouvez utiliser, modifier et redistribuer ce code, y compris pour heberger une instance accessible par reseau, a condition de publier vos modifications sous la meme licence.

Pour installer une instance en production, voir [docs/INSTALL.md](docs/INSTALL.md).

## Demarrage rapide

### Prerequis

- Docker & Docker Compose
- Composer

### Installation

```bash
git clone <repo-url> && cd agora-gestion
composer install
cp .env.example .env
php artisan key:generate
```

### Lancer avec Docker (Sail)

```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate:fresh --seed
```

L'app tourne sur **http://localhost**.

### Lancer sans Docker

```bash
# Configurer .env avec une base MySQL ou SQLite locale
php artisan migrate:fresh --seed
composer run dev
```

`composer run dev` lance en parallele : `artisan serve`, `queue:listen`, `pail` (logs), et `npm run dev`.

L'app tourne sur **http://localhost:8000**.

### Comptes dev

| Email | Mot de passe | Role |
|-------|-------------|------|
| `admin@monasso.fr` | `password` | Admin (Marie Dupont) |
| `jean@monasso.fr` | `password` | Utilisateur (Jean Martin) |

Le seeder cree aussi : 3 comptes bancaires, des categories/sous-categories, 2 operations avec seances, des depenses, recettes, membres, cotisations et dons.

## Hot Reload

- **Livewire** gere le rechargement automatique des composants cote serveur. Les changements dans les classes Livewire (`app/Livewire/`) et leurs vues (`resources/views/livewire/`) sont pris en compte au prochain appel sans redemarrage.
- **Blade** : les vues sont recompilees automatiquement a chaque requete en mode `APP_DEBUG=true`.
- **Pas de build frontend** : Bootstrap et Bootstrap Icons sont charges via CDN. Pas de Vite, pas de `npm install` necessaire.

## Exercice comptable

L'exercice va du **1er septembre au 31 aout**. L'exercice 2025 = sept 2025 a aout 2026. Toutes les requetes utilisent le scope `forExercice(int $annee)`.

## Tests

```bash
php artisan test                  # Tous les tests
php artisan test --coverage       # Avec couverture
./vendor/bin/pint --test          # Verifier le formatage PSR-12
```

Les tests utilisent **Pest PHP**. Il y a des tests Feature (auth, CRUD), Unit (services), et Livewire (composants).

## Formatage

```bash
./vendor/bin/pint                 # Appliquer Laravel Pint (PSR-12)
```

---

## Architecture

### Structure

```
app/
├── Enums/           # TypeCategorie, ModePaiement, StatutMembre, StatutOperation
├── Http/
│   ├── Controllers/ # CRUD simples (Membre, Operation, Parametres)
│   └── Requests/    # Validation des formulaires
├── Livewire/        # 12 composants reactifs (formulaires + listes)
├── Models/          # 14 modeles Eloquent
├── Services/        # Logique metier (8 services)
└── View/Components/ # Composants Blade reutilisables

database/
├── migrations/      # 13 migrations domaine + 3 Laravel
├── seeders/         # Donnees de dev realistes
└── factories/       # 14 factories pour les tests

resources/views/
├── layouts/         # app.blade.php (navbar Bootstrap)
├── livewire/        # Vues des composants Livewire
└── [modules]/       # Vues par module (membres, operations, parametres...)
```

### Patterns principaux

**Controllers minces, Services epais** : les controllers ne font que valider et deleguer aux services. Toute la logique metier vit dans `app/Services/`.

```
Requete → Controller (validation) → Service (logique + DB::transaction) → Response
```

**Livewire pour l'interactivite** : les formulaires dynamiques (lignes de depense, creation inline de donateur) et les listes avec recherche/filtre sont des composants Livewire full-page.

```
Route::view('/depenses', 'depenses.index')  →  <livewire:depense-list />
                                                <livewire:depense-form />
```

**Events Livewire** : les composants communiquent par evenements (`depense-saved`, `edit-depense`).

### Modeles & Relations

```
User ──hasMany──→ Depense, Recette, Don (via saisi_par)

CompteBancaire ──hasMany──→ Depense, Recette, Cotisation, Don

Categorie ──hasMany──→ SousCategorie ──hasMany──→ DepenseLigne, RecetteLigne, BudgetLine

Operation ──hasMany──→ DepenseLigne, RecetteLigne, Don

Depense ──hasMany──→ DepenseLigne (montant reparti par sous-categorie/operation/seance)
Recette ──hasMany──→ RecetteLigne

Membre ──hasMany──→ Cotisation
Donateur ──hasMany──→ Don
```

### Enums

| Enum | Valeurs |
|------|---------|
| `TypeCategorie` | `Depense`, `Recette` |
| `ModePaiement` | `Virement`, `Cheque`, `Especes`, `Cb`, `Prelevement` |
| `StatutMembre` | `Actif`, `Inactif` |
| `StatutOperation` | `EnCours`, `Cloturee` |

---

## Guide pour le vibe coding

Ce projet est concu pour etre developpe avec un assistant IA (Claude Code). Voici les regles a suivre.

### Conventions obligatoires

- **`declare(strict_types=1);`** en haut de chaque fichier PHP
- **`final class`** sauf si l'heritage est explicitement necessaire
- **Type hints** sur tous les parametres et retours de methode
- **PHP 8.2+** : utiliser readonly, enums, typed properties
- **PSR-12** : lancer `./vendor/bin/pint` avant chaque commit
- **Locale `fr`** : labels, messages de validation, et Faker en francais

### Creer une nouvelle fonctionnalite

1. **Migration** : `php artisan make:migration create_xxx_table` — verifier avec `php artisan migrate:status`
2. **Model** : `php artisan make:model Xxx` — ajouter relations, casts, scopes, fillable
3. **Factory + Seeder** : pour les donnees de test
4. **Service** : creer `app/Services/XxxService.php` — encapsuler la logique dans `DB::transaction()`
5. **Livewire** : `php artisan make:livewire XxxForm` / `XxxList` — formulaire + liste
6. **Vue Blade** : creer la page dans `resources/views/xxx/index.blade.php` avec les composants Livewire
7. **Route** : ajouter dans `routes/web.php` sous le middleware `auth`
8. **Tests** : ecrire les tests Pest (Feature + Livewire) — viser >85% de couverture

### Regles d'architecture

| Regle | Pourquoi |
|-------|----------|
| Pas de logique metier dans les controllers | Les controllers valident et delegent, c'est tout |
| Pas de requetes SQL brutes | Utiliser Eloquent + scopes. N+1 = eager loading avec `::with()` |
| Transactions pour les ecritures multi-tables | `DB::transaction()` dans les services |
| SoftDeletes sur les modeles financiers | Depense, Recette, Don — ne jamais supprimer definitivement |
| Scope `forExercice(int)` | Toute requete liee a une periode doit filtrer par exercice (sept-aout) |
| Validation dans FormRequest | Pas de validation inline dans les controllers |
| Enums PHP pour les types fixes | `ModePaiement`, `TypeCategorie`, etc. — pas de strings magiques |

### Ajouter au frontend

- **Bootstrap 5** via CDN — pas de build frontend
- **Bootstrap Icons** pour les icones (`<i class="bi bi-xxx"></i>`)
- **Livewire 4** pour l'interactivite — pas besoin de JavaScript custom
- Si du JS custom est necessaire, l'ajouter inline avec `@push('scripts')` dans la vue

### Commandes utiles

```bash
php artisan make:model Xxx -mf         # Model + migration + factory
php artisan make:livewire XxxForm      # Composant Livewire
php artisan make:request StoreXxxRequest  # Form request
php artisan route:list --path=xxx      # Verifier les routes
php artisan test --filter=Xxx          # Tests cibles
php artisan migrate:fresh --seed       # Reset complet
./vendor/bin/pint                      # Formatage PSR-12
```

---

## Reception de documents par mail (v2.8)

L'application peut recevoir automatiquement des documents PDF par email -- en particulier
les feuilles d'emargement signees scannees par un copieur multifonction.

### Prerequis

- Extension PHP `imagick` (disponible sur la plupart des hebergeurs Laravel, activable
  via le support ou cPanel si absente)
- Une boite mail dediee sur votre domaine (ex: `emargement@votreasso.fr`)
- Le scheduler Laravel active dans cron :

  ```
  * * * * * cd /chemin/vers/agora-gestion && php artisan schedule:run >> /dev/null 2>&1
  ```

### Configuration

1. Se connecter en tant qu'admin
2. Parametres -> Reception de documents par mail
3. Onglet « Configuration IMAP » : saisir les credentials de la boite mail dediee
4. Cliquer « Tester la connexion » pour verifier
5. Onglet « Expediteurs autorises » : ajouter au moins l'adresse de votre copieur
6. Retour onglet Configuration : activer l'ingestion
7. Optionnel : lancer manuellement `php artisan incoming-mail:fetch` pour un premier test

### Parcours de la feuille d'emargement

1. Generer la feuille PDF dans l'application (onglet seances d'une operation)
2. Imprimer la feuille -- elle contient un QR code unique en haut a droite
3. Faire signer en seance
4. Scanner la feuille (PDF, page 1 doit etre la feuille avec le QR)
5. Envoyer le scan par mail depuis une adresse whitelistee a votre boite dediee
6. Dans les 5 minutes, la feuille est automatiquement attachee a la bonne seance

Alternative : upload manuel depuis la vue seance (bouton « Attacher »).

### Documents non auto-routes

Les PDFs qui n'ont pas de QR code valide (ou pas de QR du tout) atterrissent dans
« Documents en attente » (menu principal). Un humain peut les attacher manuellement
a la bonne seance.

### Rotation de APP_KEY

Si la cle Laravel est rotee, le mot de passe IMAP stocke en base devient illisible.
Il faudra le ressaisir dans la page Parametres apres rotation. Cette operation est
rare (deja necessaire pour les session cookies, password reset tokens, etc.).
