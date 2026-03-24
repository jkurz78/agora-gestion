# Double espace Comptabilité / Gestion

**Date :** 2026-03-24
**Statut :** Draft
**Lot :** 1 — Fondations (navigation, routing, layout)

## Contexte

L'application SVS Accounting gère aujourd'hui la comptabilité associative. Un nouvel espace "Gestion" doit être créé pour accueillir progressivement le suivi opérationnel : adhérents (ex-membres), opérations avec séances/participants, suivi de présence et suivi financier par participant.

Ce lot 1 pose uniquement les fondations : deux espaces distincts avec navigation séparée, sans modification du métier existant.

### Vision future (hors lot 1)

Les lots suivants enrichiront l'espace Gestion avec :
- Opérations enrichies : séances datées, participants (Tiers inscrits), animateur (User)
- Suivi de présence par séance (Présent/Absent/Excusé + notes animateur, chiffré en BDD)
- Suivi financier par participant : montant prévu vs réalisé, échéanciers
- Bordereaux de remise (chèques/espèces) avec détail par participant
- Fiche adhérent 360° (historique cotisations, dons, participations aux opérations)
- Dashboard Gestion enrichi
- Formations HelloAsso (version light du pilotage, inscription/paiement via HelloAsso)

## Périmètre lot 1

1. Architecture deux espaces avec URL préfixées (`/compta/`, `/gestion/`)
2. Switcher d'espace dans l'en-tête, persisté en BDD par utilisateur
3. Navbar et footer colorés selon l'espace
4. Menus répartis entre les deux espaces
5. Dashboard Gestion minimal
6. Migration de "Membres" vers "Adhérents" dans l'espace Gestion

## Architecture routage

### Principe

Deux groupes de routes Laravel avec préfixe, un middleware `DetecteEspace` qui transmet l'espace courant à la vue.

### Structure URL

```
/                              → redirect vers /{dernier_espace}/dashboard
/compta/dashboard              → Dashboard comptabilité (existant)
/compta/transactions           → Transactions
/compta/transactions/all       → Toutes les transactions
/compta/dons                   → Dons
/compta/cotisations            → Cotisations
/compta/tiers                  → Tiers
/compta/tiers/{tiers}/transactions → Transactions d'un tiers
/compta/budget                 → Budget
/compta/rapprochement          → Rapprochement bancaire
/compta/rapprochement/{id}     → Détail rapprochement
/compta/virements              → Virements internes
/compta/operations             → Opérations (resource)
/compta/rapports               → Rapports
/compta/parametres/*           → Paramètres (association, catégories, etc.)
/compta/exercices/*            → Exercices (clôture, changer, audit)

/gestion/dashboard             → Dashboard gestion (nouveau)
/gestion/adherents             → Liste adhérents (ex /membres)
/gestion/parametres/*          → Paramètres (mêmes contrôleurs)
```

### Middleware DetecteEspace

- Lit le segment URL pour déterminer l'espace (`compta` ou `gestion`)
- Stocke l'espace dans la request (via `request->attributes`)
- Met à jour `users.dernier_espace` en BDD (si utilisateur connecté)
- Partage avec les vues : `$espace`, `$espaceColor`, `$espaceLabel`

### Nommage des routes

Routes préfixées par l'espace :
- `compta.dashboard`, `compta.transactions.index`, `compta.tiers.index`...
- `gestion.dashboard`, `gestion.adherents`...

### Redirects legacy

Routes sans préfixe redirigent en 301 vers leur équivalent préfixé :
- `/dashboard` → `/compta/dashboard`
- `/transactions` → `/compta/transactions`
- `/membres` → `/gestion/adherents`
- `/operations` → `/compta/operations`
- Etc.

À retirer après quelques mois.

### Route racine

`GET /` redirige vers `/{user->dernier_espace}/dashboard` si connecté, sinon vers `/compta/dashboard` (puis page de login via middleware auth).

## Layout et switcher

### Layout unique paramétré

Un seul `layouts/app.blade.php` recevant les variables d'espace du middleware.

### Couleurs

| Espace | Couleur navbar/footer |
|---|---|
| Comptabilité | `#722281` (violet, inchangé) |
| Gestion | `#63B2EA` (bleu ciel) |

Seuls la navbar et le footer changent de couleur. Le reste de l'app (tableaux, boutons) conserve ses styles actuels.

### Switcher

