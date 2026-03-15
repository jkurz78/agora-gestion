# Tiers — Transactions et Refonte Membres

## Objectif

Deux améliorations liées à l'univers tiers/membres :

1. **Transactions d'un tiers** — page dédiée listant toutes les transactions (dépenses, recettes, dons, cotisations) pour un tiers donné, avec tri et filtres.
2. **Refonte Membres** — remplacer l'écran membres basé sur `statut_membre` par une vue Livewire dérivant le statut des cotisations enregistrées.

---

## Feature 1 — Transactions d'un tiers

### Route et accès

- Route : `GET /tiers/{id}/transactions` → vue Blade `tiers/transactions.blade.php` contenant `<livewire:tiers-transactions :tiersId="$tiers->id" />`
- Bouton `bi-clock-history` dans la colonne actions de `tiers-list.blade.php` (entre Modifier et Supprimer)
- En-tête de page : « Transactions — [Nom du tiers] » avec lien retour vers `/tiers`

### Composant Livewire `TiersTransactions`

**Props :** `public int $tiersId`

**Filtres (propriétés Livewire) :**
- `string $typeFilter = ''` — Tous / depense / recette / don / cotisation
- `string $dateDebut = ''`
- `string $dateFin = ''`
- `string $search = ''` — recherche texte sur libellé (debounce 300ms)

**Tri :**
- `string $sortBy = 'date'` — date | type | montant
- `string $sortDir = 'desc'` — asc | desc
- Méthode `sortBy(string $col)` qui bascule la direction si même colonne

**Query — UNION sur 4 tables filtrée par tiers_id :**

```sql
SELECT 'depense' as source_type, d.date, sc.nom as libelle,
       cb.nom as compte, d.montant, NULL as exercice
FROM depenses d
LEFT JOIN comptes_bancaires cb ON cb.id = d.compte_id
LEFT JOIN sous_categories sc ON sc.id = ...  -- via depense_lignes
WHERE d.tiers_id = :tiersId AND d.deleted_at IS NULL

UNION ALL

SELECT 'recette', r.date, sc.nom, cb.nom, r.montant, NULL
FROM recettes r ...
WHERE r.tiers_id = :tiersId AND r.deleted_at IS NULL

UNION ALL

SELECT 'don', d.date, 'Don' as libelle, cb.nom, d.montant, NULL
FROM dons d ...
WHERE d.tiers_id = :tiersId AND d.deleted_at IS NULL

UNION ALL

SELECT 'cotisation', c.date_paiement, CONCAT('Cotisation ', c.exercice),
       cb.nom, c.montant, c.exercice
FROM cotisations c ...
WHERE c.tiers_id = :tiersId AND c.deleted_at IS NULL
```

La query externe applique filtres, tri et pagination (50 par page).

Note : les dépenses et recettes ont plusieurs lignes (DepenseLigne/RecetteLigne), chacune avec une sous-catégorie. Pour la vue transactions, on utilise le libellé de l'en-tête (Depense.libelle) et le montant total (Depense.montant_total), pas les lignes détail.

**Colonnes du tableau :**

| Date | Type | Libellé | Compte | Montant |
|------|------|---------|--------|---------|
| dd/mm/yyyy | badge coloré | texte | nom compte | coloré (rouge dépense/cotisation, vert recette/don) |

**En-têtes cliquables pour tri :** Date ▲▼, Type ▲▼, Montant ▲▼.

**Barre de filtres :**
- Select Type : Tous / Dépense / Recette / Don / Cotisation
- Inputs date de/à
- Input texte recherche libellé

**Pagination Bootstrap** (50 lignes/page).

### Fichiers à créer

- `app/Livewire/TiersTransactions.php`
- `resources/views/livewire/tiers-transactions.blade.php`
- `resources/views/tiers/transactions.blade.php` (layout wrapper)

### Fichiers à modifier

- `routes/web.php` — ajouter la route
- `resources/views/livewire/tiers-list.blade.php` — ajouter bouton `bi-clock-history`

---

## Feature 2 — Refonte Membres

### Suppression du legacy

**Migrations :**
- `dropColumn` sur `tiers` : `statut_membre`, `date_adhesion`, `notes_membre`

