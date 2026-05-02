# Audit signe négatif — Slice 0

**Date** : 2026-04-30
**Branche** : claude/funny-shamir-8661f9
**Spec** : docs/specs/2026-04-30-audit-signe-negatif-s0.md
**Plan** : plans/audit-signe-negatif-s0.md
**Statut** : Slice 0 prêt — Slice 1 peut démarrer

## 1. Contexte

Le Slice 1 (programme Extourne) introduira des `Transaction` à `montant_total < 0`. Sans préparation, ces montants négatifs casseraient silencieusement des sommations dans les rapports et dashboards. Ce Slice 0 audite exhaustivement tous les sites du code qui assument `montant >= 0`, vérifie par tests de régression que les builders de rapports gèrent correctement un dataset mixte, et patche localement les sites qui cassent. Il existe déjà une source signée préexistante : `ProvisionService::extournesExercice()` via `Provision::montantSigne()` (peut retourner négatif pour les PCA). Les rapports combinent transactions + provisions — l'audit vérifie que les deux sources coexistent correctement.

## 2. Cibles d'audit

### 2.1 Builders de rapports (Step 2)

- [x] CompteResultatBuilder (`app/Services/Rapports/CompteResultatBuilder.php`) — verdict : **OK** — `SUM(transaction_lignes.montant)` algébrique, pas de `abs()`. Test 1 passe sans patch. Vérification : `accumulerRecettesResolues` et `accumulerDepensesResolues` utilisent `SUM()` natif.
- [x] FluxTresorerieBuilder (`app/Services/Rapports/FluxTresorerieBuilder.php`) — verdict : **OK** — `sum('montant_total')` algébrique. Test 2 passe. Note : `CASE WHEN type='recette' THEN montant_total ELSE 0 END` dans la requête mensuelle — comportement correct pour une recette négative (elle réduit le total recettes).
- [x] Dashboard KPIs (`app/Livewire/Dashboard.php`) — verdict : **OK** — `sum('montant_total')` algébrique. Test 3 vérifie totalRecettes=70, totalDepenses=50, soldeGeneral=20 avec dataset mixte.
- [x] Super-admin KPIs (`app/Livewire/SuperAdmin/Dashboard.php`) — verdict : **OK (hors scope transactions)** — Ce dashboard ne contient pas de KPIs de transactions ; il compte uniquement des associations (actif/suspendu/archive). Test 4 vérifie que le rendu ne casse pas avec des transactions négatives dans la DB tenant-scopée.
- [x] ClotureWizard (`app/Livewire/Exercices/ClotureWizard.php`) — verdict : **OK** — `sum('montant_total')` algébrique dans `computeFinancialSummary()`. Tests 5 et 6 vérifient totalRecettes, totalDepenses et resultat avec dataset mixte. Formule soldeOuverture = soldeReel - recettes + depenses : une recette négative réduit `recettes` ce qui augmente soldeOuverture — comportement correct.
- [x] RapprochementBancaireService (`app/Services/RapprochementBancaireService.php`) — verdict : **OK** — `calculerSoldePointage()` ligne 127-129 utilise `CASE WHEN type='depense' THEN -montant_total ELSE montant_total END`. Pour une recette à -50 : contribution = -50. Test 7 vérifie 500 + (-50) = 450.
- [x] RapportCompteResultat + RapportCompteResultatOperations (`app/Livewire/`) — verdict : **OK** — Le composant délègue à `CompteResultatBuilder` (déjà audité OK) et somme via `collect()->sum('montant_n')` algébrique. Test 8 vérifie le rendu sans erreur.
- [x] RapportExportController (`app/Http/Controllers/RapportExportController.php`) — verdict : **OK** — Les exports XLSX et PDF délèguent aux mêmes builders. Test 9 vérifie que l'export XLSX retourne 200 avec dataset mixte.
- [x] **Cas croisé transactions négatives + provisions PCA** — verdict : **OK** — Test 10 : tx recette -50 + Provision PCA (montant=-30, montantSigne()=-30). Les deux sources sont séparées (transactions via CompteResultatBuilder, provisions via ProvisionService) — pas de double-comptage. totalProduitsN=-50, totalProvisions=-30, resultatNet=-80.

