# Plan: Audit signe négatif — Slice 0 du programme Extourne & Annulation de facture

**Created**: 2026-04-30
**Branch**: `claude/funny-shamir-8661f9` (worktree) — sera mergée vers `main` à la fin du Slice 0
**Status**: approved
**Spec source**: [docs/specs/2026-04-30-audit-signe-negatif-s0.md](docs/specs/2026-04-30-audit-signe-negatif-s0.md) (PASS)
**Préalable de** : Slice 1 (extourne) puis Slice 2 (annulation de facture). Programme global suivi dans `docs/specs/2026-04-30-extourne-transaction-s1.md`.

## Goal

Fiabiliser tout le code de l'application qui suppose `Transaction.montant >= 0` ou `TransactionLigne.montant >= 0`, en préparation du Slice 1 qui introduira des transactions à montant négatif (extournes). Le slice **ne livre aucune fonctionnalité utilisateur visible** : il durcit uniformément les validations de saisie manuelle pour refuser les négatifs avec un message standardisé, vérifie par tests de régression que les builders de rapports et écrans de listing fonctionnent correctement avec un dataset mixte positif/négatif, et patche localement les sites qui cassent. À l'issue du Slice 0, le code est sûr pour accueillir les premières transactions négatives sans régression silencieuse sur les rapports, dashboards, exports et écrans existants.

## Acceptance Criteria

- [ ] Document `docs/audit/2026-04-30-signe-negatif.md` livré, exhaustif, chaque site identifié avec verdict (OK / Patché) et lien fichier:ligne
- [ ] Suite Pest verte (3170+ tests existants + ~15-20 nouveaux tests d'audit)
- [ ] Tous les formulaires utilisateur de saisie de transaction recette ou ligne MontantManuel rejettent uniformément les montants négatifs avec le message standardisé : `"Le montant doit être positif. L'extourne se fait via le bouton dédié sur une transaction existante."`
- [ ] Insertion directe en base d'une transaction négative : compte de résultat, flux trésorerie, dashboard, KPIs super-admin, exports Excel et PDF répondent correctement (sommations naturelles incluant les négatifs, pas d'`abs()` indu, pas de filtre `> 0` injustifié)
- [ ] Tous les écrans listant des transactions affichent les négatifs sans erreur (signe explicite, couleur)
- [ ] CsvImportService refuse les négatifs avec log explicite, autres lignes du fichier traitées normalement
- [ ] Aucune fonctionnalité utilisateur visible nouvelle (slice de fiabilisation pure)
- [ ] PSR-12 / Pint vert
- [ ] `declare(strict_types=1)` + `final class` sur tous les nouveaux fichiers
- [ ] Aucune migration de schéma (le slice 0 ne touche pas la DB)
- [ ] `/code-review --changed` passe

## Steps

### Step 1: Préparer le document d'audit (squelette)

**Complexity**: trivial
**RED**: N/A (livrable doc, pas de test)
**GREEN**: Créer `docs/audit/2026-04-30-signe-negatif.md` avec sections vides à cocher au fil des steps : cibles validations (9 formulaires), cibles builders rapports, cibles exports, cibles affichage, conclusions
**REFACTOR**: None
**Files**: `docs/audit/2026-04-30-signe-negatif.md`
**Commit**: `docs: scaffold audit document for slice 0 signe négatif`

### Step 2: Tests de régression sommations rapports (CompteResultat + FluxTresorerie)