Positionné dans le header, sous le nom de l'association (là où figure actuellement "Comptabilité" en texte).

- Affiché comme un dropdown Bootstrap
- Montre l'espace actif avec un indicateur visuel
- Cliquer sur l'autre espace redirige vers `/{autre_espace}/dashboard`
- La mise à jour de `dernier_espace` est gérée par le middleware à l'arrivée

### Menus par espace

**Comptabilité (`/compta`) :**
- Transactions (dropdown : Recettes & Dépenses, Dons, Cotisations, Toutes les transactions)
- Banques (dropdown : Rapprochement, Virements, Sync HelloAsso, Comptes bancaires)
- Tiers
- Budget
- Rapports
- Exercices (dropdown : Clôturer/Réouvrir, Changer d'exercice, Piste d'audit)
- Paramètres (dropdown)
- Menu utilisateur

Note : "Membres" est retiré de cet espace.

**Gestion (`/gestion`) :**
- Adhérents
- Sync HelloAsso (lien direct dans la barre)
- Paramètres (dropdown)
- Menu utilisateur

## Dashboard Gestion

### Route

`GET /gestion/dashboard` → Composant Livewire `GestionDashboard`

### Contenu

Trois cartes Bootstrap :

**Carte 1 — Opérations**
Liste des opérations de l'exercice courant :
- Nom de l'opération
- Dates (début → fin)
- Badge statut : "Dans X jours" (date_debut future) / "En cours" (entre début et fin) / "Terminée" (clôturée ou date_fin passée)
- Lien vers la fiche opération (`/compta/operations/{id}`)

**Carte 2 — Dernières adhésions**
5-10 dernières transactions de type cotisation (recette avec sous-catégorie `pour_cotisations`) :
- Date, nom du tiers, montant

**Carte 3 — Derniers dons**
5-10 dernières transactions de type don (sous-catégorie `pour_dons`) :
- Date, nom du tiers, montant

Si la carte 3 s'avère trop complexe lors de l'implémentation, elle sera reportée.

## Migration Membres → Adhérents

- Le composant `MembreList` est renommé en `AdherentList`
- La vue `membres/index.blade.php` est adaptée en `gestion/adherents.blade.php`
- Le fonctionnel reste identique pour ce lot (liste, filtres cotisation à jour/en retard)
- Le terme "Membres" est remplacé par "Adhérents" dans toute l'interface

## Changements techniques

### Nouveaux fichiers

| Fichier | Description |
|---|---|
| `app/Http/Middleware/DetecteEspace.php` | Middleware détection et persistance espace |
| `database/migrations/xxxx_add_dernier_espace_to_users_table.php` | Colonne `dernier_espace` sur users |
| `app/Livewire/EspaceSwitcher.php` | Composant dropdown switcher |
| `app/Livewire/GestionDashboard.php` | Dashboard espace Gestion |
| `resources/views/gestion/dashboard.blade.php` | Vue dashboard Gestion |
| `resources/views/gestion/adherents.blade.php` | Vue liste adhérents |

### Fichiers modifiés

| Fichier | Nature du changement |
|---|---|
| `routes/web.php` | Restructuration en deux groupes préfixés + redirects legacy |
| `resources/views/layouts/app.blade.php` | Navbar/footer dynamiques, intégration switcher, menus conditionnels |
| `app/Models/User.php` | Ajout attribut `dernier_espace`, cast enum |
| `app/Livewire/MembreList.php` → `AdherentList.php` | Renommage + adaptation route |
| Toutes les vues et composants Livewire | Mise à jour des appels `route()` avec préfixe `compta.` |

### Ce qui ne change pas

- Modèles métier (Transaction, Tiers, Operation...)
- Services (TransactionService, TiersService...)
- Contrôleurs existants (seuls les noms de routes changent)
- Logique métier et règles de gestion

## Migration BDD

```sql
ALTER TABLE users ADD COLUMN dernier_espace ENUM('compta', 'gestion') NOT NULL DEFAULT 'compta';
```

## Tests

- Test middleware `DetecteEspace` : espace correctement détecté et persisté
- Test redirects legacy : 301 vers les bonnes URL préfixées
- Test route `/` : redirection selon `dernier_espace` de l'utilisateur
- Test dashboard Gestion : affichage des 3 cartes avec données
- Test `AdherentList` : fonctionnel identique à l'ancien `MembreList` sous nouvelle route
- Test navigation : les menus affichent les bons liens selon l'espace
