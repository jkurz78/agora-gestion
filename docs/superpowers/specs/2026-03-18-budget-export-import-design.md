# Export / Import du Budget

## Contexte

La saisie ligne par ligne du budget dans l'application est fastidieuse. L'export/import permet deux cas d'usage principaux :
1. **Reconduction d'un exercice à l'autre** — exporter le budget N avec exercice=N+1, retoucher les montants dans Excel, réimporter quand l'exercice N+1 est ouvert.
2. **Travail hors application** — préparer ou ajuster le budget dans un tableur, puis le charger en base.

---

## Décisions structurantes

- **Format** : CSV (`;` UTF-8) ou Excel (`.xlsx`), au choix de l'utilisateur à l'export. Les deux formats sont acceptés à l'import. Le séparateur CSV est toujours `;`.
- **Détection du format à l'import** : par extension — `.xlsx` → `maatwebsite/excel` ; `.csv` ou `.txt` → parsing CSV natif avec `str_getcsv`.
- **Colonnes** : `exercice`, `sous_categorie`, `montant_prevu` — noms d'en-tête fixes, correspondance par nom exact de sous-catégorie.
- **Ordre des lignes** : dépenses d'abord, puis recettes, triées catégorie → sous-catégorie (identique au compte de résultat).
- **Montant nul à l'export** : `montant_prevu = 0` → cellule vide.
- **Montant vide ou zéro à l'import** : ligne ignorée (non insérée), que le montant soit une cellule vide ou la valeur `0` / `0.00`. Cela évite les divisions par zéro dans le calcul de progression et garantit que seuls les postes budgétés existent en base.
- **Remplacement à l'import** : `DELETE FROM budget_lines WHERE exercice = {exercice_courant}` (modèle `BudgetLine`, colonne `exercice`), puis insert des lignes valides. Les budgets des autres exercices ne sont pas touchés. Note : si le fichier importé ne contient que des montants vides ou zéro, le résultat est un budget vide pour l'exercice — comportement attendu et documenté dans l'avertissement du modal.
- **Atomicité** : validation exhaustive avant toute suppression/insertion — si une erreur est trouvée, rien n'est modifié.
- **Identification** : les sous-catégories sont identifiées par leur nom exact. L'utilisateur ne modifie pas cette colonne. En cas de doublon de nom en base (pas de contrainte UNIQUE actuellement), l'import rejette le fichier avec un message explicite.
- **Autorisation** : routes et actions Livewire protégées par `auth` uniquement (pas de système de rôles dans l'application).
- **Dépendance Excel** : `maatwebsite/excel` à ajouter via Composer (`composer require maatwebsite/excel`).
- **Exercice courant** : résolu par `app(ExerciceService::class)->current()` — retourne la valeur de la session `exercice_actif` si présente, sinon calcul automatique (septembre → mois courant ≥ 9 → année courante, sinon année - 1).
- **N-1** : exercice courant − 1 (ex. si exercice courant = 2025, N-1 = 2024).

---

## Export

### Interface

Bouton "Exporter" dans `BudgetTable` ouvre un modal avec 3 choix :

| Champ | Options |
|---|---|
| Format | CSV / Excel |
| Exercice à écrire dans le fichier | Exercice courant (ex. 2025) / Exercice suivant (ex. 2026) |
| Source des montants | Zéro partout / Montants de l'exercice courant / Montants de l'exercice N-1 |

**Important** : le choix de l'exercice (colonne 1) et la source des montants sont indépendants. Exemple typique pour la reconduction : exercice=2026 + montants de 2025. Si la source N-1 est sélectionnée mais qu'aucune donnée n'existe pour N-1, les montants sont traités comme zéro (cellule vide dans le fichier).

### Flux

Le modal contient trois `wire:model` Livewire pour les 3 paramètres (format, exercice, source). Un bouton déclenche une action Livewire `export()` qui construit l'URL et exécute `$this->js("window.location.href = '$url'")` — ce mécanisme contourne l'interception native de Livewire 4 sur les formulaires et déclenche le téléchargement directement depuis `BudgetExportController`.

### Format du fichier

```
exercice;sous_categorie;montant_prevu
2026;Loyers;1200.00
2026;Électricité;
2026;Cotisations membres;850.00
```

- Montant nul ou zéro → cellule vide
- Encodage UTF-8

---

## Import

### Interface

Bouton "Importer" dans `BudgetTable` ouvre un panel (via `showPanel`, pattern identique à `ImportCsv`) affichant :

- Info contextuelle : "Import pour l'exercice 2025-2026" (label via `ExerciceService::label()`)
- **Avertissement** : "⚠️ L'import supprimera toutes les lignes budgétaires existantes pour l'exercice 2025-2026 avant de charger les nouvelles données. Les montants vides ou nuls ne sont pas chargés. Cette action est irréversible."
- Champ de fichier (CSV ou Excel — `mimes:csv,txt,xlsx`, max 2 Mo)
- Bouton "Valider"
- Zone d'affichage des erreurs de validation (liste ligne par ligne, format identique à `ImportCsv`)

L'exercice cible est toujours l'exercice courant (`ExerciceService::current()`). Pas de sélecteur. L'implémentation suit le pattern `ImportCsv` : `WithFileUploads`, `showPanel`, appel à `BudgetImportService`, dispatch d'un événement `budget-imported` après succès pour forcer le re-render de `BudgetTable`.

### Validation (rejet si)

1. Colonnes requises absentes ou noms d'en-tête incorrects
2. Au moins une ligne a un `exercice` différent de l'exercice courant → message : "Le fichier contient les exercices {liste}, l'exercice ouvert est {courant}" (liste des valeurs distinctes trouvées, hors exercice courant)
3. Au moins un nom de `sous_categorie` absent de la base → message avec numéro de ligne : "Ligne 4 : sous-catégorie 'Foobar' introuvable"
4. Un nom de `sous_categorie` correspond à plusieurs entrées en base → message : "Ligne 4 : nom 'Divers' ambigu (plusieurs sous-catégories portent ce nom)"
5. Au moins un `montant_prevu` non numérique ou négatif (les cellules vides et les valeurs 0/0.00 sont acceptées)

### Flux si valide

1. `BudgetLine::where('exercice', $exercice)->delete()`
2. Insert de toutes les lignes dont le montant est non vide et > 0
3. Dispatch de l'événement `budget-imported` (le composant `BudgetTable` écoute cet événement et se re-render)
4. Message de succès dans le panel : "N lignes importées pour l'exercice 2025-2026"

---

## Architecture

### Nouveaux fichiers

| Fichier | Rôle |
|---|---|
| `app/Http/Controllers/BudgetExportController.php` | Génère et retourne le fichier (GET, auth) |
| `app/Services/BudgetExportService.php` | Logique de génération du fichier |
| `app/Services/BudgetImportService.php` | Logique de validation + import |

### Modifications existantes

| Fichier | Modification |
|---|---|
| `app/Livewire/BudgetTable.php` | Ajout `WithFileUploads` + panel import (`showPanel`, `budgetFile`, `import()`) + écoute de `budget-imported` pour refresh ; formulaire GET export dans la vue |
| `resources/views/livewire/budget-table.blade.php` | Ajout panel import + formulaire export (modal Bootstrap) |
| `routes/web.php` | Route GET `/budget/export` protégée par `auth` |

### Dépendances

- **Export/Import Excel** : `maatwebsite/excel` — à installer (`composer require maatwebsite/excel`)

---

## Tests

- Export CSV : contenu correct, ordre dépenses/recettes, montants nuls → cellule vide, indépendance exercice/source montants
- Export Excel : génération sans erreur, mêmes assertions sur le contenu
- Export source N-1 absente : montants → cellule vide
- Import valide CSV : DELETE lignes exercice courant uniquement (autres exercices préservés) + insert + ligne vide ignorée + ligne à zéro ignorée
- Import valide Excel : idem
- Import rejet en-tête incorrect : message d'erreur
- Import rejet exercice incorrect (valeur unique) : message "Le fichier contient l'exercice 2024, l'exercice ouvert est 2025"
- Import rejet exercice incorrect (valeurs multiples) : message liste les exercices trouvés
- Import rejet sous-catégorie inconnue : message avec numéro de ligne
- Import rejet sous-catégorie ambiguë : message avec numéro de ligne
- Import rejet montant invalide : message d'erreur