**Complexity**: standard
**RED**: Écrire `tests/Feature/Audit/SigneNegatifRapportsTest.php` avec :
- Test `compte_resultat_somme_correctement_les_negatifs` : crée tx +80 € + tx -80 € dans même sous-cat / exercice via factory directe en base, asserte que CompteResultatBuilder retourne ∑ produits sous-cat = 0 €
- Test `flux_tresorerie_inclut_negatifs_pointes` : tx -50 € pointée banque, asserte solde de trésorerie correct
- Test `dashboard_kpis_somme_negatifs` : dataset mixte, asserte total recettes du mois = somme nette
- Test équivalent sur super-admin KPIs (`Livewire\SuperAdmin\Dashboard\*`)
- Test `cloture_wizard_calcule_solde_ouverture_avec_negatifs` : ClotureWizard ligne 126, `soldeOuverture = soldeReel - recettes + depenses - vIn + vOut` doit fonctionner avec tx recette négative dans recettes (réduit la somme — comportement attendu)
- Test `cloture_wizard_resultat_avec_extourne` : `resultat = totalRecettes - totalDepenses`, asserter avec dataset mixte
- Test `rapprochement_service_solde_avec_negatif` : RapprochementBancaireService ligne 125 `solde_ouverture + entrées pointées − sorties pointées`, asserter avec tx pointée négative
- Test `rapport_compte_resultat_livewire` : composant RapportCompteResultat + RapportCompteResultatOperations rendent correctement avec dataset mixte
- Test `rapport_export_controller_synthese` : exports PDF/Excel via RapportExportController affichent solde ouverture correct
- **Test `compte_resultat_avec_transactions_negatives_ET_provisions_PCA`** : dataset croisé combinant (a) transactions recette à montants négatifs (futures extournes du Slice 1) ET (b) `Provision` de type recette à montant négatif (PCA — déjà autorisé dans le code via `Provision::montantSigne()`). Asserter que les rapports (compte de résultat, flux trésorerie) cumulent correctement les deux sources signées sans double-comptage ni occultation. **Pourquoi** : le module Provisions (`ProvisionService::extournesExercice` + `totalExtournes`) gère déjà des montants signés et des "extournes" virtuelles N→N+1. L'audit doit vérifier que l'introduction des transactions négatives ne casse pas la combinatoire avec ce mécanisme préexistant.

**GREEN**: Si tests passent → simple ajout. Si un test casse (ex. `abs()` indu, filtre `> 0`) → patcher le builder concerné en commentant la modification dans le fichier d'audit. Cocher chaque builder dans `docs/audit/2026-04-30-signe-negatif.md`.
**REFACTOR**: Mutualiser le helper de création de dataset mixte dans un trait/concern de test si réutilisable
**Files**: `tests/Feature/Audit/SigneNegatifRapportsTest.php`, éventuellement `app/Services/Rapports/CompteResultatBuilder.php`, `app/Services/Rapports/FluxTresorerieBuilder.php`, `docs/audit/2026-04-30-signe-negatif.md`
**Commit**: `test(audit): regression tests on rapport builders with mixed positive/negative dataset`

### Step 3: Tests de régression exports (Excel + PDF rapports)

**Complexity**: standard
**RED**: Étendre `SigneNegatifRapportsTest.php` ou créer `SigneNegatifExportsTest.php` :
- Test `export_excel_compte_resultat_somme_negatifs` : génère export, asserte les totaux Excel sommés (lit le fichier généré, parse cellules de total)
- Test `pdf_compte_resultat_somme_negatifs` : génère PDF, asserte présence des montants signés (extraction texte ou check de pattern dans HTML pré-PDF)
- Test équivalent sur PDF flux trésorerie

**GREEN**: Idem step 2 — passe si rien à patcher, sinon patch ciblé. Cocher dans audit.
**REFACTOR**: None
**Files**: `tests/Feature/Audit/SigneNegatifExportsTest.php`, éventuellement vues PDF / Excel exporters, `docs/audit/2026-04-30-signe-negatif.md`
**Commit**: `test(audit): regression tests on Excel and PDF exports with negative amounts`

### Step 4: Test de robustesse écrans (transactions négatives en base)

**Complexity**: standard
**RED**: Créer `tests/Feature/Audit/SigneNegatifEcransTest.php` :
- Insertion directe d'une `Transaction` recette à -80 € via factory + `TransactionLigne` à -80 €
- Pour chaque écran : monter le composant Livewire, asserter `assertSee` du libellé attendu, pas d'erreur 500, pagination/tri fonctionnent. Écrans visés : `TransactionUniverselle`, `TransactionCompteList`, `TiersTransactions`, écran Créances à recevoir, `Dashboard`, écran "Rapprochements bancaires" (rendering uniquement)
- Asserter que la transaction négative **n'apparaît pas** dans la vue Créances à recevoir si `EnAttente` (filtre `montant > 0` à ajouter ; cf §3.5 spec S1)

**GREEN**: Si un écran casse (ex. format), patcher localement. Si filtre Créances à recevoir doit être ajusté, le faire ici (préparation S1). Cocher chaque écran dans audit.
**REFACTOR**: Extraire factory helper `transactionRecetteNegative()` si pertinent
**Files**: `tests/Feature/Audit/SigneNegatifEcransTest.php`, éventuellement écrans Livewire, `docs/audit/2026-04-30-signe-negatif.md`
**Commit**: `test(audit): regression tests on listing screens with negative transactions in DB`

