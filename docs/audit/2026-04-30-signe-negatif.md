# Audit signe négatif — Slice 0

**Date** : 2026-04-30
**Branche** : claude/funny-shamir-8661f9
**Spec** : docs/specs/2026-04-30-audit-signe-negatif-s0.md
**Plan** : plans/audit-signe-negatif-s0.md
**Statut** : en cours (sera "Slice 0 prêt" en step 10)

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

Trait commun : `app/Livewire/Concerns/RefusesMontantNegatif.php` (créé Step 5)
Classe compagnon : `app/Livewire/Concerns/MontantValidation.php` — porte `MESSAGE` (constante de trait non accessible directement en PHP, exposée via classe finale).

- [x] TransactionForm (Step 5) — verdict : **Patché** — `lignes.*.montant` : `min:0.01` → `gt:0` + message standardisé via trait. Idem sur `affectations.*.montant` dans `saveVentilation()` (harmonisation). 2 tests verts.
- [x] TransactionUniverselle (Step 5) — verdict : **n/a** — composant listing uniquement, aucune saisie de montant. Trait non applicable. Test documentant l'analyse : 1 test skipé.
- [x] FactureEdit (Step 5) — verdict : **Patché** — `ajouterLigneManuelle()` avait déjà `gt:0` mais sans message standardisé. Ajout du trait + `montantNegatifMessages()` sur `nouvelleLigneMontantPrixUnitaire` et `nouvelleLigneMontantQuantite`. 2 tests verts.
- [ ] ReglementTable (Step 6)
- [ ] BackOffice/NoteDeFrais (Step 6)
- [ ] VirementInterneForm (Step 6)
- [ ] RemiseBancaireList (Step 7)
- [ ] Portail/NoteDeFrais (Step 7)
- [ ] CsvImportService (Step 8) — refus avec log

### 2.5 Affichage (Step 9)

- [ ] Helper formatMontant (à identifier ou créer) — gestion signe
- [ ] Tri data-sort sur colonnes montants — vérification numérique

## 3. Patches apportés

**Step 2 : aucun patch nécessaire.** Tous les builders de rapports, dashboards et exports gèrent nativement les montants négatifs via des sommations SQL algébriques (`SUM()`, `sum('montant_total')`). Aucun `abs()` indu ni filtre `WHERE montant > 0` injustifié détecté dans les cibles du Step 2.

Nota bene sur `FluxTresorerieBuilder` : les requêtes mensuelle et rapprochement utilisent `CASE WHEN type='recette' THEN montant_total ELSE 0 END` pour agréger séparément recettes et dépenses. Une recette à montant négatif réduit correctement `total_recettes` — comportement cohérent.

**Step 3 : un patch nécessaire.**

- `resources/views/pdf/rapport-compte-resultat.blade.php` ligne 84 : filtre de visibilité des sous-catégories dans la vue PDF. Le filtre `$sc['montant_n'] > 0` excluait silencieusement les sous-catégories dont le montant exercice N est strictement négatif — elles n'apparaissaient pas dans le PDF imprimé. Corrigé en `$sc['montant_n'] != 0` (idem pour `montant_n1` et `budget`). La sémantique correcte est : afficher une sous-catégorie dès qu'elle a un montant non-nul sur l'un des deux exercices ou un budget alloué, quelle que soit la polarité du montant.

**Step 4 : un patch nécessaire.**

- `app/Services/TransactionUniverselleService.php` méthode `paginate()` : le filtre `statutReglement='en_attente'` n'excluait pas les recettes à montant négatif ou nul. Ces recettes apparaissaient dans la vue "Créances à recevoir" alors qu'elles ne constituent pas des créances réelles (une recette à montant_total <= 0 est soit une extourne future du Slice 1, soit invalide comme créance à encaisser). Corrigé en ajoutant `whereNot(source_type='recette' AND montant<=0)` dans la query outer uniquement quand `statutReglement='en_attente'`. Les dépenses à régler (montant dans l'outer = `-montant_total`, négatif pour des dépenses positives) ne sont pas affectées par ce filtre qui cible uniquement `source_type='recette'`.

**Step 5 : deux patches nécessaires.**

- `app/Livewire/TransactionForm.php` méthode `save()` : règle `lignes.*.montant` changée de `min:0.01` à `gt:0`, message standardisé `MontantValidation::MESSAGE` ajouté via `self::montantNegatifMessages()`. Même harmonisation sur `affectations.*.montant` dans `saveVentilation()`.
- `app/Livewire/FactureEdit.php` méthode `ajouterLigneManuelle()` : règle `gt:0` déjà présente sur `nouvelleLigneMontantPrixUnitaire` et `nouvelleLigneMontantQuantite`, mais sans message. Ajout du trait `RefusesMontantNegatif` et des messages standardisés via `self::montantNegatifMessages()`.
- `app/Livewire/TransactionUniverselle.php` : aucun patch nécessaire — composant listing, pas de saisie de montant.

## 4. Précédent dans le code : extournes de provisions

`ProvisionService::extournesExercice()` (livré v2.10.0) gère déjà des montants signés et des extournes virtuelles N→N+1 des PCA/FNP. Voir `Provision::montantSigne()` (peut retourner négatif pour PCA). Notre Slice 1 introduit un mécanisme distinct (extourne **matérielle** de Transaction réelle) avec service nommé `TransactionExtourneService` pour désambiguïser.

## 5. Conclusion (Step 10)

(À remplir au step 10 : récap patches, sites OK sans patch, recommandation explicite "Slice 1 peut démarrer".)
