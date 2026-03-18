# Export / Import du Budget

## Contexte

La saisie ligne par ligne du budget dans l'application est fastidieuse. L'export/import permet deux cas d'usage principaux :
1. **Reconduction d'un exercice à l'autre** — exporter le budget N avec exercice=N+1, retoucher les montants dans Excel, réimporter.
2. **Travail hors application** — préparer ou ajuster le budget dans un tableur, puis le charger en base.

---

## Décisions structurantes

- **Format** : CSV (`;` UTF-8) ou Excel (`.xlsx`), au choix de l'utilisateur à l'export comme à l'import.
- **Colonnes** : `exercice`, `sous_categorie`, `montant_prevu` — noms d'en-tête fixes, correspondance par nom de sous-catégorie.
- **Ordre des lignes** : dépenses d'abord, puis recettes, triées catégorie → sous-catégorie (identique au compte de résultat).
- **Montant nul à l'export** : `montant_prevu = 0` → cellule vide.
- **Montant vide à l'import** : ligne ignorée (non insérée). Cela permet de ne budgéter que les postes pertinents sans créer de lignes à zéro qui provoqueraient des divisions par zéro dans le calcul de progression.
- **Remplacement à l'import** : `DELETE FROM budget_lines WHERE exercice = X` (exercice courant uniquement), puis insert des lignes valides. Les budgets des autres exercices ne sont pas touchés.
- **Atomicité** : validation exhaustive avant toute suppression/insertion — si une erreur est trouvée, rien n'est modifié.
- **Identification** : les sous-catégories sont identifiées par leur nom exact. L'utilisateur ne modifie pas cette colonne.

---

## Export

### Interface

Bouton "Exporter" dans `BudgetTable` ouvre un modal avec 3 choix :

| Champ | Options |
|---|---|
| Format | CSV / Excel |
| Exercice à écrire dans le fichier | Exercice courant (ex. 2025) / Exercice suivant (ex. 2026) |
| Source des montants | Zéro partout / Montants de l'exercice courant / Montants de l'exercice N-1 |

**Important** : le choix de l'exercice (colonne 1) et la source des montants sont indépendants. Exemple typique : exercice=2026 + montants de 2025 → fichier prêt à retoucher pour la reconduction.

### Flux

Le clic soumet un GET vers `BudgetExportController` avec les 3 paramètres. Le contrôleur retourne le fichier en téléchargement direct (pas de stockage serveur).

### Format du fichier

```
exercice;sous_categorie;montant_prevu
2026;Loyers;1200.00
2026;Électricité;
2026;Cotisations membres;850.00
```

- Montant nul → cellule vide (pas "0.00")
- Encodage UTF-8

---

## Import

### Interface

Bouton "Importer" dans `BudgetTable` ouvre un modal affichant :

- Info contextuelle : "Import pour l'exercice 2025-2026"
- **Avertissement** : "⚠️ L'import remplacera toutes les lignes budgétaires de l'exercice 2025-2026. Cette action est irréversible."
- Champ de fichier (CSV ou Excel)
- Bouton "Valider"

L'exercice cible est toujours l'exercice courant de l'application (pas de sélecteur).

### Validation (rejet si)

1. Colonnes requises absentes ou noms d'en-tête incorrects
2. Au moins une ligne a un `exercice` différent de l'exercice courant → message : "Le fichier contient l'exercice 2024, l'exercice ouvert est 2025"
3. Au moins un nom de `sous_categorie` absent de la base → message avec numéro de ligne : "Ligne 4 : sous-catégorie 'Foobar' introuvable"
4. Au moins un `montant_prevu` non numérique ou négatif (les cellules vides sont acceptées)

### Flux si valide

1. `DELETE FROM budget_lines WHERE exercice = {exercice_courant}`
2. Insert de toutes les lignes dont le montant est non vide
3. Flash de confirmation : "42 lignes importées pour l'exercice 2025-2026"

---

## Architecture

### Nouveaux fichiers

| Fichier | Rôle |
|---|---|
| `app/Http/Controllers/BudgetExportController.php` | Génère et retourne le fichier |
| `app/Http/Controllers/BudgetImportController.php` | Reçoit, valide et charge le fichier |
| `app/Services/BudgetImportService.php` | Logique de validation + import |
| `app/Services/BudgetExportService.php` | Logique de génération du fichier |

### Modifications existantes

| Fichier | Modification |
|---|---|
| `app/Livewire/BudgetTable.php` | Ajout actions pour ouvrir les modals export/import |
| `resources/views/livewire/budget-table.blade.php` | Ajout des modals + boutons |
| `routes/web.php` | Ajout des 2 routes (GET export, POST import) |

### Dépendances

- **Export Excel** : package `maatwebsite/excel` (déjà utilisé dans le projet ou à ajouter via Composer)
- **Import Excel** : idem

---

## Tests

- Export CSV : vérifie le contenu, l'ordre des lignes, les montants nuls en cellule vide, l'indépendance exercice/source
- Export Excel : vérifie la génération sans erreur
- Import valide CSV : vérifie le truncate partiel + insert + ligne vide ignorée
- Import valide Excel : idem
- Import rejet exercice incorrect : vérifie le message d'erreur
- Import rejet sous-catégorie inconnue : vérifie le message avec numéro de ligne
- Import rejet montant invalide : vérifie le message d'erreur
