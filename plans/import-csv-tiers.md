# Plan: Import CSV/Excel Tiers

**Created**: 2026-04-06
**Updated**: 2026-04-07
**Branch**: main
**Status**: approved
**Specs**: Artefacts 1-4 validés (consistency gate PASS)

## Goal

Permettre aux administrateurs d'importer des tiers en masse depuis un fichier CSV (.csv) ou Excel (.xlsx) via l'écran Tiers. L'import est **atomique (tout ou rien)** en 3 phases :

1. **Validation** — parse, valide, détecte doublons internes. Si erreur → rejet du fichier entier.
2. **Preview + résolution** — matching contre la base (nom+prénom, raison sociale). Trois statuts : Nouveau, Enrichissement auto (complète des champs vides), Conflit (valeurs divergentes ou homonymes). Les conflits se résolvent via le TiersMergeModal. Tout reste en mémoire.
3. **Commit** — un bouton "Confirmer l'import" écrit tout en une seule `DB::transaction()`. Un rapport détaillé est affiché et téléchargeable (.txt).

Le bouton "Créer un nouveau tiers" est ajouté au TiersMergeModal (bénéficie aussi au flux HelloAsso).

## Décisions prises (specs)

- **Pas de colonne `type`** — déduit automatiquement (entreprise remplie → entreprise, sinon → particulier)
- **Flags par défaut** — si aucun flag pour_depenses/pour_recettes → les deux à true
- **Pays par défaut** — France
- **Doublon interne** — rejet de tout le fichier
- **Match email + nom différent** — pas de match, warning "⚠ même email que X (#N)"
- **Homonymes** — choix du candidat puis fusion, ou création d'un nouveau tiers
- **Merge sans conflit** — enrichissement automatique (pas de modal)
- **Templates** — générés à la volée (PhpSpreadsheet pour xlsx, fputcsv pour csv)
- **Séparateur CSV** — auto-détection `;` / `,`
- **Encodage CSV** — détection et conversion Windows-1252 → UTF-8

## Acceptance Criteria

### Parsing & Validation
- [ ] AC-1: Parse fichier .xlsx valide → preview sans erreur
- [ ] AC-2: Parse fichier .csv UTF-8 séparateur `,` → preview sans erreur
- [ ] AC-3: Parse fichier .csv Windows-1252 séparateur `;` → accents préservés
- [ ] AC-4: Fichier .xls rejeté → "Format non supporté. Utilisez .csv ou .xlsx"
- [ ] AC-5: Fichier > 2 Mo rejeté
- [ ] AC-6: Fichier sans en-tête reconnu rejeté
- [ ] AC-7: Ligne sans nom ni entreprise → rejet du fichier entier
- [ ] AC-8: Email invalide → rejet du fichier entier
- [ ] AC-9: Doublon interne → rejet du fichier entier

### Déduction automatique
- [ ] AC-10: Entreprise remplie → type = entreprise
- [ ] AC-11: Pas d'entreprise → type = particulier
- [ ] AC-12: Aucun flag → pour_depenses=true, pour_recettes=true
- [ ] AC-13: Flags explicites respectés
- [ ] AC-14: Pays absent → "France"

### Matching
- [ ] AC-15: Tiers inconnu → statut "Nouveau"
- [ ] AC-16: Match sans conflit → statut "Enrichissement"
- [ ] AC-17: Match avec conflit → statut "Conflit" + bouton "Résoudre"
- [ ] AC-18: Match raison sociale pour entreprise
- [ ] AC-19: Même email + nom différent → "Nouveau" + warning
- [ ] AC-20: Homonymes → "Conflit" avec choix de candidats

### Résolution
- [ ] AC-21: Clic "Résoudre" ouvre TiersMergeModal avec les bonnes données
- [ ] AC-22: Fusion confirmée → ligne "Résolu"
- [ ] AC-23: "Créer un nouveau tiers" → ligne "Résolu — nouveau tiers"
- [ ] AC-24: Annulation → ligne reste "Conflit"
- [ ] AC-25: Choix du candidat parmi homonymes

### Commit atomique
- [ ] AC-26: Bouton "Confirmer" désactivé tant que conflits non résolus
- [ ] AC-27: Confirmation → tout en une seule DB::transaction()
- [ ] AC-28: Abandon → rien écrit en base

### Rapport
- [ ] AC-29: Résumé affiché : "X créés, Y enrichis, Z résolus"
- [ ] AC-30: Détail ligne par ligne avec décision
- [ ] AC-31: Téléchargement rapport .txt