### 2.2 Exports (Step 3)

- [x] Exports Excel compte de résultat + flux trésorerie (`app/Http/Controllers/RapportExportController.php`) — verdict : **OK** — les builders (`CompteResultatBuilder`, `FluxTresorerieBuilder`) délèguent à `SUM()` algébrique. Le Spreadsheet PhpSpreadsheet écrit les valeurs numériques telles quelles (pas de `abs()`). Tests 1, 5, 6 vérifient les valeurs numériques dans les cellules Excel (parse via `PhpOffice\PhpSpreadsheet\IOFactory::load()`).
- [x] PDF compte de résultat (`resources/views/pdf/rapport-compte-resultat.blade.php`) — verdict : **Patché** — filtre ligne 84 `$sc['montant_n'] > 0` remplacé par `$sc['montant_n'] != 0` (idem montant_n1 et budget). Les sous-catégories à montant strictement négatif étaient exclues silencieusement du PDF. Patch minimal, aucune autre logique modifiée. Tests 2 et 3 vérifient via rendu HTML pré-PDF : `60,00 €` apparaît, `-40,00 €` apparaît.
- [x] PDF flux trésorerie (`resources/views/pdf/rapport-flux-tresorerie.blade.php`) — verdict : **OK** — pas de filtre `> 0` sur les montants. `number_format()` accepte les valeurs négatives nativement. Test 4 vérifie `50,00 €` (algébrique) présent dans le HTML, `110,00 €` (abs naïve) absent.

### 2.3 Robustesse écrans (Step 4)