### Step 5: Trait `RefusesMontantNegatif` + durcissement TransactionForm, TransactionUniverselle, FactureEdit

**Complexity**: standard
**RED**: Créer `tests/Feature/Audit/RefusesNegatifTransactionFormTest.php`, `RefusesNegatifTransactionUniverselleTest.php`, `RefusesNegatifFactureEditTest.php`. Chaque test :
- Monte le composant Livewire concerné
- Soumet un montant -50 € (ou prix unitaire -50 € pour FactureEdit)
- Asserte la présence de l'erreur de validation avec le message standardisé : `"Le montant doit être positif. L'extourne se fait via le bouton dédié sur une transaction existante."`

**GREEN**:
- Créer le trait `App\Livewire\Concerns\RefusesMontantNegatif` (ou classe avec constante de message) qui expose la règle Livewire `gt:0` + le message
- Appliquer aux 3 composants : ajout règle `montant` (et `prix_unitaire`/`quantite` pour FactureEdit) avec le message uniforme
- Cocher dans audit

**REFACTOR**: Vérifier que d'autres composants ont déjà des règles `montant` non uniformes, harmoniser
**Files**: `app/Livewire/Concerns/RefusesMontantNegatif.php`, `app/Livewire/TransactionForm.php`, `app/Livewire/TransactionUniverselle.php`, `app/Livewire/FactureEdit.php`, 3 fichiers de test, `docs/audit/2026-04-30-signe-negatif.md`
**Commit**: `feat(audit): refuse negative amounts uniformly on TransactionForm, TransactionUniverselle, FactureEdit`

### Step 6: Durcissement ReglementTable + BackOffice/NoteDeFrais + VirementInterneForm

**Complexity**: standard
**RED**: Créer 3 tests Pest analogues au Step 5 sur ces composants. Chacun soumet un montant négatif et asserte le rejet avec message standard.
**GREEN**: Appliquer le trait `RefusesMontantNegatif` aux 3 composants. Cocher dans audit.
**REFACTOR**: None
**Files**: `app/Livewire/ReglementTable.php`, `app/Livewire/BackOffice/NoteDeFrais/*` (à identifier précisément), `app/Livewire/VirementInterneForm.php`, 3 tests, audit doc
**Commit**: `feat(audit): refuse negative amounts on ReglementTable, BackOffice/NoteDeFrais, VirementInterneForm`

### Step 7: Durcissement RemiseBancaireList + Portail/NoteDeFrais

**Complexity**: standard
**RED**: 2 tests Pest analogues. Le test sur Portail/NoteDeFrais vérifie le contexte multi-tenant + portail tiers (auth OTP).
**GREEN**: Appliquer le trait. Cocher dans audit.
**REFACTOR**: None
**Files**: `app/Livewire/RemiseBancaireList.php`, `app/Livewire/Portail/NoteDeFrais/*`, 2 tests, audit doc
**Commit**: `feat(audit): refuse negative amounts on RemiseBancaireList and Portail/NoteDeFrais`

### Step 8: CsvImportService refuse les montants négatifs

**Complexity**: standard
**RED**: Test `tests/Feature/Audit/CsvImportRefuseNegatifsTest.php` :
- Fichier CSV avec 3 lignes : +50, -30, +20
- Import → ligne -30 rejetée avec message dans le rapport, lignes +50 et +20 importées
- Vérifier que le log porte le numéro de ligne et la raison

**GREEN**: Modifier `CsvImportService` pour valider chaque ligne ; rejet avec message uniforme dans le rapport. Cocher dans audit.
**REFACTOR**: Vérifier qu'aucune autre voie d'import (xlsx, etc.) n'a la même faiblesse
**Files**: `app/Services/CsvImportService.php`, test, audit doc
**Commit**: `feat(audit): CsvImportService rejects negative amounts with explicit log`

### Step 9: Affichage signe — helper formatMontant + tri data-sort

**Complexity**: standard
**RED**: Tests :
- Si helper global `formatMontant()` existe : test unitaire avec valeur négative → vérifier rendu attendu (signe explicite, classe CSS `text-danger` ou équivalent)
- Test `tests/Feature/Audit/TriColonneNegatifTest.php` : monter une liste avec mix [+50, -100, +200, -10], asserter que tri ascendant donne [-100, -10, +50, +200] (tri numérique, pas lexicographique)