### TiersMergeModal
- [ ] AC-32: Bouton "Créer un nouveau tiers" visible dans le modal
- [ ] AC-33: Event tiers-merge-create-new géré par HelloAssoSyncWizard
- [ ] AC-34: Contextes existants (medecin, therapeute, etc.) non impactés

### Templates & Accès
- [ ] AC-35: Téléchargement template CSV (séparateur `;`, UTF-8 BOM)
- [ ] AC-36: Téléchargement template Excel (.xlsx)
- [ ] AC-37: Bouton "Importer" visible uniquement pour les admins

## Steps

### Step 1: TiersMergeModal — bouton "Créer un nouveau tiers"

**Complexity**: standard
**RED**: Test que le clic sur "Créer un nouveau tiers" dispatche l'event `tiers-merge-create-new` avec sourceData, context, contextData. Test que le modal se ferme. Test que les contextes existants (helloasso confirm/cancel) ne sont pas affectés.
**GREEN**: Ajouter méthode `createNewTiers()` dans TiersMergeModal. Ajouter le bouton dans la vue. Ajouter listener `onTiersMergeCreateNew()` dans HelloassoSyncWizard qui appelle `creerTiers($index)`.
**REFACTOR**: None needed.
**Files**: `app/Livewire/TiersMergeModal.php`, `resources/views/livewire/tiers-merge-modal.blade.php`, `app/Livewire/Banques/HelloassoSyncWizard.php`, `tests/Feature/Livewire/TiersMergeModalTest.php`
**Commit**: `feat: add "Créer un nouveau tiers" button to TiersMergeModal`
**AC**: AC-32, AC-33, AC-34

### Step 2: TiersCsvParserService — parsing CSV/XLSX + validation

**Complexity**: standard
**RED**: Test parse xlsx valide → tableau structuré. Test parse csv UTF-8 virgule. Test parse csv Windows-1252 point-virgule → accents préservés. Test rejet fichier sans en-tête. Test rejet ligne sans nom ni entreprise. Test rejet email invalide. Test détection doublon interne → rejet fichier entier. Test déduction type (entreprise/particulier). Test flags par défaut. Test pays par défaut.
**GREEN**: Créer `TiersCsvParserService` avec `parse(UploadedFile): TiersCsvParseResult`. Pattern copié de BudgetImportService : parseFile() → parseCsv()/parseXlsx(). Ajout auto-détection séparateur et conversion encodage. DTO `TiersCsvParseResult` : success, rows (normalisées), errors.
**REFACTOR**: Extraire le parsing CSV/XLSX commun avec BudgetImportService dans un trait ou helper si la duplication est excessive.
**Files**: `app/Services/TiersCsvParserService.php`, `app/Services/TiersCsvParseResult.php`, `tests/Feature/Services/TiersCsvParserServiceTest.php`
**Commit**: `feat: add TiersCsvParserService for CSV/XLSX tiers parsing and validation`
**AC**: AC-1, AC-2, AC-3, AC-4, AC-6, AC-7, AC-8, AC-9, AC-10, AC-11, AC-12, AC-13, AC-14

### Step 3: TiersCsvMatcherService — matching contre la BDD

**Complexity**: standard
**RED**: Test match par nom+prénom (insensible casse) → statut enrichment. Test match par raison sociale → enrichment. Test match avec conflit (valeurs divergentes) → statut conflict. Test même email + nom différent → statut new + warning. Test homonymes (>1 match) → statut conflict avec candidats. Test tiers inconnu → statut new.
**GREEN**: Créer `TiersCsvMatcherService::match(array $rows): array`. Pré-charge tous les tiers en mémoire. Chaque ligne reçoit : status (new/enrichment/conflict), matchedTiersId, matchedCandidates, conflictFields, warnings, decisionLog.
**REFACTOR**: None needed.
**Files**: `app/Services/TiersCsvMatcherService.php`, `tests/Feature/Services/TiersCsvMatcherServiceTest.php`
**Commit**: `feat: add TiersCsvMatcherService for matching CSV rows against existing tiers`
**AC**: AC-15, AC-16, AC-17, AC-18, AC-19, AC-20

### Step 4: TiersCsvImportService — commit atomique + rapport

