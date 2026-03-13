# Transactions par compte bancaire — Spec

## Objectif

Permettre de consulter toutes les transactions d'un compte bancaire (recettes, dépenses, dons, cotisations, virements internes) dans une vue unifiée, paginée, avec recherche par tiers et filtre par date, affichage du solde courant, et possibilité de modifier ou supprimer chaque transaction.

---

## Partie 1 — Renommage du champ tiers (prérequis)

Unifier la terminologie "tiers" partout dans l'application.

### Migrations
- Renommer `recettes.payeur` → `recettes.tiers` (nullable string, même contrainte)
- Renommer `depenses.beneficiaire` → `depenses.tiers` (nullable string, même contrainte)

### Fichiers impactés
- `App\Models\Recette` : `$fillable` (`payeur` → `tiers`)
- `App\Models\Depense` : `$fillable` (`beneficiaire` → `tiers`)
- `App\Livewire\RecetteForm` : propriété `$payeur` → `$tiers`, règle de validation, méthode `edit()`
- `App\Livewire\DepenseForm` : propriété `$beneficiaire` → `$tiers`, règle de validation, méthode `edit()`
- Vues Blade des formulaires Livewire : labels et `wire:model`
- Tous les tests faisant référence à `payeur` ou `beneficiaire`

---

## Partie 2 — Vue unifiée des transactions par compte

### Accès depuis la navbar

Nouvel item **"Transactions"** ajouté en top-level dans la navbar principale (au même niveau que Recettes, Dépenses, etc.), pointant vers `/comptes-bancaires/transactions`.

### Route et page

```
GET /comptes-bancaires/transactions
```

Contrôleur simple qui retourne la vue Blade `comptes-bancaires/transactions.blade.php`. Celle-ci ne contient qu'un `<x-app-layout>` avec le composant `<livewire:transaction-compte-list />`.

### Colonnes affichées

| Colonne | Description |
|---|---|
| Date | Format dd/mm/yyyy |
| Type | Recette / Dépense / Don / Cotisation / Virement entrant / Virement sortant |
| Tiers | Voir UNION ci-dessous |
| Libellé | Voir UNION ci-dessous |
| Référence | Recettes, dépenses, virements ; vide pour dons et cotisations |
| Montant | Signé : positif en vert, négatif en rouge, formaté avec 2 décimales |
| Pointé | Icône `bi-check-circle-fill text-success` si `pointe = true` ; vide pour virements (pas de champ pointage) |
| Solde courant | Masqué si filtre tiers actif ou tri ≠ date ASC (voir règles) |
| Actions | Boutons Modifier et Supprimer (voir section dédiée) |

### Structure UNION SQL

Chaque branche produit exactement les mêmes colonnes aliasées. La cotisation utilise `date_paiement AS date`. Les dons et cotisations nécessitent un JOIN pour obtenir le tiers.