- [x] TransactionUniverselle (`app/Livewire/TransactionUniverselle.php`) — verdict : **OK** — le composant rend sans erreur avec une recette -80 € en base. Pagination, tri, header intégré fonctionnent. La valeur `-80` est affichée correctement dans la liste.
- [x] TransactionCompteList (`app/Livewire/TransactionCompteList.php`) — verdict : **OK** — sélection du compte, rendu de la liste avec une recette -80 € : pas d'erreur. Le solde progressif (solde_courant) calcule algébriquement.
- [x] TiersTransactions (`app/Livewire/TiersTransactions.php`) — verdict : **OK** — le composant rend pour un tiers ayant une transaction -80 € sans erreur ni exception.
- [x] Vue Créances à recevoir + filtre `montant > 0` — verdict : **Patché** — `TransactionUniverselleService::paginate()` ligne 56-60 : ajout d'un filtre `whereNot(source_type='recette' AND montant<=0)` quand `statutReglement='en_attente'`. Sans ce filtre, une recette à `montant_total=-50` avec `statut_reglement=en_attente` apparaissait dans la vue "Créances à recevoir" — ce qui est incorrect (une recette négative n'est pas une créance à encaisser). Les dépenses à régler (montant dans l'outer = `-montant_total`, toujours négatif pour les dépenses positives) sont préservées.
- [x] Dashboard rendering (`app/Livewire/Dashboard.php`) — verdict : **OK** — le composant rend avec un dataset mixte (+150, -80 recettes, +50 dépense). KPIs : totalRecettes=70, totalDepenses=50 visibles sans erreur.
- [x] Liste Rapprochements bancaires (`app/Livewire/RapprochementList.php`) — verdict : **OK** — le composant rend avec un rapprochement lié à une tx recette -80 €. Les totaux (crédit/débit) sont calculés par `SUM(montant_total)` algébrique, pas de crash.

### 2.4 Validations de saisie (Steps 5, 6, 7, 8)

Classe utilitaire : `app/Livewire/Concerns/MontantValidation.php` — classe statique finale portant `MESSAGE`, `RULE` et `messages()`. Convention pour Steps 6-8 : utiliser `MontantValidation::RULE` dans les tableaux de règles et `MontantValidation::messages([...])` dans les tableaux de messages.

Note refactor (post code review Step 5) : le binôme trait `RefusesMontantNegatif` + classe compagnon `MontantValidation` a été simplifié. Le trait a été supprimé ; `MontantValidation` est désormais la seule source de vérité (classe statique pure avec constructeur privé, expose `RULE`, `MESSAGE` et `messages()`). Moins de couplage, base propre pour Steps 6-8.

- [x] TransactionForm (Step 5) — verdict : **Patché** — `lignes.*.montant` : `min:0.01` → `MontantValidation::RULE` + `MontantValidation::messages(['lignes.*.montant'])`. Idem sur `affectations.*.montant` dans `saveVentilation()` : `MontantValidation::RULE` + `MontantValidation::messages(['affectations.*.montant'])`. 3 tests verts (dont `save_ventilation_refuse_montant_negatif_avec_message_standard`).
- [x] TransactionUniverselle (Step 5) — verdict : **n/a** — composant listing uniquement, aucune saisie de montant. Test documentant l'analyse : 1 test skipé.
- [x] FactureEdit (Step 5) — verdict : **Patché** — `ajouterLigneManuelle()` avait déjà `gt:0` mais sans message standardisé. Remplacé par `MontantValidation::RULE` + `MontantValidation::messages([...])` sur `nouvelleLigneMontantPrixUnitaire` et `nouvelleLigneMontantQuantite`. 2 tests verts.
- [x] ReglementTable (Step 6) — verdict : **Patché** — `updateMontant()` : ajout `Validator::make()` + `MontantValidation::RULE` + `MontantValidation::messages(['montant'])` avant la persistance. Erreur exposée via `$this->addError('montant', ...)`. 2 tests verts.
- [x] BackOffice/NoteDeFrais (Step 6) — verdict : **n/a (×2)** — `Index` est un listing pur (pas de saisie de montant). `Show::confirmValidation()` valide compte/date/modePaiement lors de la validation back-office ; le montant de la NDF est fixé à la soumission portail, pas ici. 2 tests skipés documentant l'analyse.
- [x] VirementInterneForm (Step 6) — verdict : **Patché** — `save()` : `'min:0.01'` remplacé par `MontantValidation::RULE`, message standardisé ajouté via `array_merge(MontantValidation::messages(['montant']), [...])`. 3 tests verts (négatif, zéro, positif).
- [x] RemiseBancaireList (Step 7) — verdict : **n/a** — `create()` ne saisit aucun montant directement. Le montant total d'une remise est dérivé des transactions sélectionnées dans `RemiseBancaireSelection` (sélection de transactions existantes, pas saisie de montant). Aucun patch nécessaire. 1 test skipé + 1 test positif documentant l'analyse.
- [x] Portail/NoteDeFrais (Step 7) — verdict : **Patché** — `Form::wizardNext()` étape 2 : règle `gt:0` déjà présente mais message non standardisé (`'Le montant doit être supérieur à zéro.'`). Remplacé par `MontantValidation::RULE` + `MontantValidation::messages(['draftLigne.montant'])` via `array_merge`. 3 tests verts (négatif, zéro, positif).
- [x] CsvImportService (Step 8) — verdict : **Patché** — `validateRow()` : validation `montant_ligne` scindée en deux branches distinctes : (1) non-numérique → message "valeur numérique attendue" inchangé ; (2) `<= 0` → `Log::warning('CsvImportService : montant négatif ou nul rejeté', ['csv_line' => $csvLine, 'montant' => ..., 'raison' => MontantValidation::MESSAGE])` + erreur rapport `'Colonne montant_ligne : '.MontantValidation::MESSAGE`. Le log porte `csv_line` (numéro de ligne CSV 1-based) et la raison standardisée. 4 tests verts : rejet avec message dans rapport (ligne 3), message contient `MontantValidation::MESSAGE`, log `warning` capté avec `csv_line=3`, rejet montant zéro.

### 2.5 Affichage (Step 9)

- [x] Helper formatMontant — verdict : **absent** — Aucun helper global `formatMontant` n'existe dans le projet (`app/Helpers/` contient uniquement `EmailLogo.php`, `Pays.php`, `ArticleFr.php` ; `composer.json` ne déclare pas de fichiers autoload). Le formatage est fait par `number_format($montant, 2, ',', ' ').' €'` directement dans les vues. PHP gère nativement le signe négatif : `number_format(-80, 2, ',', ' ').' €'` → `'-80,00 €'`. Pas de création d'helper (scope creep Slice 0). 1 test unitaire vérifie le comportement avec 7 valeurs représentatives (dont -1 500,00 €, 0,00 €, montants audit).
- [x] Tri data-sort sur colonnes montants — verdict : **Bug détecté et patché** — Inventaire complet : 13 occurrences `data-sort` sur colonnes montants dans les vues blade ; 4 vues ont du tri JS côté client (`localeCompare`). Seule `resources/views/livewire/provisions/provision-index.blade.php` cumule (a) `data-sort="{{ $provision->montant }}"` sur la colonne Montant et (b) tri JS `localeCompare('fr')`. Bug : `localeCompare` est lexicographique et produit un ordre incorrect pour les montants (ex. `'9.00'` trié après `'150.00'`). **Patch** : ajout de `compareVals(aVal, bVal)` dans le script JS de `provision-index.blade.php` — détecte si les deux valeurs sont numériques (`parseFloat` + `!isNaN`) et utilise la soustraction arithmétique, sinon replie sur `localeCompare`. La fonction est appliquée aux deux callsites (tri click + `reApplySort`). 2 tests Pest : (1) `data-sort` émet des valeurs numériques brutes `decimal:2` (ex. `-100.00`, pas `-100,00 €`) ; (2) démontre que `localeCompare` bogue sur `['9.00', '150.00']` tandis que le tri numérique par `(float)` est correct.

## 3. Patches apportés

**Step 2 : aucun patch nécessaire.** Tous les builders de rapports, dashboards et exports gèrent nativement les montants négatifs via des sommations SQL algébriques (`SUM()`, `sum('montant_total')`). Aucun `abs()` indu ni filtre `WHERE montant > 0` injustifié détecté dans les cibles du Step 2.

Nota bene sur `FluxTresorerieBuilder` : les requêtes mensuelle et rapprochement utilisent `CASE WHEN type='recette' THEN montant_total ELSE 0 END` pour agréger séparément recettes et dépenses. Une recette à montant négatif réduit correctement `total_recettes` — comportement cohérent.

**Step 3 : un patch nécessaire.**

- `resources/views/pdf/rapport-compte-resultat.blade.php` ligne 84 : filtre de visibilité des sous-catégories dans la vue PDF. Le filtre `$sc['montant_n'] > 0` excluait silencieusement les sous-catégories dont le montant exercice N est strictement négatif — elles n'apparaissaient pas dans le PDF imprimé. Corrigé en `$sc['montant_n'] != 0` (idem pour `montant_n1` et `budget`). La sémantique correcte est : afficher une sous-catégorie dès qu'elle a un montant non-nul sur l'un des deux exercices ou un budget alloué, quelle que soit la polarité du montant.

**Step 4 : un patch nécessaire.**

- `app/Services/TransactionUniverselleService.php` méthode `paginate()` : le filtre `statutReglement='en_attente'` n'excluait pas les recettes à montant négatif ou nul. Ces recettes apparaissaient dans la vue "Créances à recevoir" alors qu'elles ne constituent pas des créances réelles (une recette à montant_total <= 0 est soit une extourne future du Slice 1, soit invalide comme créance à encaisser). Corrigé en ajoutant `whereNot(source_type='recette' AND montant<=0)` dans la query outer uniquement quand `statutReglement='en_attente'`. Les dépenses à régler (montant dans l'outer = `-montant_total`, négatif pour des dépenses positives) ne sont pas affectées par ce filtre qui cible uniquement `source_type='recette'`.

**Step 5 : deux patches nécessaires (refactorisés post code review).**

- `app/Livewire/TransactionForm.php` méthode `save()` : règle `lignes.*.montant` changée de `min:0.01` à `MontantValidation::RULE`, messages via `MontantValidation::messages(['lignes.*.montant'])`. Méthode `saveVentilation()` : harmonisation `affectations.*.montant` avec `MontantValidation::RULE` + `MontantValidation::messages(['affectations.*.montant'])`.
- `app/Livewire/FactureEdit.php` méthode `ajouterLigneManuelle()` : règle `gt:0` déjà présente, remplacée par `MontantValidation::RULE` ; messages standardisés via `MontantValidation::messages([...])` sur `nouvelleLigneMontantPrixUnitaire` et `nouvelleLigneMontantQuantite`.
- `app/Livewire/TransactionUniverselle.php` : aucun patch nécessaire — composant listing, pas de saisie de montant.
- Refactor post code review : `app/Livewire/Concerns/RefusesMontantNegatif.php` (trait) supprimé. `MontantValidation` étendu pour être la seule source de vérité (`RULE`, `MESSAGE`, `messages()`). Pattern à suivre pour Steps 6-8 : `MontantValidation::RULE` dans les règles, `MontantValidation::messages([...])` dans les messages.

**Step 6 : deux patches nécessaires.**

- `app/Livewire/ReglementTable.php` méthode `updateMontant()` : ajout `Validator::make(['montant' => $parsed], ['montant' => ['required', 'numeric', MontantValidation::RULE]], MontantValidation::messages(['montant']))` avant la persistance. Si validation échoue : `$this->addError('montant', ...)` + `return`. Cette approche (Validator manuel + addError) est utilisée car les paramètres arrivent en argument et non via une propriété Livewire.
- `app/Livewire/VirementInterneForm.php` méthode `save()` : `'min:0.01'` remplacé par `MontantValidation::RULE` ; message standardisé ajouté via `array_merge(MontantValidation::messages(['montant']), [...autres messages...])`.
- `app/Livewire/BackOffice/NoteDeFrais/Index.php` : n/a — listing uniquement.
- `app/Livewire/BackOffice/NoteDeFrais/Show.php` : n/a — `confirmValidation()` ne saisit pas de montant (le montant est fixé à la soumission portail).

**Step 7 : un patch nécessaire.**

- `app/Livewire/Portail/NoteDeFrais/Form.php` méthode `wizardNext()` étape 2 : la règle `gt:0` existait déjà mais le message était non standardisé (`'Le montant doit être supérieur à zéro.'`). Remplacé par `MontantValidation::RULE` + `array_merge(['required' => ..., 'numeric' => ...], MontantValidation::messages(['draftLigne.montant']))`. Message standardisé : `MontantValidation::MESSAGE`.
- `app/Livewire/RemiseBancaireList.php` : n/a — `create()` ne saisit aucun montant. Le montant total d'une remise est dérivé des transactions sélectionnées dans `RemiseBancaireSelection`. Aucun patch nécessaire.

**Step 8 : un patch nécessaire.**

- `app/Services/CsvImportService.php` méthode `validateRow()` : la validation de `montant_ligne` était une condition combinée (`! is_numeric($montant) || (float) $montant <= 0`) avec message générique `"doit être un nombre > 0"`. Scindée en deux branches : (1) non-numérique → message "valeur numérique attendue" ; (2) valeur <= 0 → `Log::warning('CsvImportService : montant négatif ou nul rejeté', ['csv_line' => $csvLine, 'montant' => $montant, 'raison' => MontantValidation::MESSAGE])` + erreur rapport `'Colonne montant_ligne : '.MontantValidation::MESSAGE`. Imports `MontantValidation` et `Log` ajoutés. Le log porte toujours le numéro de ligne CSV 1-based (`csv_line`) pour traçabilité. Les autres lignes du fichier sont toujours traitées (validation exhaustive : Phase 1 collecte toutes les erreurs avant de rejeter l'import entier en Phase 2).

**Step 9 : un patch nécessaire (tri JS provision-index).**

- `resources/views/livewire/provisions/provision-index.blade.php` script JS : les deux callsites de `localeCompare(bVal, 'fr')` remplacés par `compareVals(aVal, bVal)`. La fonction `compareVals` (ajoutée juste avant les event listeners) détecte si les deux valeurs sont parsables en float (`!isNaN(parseFloat(…))`) et retourne leur différence arithmétique ; sinon replie sur `localeCompare`. La colonne Montant (`data-sort="{{ $provision->montant }}"`) émet des valeurs `decimal:2` (`'-100.00'`, `'50.00'` etc.) qui sont désormais triées numériquement. Les colonnes texte (Libellé, Sous-catégorie, Type, Tiers, Opération, Séance) conservent le tri `localeCompare` via la branche `isNaN`.
- `tests/Feature/Audit/SigneNegatifAffichageTest.php` : 3 tests verts — (1) `format_montant_affiche_signe_negatif_correctement` : 7 assertions `number_format` ; (2) `data_sort_sur_colonnes_montants_est_numerique` : parse HTML Livewire ProvisionIndex, vérifie présence des 4 valeurs brutes et absence de texte formaté avec virgule ou € ; (3) `tri_data_sort_montants_est_numerique_pas_lexicographique` : démontre le bug `localeCompare` sur `['9.00', '150.00']` vs tri `(float)` correct.

## 4. Précédent dans le code : extournes de provisions

`ProvisionService::extournesExercice()` (livré v2.10.0) gère déjà des montants signés et des extournes virtuelles N→N+1 des PCA/FNP. Voir `Provision::montantSigne()` (peut retourner négatif pour PCA). Notre Slice 1 introduit un mécanisme distinct (extourne **matérielle** de Transaction réelle) avec service nommé `TransactionExtourneService` pour désambiguïser.

## 5. Conclusion (Step 10)

### Récap des patches apportés (5 patches production)

1. **PDF compte de résultat** (`resources/views/pdf/rapport-compte-resultat.blade.php` ligne 84) — filtre de visibilité `$sc['montant_n'] > 0` remplacé par `$sc['montant_n'] != 0` (idem `montant_n1` et `budget`). Les sous-catégories à montant strictement négatif n'étaient plus exclues silencieusement du PDF imprimé.

2. **Filtre créances à recevoir** (`app/Services/TransactionUniverselleService.php` méthode `paginate()`) — ajout de `whereNot(source_type='recette' AND montant_total<=0)` quand `statutReglement='en_attente'`. Une recette à montant négatif n'est pas une créance à encaisser.

3. **Classe MontantValidation** (`app/Livewire/Concerns/MontantValidation.php`) — nouvelle classe statique pure (source de vérité unique) exposant `RULE` (`gt:0`), `MESSAGE` et `messages()`. Remplace l'ancien trait `RefusesMontantNegatif` (supprimé). Adoptée dans `TransactionForm.php` (règles `lignes.*.montant` et `affectations.*.montant`) et `FactureEdit.php` (règles `nouvelleLigneMontantPrixUnitaire` et `nouvelleLigneMontantQuantite`).

4. **Harmonisation messages de validation** (`app/Livewire/ReglementTable.php`, `app/Livewire/VirementInterneForm.php`, `app/Livewire/Portail/NoteDeFrais/Form.php`, `app/Services/CsvImportService.php`) — remplacement des messages ad hoc (`'min:0.01'`, `'Le montant doit être supérieur à zéro.'`) par `MontantValidation::RULE` + `MontantValidation::messages([...])`. `CsvImportService` ajoute en outre un `Log::warning` avec `csv_line` pour traçabilité des rejets CSV.

5. **Tri numérique colonne montant provisions** (`resources/views/livewire/provisions/provision-index.blade.php`) — les deux callsites `localeCompare` du tri JS côté client remplacés par `compareVals(aVal, bVal)`. La fonction `compareVals` utilise la soustraction arithmétique quand les deux valeurs sont parsables en float, et replie sur `localeCompare` sinon. Corrige le tri lexicographique incorrect sur des chaînes décimales (ex. `'9.00'` > `'150.00'` en localeCompare).

### Sites OK sans patch (aucune modification nécessaire)

Les composants et services suivants gèrent nativement les montants négatifs et n'ont requis aucun patch :

- `CompteResultatBuilder`, `FluxTresorerieBuilder` — `SUM()` algébrique en SQL
- `Dashboard.php` (tenant), `SuperAdmin/Dashboard.php` — `sum('montant_total')` algébrique
- `ClotureWizard.php` — `sum('montant_total')` algébrique dans `computeFinancialSummary()`
- `RapprochementBancaireService::calculerSoldePointage()` — `CASE WHEN type='depense' THEN -montant_total ELSE montant_total END`
- `RapportCompteResultat`, `RapportCompteResultatOperations` — délèguent à `CompteResultatBuilder`
- `RapportExportController` (exports XLSX et PDF flux trésorerie) — délèguent aux builders
- `TransactionUniverselle`, `TransactionCompteList`, `TiersTransactions` — affichage et tri sans filtre `> 0`
- `RapprochementList` — totaux crédit/débit `SUM(montant_total)` algébrique
- `BackOffice/NoteDeFrais/Index`, `BackOffice/NoteDeFrais/Show` — listing / validation sans saisie de montant
- `RemiseBancaireList` — montant total dérivé des transactions sélectionnées, pas saisi directement
- `PDF rapport flux trésorerie` — `number_format()` accepte nativement les valeurs négatives

### Statistiques du Slice 0

- **Commits** : 14 (de `f0b8ecd6` à `8fa4202e`)
- **Fichiers de tests ajoutés** : 13 fichiers dans `tests/Feature/Audit/` (48 cas de test `it(...)`, 146 assertions)
- **Fichiers de production modifiés / créés** : 10 (1 créé : `MontantValidation.php` ; 9 modifiés : voir section 3)
- **Fichier de documentation** : 1 (`docs/audit/2026-04-30-signe-negatif.md`)
- **Migrations de schéma** : aucune (le Slice 0 est un audit pur — zéro DDL)
- **Fonctionnalités utilisateur nouvelles** : aucune (les validations existantes produisent désormais un message standard ; UX identique)

### Résultat de la suite de tests

- Suite Audit (`tests/Feature/Audit/`) : **48 passed** (146 assertions) — tous verts
- Suite Feature complète : **2740 passed, 4 failed préexistants** (`EmargementRoundTripTest` × 1, `QrCodeExtractorTest` × 3) — ces échecs existent depuis avant Slice 0 et ne concernent pas les montants signés
- Suite Unit : **1 passed, 399 deprecated** (avertissements `PDO::MYSQL_ATTR_SSL_CA` — non bloquants, PHP 8.4 compat, présents sur tout le projet avant Slice 0)
- Pint : **vert sur tous les fichiers Slice 0** ; 2 violations préexistantes dans `DemoLoginAsTierController.php` et `config/version.php` (introduites avant le Slice 0, hors périmètre de cet audit)

### Recommandation

Le code est prêt à accueillir des transactions à montant négatif. Les cinq sites qui auraient silencieusement cassé (PDF compte de résultat, filtre créances, validation TransactionForm/FactureEdit, messages validation, tri provisions) ont été patchés et couverts par des tests de régression. Tous les autres sites de sommation et d'affichage gèrent algébriquement les montants négatifs sans modification.

**Le Slice 1 (extourne de transaction) peut démarrer.**