**Fichiers à supprimer :**
- `app/Http/Controllers/MembreController.php`
- `app/Http/Requests/StoreMembreRequest.php`
- `app/Http/Requests/UpdateMembreRequest.php`
- `resources/views/membres/create.blade.php`
- `resources/views/membres/edit.blade.php`
- `resources/views/membres/show.blade.php`
- `resources/views/membres/index.blade.php`

**Modèle `Tiers` :**
- Retirer `statut_membre`, `date_adhesion`, `notes_membre` du `$fillable` et `casts()`
- Supprimer `scopeMembres()`

**Routes :**
- Supprimer `Route::resource('membres', MembreController::class)`
- Ajouter `Route::view('/membres', 'membres.index')->name('membres.index')`

### Nouvelle page `/membres`

**Vue `resources/views/membres/index.blade.php` :**
```blade
<x-app-layout>
    <livewire:cotisation-form />
    <livewire:membre-list />
</x-app-layout>
```

### Composant Livewire `MembreList`

**Définition du statut membre :**
- **À jour** : a au moins une cotisation sur l'exercice courant (déterminé par `ExerciceService::exerciceCourant()`)
- **En retard** : a une cotisation sur l'exercice précédent mais aucune sur l'exercice courant
- **Filtre "Tous"** : union des deux catégories ci-dessus (tout tiers ayant au moins une cotisation, tous exercices confondus)

**Propriétés :**
- `string $filtre = 'a_jour'` — a_jour | en_retard | tous
- `string $search = ''`

**Colonnes :**

| Nom | Dernière cotisation | Montant | Mode | Compte | Pointé | Actions |
|-----|--------------------|---------|----|--------|--------|---------|
| 🏢/👤 Nom | dd/mm/yyyy (exercice) | XX,XX € | badge | nom | ✓/— | bouton |

**Bouton "Nouvelle cotisation" par ligne :**
- Dispatche un événement Livewire `open-cotisation-for-tiers` avec `tiersId`
- `CotisationForm` écoute cet événement, se positionne en modal avec le tiers pré-sélectionné

**Bouton global "+ Nouvelle cotisation" en haut :**
- Dispatche `open-cotisation-for-tiers` sans tiersId → formulaire vide

**Adaptation de `CotisationForm` :**
- Ajouter `#[On('open-cotisation-for-tiers')]` sur une méthode `openForTiers(?int $tiersId = null)`
- Si `$tiersId` fourni : pré-renseigner `$this->tiers_id` et les champs de l'autocomplete
- Appeler `showNewForm()`

### Fichiers à créer

- `app/Livewire/MembreList.php`
- `resources/views/livewire/membre-list.blade.php`
- `resources/views/membres/index.blade.php` (nouvelle version)
- `database/migrations/YYYY_MM_DD_drop_membre_columns_from_tiers.php`

### Fichiers à modifier

- `app/Models/Tiers.php` — retirer colonnes legacy
- `app/Livewire/CotisationForm.php` — ajouter `#[On('open-cotisation-for-tiers')]`
- `routes/web.php` — remplacer resource par view

### Fichiers à supprimer

- `app/Http/Controllers/MembreController.php`
- `app/Http/Requests/StoreMembreRequest.php`
- `app/Http/Requests/UpdateMembreRequest.php`
- `resources/views/membres/create.blade.php`
- `resources/views/membres/edit.blade.php`
- `resources/views/membres/show.blade.php`
- `resources/views/membres/index.blade.php` (ancienne version)

---

## Tests

**TiersTransactions :**
- Rendu de la page avec tiers valide
- Filtre par type retourne uniquement les transactions du bon type
- Tri par date asc/desc
- Recherche texte filtre sur libellé
- Tiers sans transactions → message vide

**MembreList :**
- Filtre "À jour" retourne uniquement tiers avec cotisation exercice courant
- Filtre "En retard" retourne tiers avec cotisation N-1 mais pas N
- Clic "Nouvelle cotisation" sur une ligne ouvre CotisationForm avec tiers pré-sélectionné
- CotisationForm sans tiers pre-sélectionné s'ouvre vide

**Migration :**
- Les colonnes supprimées n'existent plus après migration
