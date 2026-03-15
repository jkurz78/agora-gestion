# Import CSV — Dépenses et Recettes

## Contexte

L'association saisit aujourd'hui toutes ses transactions manuellement. L'import CSV permet d'alimenter les dépenses et recettes depuis un fichier tableur (export Excel nettoyé, export bancaire, etc.), de façon ponctuelle ou régulière. Les dons et cotisations sont hors périmètre (traités ultérieurement via l'API HelloAsso).

---

## Décisions structurantes

- **Format** : CSV avec séparateur `;`, encodage UTF-8 (avec ou sans BOM)
- **Atomicité** : validation exhaustive avant toute insertion — si une erreur est trouvée, aucune donnée n'est créée
- **Doublons** : détectés par `date` + `reference` déjà présents en base (hors soft-deleted, par exercice comptable). Les doublons intra-fichier sont également détectés lors du parsing.
- **Références inconnues** (tiers, sous-catégorie, compte, opération) : rejet avec message explicite
- **libellé** : devient optionnel (migration + formulaires)
- **référence** : devient obligatoire (migration + formulaires)
- **montant_total** : calculé automatiquement par somme des lignes, non présent dans le CSV
- **seance** : champ absent du CSV (hors périmètre, saisie manuelle uniquement)

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
| `sous_categorie` | Oui | Nom exact de la sous-catégorie, du type correspondant (depense ou recette). Insensible à la casse. |
| `montant_ligne` | Oui | Montant décimal > 0, séparateur `.` |
| `mode_paiement` | Oui (1ère ligne du groupe) | `virement`, `cheque`, `especes`, `cb`, `prelevement` |
| `compte` | Oui (1ère ligne du groupe) | Nom exact d'un compte bancaire actif (`actif_recettes_depenses = true`). Insensible à la casse. |
| `libelle` | Non | Texte libre, lu sur la 1ère ligne du groupe uniquement |
| `tiers` | Non | `displayName()` du tiers (ex : `"Jean DUPONT"` pour un particulier, `"MAISON DUPONT"` pour une entreprise). Insensible à la casse. En cas d'homonyme : rejet avec message explicite. Doit avoir le flag `pour_depenses` ou `pour_recettes` selon le type d'import. |
| `operation` | Non | Nom exact de l'opération. Insensible à la casse. Doit exister en base si renseignée. |

### Regroupement en transactions

Les lignes partageant la même `date` + `reference` forment **une seule transaction** avec plusieurs lignes comptables. Le `montant_total` est la somme des `montant_ligne` du groupe.

Les champs `mode_paiement`, `compte`, `libelle`, `tiers` sont lus sur la **première ligne** du groupe ; les lignes suivantes peuvent les laisser vides.

### Encodage et lignes vides

- Seul UTF-8 (avec ou sans BOM) est accepté. Un fichier en Latin-1/Windows-1252 est rejeté avec le message : `"Le fichier doit être encodé en UTF-8. Enregistrez votre fichier CSV en UTF-8 depuis Excel ou LibreOffice."`
- Le BOM UTF-8 (`\xEF\xBB\xBF`) est détecté et supprimé avant parsing de l'en-tête.
- Les lignes entièrement vides sont ignorées silencieusement.

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

- `libelle` : `NOT NULL` → `nullable`
- `reference` : `nullable` → `NOT NULL` (les enregistrements existants sans référence reçoivent `'—'` comme valeur de migration avant la contrainte)

### Formulaires `DepenseForm` et `RecetteForm`

- Propriété `public string $libelle = ''` → `public ?string $libelle = null`
- Règle de validation `libelle` : `['required', 'string', 'max:255']` → `['nullable', 'string', 'max:255']`
- Règle de validation `reference` : `['nullable', ...]` → `['required', 'string', 'max:100']`
- Templates Blade : retirer l'astérisque sur `libelle`, ajouter l'astérisque sur `reference`

---

## Architecture technique

### 1. `CsvImportService`

**Namespace :** `App\Services\CsvImportService`

Service unique paramétré par type (`depense` ou `recette`).

**Méthode principale :**
```php
public function import(UploadedFile $file, string $type): CsvImportResult
```

Note : `saisi_par` est résolu via `auth()->id()` à l'intérieur de `DepenseService::create()` / `RecetteService::create()`, comme pour la saisie manuelle. `CsvImportService` tourne dans un contexte Livewire authentifié — `auth()->id()` est valide.

**Stratégie de transactions — point critique :**

`CsvImportService` ne doit **PAS** ouvrir de transaction globale englobante. `DepenseService::create()` utilise `NumeroPieceService::assign()` qui exécute un `SELECT FOR UPDATE` ; imbriquer cela dans une transaction externe provoquerait des comportements indéfinis avec les savepoints MySQL.

La stratégie est :
1. **Phase 1 — Validation exhaustive** : parser tout le fichier, collecter toutes les erreurs. Si une seule erreur → retourner `CsvImportResult` sans aucune insertion.
2. **Phase 2 — Insertion** : uniquement si phase 1 sans erreur. Appeler `DepenseService::create()` (ou `RecetteService`) pour chaque groupe. Chaque appel gère sa propre transaction. La validation préalable garantit qu'aucune insertion ne peut échouer.

**Étapes de la phase 1 :**
1. Détection BOM, décodage UTF-8
2. Validation de l'en-tête (colonnes attendues présentes)
3. Parsing ligne par ligne :
   - Validation de chaque champ (format, enum, existence en base)
   - Détection doublons intra-fichier (groupes `date`+`reference` vus plusieurs fois dans le fichier)
   - Détection doublons en base (hors soft-deleted, même exercice)
   - Collecte exhaustive des erreurs avec numéro de ligne CSV
4. Retourne `CsvImportResult` avec liste d'erreurs si échec

**Value object de retour :**

**Namespace :** `App\Services\CsvImportResult`

```php
final class CsvImportResult
{
    public function __construct(
        public readonly bool $success,
        public readonly int $transactionsCreated = 0,
        public readonly int $lignesCreated = 0,
        public readonly array $errors = [], // [['line' => 4, 'message' => '...']]
    ) {}
}
```

### 2. Composant Livewire `ImportCsv`

**Namespace :** `App\Livewire\ImportCsv`

Composant unique paramétré par `string $type` (`depense` ou `recette`).

**État :**
- `bool $showPanel = false` — panneau import visible ou non
- `?array $errors = null` — liste des erreurs retournées
- `?string $successMessage = null` — message de succès
- Fichier uploadé via `WithFileUploads`

**Comportement :**
- Validation locale Livewire : fichier présent, extension `.csv`, taille max 2 Mo
- Appel `CsvImportService::import($file, $this->type)`
- En succès : `$dispatch('csv-imported')` pour rafraîchir la liste parente, affiche message vert
- En erreur : affiche le tableau d'erreurs sans fermer le panneau

**Configuration Sail :** vérifier que `upload_max_filesize` et `post_max_size` dans `php.ini` sont ≥ 2 Mo (valeur par défaut Sail = 8 Mo, pas de changement requis).

### 3. Contrôleur et routes pour les templates

**Contrôleur :** `App\Http\Controllers\CsvImportController`

```php
public function template(string $type): Response  // 'depense' ou 'recette'
```

Génère un fichier CSV avec l'en-tête + 3 lignes d'exemple et le sert en téléchargement (`Content-Disposition: attachment; filename="modele-{type}.csv"`).

**Routes :**
```php
Route::get('/depenses/import/template', [CsvImportController::class, 'template'])
    ->defaults('type', 'depense')
    ->name('depenses.import.template');

Route::get('/recettes/import/template', [CsvImportController::class, 'template'])
    ->defaults('type', 'recette')
    ->name('recettes.import.template');
```

---

## Interface utilisateur

### Barre d'action (dans `depense-list` et `recette-list`)

```
[ + Nouvelle dépense ]  [ ↑ Importer ]  [ ↓ Télécharger le modèle ]
```

- **"Importer"** : ouvre le panneau import inline sous la barre. Structuré pour accueillir d'autres types d'import à l'avenir (HelloAsso, OFX…). Pour l'instant un seul choix "Import CSV", le panneau s'ouvre directement dessus.
- **"Télécharger le modèle"** : lien direct vers la route template, contextuel au type (dépense ou recette).

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
| `sous_categorie` | Nom existant dans `sous_categories` filtré par type (`depense` ou `recette`). Insensible à la casse. |
| `montant_ligne` | Numérique, décimale autorisée, > 0 |
| `mode_paiement` | Valeur dans `['virement','cheque','especes','cb','prelevement']` |
| `compte` | Nom existant dans `comptes_bancaires` avec `actif_recettes_depenses = true`. Insensible à la casse. |
| `tiers` | Si renseigné : `displayName()` existant dans `tiers` avec flag `pour_depenses`/`pour_recettes`. Insensible à la casse. Homonyme → rejet. |
| `operation` | Si renseignée : nom existant dans `operations`. Insensible à la casse. |
| **Doublon intra-fichier** | `date` + `reference` apparaissant plusieurs fois dans le même CSV → rejet dès le parsing |
| **Doublon en base** | `date` + `reference` déjà présents en base (hors soft-deleted, même exercice comptable) → rejet |

---

## Ce qui est hors périmètre

- Import des dons et cotisations (futur : API HelloAsso)
- Mise à jour de transactions existantes via CSV (import = création uniquement)
- Import de tiers inconnus à la volée
- Support Excel (`.xlsx`) — CSV uniquement
- Champ `seance` sur les lignes comptables (saisie manuelle via formulaire uniquement)
- Encodages autres qu'UTF-8 (Latin-1, Windows-1252…)