**Complexity**: standard
**RED**: Test que les lignes new créent un tiers via TiersService::create(). Test que les lignes enrichment mettent à jour via TiersService::update(). Test que les lignes resolved (fusion ou création) sont traitées correctement. Test que tout est dans une DB::transaction(). Test compteurs du rapport. Test format texte du rapport (toText()).
**GREEN**: Créer `TiersCsvImportService::import(array $resolvedRows): TiersCsvImportReport`. DTO `TiersCsvImportReport` : compteurs (created, enriched, resolvedMerge, resolvedNew) + détail ligne par ligne + méthode `toText(): string`.
**REFACTOR**: None needed.
**Files**: `app/Services/TiersCsvImportService.php`, `app/Services/TiersCsvImportReport.php`, `tests/Feature/Services/TiersCsvImportServiceTest.php`
**Commit**: `feat: add TiersCsvImportService with atomic commit and import report`
**AC**: AC-27, AC-29, AC-30, AC-31

### Step 5: Livewire — ImportCsvTiers (upload + preview + résolution)

**Complexity**: complex
**RED**: Test upload fichier valide → preview affiché. Test upload fichier invalide → erreurs affichées. Test fichier > 2 Mo → rejeté. Test statuts affichés correctement (badges). Test clic "Résoudre" dispatche open-tiers-merge. Test tiers-merge-confirmed met à jour la ligne. Test tiers-merge-create-new met à jour la ligne. Test tiers-merge-cancelled garde le conflit. Test choix candidat parmi homonymes. Test enrichissement auto sans modal.
**GREEN**: Créer composant ImportCsvTiers :
- Upload via WithFileUploads (csv/xlsx, max 2048 Ko, rejet .xls)
- Phase 1 : appel TiersCsvParserService → erreurs ou preview
- Phase 2 : appel TiersCsvMatcherService → tableau preview avec statuts et badges
- Bouton "Résoudre" par ligne conflit → dispatch open-tiers-merge (context='csv_import')
- Listeners : tiers-merge-confirmed, tiers-merge-create-new, tiers-merge-cancelled
- Sélecteur de candidat pour homonymes (dropdown avant ouverture modal)
- Enrichissement auto des lignes sans conflit (pas de modal)
- État complet stocké dans propriétés Livewire
**REFACTOR**: Extraire la logique de résolution dans des méthodes privées bien nommées.
**Files**: `app/Livewire/ImportCsvTiers.php`, `resources/views/livewire/import-csv-tiers.blade.php`, `tests/Feature/Livewire/ImportCsvTiersTest.php`
**Commit**: `feat: add ImportCsvTiers Livewire component with upload, preview and conflict resolution`
**AC**: AC-5, AC-21, AC-22, AC-23, AC-24, AC-25, AC-26

### Step 6: Commit atomique + rapport + téléchargement

**Complexity**: standard
**RED**: Test bouton "Confirmer" désactivé si conflits non résolus. Test confirmation appelle TiersCsvImportService dans une transaction. Test rapport affiché après import. Test bouton "Télécharger le rapport" retourne un .txt. Test abandon → rien en base. Test dispatch tiers-saved pour rafraîchir la liste.
**GREEN**: Dans ImportCsvTiers :
- Bouton "Confirmer l'import" (désactivé si conflits restants)
- Appel TiersCsvImportService::import() avec toutes les lignes résolues
- Affichage du rapport (résumé + détail scrollable)
- Bouton "Télécharger le rapport" → response download .txt
- Bouton "Annuler" → reset de l'état, aucune écriture
- Dispatch `tiers-saved` pour rafraîchir TiersList
**REFACTOR**: None needed.
**Files**: `app/Livewire/ImportCsvTiers.php`, `resources/views/livewire/import-csv-tiers.blade.php`, `tests/Feature/Livewire/ImportCsvTiersTest.php`
**Commit**: `feat: add atomic commit, import report display and download`
**AC**: AC-26, AC-27, AC-28, AC-29, AC-30, AC-31

### Step 7: Intégration écran Tiers + templates + routes

**Complexity**: standard
**RED**: Test bouton "Importer" visible admin, invisible utilisateur simple. Test téléchargement template CSV (en-têtes correctes, séparateur `;`, UTF-8 BOM). Test téléchargement template xlsx (en-têtes correctes). Test route template protégée admin.
**GREEN**:
- Bouton "Importer" dans tiers-list.blade.php (conditionné `auth()->user()->is_admin`)
- Inclusion `<livewire:import-csv-tiers />` dans la page tiers
- Route GET pour templates, contrôleur ou closure qui génère à la volée :
  - CSV : fputcsv avec `;`, UTF-8 BOM, en-têtes des 11 colonnes
  - XLSX : PhpSpreadsheet, en-têtes des 11 colonnes