**GREEN**: Adapter helper d'affichage si nécessaire ; vérifier que les `data-sort` sur colonnes montants utilisent bien des valeurs numériques (et non du texte formaté). Cocher dans audit.
**REFACTOR**: Documenter la convention dans audit doc
**Files**: helper formatage si patché, vues Blade si patchées, 1-2 tests, audit doc
**Commit**: `feat(audit): formatMontant displays sign + verify numeric sort on data-sort columns`

### Step 10: Finalisation document d'audit + Pre-PR quality gate

**Complexity**: trivial
**RED**: N/A
**GREEN**:
- Re-lire le document d'audit, vérifier que toutes les sections sont remplies (chaque site a verdict OK ou Patché avec lien fichier:ligne)
- Section conclusion : récapitulatif des patches apportés, liste des sites OK sans patch, recommandation pour la suite (Slice 1 peut démarrer)
- Lancer suite Pest complète : doit être verte
- Lancer Pint : doit être vert
- Lancer `/code-review --changed` : doit passer

**REFACTOR**: None
**Files**: `docs/audit/2026-04-30-signe-negatif.md`
**Commit**: `docs(audit): finalize signe négatif audit — slice 0 ready for slice 1`

## Complexity Classification

Synthèse :

| Step | Complexity | Justification |
|---|---|---|
| 1 | trivial | Doc squelette uniquement |
| 2 | standard | Tests + patches potentiels sur builders |
| 3 | standard | Tests + patches potentiels sur exports |
| 4 | standard | Tests + ajustement filtre Créances |
| 5 | standard | Trait + 3 composants + 3 tests |
| 6 | standard | 3 composants + 3 tests |
| 7 | standard | 2 composants + 2 tests |
| 8 | standard | Service + 1 test |
| 9 | standard | Helper + 2 tests |
| 10 | trivial | Doc + gates |

Aucun step `complex` : le slice 0 est volontairement de la fiabilisation, pas d'architecture nouvelle.

## Pre-PR Quality Gate

- [ ] Tous les tests Pest passent (`./vendor/bin/sail test`)
- [ ] Pint passe (`./vendor/bin/sail pint --test`)
- [ ] `/code-review --changed` passe sans bloqueur
- [ ] Document `docs/audit/2026-04-30-signe-negatif.md` complet et exhaustif
- [ ] Aucune migration de schéma introduite
- [ ] Aucune fonctionnalité utilisateur visible nouvelle (vérification manuelle rapide)
- [ ] Recommandation explicite "Slice 1 peut démarrer" dans la conclusion de l'audit

## Risks & Open Questions

- **R1 — Sites manqués lors de l'audit** : la liste des cibles dans la spec S0 (§3.1) peut être incomplète. Mitigation : pendant Step 2-4, faire une passe `grep -rn "where('montant'" app/` et `grep -rn "abs(\$montant" app/` et `grep -rn "->montant\s*>\s*0" app/` pour découvrir les sites non listés. Ajouter au doc d'audit toute trouvaille.
- **R2 — Tests existants assument `montant >= 0`** : possible qu'un test existant casse parce qu'il valide un message d'erreur sur un autre champ que `montant` et qu'on change le message. Mitigation : exécuter la suite après Step 5 et corriger les tests concernés sans modifier le comportement métier.
- **R3 — Filtre Créances à recevoir actuel** : si la vue actuelle filtre `statut_reglement = EnAttente` sans filtrer `montant > 0`, l'ajout du filtre montant peut affecter d'autres cas (ex. transaction réelle à 0 €). Vérifier en Step 4 que cet edge case n'existe pas en prod (peu probable mais à confirmer).
- **R4 — Coordination avec autres branches en cours** : la branche `claude/funny-shamir-8661f9` est un worktree. Si `main` reçoit d'autres mergés pendant le Slice 0, il faudra rebaser. Mitigation : viser une livraison rapide (1-2 jours), rebaser en début de chaque session.
- **Q1 — Format du message standard** : confirmer que `"Le montant doit être positif. L'extourne se fait via le bouton dédié sur une transaction existante."` est le bon wording. Alternative plus user-friendly : `"Le montant doit être positif. Pour annuler une recette, utilisez le bouton « Annuler la transaction » sur la fiche de la transaction concernée."` — à trancher avant Step 5.
- **Q2 — Helper formatMontant existe-t-il aujourd'hui ?** À identifier en Step 9 ; si non, créer.
- **Q3 — Rôle Comptable** : vérifier que le système de rôles existant supporte un rôle Comptable distinct d'Admin et Gestionnaire. Pas bloquant pour S0 (qui ne touche pas aux permissions) mais critique pour S1.