```sql
-- Recette
SELECT
    r.id, 'recette' AS source_type, r.date,
    'Recette' AS type_label,
    r.tiers,
    r.libelle,
    r.reference,
    r.montant_total AS montant,        -- positif
    r.mode_paiement,
    r.pointe
FROM recettes r
WHERE r.compte_id = ? AND r.deleted_at IS NULL

UNION ALL

-- Dépense
SELECT
    d.id, 'depense', d.date,
    'Dépense',
    d.tiers,
    d.libelle,
    d.reference,
    -d.montant_total,                  -- négatif
    d.mode_paiement,
    d.pointe
FROM depenses d
WHERE d.compte_id = ? AND d.deleted_at IS NULL

UNION ALL

-- Don
SELECT
    dn.id, 'don', dn.date,
    'Don',
    CONCAT(do.prenom, ' ', do.nom),   -- JOIN donateurs
    dn.objet,
    NULL,                              -- pas de référence
    dn.montant,
    dn.mode_paiement,
    dn.pointe
FROM dons dn
LEFT JOIN donateurs do ON do.id = dn.donateur_id
WHERE dn.compte_id = ? AND dn.deleted_at IS NULL

UNION ALL

-- Cotisation
SELECT
    c.id, 'cotisation', c.date_paiement AS date,  -- alias obligatoire
    'Cotisation',
    CONCAT(m.prenom, ' ', m.nom),     -- JOIN membres
    CONCAT('Cotisation ', c.exercice),
    NULL,
    c.montant,
    c.mode_paiement,
    c.pointe
FROM cotisations c
LEFT JOIN membres m ON m.id = c.membre_id
WHERE c.compte_id = ? AND c.deleted_at IS NULL

UNION ALL

-- Virement sortant (ce compte est la source)
SELECT
    vi.id, 'virement_sortant', vi.date,
    'Virement sortant',
    cb.nom,                            -- compte destination
    CONCAT('Virement vers ', cb.nom),
    vi.reference,
    -vi.montant,                       -- négatif
    NULL,                              -- pas de mode_paiement
    NULL                               -- pas de pointe
FROM virements_internes vi
JOIN comptes_bancaires cb ON cb.id = vi.compte_destination_id
WHERE vi.compte_source_id = ? AND vi.deleted_at IS NULL

UNION ALL

-- Virement entrant (ce compte est la destination)
SELECT
    vi.id, 'virement_entrant', vi.date,
    'Virement entrant',
    cb.nom,                            -- compte source
    CONCAT('Virement depuis ', cb.nom),
    vi.reference,
    vi.montant,                        -- positif
    NULL,
    NULL
FROM virements_internes vi
JOIN comptes_bancaires cb ON cb.id = vi.compte_source_id
WHERE vi.compte_destination_id = ? AND vi.deleted_at IS NULL
```