- Routes protégées middleware admin
**REFACTOR**: None needed.
**Files**: `resources/views/livewire/tiers-list.blade.php`, `routes/web.php`, `app/Http/Controllers/TiersTemplateController.php`
**Commit**: `feat: add import button to tiers list, template downloads and routes`
**AC**: AC-35, AC-36, AC-37

## Complexity Classification

| Rating | Criteria | Review depth |
|--------|----------|--------------|
| `trivial` | Single-file rename, config change, typo fix, documentation-only | Skip inline review; covered by final `/code-review --changed` |
| `standard` | New function, test, module, or behavioral change within existing patterns | Spec-compliance + relevant quality agents |
| `complex` | Architectural change, security-sensitive, cross-cutting concern, new abstraction | Full agent suite including opus-tier agents |

## Pre-PR Quality Gate

- [ ] All tests pass (`./vendor/bin/pest`)
- [ ] Linter passes (`./vendor/bin/pint`)
- [ ] `/code-review --changed` passes
- [ ] Test manuel : upload CSV + XLSX, preview correct, enrichissement auto, résolution conflit, commit atomique, rapport téléchargé

## Architecture

```
┌─────────────────────────────────────────────────────────┐
│                    Écran Tiers                           │
│  ┌───────────┐  ┌──────────────────┐  ┌──────────────┐ │
│  │ TiersList  │  │ ImportCsvTiers   │  │TiersMergeModal│ │
│  │ (existant) │  │ (nouveau)        │  │ (modifié)    │ │
│  └───────────┘  └──────────────────┘  └──────────────┘ │
│                         │                     ▲         │
│                    open-tiers-merge            │         │
│                    tiers-merge-confirmed       │         │
│                    tiers-merge-create-new      │         │
│                         └─────────────────────┘         │
└─────────────────────────────────────────────────────────┘
         │                       │
         ▼                       ▼
┌─────────────────┐   ┌─────────────────────┐
│ TiersService    │   │ TiersCsvImportService│
│ (existant)      │   │ (nouveau)            │
│ create / update │   │ commit atomique      │
└─────────────────┘   └─────────────────────┘
                              │
                    ┌─────────┼──────────┐
                    ▼         ▼          ▼
            ┌──────────┐ ┌─────────┐ ┌──────────┐
            │ Parser   │ │ Matcher │ │ Report   │
            │ Service  │ │ Service │ │ DTO      │
            └──────────┘ └─────────┘ └──────────┘
```

## Nouveaux fichiers

| Fichier | Responsabilité |
|---------|---------------|
| `app/Services/TiersCsvParserService.php` | Parse CSV/XLSX, valide en-tête + lignes, détecte doublons internes |
| `app/Services/TiersCsvParseResult.php` | DTO : success, rows, errors |
| `app/Services/TiersCsvMatcherService.php` | Matching contre la BDD, attribution des statuts |
| `app/Services/TiersCsvImportService.php` | Commit atomique DB::transaction() |
| `app/Services/TiersCsvImportReport.php` | DTO rapport : compteurs + détail + toText() |
| `app/Livewire/ImportCsvTiers.php` | Composant Livewire : upload, preview, résolution, commit |
| `resources/views/livewire/import-csv-tiers.blade.php` | Vue du composant |
| `app/Http/Controllers/TiersTemplateController.php` | Génération templates CSV/XLSX à la volée |

## Fichiers modifiés

| Fichier | Modification |
|---------|-------------|
| `app/Livewire/TiersMergeModal.php` | + méthode `createNewTiers()`, event `tiers-merge-create-new` |
| `resources/views/livewire/tiers-merge-modal.blade.php` | + bouton "Créer un nouveau tiers" |
| `app/Livewire/Banques/HelloassoSyncWizard.php` | + listener `onTiersMergeCreateNew()` |
| `resources/views/livewire/tiers-list.blade.php` | + bouton "Importer" (admin) + inclusion composant |
| `routes/web.php` | + routes templates + protection admin |

## Risks & Open Questions

Tous résolus pendant la phase de spécification — aucune question ouverte.

## Feature connexe identifiée (hors scope)

**Disambiguation dynamique des homonymes** dans les sélecteurs de tiers (affichage email/ville pour distinguer les doublons). Spec séparée à produire.
