# Refonte écran Sous-catégories

**Date :** 2026-03-28
**Statut :** Validé

## Contexte

L'écran de gestion des sous-catégories (`/parametres/sous-categories`) souffre de plusieurs problèmes UX :

1. **Tri réinitialisé** — Les toggles Dons/Cotisations/Inscriptions font un POST + rechargement complet de page, ce qui perd le tri JS côté client
2. **Édition incohérente** — Le bouton Modifier recharge la page et transforme la ligne en champs éditables, mais cliquer sur une cellule ne fait rien
3. **Colonnes Dons/Cotisations/Inscriptions** — Pas de possibilité de filtrer dessus
4. **Formulaire de création** — Bloc dépliable peu élégant, incohérent avec le reste

## Design

### Architecture technique

- **Remplacement** de la page Blade statique (`parametres/sous-categories/index.blade.php`) par un **composant Livewire** `SousCategorieList`
- Le `SousCategorieController` est simplifié : seule la méthode `index()` subsiste pour rendre la vue contenant le composant Livewire
- Les toggles de flags passent par `wire:click` → plus de rechargement de page
- Le **tri reste en JS côté client** — puisqu'il n'y a plus de rechargement, l'état est préservé
- Les **filtres** (Type, Catégorie, Dons/Cotisations/Inscriptions) restent en JS côté client
- Alpine.js gère l'édition inline des cellules
- Les cellules éditables inline utilisent `wire:ignore.self` pour empêcher Livewire de détruire l'état Alpine lors du morph DOM

### Interactions

| Action | Déclencheur | Comportement |
|--------|-------------|--------------|
| **Créer** | Bouton "Ajouter une sous-catégorie" en haut | Modale Bootstrap |
| **Modifier (complet)** | Bouton crayon (✏) par ligne | Modale Bootstrap pré-remplie |
| **Modifier (rapide)** | Clic sur cellule Nom ou Code CERFA | Cellule devient un `<input>`, sauvegarde au blur/Entrée, Échap annule |
| **Toggle flag** | Clic sur pastille Dons/Cotisations/Inscriptions dans la ligne | `wire:click` Livewire, pas de rechargement |
| **Supprimer** | Bouton poubelle (🗑) par ligne | `wire:confirm` puis suppression Livewire |
| **Filtrer par flag** | Clic sur en-tête colonne Dons/Cotisations/Inscriptions | Bascule JS "tous" ↔ "seulement cochés" (vert = filtré, gris = tous) |
| **Trier** | Clic sur en-tête Catégorie/Nom/Code CERFA | Tri JS côté client, état préservé |
| **Filtrer type/catégorie** | Radios Type (Tout/Recettes/Dépenses) + dropdown Catégorie | JS côté client, inchangé |

### Modale Créer / Modifier

- **Un seul composant** avec titre dynamique ("Ajouter une sous-catégorie" vs "Modifier la sous-catégorie")
- Champs :
  - **Catégorie** — select (requis), liste des catégories
  - **Nom** — text input (requis, max 100 caractères)
  - **Code CERFA** — text input (optionnel, max 10 caractères)
  - **Flags** — 3 checkboxes inline : Dons, Cotisations, Inscriptions
- Validation Livewire en temps réel
- Fermeture automatique après succès + flash message de confirmation
- La modale est gérée via Livewire + événements Bootstrap (`wire:click` pour ouvrir, dispatch d'événement JS pour fermer)

### Édition inline

- Applicable uniquement sur **Nom** et **Code CERFA**
  - La catégorie nécessite un select → édition via modale uniquement
- **Clic** sur la cellule → le texte est remplacé par un `<input>` pré-rempli avec focus automatique
- **Entrée** ou **blur** → sauvegarde via appel Livewire (`updateField`)
- **Échap** → annulation, retour au texte original
- Implémenté via **Alpine.js** (`x-data`, `x-on:click`, `x-on:keydown`, `x-ref`)
- Feedback visuel : bordure bleue sur le champ actif (cohérent avec Bootstrap)
- En cas d'erreur de validation (nom vide, trop long), affichage d'un toast d'erreur et retour au texte original

### Filtres en en-tête de colonne (Dons / Cotisations / Inscriptions)

- Chaque en-tête de colonne flag affiche un **badge cliquable** :
  - **État "tous"** : badge gris `tous` → aucun filtrage
  - **État "filtré"** : badge vert `✓ filtré` → ne montre que les lignes où le flag est actif
- Clic sur le badge = bascule entre les deux états
- Filtre **côté client** (JS), se combine en AND avec les filtres Type et Catégorie existants
- Le tri est préservé lors de l'activation/désactivation des filtres

### Tableau

- **En-têtes** : style existant (`table-dark` avec `--bs-table-bg:#3d5473`)
- **Colonnes triables** : Catégorie, Nom, Code CERFA — icônes de tri (▲▼) dans l'en-tête
- **Colonnes flag** : pastilles colorées (vert ● = actif, gris ○ = inactif) — cliquables pour toggle
- **Colonne Actions** : bouton crayon (modifier → modale) + bouton poubelle (supprimer)

### Suppression — gestion des contraintes FK

La méthode `delete()` du composant Livewire attrape `QueryException` (code `23000`) et affiche un message d'erreur flash si la sous-catégorie est utilisée dans des transactions, budgets, dons, cotisations, etc. Même pattern que le controller actuel.

### Ce qui ne change pas

- Le modèle `SousCategorie` et ses relations
- Le composant `SousCategorieAutocomplete` (utilisé ailleurs)
- Les filtres Type (radios) et Catégorie (dropdown) en haut de page

## Fichiers impactés

| Fichier | Action |
|---------|--------|
| `app/Livewire/SousCategorieList.php` | **Créer** — nouveau composant Livewire |
| `resources/views/livewire/sous-categorie-list.blade.php` | **Créer** — vue du composant |
| `resources/views/parametres/sous-categories/index.blade.php` | **Modifier** — simplifier pour inclure le composant Livewire |
| `app/Http/Controllers/SousCategorieController.php` | **Modifier** — ne garder que `index()` |
| `routes/web.php` | **Modifier** — supprimer les routes `store`, `update`, `destroy`, `toggle-flag` |
| `app/Http/Requests/StoreSousCategorieRequest.php` | **Supprimer** — validation déplacée dans le composant Livewire |
| `app/Http/Requests/UpdateSousCategorieRequest.php` | **Supprimer** — validation déplacée dans le composant Livewire |
| `tests/Feature/SousCategorieTest.php` | **Réécrire** — remplacer tests HTTP par tests Livewire (`Livewire::test()`) |

## Validation

- [ ] Cocher/décocher un flag ne réinitialise pas le tri
- [ ] Clic sur cellule Nom → édition inline → Entrée sauvegarde
- [ ] Clic sur cellule Code CERFA → édition inline → Échap annule
- [ ] Bouton crayon → modale pré-remplie → Enregistrer met à jour la ligne
- [ ] Bouton Ajouter → modale vide → Enregistrer crée la ligne
- [ ] Filtres en en-tête Dons/Cotisations/Inscriptions fonctionnent
- [ ] Filtres se combinent en AND (Type + Catégorie + flags)
- [ ] Tri préservé après toggle, édition inline, filtre
- [ ] Suppression avec confirmation, message d'erreur si FK
