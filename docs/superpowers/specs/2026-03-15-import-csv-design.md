# Import CSV — Dépenses et Recettes

## Contexte

L'association saisit aujourd'hui toutes ses transactions manuellement. L'import CSV permet d'alimenter les dépenses et recettes depuis un fichier tableur (export Excel nettoyé, export bancaire, etc.), de façon ponctuelle ou régulière. Les dons et cotisations sont hors périmètre (traités ultérieurement via l'API HelloAsso).

---

## Décisions structurantes

- **Format** : CSV avec séparateur `;`
- **Atomicité** : tout ou rien — un seul fichier en erreur annule l'intégralité de l'import
- **Doublons** : détectés par `date` + `reference` déjà présents en base → rejet
- **Références inconnues** (tiers, sous-catégorie, compte, opération) : rejet avec message explicite
- **libellé** : devient optionnel (migration)
- **référence** : devient obligatoire (migration + formulaires)
- **montant_total** : calculé automatiquement, non présent dans le CSV

---

## Format du fichier CSV

### En-tête obligatoire

```
date;reference;sous_categorie;montant_ligne;mode_paiement;compte;libelle;tiers;operation
```

### Colonnes

| Colonne | Obligatoire | Description |
|---------|-------------|-------------|
| `date` | Oui | Format `YYYY-MM-DD` |
| `reference` | Oui | Référence externe (facture, chèque…). Clé de regroupement avec `date` |
| `sous_categorie` | Oui | Nom exact de la sous-catégorie. Doit exister en base |
| `montant_ligne` | Oui | Montant décimal > 0, séparateur `.` |
| `mode_paiement` | Oui (1ère ligne du groupe) | `virement`, `cheque`, `especes`, `cb`, `prelevement` |
| `compte` | Oui (1ère ligne du groupe) | Nom exact du compte bancaire. Doit exister en base |
| `libelle` | Non | Texte libre, lu sur la 1ère ligne du groupe uniquement |
| `tiers` | Non | Nom affiché exact (`displayName()`). Doit exister en base si renseigné |
| `operation` | Non | Nom exact de l'opération. Doit exister en base si renseignée |

### Regroupement en transactions

Les lignes partageant la même `date` + `reference` forment **une seule transaction** avec plusieurs lignes comptables. Le `montant_total` est la somme des `montant_ligne` du groupe.

Les champs `mode_paiement`, `compte`, `libelle`, `tiers` sont lus sur la **première ligne** du groupe ; les lignes suivantes peuvent les laisser vides.

### Exemple

```
date;reference;sous_categorie;montant_ligne;mode_paiement;compte;libelle;tiers;operation
2024-09-15;FAC-001;Fournitures;100.00;virement;Compte principal;Achat papeterie;MAISON DUPONT;
2024-09-15;FAC-001;Communication;50.00;virement;Compte principal;;;
2024-09-20;CHQ-042;Déplacements;75.00;cheque;Compte principal;Frais déplacement;;AG 2024
```

Résultat : 2 transactions créées, la première avec 2 lignes comptables (montant_total = 150 €), la seconde avec 1 ligne (75 €).

---

## Modifications du schéma existant

### Migration sur `depenses` et `recettes`

- `libelle` : `NOT NULL` → `nullable` (valeur par défaut `null` pour les nouvelles entrées)
- `reference` : `nullable` → `NOT NULL` (les enregistrements existants sans référence reçoivent `'—'` comme valeur par défaut avant la contrainte)

### Formulaires `DepenseForm` et `RecetteForm`

- Règle de validation `libelle` : `required` → `nullable|string|max:255`
- Règle de validation `reference` : `nullable` → `required|string|max:100`

---

## Architecture technique

### 1. `CsvImportService`

Service unique paramétré par type (`depense` ou `recette`).

**Méthode principale :**
```php
public function import(UploadedFile $file, string $type, int $userId): CsvImportResult
```

**Étapes internes :**
1. Lecture et décodage du fichier (UTF-8, détection BOM)
2. Validation de l'en-tête (colonnes attendues présentes)
3. Parsing ligne par ligne :
   - Validation de chaque champ (format, valeur enum, existence en base)
   - Collecte de toutes les erreurs avant d'arrêter (rapport complet)
4. Si erreurs → retourne `CsvImportResult` avec liste d'erreurs, aucun enregistrement créé
5. Si aucune erreur → `DB::transaction()` :
   - Groupement par `date` + `reference`
   - Pour chaque groupe : création via `DepenseService::create()` ou `RecetteService::create()`
   - Attribution `numero_piece` via `NumeroPieceService`
6. Retourne `CsvImportResult` avec compteurs (transactions, lignes)

**Value object de retour :**
```php
final class CsvImportResult {
    public bool $success;
    public int $transactionsCreated;
    public int $lignesCreated;
    public array $errors; // [['line' => 4, 'message' => '...']]
}
```

### 2. Composant Livewire `ImportCsv`

Composant unique paramétré par `string $type` (`depense` ou `recette`).

**État :**
- `bool $showPanel` — panneau import visible ou non
- `?array $errors` — liste des erreurs retournées
- `?string $successMessage` — message de succès
- Fichier uploadé via `WithFileUploads`

**Comportement :**
- Validation locale : fichier présent, extension `.csv`, taille max 2 Mo
- Appel `CsvImportService::import()`
- En succès : émet `$dispatch('csv-imported')` pour rafraîchir la liste parente
- En erreur : affiche le tableau d'erreurs sans fermer le panneau

### 3. Routes pour les templates

```php
Route::get('/depenses/import/template', fn() => CsvImportController::template('depense'))
    ->name('depenses.import.template');
Route::get('/recettes/import/template', fn() => CsvImportController::template('recette'))
    ->name('recettes.import.template');
```

Le contrôleur génère un fichier CSV avec l'en-tête + 3 lignes d'exemple et le sert en téléchargement (`Content-Disposition: attachment`).

---

## Interface utilisateur

### Barre d'action (dans `depense-list` et `recette-list`)

```
[ + Nouvelle dépense ]  [ ↑ Importer ]  [ ↓ Télécharger le modèle ]
```

- **"Importer"** : ouvre le panneau import inline sous la barre. Structure prévue pour accueillir d'autres types d'import à l'avenir (HelloAsso, OFX…). Pour l'instant un seul choix "Import CSV", le panneau s'ouvre directement.
- **"Télécharger le modèle"** : lien direct vers la route template, télécharge le CSV d'exemple.

### Panneau import (état erreur)

```
┌─────────────────────────────────────────────────────┐
│  Importer des dépenses — CSV                        │
│                                                     │
│  [Choisir un fichier]  factures-sept.csv            │
│                                                     │
│  [ Lancer l'import ]                                │
│                                                     │
│  ✗ 3 erreurs détectées — aucune donnée importée     │
│  ┌───────┬──────────────────────────────────────┐   │
│  │ Ligne │ Erreur                               │   │
│  ├───────┼──────────────────────────────────────┤   │
│  │  4    │ sous-catégorie "Toto" inconnue       │   │
│  │  7    │ mode_paiement "carte" invalide       │   │
│  │  12   │ date "32/13/2024" invalide           │   │
│  └───────┴──────────────────────────────────────┘   │
└─────────────────────────────────────────────────────┘
```

### Panneau import (état succès)

```
✓ Import réussi : 3 transactions créées (5 lignes comptables)
```

---

## Règles de validation par colonne

| Colonne | Règle |
|---------|-------|
| `date` | Format `YYYY-MM-DD` valide |
| `reference` | Non vide, max 100 caractères |
| `sous_categorie` | Nom exact existant dans `sous_categories`. Sensible à la casse : non (LIKE insensible) |
| `montant_ligne` | Numérique, décimale autorisée, > 0 |
| `mode_paiement` | Valeur dans `['virement','cheque','especes','cb','prelevement']` |
| `compte` | Nom exact existant dans `comptes_bancaires` |
| `tiers` | Si renseigné : `displayName()` exact existant dans `tiers` avec flag `pour_depenses`/`pour_recettes` |
| `operation` | Si renseignée : nom exact existant dans `operations` |
| **Doublon** | `date` + `reference` déjà présents dans la table cible → rejet |

---

## Ce qui est hors périmètre

- Import des dons et cotisations (futur : API HelloAsso)
- Mise à jour de transactions existantes via CSV (import = création uniquement)
- Import de tiers inconnus à la volée
- Support Excel (`.xlsx`) — CSV uniquement