**Note sur les virements en boucle** : si `compte_source_id = compte_destination_id`, le virement apparaît deux fois (sortant + entrant, s'annulant). La prévention de cette situation est garantie au niveau de la saisie des virements (contrainte applicative existante). Aucune garde supplémentaire n'est nécessaire dans ce service.

### Tri et stabilité

Tri par défaut : **date ASC, puis `source_type` ASC, puis `id` ASC** (tri stable intra-journée — `id` étant propre à chaque table, le tri secondaire par `source_type` garantit un ordre déterministe entre types différents à la même date).

L'utilisateur peut changer le tri sur les colonnes : date (ASC/DESC), montant, type, tiers.

### Filtres

- **Compte** (`$compteId`) : select parmi tous les comptes bancaires. Si `null`, la liste n'est pas chargée et un message invite à sélectionner un compte.
- **Date début / Date fin** : filtre `WHERE date BETWEEN ? AND ?` appliqué à chaque branche
- **Tiers** (`$searchTiers`) : filtre `WHERE tiers LIKE '%?%'` appliqué à chaque branche (sur la colonne tiers aliasée, via sous-requête wrappant la UNION)

### Pagination

15 lignes par page.

### Solde courant

Visible uniquement quand : `$searchTiers` est vide **et** `$sortColumn = 'date'` **et** `$sortDirection = 'asc'`.

**Calcul du solde avant la page courante :**

Le service exécute une seconde requête wrappant la UNION complète (avec les mêmes filtres date, sans filtre tiers si on est dans le cas affichage solde) et calcule `SUM(montant)` sur les `($page - 1) * $perPage` premières lignes via une sous-requête avec `LIMIT offset` :

```sql
SELECT SUM(montant) FROM (
    SELECT montant FROM ( /* UNION complète */ ) AS u
    ORDER BY date ASC, source_type ASC, id ASC
    LIMIT {offset}
) AS avant_page
```

`soldeAvantPage = $compte->solde_initial + (float) $sumAvant`

**Calcul ligne par ligne (PHP) :**

```php
$solde = $soldeAvantPage;
foreach ($transactions as $tx) {
    $solde += $tx->montant;
    $tx->solde_courant = $solde;
}
```

Quand le solde est masqué, un message discret sous le tableau explique pourquoi.

### Actions par ligne — Modifier et Supprimer

Chaque ligne affiche deux boutons dans la colonne Actions :

**Modifier :** navigue vers la page existante du type concerné avec le formulaire d'édition pré-ouvert. Le composant Livewire cible dispatch un événement `edit-{type}` avec l'id :

| Type | Action |
|---|---|
| Recette | `redirect()->to('/recettes')` + dispatch `edit-recette:{id}` via session flash, ou lien direct `href="/recettes#edit-{id}"` |
| Dépense | idem vers `/depenses` |
| Don | idem vers `/dons` |
| Cotisation | lien vers la page du membre `/membres/{membre_id}` |
| Virement | lien vers `/virements` |

Implémentation recommandée : **redirection simple vers la page du type** avec un paramètre query `?edit={id}` que le composant Livewire cible lit au montage pour ouvrir le formulaire d'édition. Cela réutilise entièrement les formulaires existants sans dupliquer de logique.

**Supprimer :** confirmation JavaScript (`confirm()`), puis appel à une méthode Livewire `deleteTransaction(string $sourceType, int $id)` qui dispatche vers le service approprié :

| `source_type` | Service appelé |
|---|---|
| `recette` | `RecetteService::delete()` |
| `depense` | `DepenseService::delete()` |
| `don` | `DonService::delete()` |
| `cotisation` | `CotisationService::delete()` |
| `virement_sortant` / `virement_entrant` | `VirementService::delete()` |

Les transactions verrouillées par un rapprochement (`isLockedByRapprochement() === true`) ont leurs boutons désactivés (attribut `disabled`). La méthode `deleteTransaction()` vérifie également le verrou **côté serveur** avant de déléguer au service, pour ne pas dépendre uniquement du bouton désactivé côté UI.

---

## Architecture technique

### Nouveaux fichiers

| Fichier | Rôle |
|---|---|
| `app/Services/TransactionCompteService.php` | Construit la UNION, applique les filtres, retourne paginator + soldeAvantPage |
| `app/Livewire/TransactionCompteList.php` | Composant Livewire : état filtres/tri/pagination, suppression |
| `resources/views/livewire/transaction-compte-list.blade.php` | Vue du composant |
| `resources/views/comptes-bancaires/transactions.blade.php` | Page Blade conteneur |

### Fichiers modifiés

| Fichier | Modification |
|---|---|
| `database/migrations/…_rename_payeur_to_tiers_on_recettes.php` | Renommage colonne |
| `database/migrations/…_rename_beneficiaire_to_tiers_on_depenses.php` | Renommage colonne |
| `app/Models/Recette.php` | `$fillable` |
| `app/Models/Depense.php` | `$fillable` |
| `app/Livewire/RecetteForm.php` | `$payeur` → `$tiers` |
| `app/Livewire/DepenseForm.php` | `$beneficiaire` → `$tiers` |
| `resources/views/livewire/recette-form.blade.php` | Label + wire:model |
| `resources/views/livewire/depense-form.blade.php` | Label + wire:model |
| `routes/web.php` | Nouvelle route GET |
| Vue navbar | Nouvel item "Transactions" top-level |
| Tests existants | `payeur` → `tiers`, `beneficiaire` → `tiers` |

### Composant Livewire `TransactionCompteList`

Propriétés publiques :
- `?int $compteId = null`
- `string $dateDebut` — 1er septembre de l'exercice courant
- `string $dateFin` — 31 août de l'exercice courant + 1
- `string $searchTiers = ''`
- `string $sortColumn = 'date'`
- `string $sortDirection = 'asc'`

`render()` : si `$compteId` est null, retourne la vue avec une collection vide et sans appel au service.

---

## Tests

### Renommage tiers
- Tests `RecetteTest` et `DepenseTest` mis à jour (`payeur` → `tiers`, `beneficiaire` → `tiers`)

### `TransactionCompteService`
- Chaque type de transaction apparaît dans la liste du bon compte
- Un virement sortant apparaît négatif sur le compte source, positif sur la destination
- Les filtres date début/fin filtrent correctement
- La recherche tiers filtre correctement
- `soldeAvantPage` est correct pour une page 1 (= 0 transactions avant) et une page 2
- Le calcul PHP ligne par ligne accumule correctement sur plusieurs transactions d'une même page
- Les transactions soft-deleted n'apparaissent pas

### `TransactionCompteList` (Livewire)
- Aucun appel au service si `$compteId` est null
- Changer le compte recharge la liste
- La suppression d'une recette non verrouillée fonctionne
- La suppression d'une recette verrouillée échoue silencieusement (bouton désactivé)
